<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Facebook Page whose Lead Ads forms send leads to a business. The Page access token is encrypted at rest
 * and never sent to the browser.
 */
class LeadAdsPage extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    protected $hidden = ['page_access_token'];

    protected function casts(): array
    {
        return [
            'page_access_token' => 'encrypted',
            'whatsapp_consent' => 'boolean',
            'last_lead_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(LeadAdsLead::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
