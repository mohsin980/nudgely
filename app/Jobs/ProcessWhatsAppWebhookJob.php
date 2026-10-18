<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use App\Services\WhatsApp\WhatsAppWebhookProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one stored WhatsApp webhook event. Safe to run more than once.
 */
class ProcessWhatsAppWebhookJob implements ShouldBeUnique, ShouldQueue
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
        return 'whatsapp:'.$this->webhookEventId;
    }

    public function handle(WhatsAppWebhookProcessor $processor): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

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
            'provider' => 'whatsapp',
            'organization_id' => $event?->organization_id,
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
