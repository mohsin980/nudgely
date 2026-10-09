<?php

use App\Enums\MessageStatus;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Exceptions\Email\MessageNotYetSentException;
use App\Exceptions\Webhooks\WebhookReplayException;
use App\Jobs\ProcessDeliveryEventJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Services\Email\DeliveryEventProcessor;
use App\Services\Email\EmailService;
use App\Services\Webhooks\WebhookReplayService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->withoutVite();
    configureEmailWebhooks();
    Queue::fake();
    fakeEmailProvider();
    $this->business = teamBusiness('Events HVAC');
    $this->organization = $this->business['organization'];
    $this->customer = $this->business['customer'];
    $this->message = sentEmail($this->business);
    $this->providerMessageId = $this->message->provider_message_id;
});

/**
 * Run every stored delivery event through the processor, as the job would.
 */
function processDeliveryEvents(): void
{
    WebhookEvent::query()->where('event_type', WebhookEvent::TYPE_DELIVERY_EVENT)->orderBy('id')->get()
        ->each(fn (WebhookEvent $event) => app(DeliveryEventProcessor::class)->process($event));
}

/**
 * Store a webhook event row directly (tests of the lifecycle; the model is guarded against mass assignment).
 *
 * @param  array<string, mixed>  $attributes
 */
function storeEvent(array $attributes): WebhookEvent
{
    $event = new WebhookEvent;
    $event->forceFill($attributes)->save();

    return $event;
}

function lastDeliveryEvent(): WebhookEvent
{
    return WebhookEvent::query()->where('event_type', WebhookEvent::TYPE_DELIVERY_EVENT)->latest('id')->firstOrFail();
}

test('15. a valid delivery event is accepted and queued for processing', function () {
    postDeliveryEvent(deliveryReport('Delivery', $this->providerMessageId, 'pat@example.com'))
        ->assertOk()->assertJson(['message' => 'Accepted.']);

    expect(WebhookEvent::query()->where('event_type', WebhookEvent::TYPE_DELIVERY_EVENT)->count())->toBe(1)
        ->and(lastDeliveryEvent()->status->value)->toBe('received');
    Queue::assertPushed(ProcessDeliveryEventJob::class);
});

test('16. a delivery event with a wrong secret is rejected and stored nowhere', function () {
    postDeliveryEvent(deliveryReport('Delivery', $this->providerMessageId, 'pat@example.com'), secret: 'wrong')->assertUnauthorized();
    postDeliveryEvent(deliveryReport('Delivery', $this->providerMessageId, 'pat@example.com'), user: null)->assertUnauthorized();

    expect(WebhookEvent::query()->where('event_type', WebhookEvent::TYPE_DELIVERY_EVENT)->count())->toBe(0);
});

test('17. malformed delivery payloads are rejected', function (array $payload) {
    postDeliveryEvent($payload)->assertStatus(422);

    expect(WebhookEvent::query()->where('event_type', WebhookEvent::TYPE_DELIVERY_EVENT)->count())->toBe(0);
})->with([
    'unknown record type' => [['RecordType' => 'Open', 'MessageID' => 'abcdefgh-1234', 'Recipient' => 'pat@example.com']],
    'missing message id' => [['RecordType' => 'Delivery', 'Recipient' => 'pat@example.com']],
    'bad recipient' => [['RecordType' => 'Delivery', 'MessageID' => 'abcdefgh-1234', 'Recipient' => 'not-an-email']],
]);

test('18. the same event delivered twice is acknowledged and recorded once', function () {
    $payload = deliveryReport('Delivery', $this->providerMessageId, 'pat@example.com');

    postDeliveryEvent($payload)->assertOk();
    postDeliveryEvent($payload)->assertOk()->assertJson(['message' => 'Already received.']);
    processDeliveryEvents();

    expect(WebhookEvent::query()->where('event_type', WebhookEvent::TYPE_DELIVERY_EVENT)->count())->toBe(1)
        ->and($this->message->refresh()->status)->toBe(MessageStatus::Delivered);
});

test('19. concurrent duplicates cannot both be stored, and a second apply changes nothing', function () {
    $payload = deliveryReport('Delivery', $this->providerMessageId, 'pat@example.com');

    // The database refuses the second copy: the unique index on provider, type and external ID.
    DB::transaction(fn () => postDeliveryEvent($payload)->assertOk());
    expect(fn () => DB::transaction(fn () => storeEvent([
        'provider' => 'postmark', 'event_type' => WebhookEvent::TYPE_DELIVERY_EVENT,
        'external_event_id' => 'Delivery:'.$this->providerMessageId, 'payload' => $payload,
    ])))->toThrow(UniqueConstraintViolationException::class);

    // Two different records that describe the same delivery: applied once, the second is ignored.
    $first = lastDeliveryEvent();
    $second = storeEvent([
        'provider' => 'postmark', 'event_type' => WebhookEvent::TYPE_DELIVERY_EVENT, 'external_event_id' => 'Delivery:copy-'.uniqid(),
        'payload' => $payload, 'status' => 'received',
    ]);
    $deliveredAt = null;
    expect(app(DeliveryEventProcessor::class)->process($first))->toBe(DeliveryEventProcessor::APPLIED);
    $deliveredAt = $this->message->refresh()->delivered_at;
    expect(app(DeliveryEventProcessor::class)->process($second))->toBe(DeliveryEventProcessor::DUPLICATE)
        ->and($this->message->refresh()->delivered_at->equalTo($deliveredAt))->toBeTrue();
});

