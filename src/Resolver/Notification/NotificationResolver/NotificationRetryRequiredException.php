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

namespace Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver;

/**
 * The notification cannot be processed yet (e.g. the customer may still be returning to the shop
 * and finalizing the checkout), so it must not be acknowledged: Adyen will deliver it again later.
 */
final class NotificationRetryRequiredException extends \RuntimeException
{
}
