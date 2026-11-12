<?php

namespace App\Billing;

use App\Enums\Billing\BillingInterval;
use App\Enums\Billing\LimitKey;
use App\Exceptions\Billing\BillingException;

/**
 * All plans, from config/billing.php, validated once: every plan must define every limit,
 * a non-negative price and a known interval, and the default plan must exist.
 *
 * Config defines the plans and everything the billing provider owns (price, interval, provider price ID).
 * Platform administrators may override only the display name and the limits (see PlanSettingsService); those
 * overrides are applied on top, lazily, and invalid ones are ignored so a bad row can never break billing.
 */
final class PlanCatalog
{
    /** @var array<string, Plan> The plans exactly as configured. */
    private array $configured = [];

    /** @var array<string, Plan>|null Configured plans with administrator overrides applied. */
    private ?array $resolved = null;

    /** @var (\Closure(): array<string, array{name: ?string, limits: ?array<string, int|null>}>)|null */
    private ?\Closure $overrides;

    private string $defaultKey;

    /**
     * @param  array<string, mixed>  $config  config('billing')
     * @param  (\Closure(): array<string, array{name: ?string, limits: ?array<string, int|null>}>)|null  $overrides  loaded on first use
     *
     * @throws BillingException
     */
    public function __construct(array $config, ?\Closure $overrides = null)
    {
        $this->overrides = $overrides;

        foreach ($config['plans'] ?? [] as $key => $plan) {
            $this->configured[$key] = $this->build((string) $key, is_array($plan) ? $plan : []);
        }

        $this->defaultKey = (string) ($config['default_plan'] ?? '');

        if ($this->configured === [] || ! isset($this->configured[$this->defaultKey])) {
            throw BillingException::invalidConfiguration('the default plan must be one of the configured plans.');
        }
    }

    /**
     * @return array<string, Plan>
     */
    public function all(): array
    {
        return $this->resolved();
    }

    /** Forget the loaded overrides so the next read sees saved changes (used after an administrator edits a plan). */
    public function refresh(): void
    {
        $this->resolved = null;
    }

    /**
     * The plans exactly as configured, ignoring administrator overrides (to show what "reset" restores).
     *
     * @return array<string, Plan>
     */
    public function configured(): array
    {
        return $this->configured;
    }

    /**
     * @throws BillingException
     */
    public function get(string $key): Plan
    {
        return $this->resolved()[$key] ?? throw BillingException::unknownPlan($key);
    }

    public function find(?string $key): ?Plan
    {
        return $key === null ? null : ($this->resolved()[$key] ?? null);
    }

    /**
     * The plan sold under a provider price ID (e.g. a Stripe price), or null.
     */
    public function findByProviderPriceId(?string $priceId): ?Plan
    {
        if (blank($priceId)) {
            return null;
        }

        foreach ($this->resolved() as $plan) {
            if ($plan->providerPriceId === $priceId || in_array($priceId, $plan->providerPriceIds, true) || in_array($priceId, $plan->legacyProviderPriceIds, true)) {
                return $plan;
            }
        }

        return null;
    }

    public function default(): Plan
    {
        return $this->resolved()[$this->defaultKey];
    }

    /**
     * @param  array<string, mixed>  $plan
     *
     * @throws BillingException
     */
    private function build(string $key, array $plan): Plan
    {
        if (! preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key) || ! is_string($plan['name'] ?? null)) {
            throw BillingException::invalidConfiguration("plan [{$key}] needs a lowercase key and a name.");
        }

        $price = $plan['price_cents'] ?? null;
        $interval = BillingInterval::tryFrom((string) ($plan['interval'] ?? ''));

        if (! is_int($price) || $price < 0 || $interval === null) {
            throw BillingException::invalidConfiguration("plan [{$key}] needs a price in cents and an interval.");
        }

        $limits = [];

        foreach (LimitKey::cases() as $limit) {
            if (! array_key_exists($limit->value, $plan['limits'] ?? [])) {
                throw BillingException::invalidConfiguration("plan [{$key}] is missing the [{$limit->value}] limit.");
            }

            $value = $plan['limits'][$limit->value];

            if ($value !== null && (! is_int($value) || $value < 0)) {
                throw BillingException::invalidConfiguration("plan [{$key}] limit [{$limit->value}] must be a whole number or null.");
            }

            $limits[$limit->value] = $value;
        }

        $prices = $plan['prices'] ?? [];
        $providerPriceIds = $plan['provider_price_ids'] ?? [];

        foreach ($prices as $currency => $amount) {
            if (! is_string($currency) || ! is_int($amount) || $amount < 0) {
                throw BillingException::invalidConfiguration("plan [{$key}] price for [{$currency}] must be a whole number of minor units.");
            }
        }

