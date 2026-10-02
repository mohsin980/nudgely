<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A received provider webhook, kept for idempotency, retries and troubleshooting.
 */
class WebhookEvent extends Model
{
    public const TYPE_INBOUND_EMAIL = 'inbound_email';

    protected $guarded = ['*'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return $this->processed_at !== null || $this->failed_at !== null;
    }
}
