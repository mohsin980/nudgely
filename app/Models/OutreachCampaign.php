<?php

namespace App\Models;

use App\Enums\Outreach\OutreachCampaignStatus;
use App\Enums\Outreach\OutreachChannel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OutreachCampaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'channel' => OutreachChannel::class,
            'status' => OutreachCampaignStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(OutreachTemplate::class, 'outreach_template_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(OutreachCategory::class, 'outreach_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OutreachMessage::class);
    }
}
