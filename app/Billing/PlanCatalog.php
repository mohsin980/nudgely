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
            if ($plan->providerPriceId === $priceId) {
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

        return new Plan($key, $plan['name'], $price, $interval, $limits, array_values($plan['features'] ?? []), $plan['provider_price_id'] ?? null);
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

        return $this->resolved = $plans;
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

        // Price, interval, features and the provider price ID are never overridden.
        return new Plan($plan->key, $name, $plan->priceCents, $plan->interval, $limits, $plan->features, $plan->providerPriceId);
    }
}
