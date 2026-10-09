<?php

use App\Enums\Billing\LimitKey;
use App\Enums\MessageStatus;
use App\Exceptions\Email\EmailProviderException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Jobs\SendEmailJob;
use App\Models\Conversation;
use App\Models\EmailConnection;
use App\Models\Message;
use App\Services\Billing\UsageService;
use App\Services\Email\EmailService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->business = teamBusiness('Reliable HVAC');
    $this->organization = $this->business['organization'];
    $this->customer = $this->business['customer'];
    $this->provider = fakeEmailProvider();
    $this->conversation = Conversation::factory()->for($this->customer)->create(['organization_id' => $this->organization->id, 'subject' => 'AC']);
    // Sends are driven by hand (deliverJob), so the job never runs during the dispatch itself.
    Queue::fake();
    config(['services.postmark.server_token' => 'server-token-must-never-leak']);
    config(['email.providers.postmark.server_token' => 'server-token-must-never-leak']);
});

function deliverJob(int $messageId): void
{
    (new SendEmailJob($messageId))->handle(app(EmailService::class));
}

test('1. a verified connection sends, and the message ends sent', function () {
    $message = app(EmailService::class)->sendToConversation($this->conversation, 'Estimate', '<p>Hi</p>', 'Hi');
    deliverJob($message->id);

    expect($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($message->sent_at)->not->toBeNull()
        ->and($this->provider->sent)->toHaveCount(1);
});

test('2. an unverified connection cannot send', function () {
    EmailConnection::query()->where('organization_id', $this->organization->id)->update(['verification_status' => 'failed']);

    expect(fn () => app(EmailService::class)->sendToConversation($this->conversation, 'Estimate', '<p>Hi</p>', 'Hi'))
        ->toThrow(EmailSendingNotAllowedException::class);
});

test('3. another organization\'s connection is rejected', function () {
    $other = teamBusiness('Other Plumbing');
    $otherConnection = EmailConnection::query()->where('organization_id', $other['organization']->id)->firstOrFail();

    expect(fn () => app(EmailService::class)->assertCanSendFrom($otherConnection, $this->organization->id))
        ->toThrow(EmailSendingNotAllowedException::class);
});

test('4. a provider success moves the message to sent with its provider ID and time', function () {
    $message = app(EmailService::class)->sendToConversation($this->conversation, 'Estimate', '<p>Hi</p>', 'Hi');
    deliverJob($message->id);

    $message->refresh();
    expect($message->status)->toBe(MessageStatus::Sent)
        ->and($message->provider_message_id)->toBe('fake-message-1')
        ->and($message->send_attempts)->toBe(1);
});

test('5. a temporary provider failure is retried by the queue, then sent once', function () {
    $message = app(EmailService::class)->sendToConversation($this->conversation, 'Estimate', '<p>Hi</p>', 'Hi');
    $this->provider->sendFailures = [EmailProviderException::unavailable('Provider is busy.')];

    expect(fn () => deliverJob($message->id))->toThrow(EmailProviderException::class);
    expect($message->refresh()->status)->toBe(MessageStatus::Queued);

    deliverJob($message->id);

    expect($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($this->provider->sent)->toHaveCount(1)
        ->and($message->send_attempts)->toBe(2);
});

test('6. a permanent provider rejection is recorded and never retried', function () {
    $message = app(EmailService::class)->sendToConversation($this->conversation, 'Estimate', '<p>Hi</p>', 'Hi');
    $this->provider->sendFailures = [EmailProviderException::emailRejected('Recipient blocked.')];

    // No exception: the queue must not retry it.
    deliverJob($message->id);
    deliverJob($message->id);

    expect($message->refresh()->status)->toBe(MessageStatus::Failed)
        ->and($message->failure_reason)->toBe('The email could not be accepted by the provider.')
        ->and($this->provider->sent)->toHaveCount(0)
        ->and($message->send_attempts)->toBe(1);
});

test('7. a duplicate send job never emails the customer twice', function () {
    $message = app(EmailService::class)->sendToConversation($this->conversation, 'Estimate', '<p>Hi</p>', 'Hi');

    deliverJob($message->id);
    deliverJob($message->id);
    deliverJob($message->id);

    expect($this->provider->sent)->toHaveCount(1);
});

test('8. a provider timeout is recorded as an unknown outcome and is not resent', function () {
    $message = app(EmailService::class)->sendToConversation($this->conversation, 'Estimate', '<p>Hi</p>', 'Hi');
    $this->provider->sendFailures = [EmailProviderException::sendOutcomeUnknown('Postmark send email request timed out.')];

    deliverJob($message->id);
    deliverJob($message->id);

    $message->refresh();
    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->failure_reason)->toContain('not resent')
        ->and($this->provider->sent)->toHaveCount(0);
});

test('9. the provider message ID is stored exactly as the provider returned it', function () {
    $message = app(EmailService::class)->sendToConversation($this->conversation, 'Estimate', '<p>Hi</p>', 'Hi');
    deliverJob($message->id);

    $stored = $message->refresh()->provider_message_id;
    expect($stored)->toBeString()->not->toBeEmpty()
        ->and($this->provider->sent[0]->metadata['message_id'])->toBe((string) $message->id)
        ->and(Message::query()->where('provider_message_id', $stored)->count())->toBe(1);
});

test('10. provider credentials never appear in logs, messages or the email row', function () {
    $logs = captureLogs(function () {
        $message = app(EmailService::class)->sendToConversation($this->conversation, 'Estimate', '<p>Hi</p>', 'Hi');
        $this->provider->sendFailures = [EmailProviderException::authenticationFailed('Postmark send email failed with HTTP 401.')];
        deliverJob($message->id);
    });

    $everything = json_encode($logs).json_encode(Message::query()->get()->toArray());
    expect($everything)->not->toContain('server-token-must-never-leak');
});

test('11. every send step logs the same correlation ID, with an event name and duration', function () {
    $logs = captureLogs(function () {
        $message = app(EmailService::class)->sendToConversation($this->conversation, 'Estimate', '<p>Hi</p>', 'Hi');
        deliverJob($message->id);
    });

    $events = collect($logs)->filter(fn ($log) => str_starts_with($log['message'], 'email.'))->values();
    $correlation = $events->first()['context']['correlation_id'];

    expect($events->pluck('message')->all())->toContain('email.queued', 'email.sending', 'email.sent')
        ->and($correlation)->not->toBeNull()
        ->and($events->pluck('context.correlation_id')->unique()->all())->toBe([$correlation])
        ->and(collect($logs)->firstWhere('message', 'email.sent')['context']['duration_ms'])->toBeFloat();
});

test('12. failed sends are logged with a reason code, never the body or the address', function () {
    $message = app(EmailService::class)->sendToConversation($this->conversation, 'Estimate', '<p>Secret body text</p>', 'Secret body text');
    $this->provider->sendFailures = [EmailProviderException::emailRejected('Recipient blocked.')];

    $logs = captureLogs(fn () => deliverJob($message->id));

    $failed = collect($logs)->firstWhere('message', 'email.failed');
    expect($failed['context']['reason'])->toBe('rejected')
        ->and(json_encode($logs))->not->toContain('Secret body text')
        ->and(json_encode($logs))->not->toContain('pat@example.com');
});

test('13. a retried send counts once against the monthly email limit', function () {
    $message = app(EmailService::class)->sendToConversation($this->conversation, 'Estimate', '<p>Hi</p>', 'Hi');
    $this->provider->sendFailures = [EmailProviderException::unavailable('down')];

    expect(fn () => deliverJob($message->id))->toThrow(EmailProviderException::class);
    deliverJob($message->id);
    deliverJob($message->id);

    expect(app(UsageService::class)->usage($this->organization, LimitKey::OutboundEmails))->toBe(1);
});

test('14. a bounced message still counts as sent, a failed one does not', function () {
    $bounced = app(EmailService::class)->sendToConversation($this->conversation, 'One', '<p>a</p>', 'a');
    deliverJob($bounced->id);
    $bounced->refresh()->forceFill(['status' => MessageStatus::Bounced])->save();

    $failed = app(EmailService::class)->sendToConversation($this->conversation, 'Two', '<p>b</p>', 'b');
    $this->provider->sendFailures = [EmailProviderException::emailRejected('no')];
    deliverJob($failed->id);

    expect(app(UsageService::class)->usage($this->organization, LimitKey::OutboundEmails))->toBe(1);
});
