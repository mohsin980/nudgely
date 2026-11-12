<?php

namespace App\Billing;

/**
 * Add-ons: optional extras bought on top of a plan. Config (config/billing.php 'addons') is the default; an
 * administrator's saved package (PlanSetting, kind addon) replaces the fields it sets, and can add new add-ons.
 *
 * validate() reports every mistake that would make an add-on wrong to sell (an unknown plan, feature or limit, or a
 * price that is not positive), so a test can fail before a customer sees it.
 */
final class AddonCatalog
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $resolved = null;

    /**
     * @param  array<string, mixed>  $billing  config('billing')
     * @param  (\Closure(): array<string, array<string, mixed>>)|null  $overrides  saved add-ons, keyed by add-on key
     */
    public function __construct(private readonly array $billing, private readonly ?\Closure $overrides = null) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->resolved ??= $this->resolve();
    }

    /** Forget loaded add-ons so the next read sees saved changes. */
    public function refresh(): void
    {
        $this->resolved = null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Active add-ons a business on this plan can buy.
     *
     * @return array<string, array<string, mixed>>
     */
    public function availableFor(string $planKey): array
    {
        return array_filter($this->all(), fn (array $addon) => ($addon['active'] ?? true)
            && in_array($planKey, $addon['applies_to'] ?? [], true));
    }

    /**
     * Every problem with the add-on definitions. Empty when all are sellable.
     *
     * @return list<string>
     */
    public function validate(): array
    {
        $plans = $this->billing['plans'] ?? [];
        $features = array_keys($this->billing['feature_labels'] ?? []);
        $errors = [];

        foreach ($this->all() as $key => $addon) {
            $prefix = "addon [{$key}]";

            if (blank($addon['name'] ?? null)) {
                $errors[] = "{$prefix}: name is missing.";
            }

            if (! is_int($addon['price_cents'] ?? null) || $addon['price_cents'] <= 0) {
                $errors[] = "{$prefix}: price_cents must be a positive whole number of cents.";
            }

            foreach ($addon['prices'] ?? [] as $currency => $amount) {
                if (! is_int($amount) || $amount <= 0) {
                    $errors[] = "{$prefix}: the {$currency} price must be a positive whole number of minor units.";
                }
            }

            if (($addon['interval'] ?? null) !== 'month') {
                $errors[] = "{$prefix}: interval must be 'month'.";
            }

            if (($addon['applies_to'] ?? []) === []) {
                $errors[] = "{$prefix}: applies_to lists no plans, so it can never be bought.";
            }

            foreach ($addon['applies_to'] ?? [] as $planKey) {
                if (! array_key_exists($planKey, $plans)) {
                    $errors[] = "{$prefix}: applies_to names an unknown plan [{$planKey}].";
                }
            }

            foreach ($addon['features'] ?? [] as $feature) {
                if (! in_array($feature, $features, true)) {
                    $errors[] = "{$prefix}: feature [{$feature}] has no label in feature_labels.";
                }
            }

            foreach (array_keys($addon['limits'] ?? []) as $limit) {
                foreach ($addon['applies_to'] ?? [] as $planKey) {
                    if (isset($plans[$planKey]) && ! array_key_exists($limit, $plans[$planKey]['limits'] ?? [])) {
                        $errors[] = "{$prefix}: limit [{$limit}] does not exist on plan [{$planKey}].";
                    }
                }
            }

            if (($addon['features'] ?? []) === [] && ($addon['limits'] ?? []) === []) {
                $errors[] = "{$prefix}: changes nothing (no features and no limits).";
            }
        }

        return $errors;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resolve(): array
    {
        $saved = [];

        try {
            $saved = $this->overrides === null ? [] : ($this->overrides)();
        } catch (\Throwable) {
            // Add-ons keep working from config if the saved settings cannot be read.
        }

        $addons = [];

        foreach ($this->billing['addons'] ?? [] as $key => $addon) {
            $addons[$key] = $this->merge(is_array($addon) ? $addon : [], $saved[$key] ?? []);
        }

        foreach ($saved as $key => $row) {
            if (! isset($addons[$key]) && ($built = $this->fromRow((string) $key, $row)) !== null) {
                $addons[$key] = $built;
            }
        }

        return $addons;
    }

    /**
     * Saved fields replace configured ones; prices and provider price IDs merge per currency.
     *
     * @param  array<string, mixed>  $configured
     * @param  array<string, mixed>  $saved
     * @return array<string, mixed>
     */
    private function merge(array $configured, array $saved): array
    {
        $prices = $configured['prices'] ?? [];
        $ids = $configured['provider_price_ids'] ?? [];
        $priceCents = $configured['price_cents'] ?? null;
        $providerPriceId = $configured['provider_price_id'] ?? null;

        foreach ($saved['prices'] ?? [] as $currency => $amount) {
            if ($currency === 'USD') {
                $priceCents = $amount;
            } else {
                $prices[$currency] = $amount;
            }
        }

        foreach ($saved['provider_price_ids'] ?? [] as $currency => $id) {
            if ($currency === 'USD') {
                $providerPriceId = $id;
            } else {
                $ids[$currency] = $id;
            }
        }

        return array_merge($configured, [
            'name' => $saved['name'] ?? $configured['name'] ?? null,
            'description' => $saved['description'] ?? $configured['description'] ?? '',
            'price_cents' => $priceCents,
            'provider_price_id' => $providerPriceId,
            'prices' => $prices,
            'provider_price_ids' => $ids,
            'applies_to' => $saved['applies_to'] ?? $configured['applies_to'] ?? [],
            'features' => $saved['features'] ?? $configured['features'] ?? [],
            'limits' => $saved['limits'] ?? $configured['limits'] ?? [],
            'active' => $saved['active'] ?? true,
            'legacy_provider_price_ids' => array_values($saved['legacy_provider_price_ids'] ?? []),
            'interval' => 'month',
        ]);
    }

    /**
     * An add-on an administrator created (not in config). Null when the saved row is incomplete.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function fromRow(string $key, array $row): ?array
    {
        if (! preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key) || blank($row['name'] ?? null)) {
            return null;
        }

        return $this->merge(['interval' => 'month', 'price_cents' => 0, 'prices' => [], 'provider_price_ids' => [], 'applies_to' => [], 'features' => [], 'limits' => []], $row);
    }
}
