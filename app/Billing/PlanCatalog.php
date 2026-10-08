<?php

namespace App\Billing;

use App\Enums\Billing\BillingInterval;
use App\Enums\Billing\LimitKey;
use App\Exceptions\Billing\BillingException;

/**
 * All plans, from config/billing.php, validated once: every plan must define every limit,
 * a non-negative price and a known interval, and the default plan must exist.
 */
final class PlanCatalog
{
    /** @var array<string, Plan> */
    private array $plans = [];

    private string $defaultKey;

    /**
     * @param  array<string, mixed>  $config  config('billing')
     *
     * @throws BillingException
     */
    public function __construct(array $config)
    {
        foreach ($config['plans'] ?? [] as $key => $plan) {
            $this->plans[$key] = $this->build((string) $key, is_array($plan) ? $plan : []);
        }

        $this->defaultKey = (string) ($config['default_plan'] ?? '');

        if ($this->plans === [] || ! isset($this->plans[$this->defaultKey])) {
            throw BillingException::invalidConfiguration('the default plan must be one of the configured plans.');
        }
    }

    /**
     * @return array<string, Plan>
     */
    public function all(): array
    {
        return $this->plans;
    }

    /**
     * @throws BillingException
     */
    public function get(string $key): Plan
    {
        return $this->plans[$key] ?? throw BillingException::unknownPlan($key);
    }

    public function find(?string $key): ?Plan
    {
        return $key === null ? null : ($this->plans[$key] ?? null);
    }

    /**
     * The plan sold under a provider price ID (e.g. a Stripe price), or null.
     */
    public function findByProviderPriceId(?string $priceId): ?Plan
    {
        if (blank($priceId)) {
            return null;
        }

        foreach ($this->plans as $plan) {
            if ($plan->providerPriceId === $priceId) {
                return $plan;
            }
        }

        return null;
    }

    public function default(): Plan
    {
        return $this->plans[$this->defaultKey];
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
}
