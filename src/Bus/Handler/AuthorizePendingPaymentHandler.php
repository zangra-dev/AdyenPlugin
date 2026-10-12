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
use Sylius\AdyenPlugin\Bus\Command\AuthorizePendingPayment;
use Sylius\AdyenPlugin\Bus\Command\PaymentStatusReceived;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Finalizes a payment from an authorisation notification the same way the "payment details"
 * response would have done: the payment gets the authorised result and the psp reference of
 * the notification, then the regular "payment status received" flow completes the order checkout
 * (and sends the confirmation), and authorizes the payment.
 */
final class AuthorizePendingPaymentHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $commandBus,
    ) {
    }

    public function __invoke(AuthorizePendingPayment $command): void
    {
        $payment = $command->payment;

        $details = $payment->getDetails();
        unset($details['action']);

        $details['resultCode'] = 'Authorised';
        $details['pspReference'] = $command->notificationData->pspReference;

        $payment->setDetails($details);

        $this->commandBus->dispatch(new PaymentStatusReceived($payment));

        $this->entityManager->flush();
    }
}
