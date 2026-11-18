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

namespace Sylius\AdyenPlugin\Checker;

use Sylius\AdyenPlugin\Provider\OrderAmountDueProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;

final class PaymentPayByLinkAvailabilityChecker implements PaymentPayByLinkAvailabilityCheckerInterface
{
    public function __construct(
        private AdyenPaymentMethodCheckerInterface $adyenPaymentMethodChecker,
        private OrderAmountDueProviderInterface $orderAmountDueProvider,
        private array $allowedStates = [PaymentInterface::STATE_NEW, PaymentInterface::STATE_PROCESSING],
    ) {
    }

    public function canBeGenerated(PaymentInterface $payment): bool
    {
        if (!$this->adyenPaymentMethodChecker->isAdyenPayment($payment)) {
            return false;
        }

        if (!in_array($payment->getState(), $this->allowedStates, true)) {
            return false;
        }

        $order = $payment->getOrder();

        return null !== $order && $this->orderAmountDueProvider->getAmountDue($order) > 0;
    }
}
