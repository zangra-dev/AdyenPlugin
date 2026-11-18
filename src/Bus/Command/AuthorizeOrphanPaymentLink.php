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
use Sylius\Component\Core\Model\OrderInterface;

/**
 * Authorisation notification for a payment link created directly in the Adyen backoffice: no payment
 * is known on our side, the notification is only matched with an order through its merchant reference.
 */
final class AuthorizeOrphanPaymentLink
{
    public function __construct(
        public readonly OrderInterface $order,
        public readonly NotificationItemData $notificationData,
    ) {
    }
}
