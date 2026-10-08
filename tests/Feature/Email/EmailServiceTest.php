<?php

namespace Tests\Feature\Email;

use App\Enums\EmailVerificationStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Exceptions\Email\EmailProviderException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Jobs\SendEmailJob;
use App\Models\EmailConnection;
use App\Models\Message;
use App\Models\Organization;
use App\Services\Email\EmailProviderManager;
use App\Services\Email\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

class EmailServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeEmailProvider $provider;

    private EmailService $emails;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $provider = $this->provider = new FakeEmailProvider;
        app(EmailProviderManager::class)->extend('postmark', fn () => $provider);
        $this->emails = app(EmailService::class);
        $this->organization = Organization::factory()->create();
    }

    private function defaultSender(?Organization $organization = null, string $status = 'verified'): EmailConnection
    {
        $connection = EmailConnection::factory()->for($organization ?? $this->organization)->create([
            'domain' => 'example.com',
            'sender_email' => 'sales@example.com',
            'sender_name' => 'Dallas Cooling',
            'is_default' => true,
        ]);
        $connection->forceFill(['verification_status' => $status, 'verified_at' => $status === 'verified' ? now() : null])->save();

        return $connection;
    }

    private function queue(?Organization $organization = null, array $overrides = []): Message
    {
        return $this->emails->send(...array_merge([
            'organization' => $organization ?? $this->organization,
            'to' => 'customer@gmail.com',
            'subject' => 'Your estimate',
            'html' => '<p>Hi</p>',
            'text' => 'Hi',
        ], $overrides));
    }

    // Sending through the verified business sender

    public function test_it_queues_and_sends_using_the_verified_business_sender(): void
    {
        Queue::fake();
        $connection = $this->defaultSender();

        $message = $this->queue(overrides: ['replyTo' => 'reply+token123@inbound.quoteflow.ai', 'toName' => 'Pat']);

        Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->messageId === $message->id);
        $this->assertSame(MessageStatus::Queued, $message->status);
        $this->assertSame(MessageDirection::Outbound, $message->direction);
        $this->assertSame(MessageChannel::Email, $message->channel);
        $this->assertSame($connection->id, $message->email_connection_id);

        $result = $this->emails->deliver($message);

        $this->assertTrue($result->successful());
        $email = $this->provider->sent[0];
        $this->assertSame('sales@example.com', $email->fromEmail);
        $this->assertSame('Dallas Cooling', $email->fromName);
        $this->assertSame('customer@gmail.com', $email->toEmail);
        $this->assertSame('Pat', $email->toName);
        $this->assertSame('reply+token123@inbound.quoteflow.ai', $email->replyTo);
        $this->assertSame('<p>Hi</p>', $email->htmlBody);
        $this->assertSame('Hi', $email->textBody);
        $this->assertSame((string) $message->id, $email->metadata['message_id']);

        $message->refresh();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('fake-message-1', $message->provider_message_id);
        $this->assertNotNull($message->sent_at);
    }

    public function test_no_reply_to_is_invented(): void
    {
        Queue::fake();
        $this->defaultSender();

        $this->emails->deliver($this->queue());

        $this->assertNull($this->provider->sent[0]->replyTo);
    }

    public function test_subject_newlines_are_removed(): void
    {
        Queue::fake();
        $this->defaultSender();

        $message = $this->queue(overrides: ['subject' => "Hello\r\nBcc: someone@example.org"]);

        $this->assertSame('Hello  Bcc: someone@example.org', $message->subject);
    }

    // Eligibility

    public function test_it_rejects_an_organization_without_a_sender(): void
    {
        Queue::fake();

        $this->expectExceptionObject(EmailSendingNotAllowedException::noSender());

        $this->queue();
    }

    public function test_it_rejects_a_pending_connection(): void
    {
        Queue::fake();
        $this->defaultSender(status: 'pending');

        $this->expectExceptionObject(EmailSendingNotAllowedException::notVerified());

        $this->queue();
    }

    public function test_it_rejects_a_failed_connection(): void
    {
        Queue::fake();
        $this->defaultSender(status: 'failed');

        try {
            $this->queue();
            $this->fail('Expected exception.');
        } catch (EmailSendingNotAllowedException $e) {
            $this->assertSame('Your business email domain must be verified before emails can be sent.', $e->getMessage());
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_it_rejects_a_sender_outside_the_verified_domain(): void
    {
        Queue::fake();
        $connection = $this->defaultSender();
        // Bypass the model's verification reset to simulate inconsistent data.
        EmailConnection::query()->whereKey($connection->id)->update(['sender_email' => 'sales@otherdomain.com']);

        $this->expectExceptionObject(EmailSendingNotAllowedException::senderMismatch());

        $this->queue();
    }

    public function test_it_rejects_invalid_recipient_and_reply_to(): void
    {
        Queue::fake();
        $this->defaultSender();

        foreach ([['to' => 'not-an-email'], ['replyTo' => 'nope']] as $overrides) {
            try {
                $this->queue(overrides: $overrides);
                $this->fail('Expected exception.');
            } catch (EmailSendingNotAllowedException) {
            }
        }

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_it_only_uses_the_given_organizations_connection(): void
    {
        Queue::fake();
        $otherOrganization = Organization::factory()->create();
        $this->defaultSender($otherOrganization);

        try {
            $this->queue();
            $this->fail('Expected exception.');
        } catch (EmailSendingNotAllowedException) {
        }

        $mine = $this->defaultSender();
        $message = $this->queue();

        $this->assertSame($this->organization->id, $message->organization_id);
        $this->assertSame($mine->id, $message->email_connection_id);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_a_connection_from_another_organization_is_never_used_for_delivery(): void
    {
        $foreign = $this->defaultSender(Organization::factory()->create());
        $message = withoutTenantTriggers('messages', fn () => Message::factory()->create(['organization_id' => $this->organization->id, 'email_connection_id' => $foreign->id, 'from_address' => 'sales@example.com']));

        $result = $this->emails->deliver($message);

        $this->assertFalse($result->successful());
        $this->assertSame([], $this->provider->sent);
        $this->assertSame(MessageStatus::Failed, $message->fresh()->status);
    }

    // Delivery re-checks and idempotency

    public function test_delivery_rechecks_verification(): void
    {
        Queue::fake();
        $connection = $this->defaultSender();
        $message = $this->queue();

        $connection->forceFill(['verification_status' => EmailVerificationStatus::Pending, 'verified_at' => null])->save();
        $result = $this->emails->deliver($message);

        $this->assertFalse($result->successful());
        $this->assertSame([], $this->provider->sent);
        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertSame('Your business email domain must be verified before emails can be sent.', $message->failure_reason);
        $this->assertNotNull($message->failed_at);
    }

    public function test_delivery_fails_when_the_connection_was_removed(): void
    {
        Queue::fake();
        $connection = $this->defaultSender();
        $message = $this->queue();

        $connection->delete();
        $this->emails->deliver($message->fresh());

        $this->assertSame(MessageStatus::Failed, $message->fresh()->status);
        $this->assertSame([], $this->provider->sent);
    }

    public function test_a_message_is_delivered_only_once(): void
    {
        Queue::fake();
        $this->defaultSender();
        $message = $this->queue();

        $this->emails->deliver($message);
        $second = $this->emails->deliver(Message::find($message->id));

        $this->assertCount(1, $this->provider->sent);
        $this->assertSame(MessageStatus::Sent, $second->status);
    }

    public function test_transient_failures_requeue_the_message_and_rethrow(): void
    {
        Queue::fake();
        $this->defaultSender();
        $message = $this->queue();
        $this->provider->sendFailures = [EmailProviderException::sendUnavailable('HTTP 503')];

        try {
            $this->emails->deliver($message);
            $this->fail('Expected exception.');
        } catch (EmailProviderException $e) {
            $this->assertTrue($e->isTransient());
        }

        $this->assertSame(MessageStatus::Queued, $message->fresh()->status);

        $this->emails->deliver($message);
        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
        $this->assertCount(1, $this->provider->sent);
    }

    public function test_permanent_provider_failures_are_recorded_without_rethrowing(): void
    {
        Queue::fake();
        $this->defaultSender();
        $message = $this->queue();
        $this->provider->sendFailures = [EmailProviderException::emailRejected('HTTP 422 (error code 406)')];

        $result = $this->emails->deliver($message);

        $this->assertSame(MessageStatus::Failed, $result->status);
        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertSame('The email could not be accepted by the provider.', $message->failure_reason);
        $this->assertNull($message->provider_message_id);
    }
}
