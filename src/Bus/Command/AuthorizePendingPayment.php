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
use Sylius\Component\Core\Model\PaymentInterface;

/**
 * Authorisation notification for a payment whose checkout was never finalized on our side
 * (e.g. the customer paid in a banking app and never came back to the shop), and which could
 * only be matched using the merchant reference (order number) of the notification.
 */
final class AuthorizePendingPayment implements NotificationDataAwarePaymentCommand
{
    public function __construct(
        public readonly PaymentInterface $payment,
        public readonly NotificationItemData $notificationData,
    ) {
    }
}
