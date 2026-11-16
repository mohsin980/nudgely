<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A type of business the outreach is aimed at (for example HVAC, cafe). Contacts and templates belong to one.
 */
class OutreachCategory extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(OutreachContact::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(OutreachTemplate::class);
    }
}
