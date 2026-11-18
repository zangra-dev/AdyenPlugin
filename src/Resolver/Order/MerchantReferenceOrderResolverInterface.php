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

namespace Sylius\AdyenPlugin\Resolver\Order;

use Sylius\Component\Core\Model\OrderInterface;

interface MerchantReferenceOrderResolverInterface
{
    /**
     * Finds an order from an Adyen merchant reference, e.g. "Order-000186791", "order-186791" or "000186791".
     */
    public function resolve(string $merchantReference): ?OrderInterface;
}
