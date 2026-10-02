<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\Email\InboundEmailProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one stored inbound-email webhook event. Safe to run more than once.
 */
class ProcessInboundEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $webhookEventId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function uniqueId(): string
    {
        return (string) $this->webhookEventId;
    }

    public function handle(InboundEmailProcessor $processor): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event !== null) {
            $processor->process($event);
        }
    }

    public function failed(?Throwable $exception): void
    {
        WebhookEvent::query()
            ->whereKey($this->webhookEventId)
            ->whereNull('processed_at')
            ->whereNull('failed_at')
            ->update(['failed_at' => now(), 'failure_reason' => 'Processing failed after several attempts.', 'updated_at' => now()]);

        Log::error('Inbound email processing failed.', [
            'webhook_event_id' => $this->webhookEventId,
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
