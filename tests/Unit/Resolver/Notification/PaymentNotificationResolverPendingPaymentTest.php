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

namespace Tests\Sylius\AdyenPlugin\Unit\Resolver\Notification;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\NoResultException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\AdyenPlugin\Bus\Command\AuthorizePendingPayment;
use Sylius\AdyenPlugin\Bus\PaymentCommandFactoryInterface;
use Sylius\AdyenPlugin\Repository\AdyenReferenceRepositoryInterface;
use Sylius\AdyenPlugin\Repository\PaymentLinkRepositoryInterface;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver\NoCommandResolvedException;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver\NotificationDeferralRequiredException;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver\PaymentNotificationResolver;
use Sylius\AdyenPlugin\Resolver\Notification\Struct\Amount;
use Sylius\AdyenPlugin\Resolver\Notification\Struct\NotificationItemData;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

final class PaymentNotificationResolverPendingPaymentTest extends TestCase
{
    private AdyenReferenceRepositoryInterface&MockObject $referenceRepository;

    private RepositoryInterface&MockObject $orderRepository;

    private PaymentNotificationResolver $resolver;

    protected function setUp(): void
    {
        $this->referenceRepository = $this->createMock(AdyenReferenceRepositoryInterface::class);
        $this->referenceRepository->method('getOneByCodeAndReference')->willThrowException(new NoResultException());

        $this->orderRepository = $this->createMock(RepositoryInterface::class);

        $this->resolver = new PaymentNotificationResolver(
            $this->referenceRepository,
            $this->createMock(PaymentLinkRepositoryInterface::class),
            $this->createMock(PaymentCommandFactoryInterface::class),
            $this->orderRepository,
        );
    }

    public function testItResolvesPendingPaymentByMerchantReference(): void
    {
        $payment = $this->createPayment();
        $this->orderRepository->method('findOneBy')->with(['number' => '000185191'])->willReturn(
            $this->createOrder(OrderInterface::STATE_CART, $payment),
        );

        $command = $this->resolver->resolve('adyen', $this->createNotification());

        self::assertInstanceOf(AuthorizePendingPayment::class, $command);
        self::assertSame($payment, $command->payment);
    }

    public function testItAsksForDeferralWhenNotificationIsTooRecent(): void
    {
        $this->orderRepository->method('findOneBy')->willReturn(
            $this->createOrder(OrderInterface::STATE_CART, $this->createPayment()),
        );

        $notification = $this->createNotification();
        $notification->eventDate = (new \DateTimeImmutable('-3 seconds'))->format(\DATE_ATOM);

        try {
            $this->resolver->resolve('adyen', $notification);
            self::fail('Expected a deferral exception.');
        } catch (NotificationDeferralRequiredException $exception) {
            self::assertEqualsWithDelta(
                PaymentNotificationResolver::PENDING_PAYMENT_GRACE_PERIOD - 3,
                $exception->getRemainingSeconds(),
                2,
            );
        }
    }

    public function testItDoesNotResolveWhenOrderIsAlreadyPlaced(): void
    {
        $this->orderRepository->method('findOneBy')->willReturn(
            $this->createOrder(OrderInterface::STATE_NEW, $this->createPayment()),
        );

        $this->expectException(NoCommandResolvedException::class);

        $this->resolver->resolve('adyen', $this->createNotification());
    }

    public function testItDoesNotResolveWhenAmountDiffers(): void
    {
        $this->orderRepository->method('findOneBy')->willReturn(
            $this->createOrder(OrderInterface::STATE_CART, $this->createPayment(1234)),
        );

        $this->expectException(NoCommandResolvedException::class);

        $this->resolver->resolve('adyen', $this->createNotification());
    }

    public function testItDoesNotResolveFailedAuthorisation(): void
    {
        $this->orderRepository->expects(self::never())->method('findOneBy');

        $notification = $this->createNotification();
        $notification->success = 'false';

        $this->expectException(NoCommandResolvedException::class);

        $this->resolver->resolve('adyen', $notification);
    }

    private function createNotification(): NotificationItemData
    {
        $amount = new Amount();
        $amount->value = 5000;
        $amount->currency = 'EUR';

        $notification = new NotificationItemData();
        $notification->eventCode = 'authorisation';
        $notification->eventDate = (new \DateTimeImmutable('-10 minutes'))->format(\DATE_ATOM);
        $notification->success = 'true';
        $notification->pspReference = 'PSP2';
        $notification->merchantReference = '000185191';
        $notification->amount = $amount;

        return $notification;
    }

    private function createPayment(int $amount = 5000): PaymentInterface&MockObject
    {
        $method = $this->createMock(PaymentMethodInterface::class);
        $method->method('getCode')->willReturn('adyen');

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getState')->willReturn(PaymentInterface::STATE_NEW);
        $payment->method('getAmount')->willReturn($amount);
        $payment->method('getCurrencyCode')->willReturn('EUR');

        return $payment;
    }

    private function createOrder(string $state, PaymentInterface $payment): OrderInterface&MockObject
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getState')->willReturn($state);
        $order->method('getPayments')->willReturn(new ArrayCollection([$payment]));

        return $order;
    }
}
