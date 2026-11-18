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

namespace Sylius\AdyenPlugin\Twig\Extension;

use Sylius\AdyenPlugin\Checker\PaymentPayByLinkAvailabilityCheckerInterface;
use Sylius\AdyenPlugin\Provider\OrderAmountDueProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PaymentLinkGenerationCheckerExtension extends AbstractExtension
{
    public function __construct(
        private readonly PaymentPayByLinkAvailabilityCheckerInterface $paymentPayByLinkAvailabilityChecker,
        private readonly OrderAmountDueProviderInterface $orderAmountDueProvider,
    ) {
    }

    /** @return array<TwigFunction> */
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'sylius_adyen_can_generate_payment_link',
                $this->paymentPayByLinkAvailabilityChecker->canBeGenerated(...),
                ['is_safe' => ['html']],
            ),
            new TwigFunction(
                'sylius_adyen_payment_link_amount_due',
                $this->getAmountDue(...),
            ),
        ];
    }

    /**
     * Default amount of a payment link, in minor units.
     */
    public function getAmountDue(PaymentInterface $payment): int
    {
        $order = $payment->getOrder();

        return null === $order ? 0 : $this->orderAmountDueProvider->getAmountDue($order);
    }
}
