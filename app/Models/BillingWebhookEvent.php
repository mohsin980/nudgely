<?php

namespace App\Models;

use App\Enums\Billing\WebhookEventStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * A received billing-provider webhook: kept so each event is handled once and failures can be traced.
 * Only safe debugging metadata is stored (ids and states), never the payload itself.
 */
class BillingWebhookEvent extends Model
{
    protected $guarded = ['*'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return ['status' => WebhookEventStatus::class, 'metadata' => 'array', 'processed_at' => 'datetime', 'failed_at' => 'datetime'];
    }
}
