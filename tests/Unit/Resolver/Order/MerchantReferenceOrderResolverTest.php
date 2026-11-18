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

namespace Tests\Sylius\AdyenPlugin\Unit\Resolver\Order;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\AdyenPlugin\Resolver\Order\MerchantReferenceOrderResolver;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

final class MerchantReferenceOrderResolverTest extends TestCase
{
    private MockObject&RepositoryInterface $orderRepository;

    private MerchantReferenceOrderResolver $resolver;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(RepositoryInterface::class);
        $this->resolver = new MerchantReferenceOrderResolver($this->orderRepository);
    }

    #[DataProvider('provideReferencesOfTheSameOrder')]
    public function testItResolvesTheSameOrderWhateverTheReferenceFormat(string $reference): void
    {
        $order = $this->createMock(OrderInterface::class);
        $this->orderRepository
            ->method('findOneBy')
            ->willReturnCallback(static fn (array $criteria): ?OrderInterface => '000186791' === $criteria['number'] ? $order : null)
        ;

        self::assertSame($order, $this->resolver->resolve($reference));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideReferencesOfTheSameOrder(): iterable
    {
        yield 'prefixed and padded' => ['Order-000186791'];
        yield 'lower case prefix, not padded' => ['order-186791'];
        yield 'padded number only' => ['000186791'];
        yield 'not padded number only' => ['186791'];
        yield 'padded to an other length' => ['Order-0000186791'];
    }

    public function testItReturnsNullWhenNoOrderMatches(): void
    {
        $this->orderRepository->method('findOneBy')->willReturn(null);

        self::assertNull($this->resolver->resolve('Order-000999999'));
    }

    public function testItDoesNotTryNumericCandidatesForAFreeTextReference(): void
    {
        $this->orderRepository
            ->expects(self::once())
            ->method('findOneBy')
            ->with(['number' => 'some-free-text'])
            ->willReturn(null)
        ;

        self::assertNull($this->resolver->resolve('some-free-text'));
    }
}
