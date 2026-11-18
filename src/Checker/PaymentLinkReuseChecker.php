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

namespace Sylius\AdyenPlugin\Checker;

use Sylius\AdyenPlugin\Entity\PaymentLinkInterface;
use Sylius\AdyenPlugin\Repository\PaymentLinkRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class PaymentLinkReuseChecker implements PaymentLinkReuseCheckerInterface
{
    public function __construct(
        private readonly PaymentLinkRepositoryInterface $paymentLinkRepository,
    ) {
    }

    public function findReusable(PaymentInterface $payment, int $amount): ?PaymentLinkInterface
    {
        if (PaymentInterface::STATE_PROCESSING !== $payment->getState()) {
            return null;
        }

        $paymentLink = $this->paymentLinkRepository->findLatestByPayment($payment);
        if (null === $paymentLink || $paymentLink->isExpired() || $paymentLink->getAmount() !== $amount) {
            return null;
        }

        return $paymentLink;
    }
}