test('20. the event is stored before any business change, so a crash later loses nothing', function () {
    postDeliveryEvent(deliveryReport('Delivery', $this->providerMessageId, 'pat@example.com'))->assertOk();

    // The request itself changed no message; the change happens in the job.
    expect($this->message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and(lastDeliveryEvent()->received_at)->not->toBeNull();
    Queue::assertPushed(ProcessDeliveryEventJob::class);
});

test('21. an event that arrives before the send is recorded is retried, then applied', function () {
    Message::query()->whereKey($this->message->id)->update(['status' => MessageStatus::Sending->value]);
    postDeliveryEvent(deliveryReport('Delivery', $this->providerMessageId, 'pat@example.com'))->assertOk();
    $event = lastDeliveryEvent();

    expect(fn () => app(DeliveryEventProcessor::class)->process($event))->toThrow(MessageNotYetSentException::class);
    $event->refresh();
    expect($event->status->value)->toBe('received')
        ->and($event->attempt_count)->toBe(1)
        ->and($event->failure_reason)->toContain('retried');

    Message::query()->whereKey($this->message->id)->update(['status' => MessageStatus::Sent->value]);
    app(DeliveryEventProcessor::class)->process($event->refresh());

    expect($this->message->refresh()->status)->toBe(MessageStatus::Delivered)
        ->and($event->refresh()->status->value)->toBe('processed')
        ->and($event->attempt_count)->toBe(2);
});

test('22. a permanently invalid event is recorded as failed and not retried', function () {
    $event = storeEvent([
        'provider' => 'postmark', 'event_type' => WebhookEvent::TYPE_DELIVERY_EVENT, 'external_event_id' => 'Delivery:bad',
        'payload' => ['RecordType' => 'Open'], 'status' => 'received',
    ]);

    expect(app(DeliveryEventProcessor::class)->process($event))->toBe(DeliveryEventProcessor::INVALID)
        ->and($event->refresh()->status->value)->toBe('failed')
        ->and($event->failure_reason)->toBe('Payload is not a valid delivery event.');
});

test('23. replaying a failed event applies it once and does not repeat the change', function () {
    $event = storeEvent([
        'provider' => 'postmark', 'event_type' => WebhookEvent::TYPE_DELIVERY_EVENT, 'external_event_id' => 'Delivery:replay',
        'payload' => deliveryReport('Delivery', $this->providerMessageId, 'pat@example.com'), 'status' => 'failed', 'failed_at' => now(),
        'failure_reason' => 'It failed earlier.',
    ]);

    app(WebhookReplayService::class)->replay($event->id);
    processDeliveryEvents();
    $deliveredAt = $this->message->refresh()->delivered_at;

    // A second replay of a finished event is refused, so the change cannot be repeated.
    expect(fn () => app(WebhookReplayService::class)->replay($event->id))->toThrow(WebhookReplayException::class)
        ->and($this->message->refresh()->delivered_at->equalTo($deliveredAt))->toBeTrue()
        ->and($event->refresh()->attempt_count)->toBe(1);
});

test('24. another organization cannot replay an event that is not theirs', function () {
    $event = storeEvent([
        'provider' => 'postmark', 'event_type' => WebhookEvent::TYPE_DELIVERY_EVENT, 'external_event_id' => 'Delivery:org',
        'payload' => [], 'status' => 'failed', 'failed_at' => now(), 'organization_id' => $this->organization->id,
    ]);
    $other = teamBusiness('Other Roofing');

    expect(fn () => app(WebhookReplayService::class)->replay($event->id, $other['organization']->id))
        ->toThrow(WebhookReplayException::class, 'Webhook event not found.')
        ->and($event->refresh()->status->value)->toBe('failed');
});

test('25. a bounce marks the message bounced and stops emails to that address', function () {
    postDeliveryEvent(['RecordType' => 'Bounce', 'ID' => 4242, 'Type' => 'HardBounce', 'MessageID' => $this->providerMessageId, 'Email' => 'pat@example.com'])->assertOk();
    processDeliveryEvents();

    $this->message->refresh();
    expect($this->message->status)->toBe(MessageStatus::Bounced)
        ->and($this->message->bounced_at)->not->toBeNull()
        ->and($this->customer->refresh()->email_suppression_reason)->toBe('bounced')
        ->and($this->customer->isEmailSuppressed())->toBeTrue()
        ->and($this->customer->email)->toBe('pat@example.com');

    // The customer is kept, but no further email goes to the bounced address.
    $conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->organization->id]);
    expect(fn () => app(EmailService::class)->sendToConversation($conversation, 'Again', '<p>x</p>', 'x'))
        ->toThrow(EmailSendingNotAllowedException::class, 'bounced or was reported');
});

