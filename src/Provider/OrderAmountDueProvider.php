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

namespace Sylius\AdyenPlugin\Provider;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class OrderAmountDueProvider implements OrderAmountDueProviderInterface
{
    public function getAmountDue(OrderInterface $order): int
    {
        $paid = 0;

        /** @var PaymentInterface $payment */
        foreach ($order->getPayments() as $payment) {
            if (PaymentInterface::STATE_COMPLETED === $payment->getState()) {
                $paid += (int) $payment->getAmount();
            }
        }

        return max(0, $order->getTotal() - $paid);
    }
}
