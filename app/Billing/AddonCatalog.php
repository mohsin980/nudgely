<?php

namespace App\Billing;

/**
 * Add-ons from config/billing.php ('addons'): optional extras bought on top of a plan.
 *
 * Reads config only; prices and Stripe price IDs stay in config. validate() reports every mistake that would
 * make an add-on wrong to sell (an unknown plan, feature or limit, or a price that is not positive), so a test
 * can fail before a customer sees it.
 */
final class AddonCatalog
{
    /**
     * @param  array<string, mixed>  $billing  config('billing')
     */
    public function __construct(private readonly array $billing) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->billing['addons'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Add-ons a business on this plan can buy.
     *
     * @return array<string, array<string, mixed>>
     */
    public function availableFor(string $planKey): array
    {
        return array_filter($this->all(), fn (array $addon) => in_array($planKey, $addon['applies_to'] ?? [], true));
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
}
