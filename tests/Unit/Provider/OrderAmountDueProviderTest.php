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

namespace Tests\Sylius\AdyenPlugin\Unit\Provider;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Sylius\AdyenPlugin\Provider\OrderAmountDueProvider;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class OrderAmountDueProviderTest extends TestCase
{
    private OrderAmountDueProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new OrderAmountDueProvider();
    }

    public function testItReturnsTheOrderTotalWhenNothingWasPaid(): void
    {
        $order = $this->createOrder(23686, [
            $this->createPayment(PaymentInterface::STATE_NEW, 23686),
        ]);

        self::assertSame(23686, $this->provider->getAmountDue($order));
    }

    public function testItSubtractsOnlyCompletedPayments(): void
    {
        $order = $this->createOrder(23686, [
            $this->createPayment(PaymentInterface::STATE_COMPLETED, 10000),
            $this->createPayment(PaymentInterface::STATE_COMPLETED, 3686),
            $this->createPayment(PaymentInterface::STATE_FAILED, 5000),
            $this->createPayment(PaymentInterface::STATE_CANCELLED, 5000),
            $this->createPayment(PaymentInterface::STATE_PROCESSING, 10000),
        ]);

        self::assertSame(10000, $this->provider->getAmountDue($order));
    }

    public function testItNeverReturnsANegativeAmount(): void
    {
        $order = $this->createOrder(10000, [
            $this->createPayment(PaymentInterface::STATE_COMPLETED, 12000),
        ]);

        self::assertSame(0, $this->provider->getAmountDue($order));
    }

    /**
     * @param PaymentInterface[] $payments
     */
    private function createOrder(int $total, array $payments): OrderInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getTotal')->willReturn($total);
        $order->method('getPayments')->willReturn(new ArrayCollection($payments));

        return $order;
    }

    private function createPayment(string $state, int $amount): PaymentInterface
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getState')->willReturn($state);
        $payment->method('getAmount')->willReturn($amount);

        return $payment;
    }
}
