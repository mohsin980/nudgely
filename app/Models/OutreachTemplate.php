<?php

namespace App\Models;

use App\Enums\Outreach\OutreachChannel;
use App\Enums\Outreach\OutreachTemplateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message the team wants to send. Only an approved template can be used in a campaign.
 */
class OutreachTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'channel' => OutreachChannel::class,
            'status' => OutreachTemplateStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(OutreachCategory::class, 'outreach_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isApproved(): bool
    {
        return $this->status === OutreachTemplateStatus::Approved;
    }
}
