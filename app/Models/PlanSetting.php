<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A package an administrator created or edited: a plan or an add-on (`kind`). A row overrides the configured package
 * with the same key, or adds a new one. Written only by PackageService; read by PlanCatalog and AddonCatalog.
 */
class PlanSetting extends Model
{
    public const KIND_PLAN = 'plan';

    public const KIND_ADDON = 'addon';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'limits' => 'array',
            'prices' => 'array',
            'provider_price_ids' => 'array',
            'legacy_provider_price_ids' => 'array',
            'features' => 'array',
            'applies_to' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * Plan overrides, keyed by plan key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function planOverrides(): array
    {
        return static::overridesOf(self::KIND_PLAN);
    }

    /**
     * Add-on overrides, keyed by add-on key.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function addonOverrides(): array
    {
        return static::overridesOf(self::KIND_ADDON);
    }

    /**
     * Back-compatible name for the plan overrides read by PlanCatalog.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function overrides(): array
    {
        return static::planOverrides();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function overridesOf(string $kind): array
    {
        return static::query()->where('kind', $kind)->get()
            ->mapWithKeys(fn (self $row) => [$row->plan_key => [
                'name' => $row->name,
                'description' => $row->description,
                'limits' => $row->limits,
                'prices' => $row->prices,
                'provider_price_ids' => $row->provider_price_ids,
                'legacy_provider_price_ids' => $row->legacy_provider_price_ids,
                'features' => $row->features,
                'applies_to' => $row->applies_to,
                'active' => $row->active,
            ]])->all();
    }
}
