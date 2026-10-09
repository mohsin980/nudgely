<?php

namespace App\Services\Webhooks;

use App\Enums\Billing\WebhookEventStatus;
use App\Exceptions\Webhooks\WebhookReplayException;
use App\Jobs\ProcessDeliveryEventJob;
use App\Jobs\ProcessInboundEmailJob;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Log;

/**
 * Operator replay of a failed webhook event. No public endpoint: it is reached only from the console.
 *
 * The stored event keeps its identity (provider, event type, external ID, payload). Processing it
 * again is safe because each processor is idempotent, so a replay can never repeat a business action.
 */
class WebhookReplayService
{
    /**
     * @param  ?int  $organizationId  When given, only that organization's events can be replayed. Other
     *                                events report "not found", so their existence is not revealed.
     *
     * @throws WebhookReplayException
     */
    public function replay(int $eventId, ?int $organizationId = null): WebhookEvent
    {
        $event = WebhookEvent::query()
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->whereKey($eventId)
            ->first();

        if ($event === null) {
            throw new WebhookReplayException('Webhook event not found.');
        }

        if ($event->status !== WebhookEventStatus::Failed) {
            throw new WebhookReplayException("Only failed events can be replayed; this one is {$event->status->value}.");
        }

        if (($event->payload ?? []) === ['redacted' => true]) {
            throw new WebhookReplayException('The payload was removed by the retention policy, so this event cannot be replayed.');
        }

        $previousAttempts = $event->attempt_count;
        $event->markReceivedForReplay();

        match ($event->event_type) {
            WebhookEvent::TYPE_INBOUND_EMAIL => ProcessInboundEmailJob::dispatch($event->id),
            WebhookEvent::TYPE_DELIVERY_EVENT => ProcessDeliveryEventJob::dispatch($event->id),
            default => throw new WebhookReplayException('This event type cannot be replayed.'),
        };

        Log::info('webhook.replayed', [
            'event' => 'webhook.replayed',
            'webhook_event_id' => $event->id,
            'provider' => $event->provider,
            'organization_id' => $event->organization_id,
            'correlation_id' => $event->correlation_id,
            'previous_attempts' => $previousAttempts,
        ]);

        return $event->refresh();
    }
}
