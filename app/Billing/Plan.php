<?php

namespace App\Billing;

use App\Enums\Billing\BillingInterval;
use App\Enums\Billing\LimitKey;
use App\Support\Money;

/**
 * One subscription plan, read from config/billing.php by PlanCatalog.
 */
final class Plan
{
    /**
     * @param  array<string, int|null>  $limits  LimitKey value => limit (null = unlimited)
     * @param  list<string>  $features
     * @param  array<string, int>  $prices  Currency => price in minor units, for currencies other than USD
     * @param  array<string, string>  $providerPriceIds  Currency => provider price ID, for currencies other than USD
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly int $priceCents,
        public readonly BillingInterval $interval,
        public readonly array $limits,
        public readonly array $features,
        public readonly ?string $providerPriceId,
        public readonly array $prices = [],
        public readonly array $providerPriceIds = [],
    ) {}

    /**
     * The price in a currency (minor units), or null when this plan isn't sold in that currency.
     */
    public function priceFor(string $currency): ?int
    {
        return $currency === 'USD' ? $this->priceCents : ($this->prices[$currency] ?? null);
    }

    /**
     * The provider price ID to charge in a currency, or null when this plan isn't sold in that currency.
     */
    public function providerPriceIdFor(string $currency): ?string
    {
        if ($currency === 'USD') {
            return $this->providerPriceId;
        }

        return filled($this->providerPriceIds[$currency] ?? null) ? $this->providerPriceIds[$currency] : null;
    }

    /**
     * The limit, or null for unlimited.
     */
    public function limit(LimitKey $key): ?int
    {
        return $this->limits[$key->value] ?? null;
    }

    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function isFree(): bool
    {
        return $this->priceCents === 0;
    }

    /**
     * "$29", "PKR 8,000.00" or "$0" for Free. Falls back to the USD price when the plan isn't sold in the currency.
     */
    public function amountLabel(string $currency = 'USD'): string
    {
        $price = $this->priceFor($currency);

        if ($price === null) {
            return Money::format($this->priceCents, 'USD');
        }

        if ($price === 0) {
            return $currency === 'USD' ? '$0' : $currency.' 0.00';
        }

        return Money::format($price, $currency);
    }

    /**
     * "$29.00 / month" or "PKR 8,000.00 / month".
     */
    public function priceLabel(string $currency = 'USD'): string
    {
        return $this->amountLabel($currency).' / '.$this->interval->value;
    }
}
