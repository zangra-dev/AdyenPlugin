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

namespace Tests\Sylius\AdyenPlugin\Unit\Controller\Shop;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Sylius\AdyenPlugin\Bus\Command\DeferredNotification;
use Sylius\AdyenPlugin\Controller\Shop\ProcessNotificationsAction;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver\NoCommandResolvedException;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver\NotificationDeferralRequiredException;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolverInterface;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationToCommandResolverInterface;
use Sylius\AdyenPlugin\Resolver\Notification\Struct\NotificationItemData;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final class ProcessNotificationsActionDeferralTest extends TestCase
{
    private NotificationToCommandResolverInterface&MockObject $commandResolver;

    private NotificationResolverInterface&MockObject $notificationResolver;

    private MessageBusInterface&MockObject $bus;

    private ProcessNotificationsAction $action;

    protected function setUp(): void
    {
        $this->commandResolver = $this->createMock(NotificationToCommandResolverInterface::class);
        $this->notificationResolver = $this->createMock(NotificationResolverInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);

        $this->action = new ProcessNotificationsAction(
            $this->commandResolver,
            $this->notificationResolver,
            new NullLogger(),
            $this->bus,
        );
    }

    public function testItQueuesADelayedMessageAndAcceptsTheNotification(): void
    {
        $item = $this->createItem();
        $this->notificationResolver->method('resolve')->willReturn([$item]);
        $this->commandResolver->method('resolve')->willThrowException(new NotificationDeferralRequiredException(85));

        $this->bus
            ->expects(self::once())
            ->method('dispatch')
            ->with(
                self::callback(static fn (object $message): bool => $message instanceof DeferredNotification &&
                    'adyen' === $message->paymentMethodCode &&
                    $message->notificationData === $item),
                self::callback(static function (array $stamps): bool {
                    return 1 === count($stamps) &&
                        $stamps[0] instanceof DelayStamp &&
                        86000 === $stamps[0]->getDelay();
                }),
            )
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message))
        ;

        $response = ($this->action)('adyen', new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('[accepted]', $response->getContent());
    }

    public function testItDispatchesTheResolvedCommandSynchronouslyWithoutQueuing(): void
    {
        $command = new \stdClass();
        $this->notificationResolver->method('resolve')->willReturn([$this->createItem()]);
        $this->commandResolver->method('resolve')->willReturn($command);

        $this->bus
            ->expects(self::once())
            ->method('dispatch')
            ->with($command)
            ->willReturn(new Envelope($command))
        ;

        $response = ($this->action)('adyen', new Request());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testItAcceptsUnresolvedNotificationsWithoutQueuing(): void
    {
        $this->notificationResolver->method('resolve')->willReturn([$this->createItem()]);
        $this->commandResolver->method('resolve')->willThrowException(new NoCommandResolvedException());

        $this->bus->expects(self::never())->method('dispatch');

        $response = ($this->action)('adyen', new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('[accepted]', $response->getContent());
    }

    private function createItem(): NotificationItemData
    {
        $item = new NotificationItemData();
        $item->eventCode = 'authorisation';
        $item->success = true;
        $item->pspReference = 'PSP2';
        $item->merchantReference = '000185191';

        return $item;
    }
}
