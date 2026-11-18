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
use Sylius\Component\Core\Model\PaymentInterface;

interface PaymentLinkReuseCheckerInterface
{
    /**
     * Returns the latest payment link of the payment when it can be used again for the given amount (minor units),
     * null when a new link has to be generated.
     */
    public function findReusable(PaymentInterface $payment, int $amount): ?PaymentLinkInterface;
}
