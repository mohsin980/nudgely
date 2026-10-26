<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An administrator's override of one configured plan's display name and limits. Written only by
 * PlanSettingsService; read by PlanCatalog.
 */
class PlanSetting extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['limits' => 'array'];
    }

    /**
     * @return array<string, array{name: ?string, limits: ?array<string, int|null>}>
     */
    public static function overrides(): array
    {
        return static::query()->get(['plan_key', 'name', 'limits'])
            ->mapWithKeys(fn (self $row) => [$row->plan_key => ['name' => $row->name, 'limits' => $row->limits]])->all();
    }
}
