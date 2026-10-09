<?php

use App\Enums\MessageStatus;
use App\Jobs\ProcessInboundEmailJob;
use App\Models\Conversation;
use App\Models\EmailReplyRoute;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Services\Email\InboundEmailProcessor;
use App\Services\Email\ReplyRouteService;
use App\Services\Webhooks\WebhookReplayService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->withoutVite();
    configureEmailWebhooks();
    Queue::fake();
    $this->business = teamBusiness('Reply HVAC');
    $this->organization = $this->business['organization'];
    $this->customer = $this->business['customer'];
    $this->conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->organization->id, 'subject' => 'AC']);
    $this->replyTo = app(ReplyRouteService::class)->createFor($this->conversation);
});

/**
 * A Postmark inbound payload addressed to a reply route.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function inboundReply(string $to, string $from = 'pat@example.com', array $overrides = []): array
{
    return array_merge([
        'FromName' => 'Pat Customer',
        'From' => $from,
        'FromFull' => ['Email' => $from, 'Name' => 'Pat Customer', 'MailboxHash' => ''],
        'To' => $to,
        'ToFull' => [['Email' => $to, 'Name' => '', 'MailboxHash' => '']],
        'Cc' => '', 'CcFull' => [], 'Bcc' => '', 'BccFull' => [],
        'OriginalRecipient' => $to,
        'Subject' => 'Re: AC',
        'MessageID' => 'inbound-'.uniqid(),
        'Date' => 'Fri, 2 Oct 2026 10:15:00 -0500',
        'TextBody' => 'Yes please.',
        'HtmlBody' => '<p>Yes please.</p>',
        'StrippedTextReply' => 'Yes please.',
        'Headers' => [],
        'Attachments' => [],
    ], $overrides);
}

/**
 * POST an inbound reply with the inbound credentials (or the given ones).
 */
function postInbound(array $payload, ?string $user = 'postmark', ?string $secret = 'inbound-secret', array $headers = []): TestResponse
{
    $auth = $user === null ? [] : ['Authorization' => 'Basic '.base64_encode("{$user}:{$secret}")];

    return test()->postJson(route('webhooks.email.inbound', ['provider' => 'postmark']), $payload, $auth + $headers);
}

function processInboundEvents(): void
{
    WebhookEvent::query()->where('event_type', WebhookEvent::TYPE_INBOUND_EMAIL)->orderBy('id')->get()
        ->each(fn (WebhookEvent $event) => app(InboundEmailProcessor::class)->process($event));
}

test('21. a valid reply token routes the reply to its conversation', function () {
    postInbound(inboundReply($this->replyTo))->assertOk();
    processInboundEvents();

    $message = Message::query()->where('direction', 'inbound')->sole();
    expect($message->conversation_id)->toBe($this->conversation->id)
        ->and($message->organization_id)->toBe($this->organization->id)
        ->and($message->status)->toBe(MessageStatus::Received);
});

test('22. an unknown reply token is rejected: nothing is stored against any conversation', function () {
    postInbound(inboundReply('reply+'.str_repeat('f', 40).'@inbound.quoteflow.ai'))->assertOk();
    processInboundEvents();

    expect(Message::query()->where('direction', 'inbound')->count())->toBe(0)
        ->and(WebhookEvent::sole()->status->value)->toBe('ignored');
});

test('23. an expired reply token is rejected', function () {
    EmailReplyRoute::query()->update(['expires_at' => now()->subDay()]);

    postInbound(inboundReply($this->replyTo))->assertOk();
    processInboundEvents();

    expect(Message::query()->where('direction', 'inbound')->count())->toBe(0);
});

test('24. a reply from an unexpected sender is held for review and not attached to the conversation', function () {
    postInbound(inboundReply($this->replyTo, from: 'stranger@example.com'))->assertOk();
    processInboundEvents();

    $message = Message::query()->where('direction', 'inbound')->sole();
    expect($message->status)->toBe(MessageStatus::NeedsReview)
        ->and($message->conversation_id)->toBeNull()
        ->and($message->organization_id)->toBe($this->organization->id);
});

test('25. a reply that names another organization\'s customer still lands only in the token\'s organization', function () {
    $other = teamBusiness('Other Plumbing');
    // A distinct address, so the sender really is the other tenant's customer.
    $otherCustomer = tap($other['customer'])->update(['email' => 'rival@example.com']);

    // The sender matches a customer of another tenant; the token belongs to ours.
    postInbound(inboundReply($this->replyTo, from: $otherCustomer->email))->assertOk();
    processInboundEvents();

    $message = Message::query()->where('direction', 'inbound')->sole();
    expect($message->organization_id)->toBe($this->organization->id)
        ->and($message->conversation_id)->toBeNull()
        ->and(WebhookEvent::sole()->organization_id)->toBe($this->organization->id);
});

test('26. a redelivered email is acknowledged and stored once', function () {
    $payload = inboundReply($this->replyTo);

    postInbound($payload)->assertOk()->assertJson(['message' => 'Accepted.']);
    postInbound($payload)->assertOk()->assertJson(['message' => 'Already received.']);
    processInboundEvents();

    expect(Message::query()->where('direction', 'inbound')->count())->toBe(1)
        ->and(WebhookEvent::query()->where('event_type', WebhookEvent::TYPE_INBOUND_EMAIL)->count())->toBe(1);
});

