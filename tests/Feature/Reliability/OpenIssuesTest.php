<?php

use App\Exceptions\Webhooks\WebhookReplayException;
use App\Models\WebhookEvent;
use App\Services\Maintenance\RetentionPruner;
use App\Services\Webhooks\WebhookReplayService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    configureEmailWebhooks();
});

function failedEvent(array $attributes = []): WebhookEvent
{
    $event = new WebhookEvent;
    $event->forceFill(array_merge([
        'provider' => 'postmark', 'event_type' => WebhookEvent::TYPE_DELIVERY_EVENT, 'external_event_id' => 'Delivery:'.uniqid(),
        'payload' => ['RecordType' => 'Delivery', 'MessageID' => 'abcdefgh-1234', 'Recipient' => 'pat@example.com'],
        'status' => 'failed', 'failed_at' => now(), 'failure_reason' => 'Processing failed after several attempts.', 'attempt_count' => 5,
    ], $attributes))->save();

    return $event;
}

test('failed webhook payloads are redacted after the retention period, metadata kept', function () {
    $old = failedEvent(['failed_at' => now()->subDays(40)]);
    $recent = failedEvent(['failed_at' => now()->subDays(3)]);

    expect(app(RetentionPruner::class)->prune()['webhook_payloads_redacted'])->toBe(1);

    $old->refresh();
    expect($old->payload)->toBe(['redacted' => true])
        ->and($old->status->value)->toBe('failed')
        ->and($old->failure_reason)->toBe('Processing failed after several attempts.')
        ->and($old->attempt_count)->toBe(5)
        ->and($recent->refresh()->payload)->toHaveKey('MessageID');
});

test('redaction never touches events that are not failed', function () {
    $processed = failedEvent(['status' => 'processed', 'failed_at' => null, 'processed_at' => now()->subDays(60)]);

    app(RetentionPruner::class)->prune();

    expect($processed->refresh()->payload)->toHaveKey('MessageID');
});

test('a redacted event is refused by replay with a clear reason', function () {
    $event = failedEvent(['failed_at' => now()->subDays(40)]);
    app(RetentionPruner::class)->prune();

    expect(fn () => app(WebhookReplayService::class)->replay($event->id))
        ->toThrow(WebhookReplayException::class, 'removed by the retention policy');
});

test('the message lookup index for delivery events exists', function () {
    $indexes = collect(DB::select("select indexname from pg_indexes where tablename = 'webhook_events'"))->pluck('indexname');

    expect($indexes)->toContain('webhook_events_delivery_message_id_index');
});

test('webhooks:check fails when a credential is missing and never prints one', function () {
    config(['email.providers.postmark.events_webhook_secret' => null]);

    $this->artisan('webhooks:check')->expectsOutputToContain('NOT CONFIGURED')->assertExitCode(1);
});

test('webhooks:check passes when both credentials are set, and prints neither', function () {
    config([
        'email.providers.postmark.inbound_webhook_secret' => 'inbound-secret-value',
        'email.providers.postmark.events_webhook_secret' => 'events-secret-value',
    ]);

    $this->artisan('webhooks:check')
        ->doesntExpectOutputToContain('inbound-secret-value')
        ->doesntExpectOutputToContain('events-secret-value')
        ->assertExitCode(0);
});
