<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One lead from a Facebook or Instagram lead form, keyed by Meta's lead ID. "imported" made a new customer,
 * "matched" found an existing one (same email or phone), "failed" says why in error.
 */
class LeadAdsLead extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(LeadAdsPage::class, 'lead_ads_page_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function platformLabel(): string
    {
        return $this->platform === 'ig' ? 'Instagram' : 'Facebook';
    }
}