test('27. a redelivery of a failed email does not reprocess it automatically; an operator replays it', function () {
    $payload = inboundReply($this->replyTo);
    postInbound($payload)->assertOk();
    $event = WebhookEvent::sole();
    $event->forceFill(['status' => 'failed', 'failed_at' => now(), 'failure_reason' => 'Processing failed.'])->save();
    Queue::fake();

    postInbound($payload)->assertOk()->assertJson(['message' => 'Already received.']);
    Queue::assertNotPushed(ProcessInboundEmailJob::class);

    app(WebhookReplayService::class)->replay($event->id);
    processInboundEvents();

    expect(Message::query()->where('direction', 'inbound')->count())->toBe(1)
        ->and($event->refresh()->status->value)->toBe('processed');
});

test('28. malicious HTML in a reply is sanitized before it is stored', function () {
    postInbound(inboundReply($this->replyTo, overrides: [
        'HtmlBody' => '<p onclick="steal()">Hi</p><script>alert(1)</script><a href="javascript:alert(2)">click</a><iframe src="https://evil.example"></iframe>',
    ]))->assertOk();
    processInboundEvents();

    $html = (string) Message::query()->where('direction', 'inbound')->value('body_html');
    expect($html)->toContain('Hi')
        ->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('javascript:')
        ->not->toContain('<iframe');
});

test('29. remote images and script URLs in a reply cannot run or load', function () {
    postInbound(inboundReply($this->replyTo, overrides: [
        'HtmlBody' => '<img src="https://tracker.example/pixel.gif" onerror="x()"><a href="data:text/html,<script>alert(3)</script>">x</a>',
    ]))->assertOk();
    processInboundEvents();

    $html = (string) Message::query()->where('direction', 'inbound')->value('body_html');
    expect($html)->not->toContain('<img')->not->toContain('onerror')->not->toContain('data:text/html')->not->toContain('<script');
});

test('30. a request that is not JSON is refused and logged without its body', function () {
    $logs = captureLogs(function () {
        $this->call('POST', route('webhooks.email.inbound', ['provider' => 'postmark']), [], [], [], [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode('postmark:inbound-secret'),
            'CONTENT_TYPE' => 'text/plain',
        ], 'SECRET-BODY-'.$this->replyTo)->assertStatus(415);
    });

    $rejected = collect($logs)->firstWhere('message', 'webhook.rejected');
    expect($rejected['context']['reason'])->toBe('unsupported_content_type')
        ->and(json_encode($logs))->not->toContain('SECRET-BODY')
        ->and(json_encode($logs))->not->toContain($this->replyTo);
    expect(WebhookEvent::query()->count())->toBe(0);
});

test('31. a malformed inbound payload is refused and logged with a reason code', function () {
    $logs = captureLogs(fn () => postInbound(['From' => 'nope'])->assertStatus(422));

    expect(collect($logs)->firstWhere('message', 'webhook.rejected')['context']['reason'])->toBe('malformed_payload')
        ->and(WebhookEvent::query()->count())->toBe(0);
});

test('32. a wrong inbound password is refused and the password is never logged', function () {
    $logs = captureLogs(fn () => postInbound(inboundReply($this->replyTo), 'postmark', 'wrong-password-value')->assertUnauthorized());

    expect(collect($logs)->firstWhere('message', 'webhook.rejected')['context']['reason'])->toBe('authentication_failed')
        ->and(json_encode($logs))->not->toContain('wrong-password-value')
        ->and(json_encode($logs))->not->toContain('inbound-secret');
});

test('33. the request ID is carried into the event, the processing log and the reply', function () {
    postInbound(inboundReply($this->replyTo), headers: ['X-Request-Id' => 'req-abc123def456'])->assertOk();
    $logs = captureLogs(fn () => processInboundEvents());

    expect(WebhookEvent::sole()->correlation_id)->toBe('req-abc123def456')
        ->and(collect($logs)->firstWhere('message', 'webhook.processed')['context']['correlation_id'])->toBe('req-abc123def456');
});

test('34. an unsafe request ID is replaced by a fresh one, never echoed', function () {
    postInbound(inboundReply($this->replyTo), headers: ['X-Request-Id' => "bad id\nwith-injection"])->assertOk();

    $correlation = WebhookEvent::sole()->correlation_id;
    expect($correlation)->not->toContain("\n")->not->toContain(' ')->toHaveLength(36);
});

test('35. a failed inbound email shows up in the failure listing with its reason and attempts', function () {
    postInbound(inboundReply($this->replyTo))->assertOk();
    $event = WebhookEvent::sole();
    // Routed events carry their organization; the listing filters on it.
    $event->forceFill(['status' => 'failed', 'failed_at' => now(), 'failure_reason' => 'Processing failed after several attempts.', 'attempt_count' => 5, 'organization_id' => $this->organization->id])->save();

    $this->artisan('webhooks:failed', ['--organization' => $this->organization->id])
        ->expectsOutputToContain('Processing failed after several attempts.')
        ->assertSuccessful();
});
