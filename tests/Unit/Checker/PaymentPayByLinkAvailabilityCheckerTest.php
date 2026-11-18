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

namespace Tests\Sylius\AdyenPlugin\Unit\Checker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\AdyenPlugin\Checker\AdyenPaymentMethodCheckerInterface;
use Sylius\AdyenPlugin\Checker\PaymentPayByLinkAvailabilityChecker;
use Sylius\AdyenPlugin\Provider\OrderAmountDueProviderInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class PaymentPayByLinkAvailabilityCheckerTest extends TestCase
{
    private AdyenPaymentMethodCheckerInterface&MockObject $adyenPaymentMethodChecker;

    private MockObject&OrderAmountDueProviderInterface $orderAmountDueProvider;

    private PaymentPayByLinkAvailabilityChecker $checker;

    protected function setUp(): void
    {
        $this->adyenPaymentMethodChecker = $this->createMock(AdyenPaymentMethodCheckerInterface::class);
        $this->orderAmountDueProvider = $this->createMock(OrderAmountDueProviderInterface::class);

        $this->checker = new PaymentPayByLinkAvailabilityChecker(
            $this->adyenPaymentMethodChecker,
            $this->orderAmountDueProvider,
        );
    }

    public function testCanBeGeneratedReturnsFalseWhenPaymentIsNotAdyenPayment(): void
    {
        $payment = $this->createMock(PaymentInterface::class);

        $this->adyenPaymentMethodChecker
            ->expects(self::once())
            ->method('isAdyenPayment')
            ->with($payment)
            ->willReturn(false);

        $this->orderAmountDueProvider->expects(self::never())->method('getAmountDue');

        self::assertFalse($this->checker->canBeGenerated($payment));
    }

    #[DataProvider('provideNotAllowedStates')]
    public function testCanBeGeneratedReturnsFalseWhenPaymentStateIsNotAllowed(string $state): void
    {
        $payment = $this->createPayment($state, 5000);

        $this->orderAmountDueProvider->expects(self::never())->method('getAmountDue');

        self::assertFalse($this->checker->canBeGenerated($payment));
    }

    #[DataProvider('provideAllowedStates')]
    public function testCanBeGeneratedReturnsTrueWhenThereIsAnAmountDue(string $state): void
    {
        $payment = $this->createPayment($state, 5000);

        self::assertTrue($this->checker->canBeGenerated($payment));
    }

    public function testCanBeGeneratedReturnsFalseWhenNothingIsDue(): void
    {
        $payment = $this->createPayment(PaymentInterface::STATE_NEW, 0);

        self::assertFalse($this->checker->canBeGenerated($payment));
    }

    public function testCanBeGeneratedReturnsFalseWhenPaymentHasNoOrder(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getState')->willReturn(PaymentInterface::STATE_NEW);
        $payment->method('getOrder')->willReturn(null);
        $this->adyenPaymentMethodChecker->method('isAdyenPayment')->willReturn(true);

        self::assertFalse($this->checker->canBeGenerated($payment));
    }

    public function testCanBeGeneratedUsesCustomAllowedStates(): void
    {
        $checker = new PaymentPayByLinkAvailabilityChecker(
            $this->adyenPaymentMethodChecker,
            $this->orderAmountDueProvider,
            [PaymentInterface::STATE_AUTHORIZED],
        );

        self::assertTrue($checker->canBeGenerated($this->createPayment(PaymentInterface::STATE_AUTHORIZED, 5000)));
        self::assertFalse($checker->canBeGenerated($this->createPayment(PaymentInterface::STATE_NEW, 5000)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAllowedStates(): iterable
    {
        yield 'new state' => [PaymentInterface::STATE_NEW];
        yield 'processing state' => [PaymentInterface::STATE_PROCESSING];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideNotAllowedStates(): iterable
    {
        yield 'completed state' => [PaymentInterface::STATE_COMPLETED];
        yield 'failed state' => [PaymentInterface::STATE_FAILED];
        yield 'cancelled state' => [PaymentInterface::STATE_CANCELLED];
        yield 'authorized state' => [PaymentInterface::STATE_AUTHORIZED];
        yield 'refunded state' => [PaymentInterface::STATE_REFUNDED];
    }

    private function createPayment(string $state, int $amountDue): MockObject&PaymentInterface
    {
        $order = $this->createMock(OrderInterface::class);
        $this->orderAmountDueProvider->method('getAmountDue')->with($order)->willReturn($amountDue);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getState')->willReturn($state);
        $payment->method('getOrder')->willReturn($order);

        $this->adyenPaymentMethodChecker->method('isAdyenPayment')->willReturn(true);

        return $payment;
    }
}
