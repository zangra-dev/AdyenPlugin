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

use Doctrine\ORM\NoResultException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\AdyenPlugin\Bus\Command\AuthorizeOrphanPaymentLink;
use Sylius\AdyenPlugin\Bus\PaymentCommandFactoryInterface;
use Sylius\AdyenPlugin\Repository\AdyenReferenceRepositoryInterface;
use Sylius\AdyenPlugin\Repository\PaymentLinkRepositoryInterface;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver\NoCommandResolvedException;
use Sylius\AdyenPlugin\Resolver\Notification\NotificationResolver\PaymentNotificationResolver;
use Sylius\AdyenPlugin\Resolver\Notification\Struct\Amount;
use Sylius\AdyenPlugin\Resolver\Notification\Struct\NotificationItemData;
use Sylius\AdyenPlugin\Resolver\Order\MerchantReferenceOrderResolverInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

final class PaymentNotificationResolverPaymentLinkFallbackTest extends TestCase
{
    private MerchantReferenceOrderResolverInterface&MockObject $merchantReferenceOrderResolver;

    private PaymentNotificationResolver $resolver;

    protected function setUp(): void
    {
        $referenceRepository = $this->createMock(AdyenReferenceRepositoryInterface::class);
        $referenceRepository->method('getOneByCodeAndReference')->willThrowException(new NoResultException());

        $this->merchantReferenceOrderResolver = $this->createMock(MerchantReferenceOrderResolverInterface::class);

        $this->resolver = new PaymentNotificationResolver(
            $referenceRepository,
            $this->createMock(PaymentLinkRepositoryInterface::class),
            $this->createMock(PaymentCommandFactoryInterface::class),
            $this->createMock(RepositoryInterface::class),
            $this->merchantReferenceOrderResolver,
        );
    }

    public function testItResolvesAnOrphanPaymentLinkThroughTheMerchantReference(): void
    {
        $order = $this->createOrder(OrderInterface::STATE_NEW);
        $this->merchantReferenceOrderResolver
            ->expects(self::once())
            ->method('resolve')
            ->with('Order-000186791')
            ->willReturn($order)
        ;

        $notification = $this->createNotification();
        $command = $this->resolver->resolve('adyen', $notification);

        self::assertInstanceOf(AuthorizeOrphanPaymentLink::class, $command);
        self::assertSame($order, $command->order);
        self::assertSame($notification, $command->notificationData);
    }

    public function testItDoesNotResolveAnOrderStillInCart(): void
    {
        $this->merchantReferenceOrderResolver->method('resolve')->willReturn($this->createOrder(OrderInterface::STATE_CART));

        $this->expectException(NoCommandResolvedException::class);

        $this->resolver->resolve('adyen', $this->createNotification());
    }

    public function testItDoesNotResolveWhenTheCurrencyDiffers(): void
    {
        $this->merchantReferenceOrderResolver->method('resolve')->willReturn($this->createOrder(OrderInterface::STATE_NEW, 'USD'));

        $this->expectException(NoCommandResolvedException::class);

        $this->resolver->resolve('adyen', $this->createNotification());
    }

    public function testItDoesNotResolveWhenTheOrderIsUnknown(): void
    {
        $this->merchantReferenceOrderResolver->method('resolve')->willReturn(null);

        $this->expectException(NoCommandResolvedException::class);

        $this->resolver->resolve('adyen', $this->createNotification());
    }

    public function testItDoesNotResolveWithoutPaymentLinkId(): void
    {
        $this->merchantReferenceOrderResolver->expects(self::never())->method('resolve');

        $notification = $this->createNotification();
        $notification->additionalData = [];

        $this->expectException(NoCommandResolvedException::class);

        $this->resolver->resolve('adyen', $notification);
    }

    public function testItDoesNotResolveAFailedAuthorisation(): void
    {
        $this->merchantReferenceOrderResolver->expects(self::never())->method('resolve');

        $notification = $this->createNotification();
        $notification->success = 'false';

        $this->expectException(NoCommandResolvedException::class);

        $this->resolver->resolve('adyen', $notification);
    }

    public function testItDoesNotResolveOtherEvents(): void
    {
        $this->merchantReferenceOrderResolver->expects(self::never())->method('resolve');

        $notification = $this->createNotification();
        $notification->eventCode = 'refund';

        $this->expectException(NoCommandResolvedException::class);

        $this->resolver->resolve('adyen', $notification);
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
        $notification->additionalData = ['paymentLinkId' => 'PL123'];
        $notification->amount = $amount;

        return $notification;
    }

    private function createOrder(string $state, string $currencyCode = 'EUR'): MockObject&OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getState')->willReturn($state);
        $order->method('getCurrencyCode')->willReturn($currencyCode);

        return $order;
    }
}
