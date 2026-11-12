<?php

namespace App\Models;

use App\Enums\Billing\WebhookEventStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * Lifecycle: received → processing → processed | ignored | failed, and failed → received (operator replay).
     * Each move is one conditional UPDATE: it only happens if the event is still in a status the move may start from.
     * A refused move changes nothing and returns false, so a late or repeated call cannot move an event backwards.
     */
    public function markProcessing(): bool
    {
        // Processing again is allowed: a job that died mid-way is re-run from processing.
        return $this->transition(
            ['status' => WebhookEventStatus::Processing, 'attempt_count' => DB::raw('attempt_count + 1')],
            [WebhookEventStatus::Received, WebhookEventStatus::Processing],
            'processing',
        );
    }

    public function markProcessed(): bool
    {
        return $this->transition(
            ['status' => WebhookEventStatus::Processed, 'processed_at' => now(), 'failure_reason' => null],
            [WebhookEventStatus::Processing],
            'processed',
        );
    }

    /**
     * Handled on purpose with nothing to do (a duplicate, an event for a message we don't have, a soft bounce).
     */
    public function markIgnored(string $reason): bool
    {
        return $this->transition(
            ['status' => WebhookEventStatus::Ignored, 'processed_at' => now(), 'failure_reason' => $reason],
            [WebhookEventStatus::Received, WebhookEventStatus::Processing],
            'ignored',
        );
    }

    /**
     * An attempt failed but the job will retry: the event stays received, with the reason, not failed.
     */
    public function markAttemptFailed(string $reason): bool
    {
        return $this->transition(
            ['status' => WebhookEventStatus::Received, 'failure_reason' => $reason],
            [WebhookEventStatus::Processing],
            'attempt_failed',
        );
    }

    public function markFailed(string $reason): bool
    {
        return $this->transition(
            ['status' => WebhookEventStatus::Failed, 'failed_at' => now(), 'failure_reason' => $reason],
            [WebhookEventStatus::Received, WebhookEventStatus::Processing],
            'failed',
        );
    }

    /**
     * An operator asks for a failed event to be processed again. The original identity is kept.
     */
    public function markReceivedForReplay(): bool
    {
        return $this->transition(
            ['status' => WebhookEventStatus::Received, 'failed_at' => null, 'failure_reason' => null],
            [WebhookEventStatus::Failed],
            'replay',
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<WebhookEventStatus>  $from  The statuses this move may start from.
     */
    private function transition(array $attributes, array $from, string $move): bool
    {
        $updated = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', array_map(fn (WebhookEventStatus $status) => $status->value, $from))
            ->update($attributes + ['updated_at' => now()]);

        $this->refresh();

        if ($updated === 0) {
            Log::warning('Webhook event status change refused.', [
                'event' => 'webhook.transition.refused',
                'webhook_event_id' => $this->id,
                'move' => $move,
                'status' => $this->status?->value,
            ]);

            return false;
        }

        return true;
    }
}
