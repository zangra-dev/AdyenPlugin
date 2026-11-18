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

namespace Sylius\AdyenPlugin\Email\Sender;

use Sylius\AdyenPlugin\Entity\PaymentLinkInterface;
use Sylius\AdyenPlugin\Repository\AdyenReferenceRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;

final class PaymentLinkEmailSender implements PaymentLinkEmailSenderInterface
{
    private const EMAIL_CODE = 'adyen_payment_link';

    public function __construct(
        private readonly SenderInterface $sender,
        private readonly ?AdyenReferenceRepositoryInterface $adyenReferenceRepository = null,
    ) {
    }

    public function send(PaymentLinkInterface $paymentLink, string $recipient): void
    {
        $this->sender->send(self::EMAIL_CODE, [$recipient], $this->buildContext($paymentLink));
    }

    /** @return array<string, mixed> */
    private function buildContext(PaymentLinkInterface $paymentLink): array
    {
        $payment = $paymentLink->getPayment();
        $order = $payment->getOrder();
        $customer = $order?->getCustomer();

        return [
            'paymentLinkUrl' => $paymentLink->getPaymentLinkUrl(),
            'paymentLinkId' => $paymentLink->getPaymentLinkId(),
            'expiresAt' => $paymentLink->getExpiresAt(),
            'orderNumber' => (string) $order?->getNumber(),
            'customerFirstName' => (string) $customer?->getFirstName(),
            'customerLastName' => (string) $customer?->getLastName(),
            'pspReference' => $this->resolvePspReference($payment),
            'order' => $order,
            'customer' => $customer,
            'paymentLink' => $paymentLink,
        ];
    }

    private function resolvePspReference(PaymentInterface $payment): string
    {
        return (string) $this->adyenReferenceRepository?->findLatestByPayment($payment)?->getPspReference();
    }
}
