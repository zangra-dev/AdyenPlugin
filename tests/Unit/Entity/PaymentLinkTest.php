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

namespace Tests\Sylius\AdyenPlugin\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Sylius\AdyenPlugin\Entity\PaymentLink;
use Sylius\Component\Core\Model\PaymentInterface;

final class PaymentLinkTest extends TestCase
{
    public function testItExposesAmountAndExpiry(): void
    {
        $expiresAt = new \DateTimeImmutable('+1 day');
        $link = new PaymentLink($this->createMock(PaymentInterface::class), 'PL1', 'https://pay.test/PL1', 1500, $expiresAt);

        self::assertSame(1500, $link->getAmount());
        self::assertSame($expiresAt, $link->getExpiresAt());
        self::assertFalse($link->isExpired());
    }

    public function testItIsExpiredWhenExpiryIsInThePast(): void
    {
        $link = new PaymentLink(
            $this->createMock(PaymentInterface::class),
            'PL1',
            'https://pay.test/PL1',
            1500,
            new \DateTimeImmutable('-1 minute'),
        );

        self::assertTrue($link->isExpired());
    }

    public function testItIsConsideredExpiredWithoutKnownExpiry(): void
    {
        $link = new PaymentLink($this->createMock(PaymentInterface::class), 'PL1', 'https://pay.test/PL1');

        self::assertNull($link->getExpiresAt());
        self::assertTrue($link->isExpired());
    }
}
