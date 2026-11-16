<?php

namespace App\Models;

use App\Enums\Outreach\OutreachChannel;
use App\Enums\Outreach\OutreachMessageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a campaign, sent or not. This is the send log: every attempt, its result and any skip reason.
 */
class OutreachMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'channel' => OutreachChannel::class,
            'status' => OutreachMessageStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(OutreachCampaign::class, 'outreach_campaign_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(OutreachContact::class, 'outreach_contact_id');
    }
}
