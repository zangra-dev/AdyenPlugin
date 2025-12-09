<?php

declare(strict_types=1);


namespace Sylius\AdyenPlugin\Resolver\Currency;


use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Currency\Context\CurrencyNotFoundException;

class PaymentCurrencyResolver
{
    public function __construct(
        private readonly ChannelContextInterface $channelContext,
        private ?string $configCurrencyCode = null,
    ) {
    }

    public function resolve(string $currencyCode): string
    {
        if (null === $this->configCurrencyCode) {
            return $currencyCode;
        }

        $currencies = $this->channelContext->getChannel()->getCurrencies()->toArray();
        if (!in_array($this->configCurrencyCode, $currencies)) {
            throw CurrencyNotFoundException::notAvailable($this->configCurrencyCode, $currencies);
        }

        return $this->configCurrencyCode;
    }
}