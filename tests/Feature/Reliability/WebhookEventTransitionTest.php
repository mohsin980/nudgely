<?php

use App\Enums\Billing\WebhookEventStatus;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Log;

/** A received event, in the given status. */
function webhookIn(WebhookEventStatus $status): WebhookEvent
{
    return WebhookEvent::query()->forceCreate([
        'provider' => 'test',
        'event_type' => WebhookEvent::TYPE_DELIVERY_EVENT,
        'external_event_id' => 'evt_'.str()->random(8),
        'payload' => [],
        'status' => $status,
    ]);
}

test('an event moves received → processing → processed along the allowed path', function () {
    $event = webhookIn(WebhookEventStatus::Received);

    expect($event->markProcessing())->toBeTrue()
        ->and($event->markProcessed())->toBeTrue()
        ->and($event->fresh()->status)->toBe(WebhookEventStatus::Processed);
});

test('a finished event cannot be processed or marked again', function () {
    $processed = webhookIn(WebhookEventStatus::Processed);

    expect($processed->markProcessing())->toBeFalse()
        ->and($processed->markFailed('late'))->toBeFalse()
        ->and($processed->markIgnored('late'))->toBeFalse()
        ->and($processed->fresh()->status)->toBe(WebhookEventStatus::Processed);
});

test('marking processed only works while the event is processing', function () {
    $event = webhookIn(WebhookEventStatus::Received);

    expect($event->markProcessed())->toBeFalse()
        ->and($event->fresh()->status)->toBe(WebhookEventStatus::Received)
        ->and($event->fresh()->processed_at)->toBeNull();
});

test('a refused move changes nothing and is logged', function () {
    Log::spy();
    $event = webhookIn(WebhookEventStatus::Failed);

    expect($event->markAttemptFailed('oops'))->toBeFalse()
        ->and($event->fresh()->status)->toBe(WebhookEventStatus::Failed)
        ->and($event->fresh()->failure_reason)->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context['event'] === 'webhook.transition.refused'
        && $context['move'] === 'attempt_failed');
});

test('a failed attempt goes back to received so the job can retry it', function () {
    $event = webhookIn(WebhookEventStatus::Received);
    $event->markProcessing();

    expect($event->markAttemptFailed('timeout'))->toBeTrue()
        ->and($event->fresh()->status)->toBe(WebhookEventStatus::Received)
        ->and($event->fresh()->failure_reason)->toBe('timeout');
});

test('only a failed event can be replayed', function () {
    $failed = webhookIn(WebhookEventStatus::Failed);
    $processed = webhookIn(WebhookEventStatus::Processed);

    expect($failed->markReceivedForReplay())->toBeTrue()
        ->and($failed->fresh()->status)->toBe(WebhookEventStatus::Received)
        ->and($processed->markReceivedForReplay())->toBeFalse()
        ->and($processed->fresh()->status)->toBe(WebhookEventStatus::Processed);
});

test('the attempt count rises when processing starts, and not for a refused start', function () {
    $received = webhookIn(WebhookEventStatus::Received);
    $processed = webhookIn(WebhookEventStatus::Processed);

    $received->markProcessing();
    $processed->markProcessing();

    expect($received->fresh()->attempt_count)->toBe(1)
        ->and($processed->fresh()->attempt_count)->toBe(0);
});
