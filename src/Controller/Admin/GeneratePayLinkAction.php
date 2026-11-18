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

namespace Sylius\AdyenPlugin\Controller\Admin;

use Sylius\AdyenPlugin\Email\Sender\PaymentLinkEmailSenderInterface;
use Sylius\AdyenPlugin\Exception\PaymentLinkGenerationException;
use Sylius\AdyenPlugin\Generator\PaymentLinkGeneratorInterface;
use Sylius\AdyenPlugin\Provider\OrderAmountDueProviderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Repository\PaymentRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webmozart\Assert\Assert;

final class GeneratePayLinkAction
{
    use FlashHelperTrait;

    /** @param PaymentRepositoryInterface<PaymentInterface> $paymentRepository */
    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly PaymentLinkGeneratorInterface $paymentLinkGenerator,
        private readonly PaymentLinkEmailSenderInterface $paymentLinkEmailSender,
        private readonly UrlGeneratorInterface $urlGenerator,
        RequestStack $requestStack,
        private readonly OrderAmountDueProviderInterface $orderAmountDueProvider,
    ) {
        $this->requestStack = $requestStack;
    }

    public function __invoke(string $id, Request $request): Response
    {
        /** @var PaymentInterface|null $payment */
        $payment = $this->paymentRepository->find($id);
        if (null === $payment) {
            throw new NotFoundHttpException(sprintf('Payment with ID %s not found.', $id));
        }

        $order = $payment->getOrder();
        Assert::notNull($order);

        $redirect = new RedirectResponse(
            $this->urlGenerator->generate('sylius_admin_order_show', [
                'id' => $order->getId(),
            ]),
        );

        $amountDue = $this->orderAmountDueProvider->getAmountDue($order);
        $amount = $this->resolveAmount($request, $amountDue);
        if (null === $amount || $amount <= 0 || $amount > $amountDue) {
            $this->addFlash('error', 'sylius_adyen.payment_link.invalid_amount', [
                '%amount_due%' => $this->formatAmount($amountDue),
            ]);

            return $redirect;
        }

        $previousAmount = $payment->getAmount();

        try {
            $recipient = $order->getCustomer()?->getEmail();
            Assert::stringNotEmpty($recipient, 'Cannot generate payment link for payment without customer email.');

            $payment->setAmount($amount);

            $paymentLink = $this->paymentLinkGenerator->generate($payment);

            $this->paymentLinkEmailSender->send($paymentLink, $recipient);

            $this->addFlash('success', 'sylius_adyen.payment_link.generation_success');
        } catch (PaymentLinkGenerationException) {
            $payment->setAmount((int) $previousAmount);

            $this->addFlash('error', 'sylius_adyen.payment_link.generation_fail');
        }

        return $redirect;
    }

    /**
     * @return int|null amount in minor units, the amount due when none was submitted, null when it is not valid
     */
    private function resolveAmount(Request $request, int $amountDue): ?int
    {
        $submitted = $request->request->get('amount', $request->query->get('amount'));
        if (null === $submitted || '' === trim((string) $submitted)) {
            return $amountDue;
        }

        if (1 !== preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/', trim((string) $submitted), $matches)) {
            return null;
        }

        return ((int) $matches[1]) * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function formatAmount(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }
}
