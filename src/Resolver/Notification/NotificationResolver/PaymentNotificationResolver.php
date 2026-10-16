<?php

/*
 * This file is part of the Sylius Adyen Plugin package.
 *
 * (c) Sylius Sp. z o.o.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver;

use Doctrine\ORM\NoResultException;
use Sylius\AdyenPlugin\Bus\Command\AuthorizePendingPayment;
use Sylius\AdyenPlugin\Bus\PaymentCommandFactoryInterface;
use Sylius\AdyenPlugin\Exception\UnmappedAdyenActionException;
use Sylius\AdyenPlugin\Repository\AdyenReferenceRepositoryInterface;
use Sylius\AdyenPlugin\Repository\PaymentLinkRepositoryInterface;
use Sylius\AdyenPlugin\Resolver\Notification\Struct\NotificationItemData;
use Sylius\AdyenPlugin\Resolver\Payment\EventCodeResolverInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Webmozart\Assert\Assert;

final class PaymentNotificationResolver implements CommandResolver
{
    /**
     * Time (in seconds) given to the customer to return to the shop and finalize the checkout by
     * himself before a notification is allowed to finalize it instead.
     */
    public const PENDING_PAYMENT_GRACE_PERIOD = 90;

    public function __construct(
        private readonly AdyenReferenceRepositoryInterface $adyenReferenceRepository,
        private readonly PaymentLinkRepositoryInterface $paymentLinkRepository,
        private readonly PaymentCommandFactoryInterface $commandFactory,
        private readonly RepositoryInterface $orderRepository,
    ) {
    }

    public function resolve(string $paymentMethodCode, NotificationItemData $notificationData): object
    {
        try {
            $payment = $this->getPayment($paymentMethodCode, $notificationData);
        } catch (NoCommandResolvedException $exception) {
            $pendingPayment = $this->fetchPendingPaymentByMerchantReference($paymentMethodCode, $notificationData);
            if (null === $pendingPayment) {
                throw $exception;
            }

            $remainingSeconds = $this->getRemainingGracePeriod($notificationData);
            if ($remainingSeconds > 0) {
                throw new NotificationDeferralRequiredException($remainingSeconds);
            }

            return new AuthorizePendingPayment($pendingPayment, $notificationData);
        }

        try {
            return $this->commandFactory->createForEvent(
                (string) $notificationData->eventCode,
                $payment,
                $notificationData,
            );
        } catch (UnmappedAdyenActionException) {
            throw new NoCommandResolvedException();
        }
    }

    private function getPayment(
        string $paymentMethodCode,
        NotificationItemData $notificationData,
    ): PaymentInterface {
        try {
            return $this->fetchPaymentByReference($paymentMethodCode, $notificationData);
        } catch (NoCommandResolvedException) {
            return $this->fetchPaymentByPaymentLink($paymentMethodCode, $notificationData);
        }
    }

    private function fetchPaymentByReference(
        string $paymentCode,
        NotificationItemData $notificationData,
    ): PaymentInterface {
        try {
            $reference = $this->adyenReferenceRepository->getOneByCodeAndReference(
                $paymentCode,
                $notificationData->originalReference ?? (string) $notificationData->pspReference,
            );

            $result = $reference->getPayment();
            Assert::notNull($result);

            return $result;
        } catch (NoResultException) {
            throw new NoCommandResolvedException();
        }
    }

    /**
     * Some payment methods (e.g. Bancontact Pay) are authorised with a psp reference which differs from
     * the one returned when the payment was initiated, and the customer may never return to the shop
     * to finalize the checkout, so the notification cannot be matched using any known psp reference.
     * Such a successful authorisation is matched using the merchant reference (order number), but only
     * if the order checkout was not finalized yet and the amount is the expected one.
     */
    private function fetchPendingPaymentByMerchantReference(string $paymentMethodCode, NotificationItemData $notificationData): ?PaymentInterface
    {
        if (
            EventCodeResolverInterface::EVENT_AUTHORIZATION !== $notificationData->eventCode ||
            !filter_var($notificationData->success, \FILTER_VALIDATE_BOOLEAN) ||
            null === $notificationData->pspReference ||
            null === $notificationData->merchantReference
        ) {
            return null;
        }

        $order = $this->orderRepository->findOneBy(['number' => $notificationData->merchantReference]);
        if (!$order instanceof OrderInterface || OrderInterface::STATE_CART !== $order->getState()) {
            return null;
        }

        $payments = $order->getPayments()->filter(
            static fn (PaymentInterface $payment): bool => $payment->getMethod()?->getCode() === $paymentMethodCode &&
                in_array($payment->getState(), [PaymentInterface::STATE_CART, PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING], true) &&
                self::isSameAmount($payment, $notificationData),
        );

        $result = $payments->last();

        return false === $result ? null : $result;
    }

    /**
     * @return int Seconds left until the grace period is over, 0 if it is over (or the event date is unknown)
     */
    private function getRemainingGracePeriod(NotificationItemData $notificationData): int
    {
        if (null === $notificationData->eventDate) {
            return 0;
        }

        try {
            $eventDate = new \DateTimeImmutable($notificationData->eventDate);
        } catch (\Exception) {
            return 0;
        }

        $age = (new \DateTimeImmutable())->getTimestamp() - $eventDate->getTimestamp();

        return max(0, self::PENDING_PAYMENT_GRACE_PERIOD - $age);
    }

    private static function isSameAmount(PaymentInterface $payment, NotificationItemData $notificationData): bool
    {
        $amount = $notificationData->amount;

        return null !== $amount &&
            $amount->value === $payment->getAmount() &&
            $amount->currency === $payment->getCurrencyCode()
        ;
    }

    private function fetchPaymentByPaymentLink(
        string $paymentMethodCode,
        NotificationItemData $notificationData,
    ): PaymentInterface {
        $paymentLinkId = $notificationData->additionalData['paymentLinkId'] ?? null;
        if (!isset($paymentLinkId)) {
            throw new NoCommandResolvedException('Payment link ID is not provided in the notification data.');
        }

        $result = $this->paymentLinkRepository->findOneByPaymentMethodCodeAndLinkId(
            $paymentMethodCode,
            $paymentLinkId,
        );
        if (null === $result) {
            throw new NoCommandResolvedException();
        }

        return $result->getPayment();
    }
}
