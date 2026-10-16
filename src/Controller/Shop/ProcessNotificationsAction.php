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

namespace Sylius\AdyenPlugin\Controller\Shop;

use Psr\Log\LoggerInterface;
use Sylius\AdyenPlugin\Bus\Command\DeferredNotification;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver\NoCommandResolvedException;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver\NotificationDeferralRequiredException;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolverInterface;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationToCommandResolverInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

class ProcessNotificationsAction
{
    public const EXPECTED_ADYEN_RESPONSE = '[accepted]';

    public function __construct(
        private readonly NotificationToCommandResolverInterface $notificationCommandResolver,
        private readonly NotificationResolverInterface $notificationResolver,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(string $paymentMethodCode, Request $request): Response
    {
        foreach ($this->notificationResolver->resolve($paymentMethodCode, $request) as $notificationItem) {
            if (null === $notificationItem || false === $notificationItem->success) {
                $this->logger->error(\sprintf(
                    'Payment with pspReference [%s] did not return success',
                    $notificationItem->pspReference ?? '',
                ));
            } else {
                $this->logger->debug(\sprintf(
                    'Payment with pspReference [%s] finished with event code [%s]',
                    $notificationItem->pspReference ?? '',
                    $notificationItem->eventCode ?? '',
                ));
            }

            try {
                $command = $this->notificationCommandResolver->resolve($paymentMethodCode, $notificationItem);
                $this->messageBus->dispatch($command);
            } catch (NotificationDeferralRequiredException $exception) {
                $delay = $exception->getRemainingSeconds() + 1;

                $this->messageBus->dispatch(
                    new DeferredNotification($paymentMethodCode, $notificationItem),
                    [new DelayStamp($delay * 1000)],
                );

                $this->logger->info(\sprintf(
                    'Notification of payment with pspReference [%s] was queued for processing in %d seconds, the customer may still be finalizing the checkout.',
                    $notificationItem->pspReference ?? '',
                    $delay,
                ));
            } catch (NoCommandResolvedException) {
                $this->logger->error(sprintf(
                    'Tried to dispatch an unknown command. Notification body: %s',
                    json_encode($notificationItem, \JSON_PARTIAL_OUTPUT_ON_ERROR),
                ));
            }
        }

        return new Response(self::EXPECTED_ADYEN_RESPONSE);
    }
}
