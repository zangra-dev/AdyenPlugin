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

namespace Sylius\AdyenPlugin\Resolver\Order;

use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

/**
 * Payment links created directly in the Adyen backoffice use a free merchant reference
 * ("Order-000186791"), where the order number may or may not be zero padded.
 */
final class MerchantReferenceOrderResolver implements MerchantReferenceOrderResolverInterface
{
    private const REFERENCE_PATTERN = '/^\s*(?:Order-?)?(0*)(\d+)\s*$/i';

    private const ORDER_NUMBER_LENGTH = 9;

    public function __construct(
        private readonly RepositoryInterface $orderRepository,
    ) {
    }

    public function resolve(string $merchantReference): ?OrderInterface
    {
        foreach ($this->getCandidates($merchantReference) as $candidate) {
            $order = $this->orderRepository->findOneBy(['number' => $candidate]);
            if ($order instanceof OrderInterface) {
                return $order;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function getCandidates(string $merchantReference): array
    {
        $candidates = [trim($merchantReference)];

        if (1 === preg_match(self::REFERENCE_PATTERN, $merchantReference, $matches)) {
            $digits = $matches[2];
            $candidates[] = $digits;
            $candidates[] = str_pad($digits, self::ORDER_NUMBER_LENGTH, '0', \STR_PAD_LEFT);
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $candidate): bool => '' !== $candidate)));
    }
}
