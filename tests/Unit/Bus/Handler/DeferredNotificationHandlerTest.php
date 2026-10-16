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

namespace Tests\Sylius\AdyenPlugin\Unit\Bus\Handler;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\AdyenPlugin\Bus\Command\AuthorizePendingPayment;
use Sylius\AdyenPlugin\Bus\Command\DeferredNotification;
use Sylius\AdyenPlugin\Bus\Handler\DeferredNotificationHandler;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver\NoCommandResolvedException;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver\NotificationDeferralRequiredException;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationToCommandResolverInterface;
use Sylius\AdyenPlugin\Resolver\Notification\Struct\NotificationItemData;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeferredNotificationHandlerTest extends TestCase
{
    private NotificationToCommandResolverInterface&MockObject $resolver;

    private MessageBusInterface&MockObject $bus;

    private LoggerInterface&MockObject $logger;

    private DeferredNotificationHandler $handler;

    protected function setUp(): void
    {
        $this->resolver = $this->createMock(NotificationToCommandResolverInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new DeferredNotificationHandler($this->resolver, $this->bus, $this->logger);
    }

    public function testItDispatchesTheNormalCommandWhenTheCustomerReturnedMeanwhile(): void
    {
        $message = $this->createMessage();
        $command = new \stdClass();

        $this->resolver->expects(self::once())->method('resolve')->with('adyen', $message->notificationData)->willReturn($command);
        $this->bus->expects(self::once())->method('dispatch')->with($command)->willReturn(new Envelope($command));

        ($this->handler)($message);
    }

    public function testItDispatchesTheAuthorizePendingPaymentCommandWhenStillPending(): void
    {
        $message = $this->createMessage();
        $command = new AuthorizePendingPayment($this->createMock(PaymentInterface::class), $message->notificationData);

        $this->resolver->method('resolve')->willReturn($command);
        $this->bus->expects(self::once())->method('dispatch')->with($command)->willReturn(new Envelope($command));

        ($this->handler)($message);
    }

    public function testItAsksForARetryWhenTheNotificationIsStillTooRecent(): void
    {
        $this->resolver->method('resolve')->willThrowException(new NotificationDeferralRequiredException(30));
        $this->bus->expects(self::never())->method('dispatch');

        try {
            ($this->handler)($this->createMessage());
            self::fail('Expected a recoverable exception.');
        } catch (RecoverableMessageHandlingException $exception) {
            self::assertSame(31000, $exception->getRetryDelay());
        }
    }

    public function testItLogsAndDropsTheMessageWhenNothingMatches(): void
    {
        $this->resolver->method('resolve')->willThrowException(new NoCommandResolvedException());
        $this->bus->expects(self::never())->method('dispatch');
        $this->logger->expects(self::once())->method('error');

        ($this->handler)($this->createMessage());
    }

    private function createMessage(): DeferredNotification
    {
        $item = new NotificationItemData();
        $item->eventCode = 'authorisation';
        $item->success = true;
        $item->pspReference = 'PSP2';
        $item->merchantReference = '000185191';

        return new DeferredNotification('adyen', $item);
    }
}
