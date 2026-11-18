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

namespace Tests\Sylius\AdyenPlugin\Unit\Controller\Admin;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\AdyenPlugin\Controller\Admin\GeneratePayLinkAction;
use Sylius\AdyenPlugin\Email\Sender\PaymentLinkEmailSenderInterface;
use Sylius\AdyenPlugin\Entity\PaymentLinkInterface;
use Sylius\AdyenPlugin\Exception\PaymentLinkGenerationException;
use Sylius\AdyenPlugin\Generator\PaymentLinkGeneratorInterface;
use Sylius\AdyenPlugin\Provider\OrderAmountDueProviderInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class GeneratePayLinkActionTest extends TestCase
{
    private const AMOUNT_DUE = 23686;

    private MockObject&PaymentRepositoryInterface $paymentRepository;

    private MockObject&PaymentLinkGeneratorInterface $generator;

    private MockObject&PaymentLinkEmailSenderInterface $emailSender;

    private Session $session;

    private GeneratePayLinkAction $action;

    protected function setUp(): void
    {
        $this->paymentRepository = $this->createMock(PaymentRepositoryInterface::class);
        $this->generator = $this->createMock(PaymentLinkGeneratorInterface::class);
        $this->emailSender = $this->createMock(PaymentLinkEmailSenderInterface::class);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/admin/orders/1');

        $amountDueProvider = $this->createMock(OrderAmountDueProviderInterface::class);
        $amountDueProvider->method('getAmountDue')->willReturn(self::AMOUNT_DUE);

        $this->session = new Session(new MockArraySessionStorage());
        $requestStack = new RequestStack();
        $sessionRequest = new Request();
        $sessionRequest->setSession($this->session);
        $requestStack->push($sessionRequest);

        $this->action = new GeneratePayLinkAction(
            $this->paymentRepository,
            $this->generator,
            $this->emailSender,
            $urlGenerator,
            $requestStack,
            $amountDueProvider,
        );
    }

    public function testItThrowsWhenPaymentDoesNotExist(): void
    {
        $this->paymentRepository->method('find')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);

        ($this->action)('1', new Request());
    }

    public function testItGeneratesTheLinkForTheAmountDueWhenNoAmountIsSubmitted(): void
    {
        $payment = $this->createPayment(30000);
        $payment->expects(self::once())->method('setAmount')->with(self::AMOUNT_DUE);

        $this->expectGeneration($payment);

        ($this->action)('1', new Request());

        self::assertSame(['sylius_adyen.payment_link.generation_success'], $this->getFlashMessages('success'));
    }

    #[DataProvider('provideValidAmounts')]
    public function testItGeneratesTheLinkForTheSubmittedAmount(string $submitted, int $expected): void
    {
        $payment = $this->createPayment(30000);
        $payment->expects(self::once())->method('setAmount')->with($expected);

        $this->expectGeneration($payment);

        ($this->action)('1', new Request([], ['amount' => $submitted]));

        self::assertSame(['sylius_adyen.payment_link.generation_success'], $this->getFlashMessages('success'));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideValidAmounts(): iterable
    {
        yield 'decimal with a comma' => ['12,50', 1250];
        yield 'decimal with a dot' => ['12.50', 1250];
        yield 'one decimal' => ['12.5', 1250];
        yield 'integer' => ['100', 10000];
        yield 'exactly the amount due' => ['236.86', self::AMOUNT_DUE];
        yield 'surrounded by spaces' => [' 0.01 ', 1];
    }

    #[DataProvider('provideInvalidAmounts')]
    public function testItRejectsAnInvalidAmountWithoutGeneratingAnything(string $submitted): void
    {
        $payment = $this->createPayment(30000);
        $payment->expects(self::never())->method('setAmount');

        $this->paymentRepository->method('find')->willReturn($payment);
        $this->generator->expects(self::never())->method('generate');
        $this->emailSender->expects(self::never())->method('send');

        ($this->action)('1', new Request([], ['amount' => $submitted]));

        self::assertSame(['sylius_adyen.payment_link.invalid_amount'], $this->getFlashMessages('error'));
        self::assertSame([], $this->getFlashMessages('success'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidAmounts(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zero with decimals' => ['0.00'];
        yield 'negative' => ['-5'];
        yield 'more than due' => ['236.87'];
        yield 'way more than due' => ['100000'];
        yield 'not a number' => ['abc'];
        yield 'too many decimals' => ['12.345'];
    }

    public function testItRestoresThePaymentAmountWhenTheGenerationFails(): void
    {
        $payment = $this->createPayment(30000);
        $payment->expects(self::exactly(2))->method('setAmount')->willReturnCallback(
            function (int $amount) use ($payment): PaymentInterface {
                static $calls = 0;
                self::assertSame(0 === $calls++ ? self::AMOUNT_DUE : 30000, $amount);

                return $payment;
            },
        );

        $this->paymentRepository->method('find')->willReturn($payment);
        $this->generator->method('generate')->willThrowException(PaymentLinkGenerationException::create($payment, 'Fail'));
        $this->emailSender->expects(self::never())->method('send');

        ($this->action)('1', new Request());

        self::assertSame(['sylius_adyen.payment_link.generation_fail'], $this->getFlashMessages('error'));
    }

    private function expectGeneration(PaymentInterface $payment): void
    {
        $paymentLink = $this->createMock(PaymentLinkInterface::class);

        $this->paymentRepository->method('find')->willReturn($payment);
        $this->generator->expects(self::once())->method('generate')->with($payment)->willReturn($paymentLink);
        $this->emailSender->expects(self::once())->method('send')->with($paymentLink, 'customer@example.com');
    }

    private function createPayment(int $amount): MockObject&PaymentInterface
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('customer@example.com');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getId')->willReturn(1);
        $order->method('getCustomer')->willReturn($customer);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAmount')->willReturn($amount);

        return $payment;
    }

    /**
     * @return string[]
     */
    private function getFlashMessages(string $type): array
    {
        return array_map(
            static fn (array $flash): string => $flash['message'],
            $this->session->getFlashBag()->peek($type),
        );
    }
}
