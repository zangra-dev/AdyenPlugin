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

namespace Tests\Sylius\AdyenPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\AdyenPlugin\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testPaymentLinkExpirationDefaultsToFifteenDays(): void
    {
        $config = $this->process([]);

        self::assertSame(15, $config['payment_link']['expiration_days']);
    }

    #[DataProvider('provideValidExpirationDays')]
    public function testPaymentLinkExpirationAcceptsValidValues(int $days): void
    {
        $config = $this->process(['payment_link' => ['expiration_days' => $days]]);

        self::assertSame($days, $config['payment_link']['expiration_days']);
    }

    #[DataProvider('provideInvalidExpirationDays')]
    public function testPaymentLinkExpirationRejectsInvalidValues(int $days): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['payment_link' => ['expiration_days' => $days]]);
    }

    public static function provideValidExpirationDays(): \Generator
    {
        yield 'minimum' => [1];
        yield 'custom' => [30];
        yield 'maximum' => [70];
    }

    public static function provideInvalidExpirationDays(): \Generator
    {
        yield 'zero' => [0];
        yield 'above Adyen limit' => [71];
    }

    /** @return array<string, mixed> */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
