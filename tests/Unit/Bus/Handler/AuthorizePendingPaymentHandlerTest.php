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

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\AdyenPlugin\Bus\Command\AuthorizePendingPayment;
use Sylius\AdyenPlugin\Bus\Command\PaymentStatusReceived;
use Sylius\AdyenPlugin\Bus\Handler\AuthorizePendingPaymentHandler;
use Sylius\AdyenPlugin\Resolver\Notification\Struct\NotificationItemData;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class AuthorizePendingPaymentHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private MessageBusInterface&MockObject $bus;

    private LoggerInterface&MockObject $logger;

    private AuthorizePendingPaymentHandler $handler;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new AuthorizePendingPaymentHandler($this->entityManager, $this->bus, $this->logger);
    }

    public function testItAuthorizesAPendingPayment(): void
    {
        $payment = $this->createPayment(OrderInterface::STATE_CART, PaymentInterface::STATE_NEW);
        $payment->method('getDetails')->willReturn(['action' => ['type' => 'qrCode'], 'resultCode' => 'Pending']);
        $payment->expects(self::once())->method('setDetails')->with([
            'resultCode' => 'Authorised',
            'pspReference' => 'PSP2',
        ]);

        $this->bus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(PaymentStatusReceived::class))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message))
        ;
        $this->entityManager->expects(self::once())->method('flush');
        $this->logger
            ->expects(self::once())
            ->method('info')
            ->with('Pending payment of order [000185432] was authorized from notification [PSP2].')
        ;

        ($this->handler)($this->createCommand($payment));
    }

    #[DataProvider('provideNotPendingStates')]
    public function testItDoesNothingWhenTheOrderOrPaymentIsNoLongerPending(string $orderState, string $paymentState): void
    {
        $payment = $this->createPayment($orderState, $paymentState);
        $payment->expects(self::never())->method('setDetails');

        $this->bus->expects(self::never())->method('dispatch');
        $this->entityManager->expects(self::never())->method('flush');
        $this->logger
            ->expects(self::once())
            ->method('info')
            ->with('Pending payment of order [000185432] was not authorized from notification [PSP2]: skipped, already processed.')
        ;

        ($this->handler)($this->createCommand($payment));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideNotPendingStates(): iterable
    {
        yield 'order already placed' => [OrderInterface::STATE_NEW, PaymentInterface::STATE_AUTHORIZED];
        yield 'order still in cart, payment already completed' => [OrderInterface::STATE_CART, PaymentInterface::STATE_COMPLETED];
        yield 'order still in cart, payment failed' => [OrderInterface::STATE_CART, PaymentInterface::STATE_FAILED];
    }

    private function createPayment(string $orderState, string $paymentState): PaymentInterface&MockObject
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getState')->willReturn($orderState);
        $order->method('getNumber')->willReturn('000185432');

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getState')->willReturn($paymentState);

        return $payment;
    }

    private function createCommand(PaymentInterface $payment): AuthorizePendingPayment
    {
        $item = new NotificationItemData();
        $item->pspReference = 'PSP2';

        return new AuthorizePendingPayment($payment, $item);
    }
}
