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

namespace Tests\Sylius\AdyenPlugin\Unit\Email\Sender;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\AdyenPlugin\Email\Sender\PaymentLinkEmailSender;
use Sylius\AdyenPlugin\Entity\AdyenReferenceInterface;
use Sylius\AdyenPlugin\Entity\PaymentLink;
use Sylius\AdyenPlugin\Repository\AdyenReferenceRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;

final class PaymentLinkEmailSenderTest extends TestCase
{
    private MockObject&SenderInterface $sender;

    private AdyenReferenceRepositoryInterface&MockObject $references;

    private PaymentLinkEmailSender $emailSender;

    protected function setUp(): void
    {
        $this->sender = $this->createMock(SenderInterface::class);
        $this->references = $this->createMock(AdyenReferenceRepositoryInterface::class);
        $this->emailSender = new PaymentLinkEmailSender($this->sender, $this->references);
    }

    public function testItSendsTheOrderDataAndTheLatestPspReference(): void
    {
        $payment = $this->createPayment();
        $reference = $this->createMock(AdyenReferenceInterface::class);
        $reference->method('getPspReference')->willReturn('PSP123');
        $this->references->method('findLatestByPayment')->with($payment)->willReturn($reference);

        $expiresAt = new \DateTimeImmutable('+15 days');
        $link = new PaymentLink($payment, 'PL123', 'https://pay.example/PL123', 5000, $expiresAt);

        $this->sender->expects(self::once())->method('send')->with(
            'adyen_payment_link',
            ['john@example.com'],
            self::callback(function (array $data) use ($link, $expiresAt): bool {
                self::assertSame('000042', $data['orderNumber']);
                self::assertSame('John', $data['customerFirstName']);
                self::assertSame('Doe', $data['customerLastName']);
                self::assertSame('PSP123', $data['pspReference']);
                self::assertSame('PL123', $data['paymentLinkId']);
                self::assertSame('https://pay.example/PL123', $data['paymentLinkUrl']);
                self::assertSame($expiresAt, $data['expiresAt']);
                self::assertSame($link, $data['paymentLink']);
                self::assertInstanceOf(OrderInterface::class, $data['order']);
                self::assertInstanceOf(CustomerInterface::class, $data['customer']);

                return true;
            }),
        );

        $this->emailSender->send($link, 'john@example.com');
    }

    public function testItSendsAnEmptyPspReferenceWhenNoPaymentAttemptWasMade(): void
    {
        $payment = $this->createPayment();
        $this->references->method('findLatestByPayment')->willReturn(null);

        $link = new PaymentLink($payment, 'PL123', 'https://pay.example/PL123', 5000, null);

        $this->sender->expects(self::once())->method('send')->with(
            'adyen_payment_link',
            ['john@example.com'],
            self::callback(static fn (array $data): bool => '' === $data['pspReference'] && null === $data['expiresAt']),
        );

        $this->emailSender->send($link, 'john@example.com');
    }

    private function createPayment(): PaymentInterface
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getFirstName')->willReturn('John');
        $customer->method('getLastName')->willReturn('Doe');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getNumber')->willReturn('000042');
        $order->method('getCustomer')->willReturn($customer);

        $payment = $this->createMock(PaymentInterface::class);
        $payment->method('getOrder')->willReturn($order);

        return $payment;
    }
}
