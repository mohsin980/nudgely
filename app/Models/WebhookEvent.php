<?php

namespace App\Models;

use App\Enums\Billing\WebhookEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A received provider webhook, kept for idempotency, retries and troubleshooting.
 */
class WebhookEvent extends Model
{
    public const TYPE_INBOUND_EMAIL = 'inbound_email';

    public const TYPE_DELIVERY_EVENT = 'delivery_event';

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
            'received_at' => 'datetime',
            'status' => WebhookEventStatus::class,
        ];
    }

    /**
     * Finished means nothing more will happen unless an operator replays it.
     */
    public function isFinished(): bool
    {
        return in_array($this->status, [WebhookEventStatus::Processed, WebhookEventStatus::Failed, WebhookEventStatus::Ignored], true);
    }

    /**
     * Lifecycle: received → processing → processed | ignored | failed. Each step is one UPDATE, so
     * it is atomic on its own and is not undone if a surrounding transaction rolls back.
     */
    public function markProcessing(): void
    {
        $this->transition([
            'status' => WebhookEventStatus::Processing,
            'attempt_count' => DB::raw('attempt_count + 1'),
        ]);
    }

    public function markProcessed(): void
    {
        $this->transition(['status' => WebhookEventStatus::Processed, 'processed_at' => now(), 'failure_reason' => null]);
    }

    /**
     * Handled on purpose with nothing to do (a duplicate, an event for a message we don't have, a soft bounce).
     */
    public function markIgnored(string $reason): void
    {
        $this->transition(['status' => WebhookEventStatus::Ignored, 'processed_at' => now(), 'failure_reason' => $reason]);
    }

    /**
     * An attempt failed but the job will retry: the event stays received, with the reason, not failed.
     */
    public function markAttemptFailed(string $reason): void
    {
        $this->transition(['status' => WebhookEventStatus::Received, 'failure_reason' => $reason]);
    }

    public function markFailed(string $reason): void
    {
        $this->transition(['status' => WebhookEventStatus::Failed, 'failed_at' => now(), 'failure_reason' => $reason]);
    }

    /**
     * An operator asks for a failed event to be processed again. The original identity is kept.
     */
    public function markReceivedForReplay(): void
    {
        $this->transition(['status' => WebhookEventStatus::Received, 'failed_at' => null, 'failure_reason' => null]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(array $attributes): void
    {
        static::query()->whereKey($this->getKey())->update($attributes + ['updated_at' => now()]);
        $this->refresh();
    }
}
