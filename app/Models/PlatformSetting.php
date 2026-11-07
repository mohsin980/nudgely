<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One platform-wide setting changed by a super admin. Read and written through get() and put().
 */
class PlatformSetting extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /**
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public static function get(string $key, array $default = []): array
    {
        $value = static::query()->where('key', $key)->value('value');

        return array_merge($default, is_string($value) ? (json_decode($value, true) ?: []) : (array) $value);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function put(string $key, array $value, ?User $actor = null): void
    {
        $setting = static::query()->where('key', $key)->first() ?? new static;
        $setting->key = $key;
        $setting->value = $value;
        $setting->updated_by = $actor?->id;
        $setting->save();
    }
}
