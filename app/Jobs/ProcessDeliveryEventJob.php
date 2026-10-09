<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\Email\DeliveryEventProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Applies one stored delivery event. Retried when it arrives before the send is recorded.
 */
class ProcessDeliveryEventJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly int $webhookEventId) {}

    public function handle(DeliveryEventProcessor $processor): void
    {
        $event = WebhookEvent::query()->whereKey($this->webhookEventId)->first();

        if ($event !== null) {
            $processor->process($event);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $event = WebhookEvent::query()->whereKey($this->webhookEventId)->first();

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
