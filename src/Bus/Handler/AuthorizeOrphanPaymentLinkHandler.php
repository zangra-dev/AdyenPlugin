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
use Sylius\AdyenPlugin\Bus\Command\AuthorizeOrphanPaymentLink;
use Sylius\AdyenPlugin\Bus\Command\AuthorizePaymentByLink;
use Sylius\AdyenPlugin\Checker\AdyenPaymentMethodCheckerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles a successful authorisation of a payment link created directly in the Adyen backoffice.
 *
 * The latest open Adyen payment of the order is the one completed (its amount is aligned to the amount
 * actually paid). If the order has no such payment, a new one is created for the paid amount.
 * The authorisation itself is then handled like for a payment link generated from Sylius.
 */
final class AuthorizeOrphanPaymentLinkHandler
{
    private const OPEN_STATES = [
        PaymentInterface::STATE_CART,
        PaymentInterface::STATE_NEW,
        PaymentInterface::STATE_PROCESSING,
    ];

    /**
     * @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $commandBus,
        private readonly AdyenPaymentMethodCheckerInterface $adyenPaymentMethodChecker,
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly FactoryInterface $paymentFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AuthorizeOrphanPaymentLink $command): void
    {
        $order = $command->order;
        $notificationData = $command->notificationData;

        $amount = $notificationData->amount;
        if (null === $amount) {
            return;
        }

        $payment = $this->findOpenAdyenPayment($order);
        if (null === $payment) {
            $payment = $this->createPayment($order, (string) $notificationData->paymentCode, $amount->value, $amount->currency);
        }

        if (null === $payment) {
            $this->logger->warning(sprintf(
                'Payment link notification [%s] for order [%s] could not be processed: no Adyen payment method [%s].',
                (string) $notificationData->pspReference,
                (string) $order->getNumber(),
                (string) $notificationData->paymentCode,
            ));

            return;
        }

        $previousAmount = $payment->getAmount();
        $payment->setAmount($amount->value);
        $this->entityManager->persist($payment);
        $this->entityManager->flush();

        $this->commandBus->dispatch(new AuthorizePaymentByLink($payment, $notificationData));

        $this->logger->info(sprintf(
            'Payment link created in Adyen: order [%s] payment [%s] completed from notification [%s] (amount %d %s, previous payment amount %d).',
            (string) $order->getNumber(),
            (string) $payment->getId(),
            (string) $notificationData->pspReference,
            $amount->value,
            $amount->currency,
            (int) $previousAmount,
        ));
    }

    private function findOpenAdyenPayment(OrderInterface $order): ?PaymentInterface
    {
        $payments = $order->getPayments()->filter(
            fn (PaymentInterface $payment): bool => in_array($payment->getState(), self::OPEN_STATES, true) &&
                $this->adyenPaymentMethodChecker->isAdyenPayment($payment),
        );

        $result = $payments->last();

        return false === $result ? null : $result;
    }

    private function createPayment(OrderInterface $order, string $paymentMethodCode, int $amount, string $currencyCode): ?PaymentInterface
    {
        $paymentMethod = $this->paymentMethodRepository->findOneBy(['code' => $paymentMethodCode]);
        if (!$paymentMethod instanceof PaymentMethodInterface || !$this->adyenPaymentMethodChecker->isAdyenPaymentMethod($paymentMethod)) {
            return null;
        }

        /** @var PaymentInterface $payment */
        $payment = $this->paymentFactory->createNew();
        $payment->setCurrencyCode($currencyCode);
        $payment->setAmount($amount);
        $payment->setMethod($paymentMethod);
        $payment->setState(PaymentInterface::STATE_NEW);
        $order->addPayment($payment);

        return $payment;
    }
}
