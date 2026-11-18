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

namespace Tests\Sylius\AdyenPlugin\Unit\Twig\Extension;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\AdyenPlugin\Checker\PaymentLinkReuseCheckerInterface;
use Sylius\AdyenPlugin\Checker\PaymentPayByLinkAvailabilityCheckerInterface;
use Sylius\AdyenPlugin\Entity\PaymentLinkInterface;
use Sylius\AdyenPlugin\Provider\OrderAmountDueProviderInterface;
use Sylius\AdyenPlugin\Repository\PaymentLinkRepositoryInterface;
use Sylius\AdyenPlugin\Twig\Extension\PaymentLinkGenerationCheckerExtension;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Twig\TwigFunction;

final class PaymentLinkGenerationCheckerExtensionTest extends TestCase
{
    private MockObject&PaymentLinkRepositoryInterface $repository;

    private MockObject&PaymentLinkReuseCheckerInterface $reuseChecker;

    private PaymentLinkGenerationCheckerExtension $extension;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PaymentLinkRepositoryInterface::class);
        $this->reuseChecker = $this->createMock(PaymentLinkReuseCheckerInterface::class);

        $amountDueProvider = $this->createMock(OrderAmountDueProviderInterface::class);
        $amountDueProvider->method('getAmountDue')->willReturn(4200);

        $this->extension = new PaymentLinkGenerationCheckerExtension(
            $this->createMock(PaymentPayByLinkAvailabilityCheckerInterface::class),
            $amountDueProvider,
            $this->repository,
            $this->reuseChecker,
        );
    }

    public function testItRegistersThePaymentLinkFunctions(): void
    {
        $names = array_map(
            static fn (TwigFunction $function): string => $function->getName(),
            $this->extension->getFunctions(),
        );

        self::assertContains('sylius_adyen_payment_link', $names);
        self::assertContains('sylius_adyen_reusable_payment_link', $names);
    }

    public function testItReturnsTheLatestPaymentLink(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $link = $this->createMock(PaymentLinkInterface::class);
        $this->repository->method('findLatestByPayment')->with($payment)->willReturn($link);

        self::assertSame($link, $this->extension->getPaymentLink($payment));
    }

    public function testItReturnsNullWhenThePaymentHasNoLink(): void
    {
        $this->repository->method('findLatestByPayment')->willReturn(null);

        self::assertNull($this->extension->getPaymentLink($this->createMock(PaymentInterface::class)));
    }

    public function testItReturnsTheReusableLinkForTheAmountDue(): void
    {
        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getOrder')->willReturn($this->createMock(OrderInterface::class));
        $link = $this->createMock(PaymentLinkInterface::class);
        $this->reuseChecker->method('findReusable')->with($payment, 4200)->willReturn($link);

        self::assertSame($link, $this->extension->getReusablePaymentLink($payment));
    }
}
