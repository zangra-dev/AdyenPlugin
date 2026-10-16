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

namespace Sylius\AdyenPlugin\Bus\Command;

use Sylius\AdyenPlugin\Resolver\Notification\Struct\NotificationItemData;

/**
 * A notification which must be processed later (e.g. after the customer had the time to return
 * to the shop). It only carries scalars and DTOs, so it can safely be sent through an async transport.
 * The command is resolved again when the message is handled, against fresh state.
 */
final class DeferredNotification
{
    public function __construct(
        public readonly string $paymentMethodCode,
        public readonly NotificationItemData $notificationData,
    ) {
    }
}
