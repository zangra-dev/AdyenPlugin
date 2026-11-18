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

interface OrderAmountDueProviderInterface
{
    /**
     * Order total minus the sum of its completed payments (in minor units, never negative).
     */
    public function getAmountDue(OrderInterface $order): int;
}
