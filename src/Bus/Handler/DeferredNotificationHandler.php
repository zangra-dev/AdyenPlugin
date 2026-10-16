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

namespace Sylius\AdyenPlugin\Bus\Handler;

use Psr\Log\LoggerInterface;
use Sylius\AdyenPlugin\Bus\Command\DeferredNotification;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver\NoCommandResolvedException;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver\NotificationDeferralRequiredException;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationToCommandResolverInterface;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Processes a notification which was queued by the notification endpoint. The command is resolved
 * again, so it reflects the current state: if the customer returned to the shop in the meantime,
 * the regular payment command is dispatched, otherwise the pending payment is authorized.
 */
final class DeferredNotificationHandler
{
    public function __construct(
        private readonly NotificationToCommandResolverInterface $notificationCommandResolver,
        private readonly MessageBusInterface $commandBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeferredNotification $message): void
    {
        $notificationData = $message->notificationData;

        try {
            $command = $this->notificationCommandResolver->resolve($message->paymentMethodCode, $notificationData);
        } catch (NotificationDeferralRequiredException $exception) {
            // Too early (e.g. the message was handled synchronously or the clocks differ), try again later
            throw new RecoverableMessageHandlingException(
                sprintf(
                    'Notification of payment with pspReference [%s] is still too recent to be processed.',
                    $notificationData->pspReference ?? '',
                ),
                0,
                $exception,
                ($exception->getRemainingSeconds() + 1) * 1000,
            );
        } catch (NoCommandResolvedException) {
            $this->logger->error(sprintf(
                'Deferred notification could not be resolved to a command. Notification body: %s',
                json_encode($notificationData, \JSON_PARTIAL_OUTPUT_ON_ERROR),
            ));

            return;
        }

        $this->commandBus->dispatch($command);
    }
}
