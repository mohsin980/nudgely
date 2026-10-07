<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A received billing-provider webhook: kept so each event is handled once and failures can be traced
 * (the payload itself is not stored).
 */
class BillingWebhookEvent extends Model
{
    protected $guarded = ['*'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['processed_at' => 'datetime', 'failed_at' => 'datetime'];
    }
}
