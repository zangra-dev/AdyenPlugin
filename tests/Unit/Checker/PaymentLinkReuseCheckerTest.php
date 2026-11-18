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

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\AdyenPlugin\Checker\PaymentLinkReuseChecker;
use Sylius\AdyenPlugin\Entity\PaymentLink;
use Sylius\AdyenPlugin\Repository\PaymentLinkRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class PaymentLinkReuseCheckerTest extends TestCase
{
    private MockObject&PaymentLinkRepositoryInterface $repository;

    private PaymentLinkReuseChecker $checker;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PaymentLinkRepositoryInterface::class);
        $this->checker = new PaymentLinkReuseChecker($this->repository);
    }

    public function testItReusesAnActiveLinkWithTheSameAmount(): void
    {
        $payment = $this->createPayment(PaymentInterface::STATE_PROCESSING);
        $link = $this->createLink($payment, 5000, new \DateTimeImmutable('+2 days'));
        $this->repository->method('findLatestByPayment')->with($payment)->willReturn($link);

        self::assertSame($link, $this->checker->findReusable($payment, 5000));
    }

    public function testItDoesNotReuseALinkWithADifferentAmount(): void
    {
        $payment = $this->createPayment(PaymentInterface::STATE_PROCESSING);
        $this->repository->method('findLatestByPayment')
            ->willReturn($this->createLink($payment, 5000, new \DateTimeImmutable('+2 days')));

        self::assertNull($this->checker->findReusable($payment, 4000));
    }

    public function testItDoesNotReuseAnExpiredLink(): void
    {
        $payment = $this->createPayment(PaymentInterface::STATE_PROCESSING);
        $this->repository->method('findLatestByPayment')
            ->willReturn($this->createLink($payment, 5000, new \DateTimeImmutable('-1 minute')));

        self::assertNull($this->checker->findReusable($payment, 5000));
    }

    public function testItDoesNotReuseALinkWithoutKnownExpiry(): void
    {
        $payment = $this->createPayment(PaymentInterface::STATE_PROCESSING);
        $this->repository->method('findLatestByPayment')->willReturn($this->createLink($payment, 5000, null));

        self::assertNull($this->checker->findReusable($payment, 5000));
    }

    public function testItReturnsNullWhenThereIsNoLink(): void
    {
        $payment = $this->createPayment(PaymentInterface::STATE_PROCESSING);
        $this->repository->method('findLatestByPayment')->willReturn(null);

        self::assertNull($this->checker->findReusable($payment, 5000));
    }

    public function testItDoesNotReuseALinkWhenThePaymentIsNotProcessing(): void
    {
        $payment = $this->createPayment(PaymentInterface::STATE_NEW);
        $this->repository->expects(self::never())->method('findLatestByPayment');

        self::assertNull($this->checker->findReusable($payment, 5000));
    }

    private function createPayment(string $state): PaymentInterface
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getState')->willReturn($state);

        return $payment;
    }

    private function createLink(PaymentInterface $payment, int $amount, ?\DateTimeInterface $expiresAt): PaymentLink
    {
        return new PaymentLink($payment, 'PL123', 'https://pay.example/PL123', $amount, $expiresAt);
    }
}
