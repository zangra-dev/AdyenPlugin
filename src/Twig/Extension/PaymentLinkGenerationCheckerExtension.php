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

use Sylius\AdyenPlugin\Checker\PaymentLinkReuseCheckerInterface;
use Sylius\AdyenPlugin\Checker\PaymentPayByLinkAvailabilityCheckerInterface;
use Sylius\AdyenPlugin\Entity\PaymentLinkInterface;
use Sylius\AdyenPlugin\Provider\OrderAmountDueProviderInterface;
use Sylius\AdyenPlugin\Repository\PaymentLinkRepositoryInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class PaymentLinkGenerationCheckerExtension extends AbstractExtension
{
    public function __construct(
        private readonly PaymentPayByLinkAvailabilityCheckerInterface $paymentPayByLinkAvailabilityChecker,
        private readonly OrderAmountDueProviderInterface $orderAmountDueProvider,
        private readonly ?PaymentLinkRepositoryInterface $paymentLinkRepository = null,
        private readonly ?PaymentLinkReuseCheckerInterface $paymentLinkReuseChecker = null,
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
            new TwigFunction(
                'sylius_adyen_payment_link',
                $this->getPaymentLink(...),
            ),
            new TwigFunction(
                'sylius_adyen_reusable_payment_link',
                $this->getReusablePaymentLink(...),
            ),
        ];
    }

    /**
     * Latest payment link generated for the payment, if any.
     */
    public function getPaymentLink(PaymentInterface $payment): ?PaymentLinkInterface
    {
        return $this->paymentLinkRepository?->findLatestByPayment($payment);
    }

    /**
     * The existing link that would be sent again (instead of generating a new one) for the current amount due.
     */
    public function getReusablePaymentLink(PaymentInterface $payment): ?PaymentLinkInterface
    {
        return $this->paymentLinkReuseChecker?->findReusable($payment, $this->getAmountDue($payment));
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
