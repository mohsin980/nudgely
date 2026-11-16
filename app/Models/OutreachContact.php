<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person the team may contact. Nothing is sent to a contact without a recorded consent source and date.
 */
class OutreachContact extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'consent_at' => 'datetime',
            'email_opted_out_at' => 'datetime',
            'whatsapp_opted_out_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(OutreachCategory::class, 'outreach_category_id');
    }

    public function hasConsent(): bool
    {
        return $this->consent_at !== null;
    }
}
