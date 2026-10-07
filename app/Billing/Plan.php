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
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly int $priceCents,
        public readonly BillingInterval $interval,
        public readonly array $limits,
        public readonly array $features,
        public readonly ?string $providerPriceId,
    ) {}

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

    public function priceLabel(string $currency = 'USD'): string
    {
        return Money::format($this->priceCents, $currency).' / '.$this->interval->value;
    }
}
