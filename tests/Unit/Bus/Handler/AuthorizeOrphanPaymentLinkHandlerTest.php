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

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Sylius\AdyenPlugin\Bus\Command\AuthorizeOrphanPaymentLink;
use Sylius\AdyenPlugin\Bus\Command\AuthorizePaymentByLink;
use Sylius\AdyenPlugin\Bus\Handler\AuthorizeOrphanPaymentLinkHandler;
use Sylius\AdyenPlugin\Checker\AdyenPaymentMethodCheckerInterface;
use Sylius\AdyenPlugin\Resolver\Notification\Struct\Amount;
use Sylius\AdyenPlugin\Resolver\Notification\Struct\NotificationItemData;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class AuthorizeOrphanPaymentLinkHandlerTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private MessageBusInterface&MockObject $bus;

    private AdyenPaymentMethodCheckerInterface&MockObject $adyenPaymentMethodChecker;

    private MockObject&PaymentMethodRepositoryInterface $paymentMethodRepository;

    private FactoryInterface&MockObject $paymentFactory;

    private LoggerInterface&MockObject $logger;

    private AuthorizeOrphanPaymentLinkHandler $handler;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->bus = $this->createMock(MessageBusInterface::class);
        $this->adyenPaymentMethodChecker = $this->createMock(AdyenPaymentMethodCheckerInterface::class);
        $this->paymentMethodRepository = $this->createMock(PaymentMethodRepositoryInterface::class);
        $this->paymentFactory = $this->createMock(FactoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->handler = new AuthorizeOrphanPaymentLinkHandler(
            $this->entityManager,
            $this->bus,
            $this->adyenPaymentMethodChecker,
            $this->paymentMethodRepository,
            $this->paymentFactory,
            $this->logger,
        );
    }

    public function testItCompletesTheLatestOpenAdyenPaymentWithThePaidAmount(): void
    {
        $olderPayment = $this->createPayment(PaymentInterface::STATE_NEW, 1000);
        $latestPayment = $this->createPayment(PaymentInterface::STATE_PROCESSING, 5000);
        $completedPayment = $this->createPayment(PaymentInterface::STATE_COMPLETED, 7000);
        $order = $this->createOrder([$olderPayment, $latestPayment, $completedPayment]);

        $this->adyenPaymentMethodChecker->method('isAdyenPayment')->willReturn(true);
        $this->paymentFactory->expects(self::never())->method('createNew');

        $latestPayment->expects(self::once())->method('setAmount')->with(23686);
        $olderPayment->expects(self::never())->method('setAmount');
        $completedPayment->expects(self::never())->method('setAmount');

        $notification = $this->createNotification();
        $this->expectAuthorization($latestPayment, $notification);
        $this->logger->expects(self::once())->method('info');

        ($this->handler)(new AuthorizeOrphanPaymentLink($order, $notification));
    }

    public function testItIgnoresOpenPaymentsWhichAreNotAdyenOnes(): void
    {
        $nonAdyenPayment = $this->createPayment(PaymentInterface::STATE_NEW, 5000);
        $order = $this->createOrder([$nonAdyenPayment]);

        $this->adyenPaymentMethodChecker->method('isAdyenPayment')->willReturn(false);
        $this->adyenPaymentMethodChecker->method('isAdyenPaymentMethod')->willReturn(true);

        $method = $this->createMock(PaymentMethodInterface::class);
        $this->paymentMethodRepository->method('findOneBy')->with(['code' => 'adyen'])->willReturn($method);

        $newPayment = $this->createPayment(PaymentInterface::STATE_CART, 0);
        $newPayment->expects(self::once())->method('setMethod')->with($method);
        $newPayment->expects(self::once())->method('setCurrencyCode')->with('EUR');
        $this->paymentFactory->expects(self::once())->method('createNew')->willReturn($newPayment);
        $order->expects(self::once())->method('addPayment')->with($newPayment);
        $nonAdyenPayment->expects(self::never())->method('setAmount');

        $notification = $this->createNotification();
        $this->expectAuthorization($newPayment, $notification);

        ($this->handler)(new AuthorizeOrphanPaymentLink($order, $notification));
    }

    public function testItCreatesAPaymentWhenThereIsNoOpenOne(): void
    {
        $order = $this->createOrder([$this->createPayment(PaymentInterface::STATE_COMPLETED, 5000)]);

        $this->adyenPaymentMethodChecker->method('isAdyenPayment')->willReturn(true);
        $this->adyenPaymentMethodChecker->method('isAdyenPaymentMethod')->willReturn(true);

        $method = $this->createMock(PaymentMethodInterface::class);
        $this->paymentMethodRepository->method('findOneBy')->with(['code' => 'adyen'])->willReturn($method);

        $newPayment = $this->createPayment(PaymentInterface::STATE_CART, 0);
        $newPayment->expects(self::atLeastOnce())->method('setAmount')->with(23686);
        $newPayment->expects(self::once())->method('setMethod')->with($method);
        $this->paymentFactory->expects(self::once())->method('createNew')->willReturn($newPayment);
        $order->expects(self::once())->method('addPayment')->with($newPayment);

        $notification = $this->createNotification();
        $this->expectAuthorization($newPayment, $notification);

        ($this->handler)(new AuthorizeOrphanPaymentLink($order, $notification));
    }

    public function testItDoesNothingWhenThePaymentMethodIsNotAnAdyenOne(): void
    {
        $order = $this->createOrder([]);

        $method = $this->createMock(PaymentMethodInterface::class);
        $this->paymentMethodRepository->method('findOneBy')->willReturn($method);
        $this->adyenPaymentMethodChecker->method('isAdyenPaymentMethod')->willReturn(false);

        $this->paymentFactory->expects(self::never())->method('createNew');
        $this->bus->expects(self::never())->method('dispatch');
        $this->entityManager->expects(self::never())->method('flush');
        $this->logger->expects(self::once())->method('warning');

        ($this->handler)(new AuthorizeOrphanPaymentLink($order, $this->createNotification()));
    }

    private function expectAuthorization(PaymentInterface $payment, NotificationItemData $notification): void
    {
        $this->entityManager->expects(self::once())->method('flush');
        $this->bus
            ->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(
                static fn (object $message): bool => $message instanceof AuthorizePaymentByLink &&
                    $message->payment === $payment &&
                    $message->notificationData === $notification,
            ))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message))
        ;
    }

    private function createNotification(): NotificationItemData
    {
        $amount = new Amount();
        $amount->value = 23686;
        $amount->currency = 'EUR';

        $notification = new NotificationItemData();
        $notification->eventCode = 'authorisation';
        $notification->success = 'true';
        $notification->pspReference = 'RWG8QWMCHD7HH6F6';
        $notification->merchantReference = 'Order-000186791';
        $notification->paymentCode = 'adyen';
        $notification->additionalData = ['paymentLinkId' => 'PL123'];
        $notification->amount = $amount;

        return $notification;
    }

    private function createPayment(string $state, int $amount): MockObject&PaymentInterface
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getState')->willReturn($state);
        $payment->method('getAmount')->willReturn($amount);

        return $payment;
    }

    /**
     * @param PaymentInterface[] $payments
     */
    private function createOrder(array $payments): MockObject&OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getPayments')->willReturn(new ArrayCollection($payments));
        $order->method('getNumber')->willReturn('000186791');

        return $order;
    }
}