test('26. a spam complaint marks the message complained and suppresses the address', function () {
    postDeliveryEvent(['RecordType' => 'SpamComplaint', 'ID' => 77, 'MessageID' => $this->providerMessageId, 'Email' => 'pat@example.com'])->assertOk();
    processDeliveryEvents();

    expect($this->message->refresh()->status)->toBe(MessageStatus::Complained)
        ->and($this->message->complained_at)->not->toBeNull()
        ->and($this->customer->refresh()->email_suppression_reason)->toBe('complained');
});

test('27. a repeated delivery report does not move the message again', function () {
    postDeliveryEvent(deliveryReport('Delivery', $this->providerMessageId, 'pat@example.com'))->assertOk();
    processDeliveryEvents();
    $deliveredAt = $this->message->refresh()->delivered_at;

    postDeliveryEvent(deliveryReport('Delivery', $this->providerMessageId, 'pat@example.com', ['DeliveredAt' => 'later']))->assertOk();
    processDeliveryEvents();

    expect($this->message->refresh()->delivered_at->equalTo($deliveredAt))->toBeTrue()
        // The repeat has the same external ID, so it is acknowledged at intake and never stored.
        ->and(WebhookEvent::query()->where('event_type', WebhookEvent::TYPE_DELIVERY_EVENT)->count())->toBe(1);
});

test('28. a delivery report for another recipient cannot change the message', function () {
    postDeliveryEvent(deliveryReport('Delivery', $this->providerMessageId, 'stranger@example.com'))->assertOk();
    processDeliveryEvents();

    expect($this->message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and(lastDeliveryEvent()->status->value)->toBe('ignored')
        ->and(lastDeliveryEvent()->failure_reason)->toContain('recipient');
});

test('29. a soft bounce is ignored: the provider keeps trying and the customer is not suppressed', function () {
    postDeliveryEvent(['RecordType' => 'Bounce', 'ID' => 5, 'Type' => 'SoftBounce', 'MessageID' => $this->providerMessageId, 'Email' => 'pat@example.com'])->assertOk();
    processDeliveryEvents();

    expect($this->message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($this->customer->refresh()->isEmailSuppressed())->toBeFalse();
});

test('30. a delivery report for a message we do not have is ignored', function () {
    postDeliveryEvent(deliveryReport('Delivery', 'unknown-message-id', 'pat@example.com'))->assertOk();
    processDeliveryEvents();

    expect(lastDeliveryEvent()->status->value)->toBe('ignored')
        ->and(lastDeliveryEvent()->organization_id)->toBeNull();
});

test('31. status moves only along the documented transitions', function () {
    expect(MessageStatus::Queued->canTransitionTo(MessageStatus::Delivered))->toBeFalse()
        ->and(MessageStatus::Sent->canTransitionTo(MessageStatus::Delivered))->toBeTrue()
        ->and(MessageStatus::Delivered->canTransitionTo(MessageStatus::Sent))->toBeFalse()
        ->and(MessageStatus::Bounced->canTransitionTo(MessageStatus::Delivered))->toBeFalse()
        ->and(MessageStatus::Failed->canTransitionTo(MessageStatus::Delivered))->toBeFalse()
        ->and(MessageStatus::Failed->canTransitionTo(MessageStatus::Delivered, providerEvidence: true))->toBeTrue()
        ->and(MessageStatus::Received->canTransitionTo(MessageStatus::Delivered, providerEvidence: true))->toBeFalse();
});

test('32. a provider report reconciles an email recorded as failed after an unknown outcome', function () {
    $this->message->forceFill(['status' => MessageStatus::Failed, 'failed_at' => now(), 'failure_reason' => 'Outcome unknown.'])->save();

    postDeliveryEvent(deliveryReport('Delivery', $this->providerMessageId, 'pat@example.com'))->assertOk();
    $logs = captureLogs(fn () => processDeliveryEvents());

    expect($this->message->refresh()->status)->toBe(MessageStatus::Delivered)
        ->and($this->message->failed_at)->toBeNull()
        ->and($this->message->failure_reason)->toBeNull()
        ->and(collect($logs)->pluck('message'))->toContain('email.reconciled');
});

test('33. the organization comes from the message, never from the payload', function () {
    $other = teamBusiness('Claimed Other Org');

    postDeliveryEvent(deliveryReport('Delivery', $this->providerMessageId, 'pat@example.com', [
        'OrganizationId' => $other['organization']->id,
    ]))->assertOk();
    processDeliveryEvents();

    expect(lastDeliveryEvent()->organization_id)->toBe($this->organization->id)
        ->and($this->message->refresh()->organization_id)->toBe($this->organization->id);
});

test('34. delivery processing logs carry ids and status, never the recipient address', function () {
    postDeliveryEvent(deliveryReport('Delivery', $this->providerMessageId, 'pat@example.com'))->assertOk();
    $logs = captureLogs(fn () => processDeliveryEvents());

    expect(json_encode($logs))->not->toContain('pat@example.com')
        ->and(collect($logs)->pluck('message'))->toContain('email.delivered', 'webhook.processed');
});