        return new Plan($key, $plan['name'], $price, $interval, $limits, array_values($plan['features'] ?? []), $plan['provider_price_id'] ?? null,
            $prices, array_filter($providerPriceIds, fn ($id) => filled($id)));
    }

    /**
     * @return array<string, Plan>
     */
    private function resolved(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $overrides = [];

        try {
            $overrides = $this->overrides === null ? [] : ($this->overrides)();
        } catch (\Throwable) {
            // Billing must keep working from config if the settings cannot be read.
        }

        $plans = [];

        foreach ($this->configured as $key => $plan) {
            $plans[$key] = isset($overrides[$key]) ? $this->applyOverride($plan, $overrides[$key]) : $plan;
        }

        // Plans an administrator created: not in config, so they are built whole from their saved row.
        foreach ($overrides as $key => $override) {
            if (! isset($plans[$key]) && ($built = $this->fromOverride((string) $key, $override)) !== null) {
                $plans[$key] = $built;
            }
        }

        return $this->resolved = $plans;
    }

    /**
     * A plan that exists only in the database. Null (and left out of the catalog) when its saved row is incomplete,
     * so one bad row can never break billing.
     *
     * @param  array<string, mixed>  $row
     */
    private function fromOverride(string $key, array $row): ?Plan
    {
        $name = trim((string) ($row['name'] ?? ''));
        $prices = is_array($row['prices'] ?? null) ? $row['prices'] : [];
        $ids = is_array($row['provider_price_ids'] ?? null) ? $row['provider_price_ids'] : [];
        $limitsRow = is_array($row['limits'] ?? null) ? $row['limits'] : [];

        if (! preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key) || $name === '' || ! is_int($prices['USD'] ?? null) || $prices['USD'] < 0) {
            return null;
        }

        $limits = [];

        foreach (LimitKey::cases() as $limit) {
            if (! array_key_exists($limit->value, $limitsRow)) {
                return null;
            }

            $limits[$limit->value] = $limitsRow[$limit->value];
        }

        return new Plan($key, $name, $prices['USD'], BillingInterval::Month, $limits, array_values($row['features'] ?? []),
            is_string($ids['USD'] ?? null) ? $ids['USD'] : null,
            array_filter(array_diff_key($prices, ['USD' => true]), fn ($v) => is_int($v) && $v >= 0),
            array_filter(array_diff_key($ids, ['USD' => true]), fn ($v) => is_string($v) && $v !== ''),
            is_string($row['description'] ?? null) ? $row['description'] : '',
            (bool) ($row['active'] ?? true),
            array_values(array_filter($row['legacy_provider_price_ids'] ?? [], 'is_string')));
    }

    /**
     * @param  array{name?: ?string, limits?: ?array<string, mixed>}  $override
     */
    private function applyOverride(Plan $plan, array $override): Plan
    {
        $name = is_string($override['name'] ?? null) && trim($override['name']) !== '' ? trim($override['name']) : $plan->name;
        $limits = $plan->limits;

        foreach (LimitKey::cases() as $limit) {
            if (is_array($override['limits'] ?? null) && array_key_exists($limit->value, $override['limits'])) {
                $value = $override['limits'][$limit->value];

                if ($value === null || (is_int($value) && $value >= 0)) {
                    $limits[$limit->value] = $value;
                }
            }
        }

        $priceCents = $plan->priceCents;
        $prices = $plan->prices;
        $providerPriceId = $plan->providerPriceId;
        $providerPriceIds = $plan->providerPriceIds;

        // An administrator's prices and provider price IDs replace the configured ones. When a price ID changes, the
        // old one is kept as a legacy ID so existing subscribers still map to this plan.
        $legacy = $plan->legacyProviderPriceIds;

        foreach (($override['prices'] ?? null) ?: [] as $currency => $amount) {
            if (is_string($currency) && is_int($amount) && $amount >= 0) {
                if ($currency === 'USD') {
                    $priceCents = $amount;
                } else {
                    $prices[$currency] = $amount;
                }
            }
        }

        foreach (($override['provider_price_ids'] ?? null) ?: [] as $currency => $id) {
            $oldId = $currency === 'USD' ? $providerPriceId : ($providerPriceIds[$currency] ?? null);

            if (is_string($currency) && is_string($id) && $id !== '') {
                if ($currency === 'USD') {
                    $providerPriceId = $id;
                } else {
                    $providerPriceIds[$currency] = $id;
                }

                if ($oldId !== null && $oldId !== $id) {
                    $legacy[] = $oldId;
                }
            }
        }

        $legacy = array_values(array_unique([...$legacy, ...($override['legacy_provider_price_ids'] ?? [])]));

        return new Plan($plan->key, $name, $priceCents, $plan->interval, $limits, $override['features'] ?? $plan->features, $providerPriceId,
            $prices, $providerPriceIds,
            is_string($override['description'] ?? null) ? $override['description'] : $plan->description,
            (bool) ($override['active'] ?? $plan->active),
            $legacy);
    }
}
