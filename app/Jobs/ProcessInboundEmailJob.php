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

    public int $timeout = 120;

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
        $event = WebhookEvent::query()->whereKey($this->webhookEventId)->first();

        if ($event !== null) {
            app(InboundEmailProcessor::class)->assignOrganizationFromRoute($event);
            $event->refresh();
        }

        // Only an event still waiting is marked failed; one that finished in an earlier attempt is left alone.
        if ($event !== null && ! $event->isFinished()) {
            $event->markFailed('Processing failed after several attempts. An operator can replay it.');
        }

        Log::error('webhook.failed', [
            'event' => 'webhook.failed',
            'webhook_event_id' => $this->webhookEventId,
            'provider' => $event?->provider,
            'organization_id' => $event?->organization_id,
            'attempt' => $event?->attempt_count,
            'correlation_id' => $event?->correlation_id,
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
