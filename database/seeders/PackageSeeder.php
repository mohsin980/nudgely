<?php

namespace Database\Seeders;

use App\Models\PlanSetting;
use Illuminate\Database\Seeder;

/**
 * Plans and add-ons from config/billing.php, written to the database as packages (plan_settings).
 *
 * Safe to run again: a package that already has a row is left alone, so an administrator's edits in the admin panel
 * are never overwritten. To put a package back to the configured values, use "Reset to configured" in the panel.
 */
class PackageSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('billing.plans', []) as $key => $plan) {
            PlanSetting::query()->firstOrCreate(['plan_key' => $key], [
                'kind' => PlanSetting::KIND_PLAN,
                'name' => $plan['name'],
                'limits' => $plan['limits'],
                'prices' => ['USD' => $plan['price_cents'], ...($plan['prices'] ?? [])],
                'provider_price_ids' => array_filter(['USD' => $plan['provider_price_id'] ?? null, ...($plan['provider_price_ids'] ?? [])]),
                'legacy_provider_price_ids' => [],
                'features' => array_values($plan['features'] ?? []),
                'applies_to' => null,
                'active' => true,
            ]);
        }

        foreach (config('billing.addons', []) as $key => $addon) {
            PlanSetting::query()->firstOrCreate(['plan_key' => $key], [
                'kind' => PlanSetting::KIND_ADDON,
                'name' => $addon['name'],
                'description' => $addon['description'] ?? null,
                'limits' => $addon['limits'] ?? [],
                'prices' => ['USD' => $addon['price_cents'], ...($addon['prices'] ?? [])],
                'provider_price_ids' => array_filter(['USD' => $addon['provider_price_id'] ?? null, ...($addon['provider_price_ids'] ?? [])]),
                'legacy_provider_price_ids' => [],
                'features' => array_values($addon['features'] ?? []),
                'applies_to' => array_values($addon['applies_to'] ?? []),
                'active' => true,
            ]);
        }
    }
}
