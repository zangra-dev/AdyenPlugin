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

namespace Sylius\AdyenPlugin\Bus\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Sylius\AdyenPlugin\Bus\Command\AuthorizePendingPayment;
use Sylius\AdyenPlugin\Bus\Command\PaymentStatusReceived;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Finalizes a payment from an authorisation notification the same way the "payment details"
 * response would have done: the payment gets the authorised result and the psp reference of
 * the notification, then the regular "payment status received" flow completes the order checkout
 * (and sends the confirmation), and authorizes the payment.
 *
 * The handler is idempotent: Adyen may deliver a notification more than once, so nothing is done if the
 * order has already left the cart or the payment is no longer pending.
 */
final class AuthorizePendingPaymentHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $commandBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AuthorizePendingPayment $command): void
    {
        $payment = $command->payment;

        $orderNumber = $payment->getOrder()?->getNumber() ?? '';
        $pspReference = $command->notificationData->pspReference ?? '';

        if (!$this->isStillPending($payment)) {
            $this->logger->info(sprintf(
                'Pending payment of order [%s] was not authorized from notification [%s]: skipped, already processed.',
                $orderNumber,
                $pspReference,
            ));

            return;
        }

        $details = $payment->getDetails();
        unset($details['action']);

        $details['resultCode'] = 'Authorised';
        $details['pspReference'] = $command->notificationData->pspReference;

        $payment->setDetails($details);

        $this->commandBus->dispatch(new PaymentStatusReceived($payment));

        $this->entityManager->flush();

        $this->logger->info(sprintf(
            'Pending payment of order [%s] was authorized from notification [%s].',
            $orderNumber,
            $pspReference,
        ));
    }

    private function isStillPending(PaymentInterface $payment): bool
    {
        return OrderInterface::STATE_CART === $payment->getOrder()?->getState() &&
            in_array(
                $payment->getState(),
                [PaymentInterface::STATE_CART, PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING],
                true,
            )
        ;
    }
}
