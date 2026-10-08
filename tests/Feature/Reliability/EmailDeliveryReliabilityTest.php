<?php

use App\Enums\Billing\LimitKey;
use App\Enums\MessageStatus;
use App\Exceptions\Email\EmailProviderException;
use App\Jobs\SendEmailJob;
use App\Models\Message;
use App\Services\Billing\UsageService;
use App\Services\Email\EmailService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->provider = fakeEmailProvider();
});

function runSendJob(int $messageId): void
{
    (new SendEmailJob($messageId))->handle(app(EmailService::class));
}

test('a retried send job does not email the customer twice', function () {
    $message = Message::factory()->create();

    runSendJob($message->id);
    runSendJob($message->id); // the queue delivers the same job again

    expect($this->provider->sent)->toHaveCount(1)
        ->and($message->fresh()->status)->toBe(MessageStatus::Sent);
});

test('the provider message id and sent time are stored once the provider accepts the email', function () {
    $message = Message::factory()->create();

    runSendJob($message->id);

    $message->refresh();
    expect($message->provider_message_id)->toBe('fake-message-1')
        ->and($message->sent_at)->not->toBeNull()
        ->and($message->failure_reason)->toBeNull();
});

test('a temporary provider failure is retried by the queue and then sent once', function () {
    $message = Message::factory()->create();
    $this->provider->sendFailures = [EmailProviderException::unavailable('Provider is down.')];

    expect(fn () => runSendJob($message->id))->toThrow(EmailProviderException::class);
    expect($message->fresh()->status)->toBe(MessageStatus::Queued);

    runSendJob($message->id);

    expect($this->provider->sent)->toHaveCount(1)->and($message->fresh()->status)->toBe(MessageStatus::Sent);
});

test('a permanent provider rejection is recorded with its reason and is not retried', function () {
    $message = Message::factory()->create();
    $this->provider->sendFailures = [EmailProviderException::emailRejected('Recipient blocked.')];

    // No exception: the queue must not retry a message the provider will never accept.
    runSendJob($message->id);

    $message->refresh();
    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->failed_at)->not->toBeNull()
        ->and($message->failure_reason)->toBe('The email could not be accepted by the provider.');
});

test('an unknown provider failure is never resent, because the provider may already have accepted it', function () {
    $message = Message::factory()->create();
    $this->provider->sendFailures = [new RuntimeException('Connection reset after the request was sent.')];

    runSendJob($message->id);
    runSendJob($message->id);

    $message->refresh();
    expect($this->provider->sent)->toHaveCount(0)
        ->and($message->status)->toBe(MessageStatus::Failed)
        ->and($message->failure_reason)->toContain('not resent');
});

test('a message left "sending" by a crashed worker is marked failed and never resent', function () {
    $message = Message::factory()->create(['status' => MessageStatus::Sending]);
    DB::table('messages')->where('id', $message->id)->update(['updated_at' => now()->subMinutes(30)]);

    expect(app(EmailService::class)->recoverStuckSends(15))->toBe(1);

    $message->refresh();
    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->failure_reason)->toContain('not resent')
        ->and($this->provider->sent)->toHaveCount(0);

    // Running it again changes nothing.
    expect(app(EmailService::class)->recoverStuckSends(15))->toBe(0);
});

test('an email that is still being sent is left alone by the recovery sweep', function () {
    $message = Message::factory()->create(['status' => MessageStatus::Sending]);

    expect(app(EmailService::class)->recoverStuckSends(15))->toBe(0)
        ->and($message->fresh()->status)->toBe(MessageStatus::Sending);
});

test('the recovery sweep never touches messages that were already sent', function () {
    $message = Message::factory()->create(['status' => MessageStatus::Sent, 'provider_message_id' => 'pm-1']);
    DB::table('messages')->where('id', $message->id)->update(['updated_at' => now()->subDay()]);

    expect(app(EmailService::class)->recoverStuckSends(15))->toBe(0)
        ->and($message->fresh()->status)->toBe(MessageStatus::Sent);
});

test('retries do not count against the monthly email limit twice', function () {
    $message = Message::factory()->create();
    $organization = $message->organization;
    $this->provider->sendFailures = [EmailProviderException::unavailable('down')];

    expect(fn () => runSendJob($message->id))->toThrow(EmailProviderException::class);
    runSendJob($message->id);
    runSendJob($message->id);

    expect(app(UsageService::class)->usage($organization, LimitKey::OutboundEmails))->toBe(1);
});

test('a send job for a message that no longer exists does nothing and does not crash', function () {
    runSendJob(987654);

    expect($this->provider->sent)->toHaveCount(0);
});

test('a send request that timed out is not retried, because the provider may already have accepted it', function () {
    $message = Message::factory()->create();
    $this->provider->sendFailures = [EmailProviderException::sendOutcomeUnknown('Postmark send email request timed out.')];

    runSendJob($message->id);
    runSendJob($message->id);

    $message->refresh();
    expect($message->status)->toBe(MessageStatus::Failed)
        ->and($message->failure_reason)->toContain('not resent')
        ->and($this->provider->sent)->toHaveCount(0);
});
