<?php

namespace Tests\Feature\Email;

use App\Enums\EmailVerificationStatus;
use App\Enums\MessageStatus;
use App\Exceptions\Email\EmailProviderException;
use App\Jobs\SendEmailJob;
use App\Models\EmailConnection;
use App\Models\Message;
use App\Services\Email\EmailProviderManager;
use App\Services\Email\EmailService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

class SendEmailJobTest extends TestCase
{
    use RefreshDatabase;

    private FakeEmailProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $provider = $this->provider = new FakeEmailProvider;
        app(EmailProviderManager::class)->extend('postmark', fn () => $provider);
    }

    private function runJob(Message $message): void
    {
        (new SendEmailJob($message->id))->handle(app(EmailService::class));
    }

    public function test_job_sends_the_message_and_records_the_result(): void
    {
        $message = Message::factory()->create();

        $this->runJob($message);

        $message->refresh();
        $this->assertCount(1, $this->provider->sent);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('fake-message-1', $message->provider_message_id);
        $this->assertNotNull($message->sent_at);
    }

    public function test_job_records_permanent_failures_without_retrying(): void
    {
        $message = Message::factory()->create();
        $this->provider->sendFailures = [EmailProviderException::emailRejected('HTTP 422')];

        // A permanent failure completes the job normally, so the queue will not retry it.
        $this->runJob($message);

        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertNotNull($message->failed_at);
        $this->assertSame('The email could not be accepted by the provider.', $message->failure_reason);
    }

    public function test_job_rechecks_verification_before_sending(): void
    {
        $message = Message::factory()->create();
        $message->emailConnection->forceFill(['verification_status' => EmailVerificationStatus::Failed])->save();

        $this->runJob($message);

        $this->assertSame([], $this->provider->sent);
        $this->assertSame(MessageStatus::Failed, $message->fresh()->status);
    }

    public function test_transient_failures_are_rethrown_so_the_queue_retries(): void
    {
        $message = Message::factory()->create();
        $this->provider->sendFailures = [EmailProviderException::sendUnavailable('timeout')];

        try {
            $this->runJob($message);
            $this->fail('Expected exception.');
        } catch (EmailProviderException) {
        }

        $this->assertSame(MessageStatus::Queued, $message->fresh()->status);

        // The retry succeeds.
        $this->runJob($message);
        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
    }

    public function test_retries_are_bounded(): void
    {
        $job = new SendEmailJob(1);

        $this->assertSame(5, $job->tries);
        $this->assertCount(4, $job->backoff());
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('1', $job->uniqueId());
    }

    public function test_exhausted_retries_mark_the_message_failed(): void
    {
        $message = Message::factory()->create();

        (new SendEmailJob($message->id))->failed(new RuntimeException('provider down'));

        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertSame('The email provider did not respond after several attempts.', $message->failure_reason);
    }

    public function test_exhausted_retries_never_overwrite_a_sent_message(): void
    {
        $message = Message::factory()->create();
        $this->runJob($message);

        (new SendEmailJob($message->id))->failed(new RuntimeException('late failure'));

        $this->assertSame(MessageStatus::Sent, $message->fresh()->status);
    }

    public function test_duplicate_jobs_send_one_email(): void
    {
        $message = Message::factory()->create();

        SendEmailJob::dispatch($message->id);
        SendEmailJob::dispatch($message->id);
        $this->runJob($message);

        $this->assertCount(1, $this->provider->sent);
    }

    public function test_job_is_a_no_op_for_deleted_messages(): void
    {
        $message = Message::factory()->create();
        $message->delete();

        $this->runJob($message);

        $this->assertSame([], $this->provider->sent);
    }

    public function test_end_to_end_through_postmark_with_faked_http(): void
    {
        app(EmailProviderManager::class)->forgetDrivers();
        app()->forgetInstance(EmailProviderManager::class);
        config(['email.providers.postmark.server_token' => 'server-token']);
        Http::fake(['api.postmarkapp.com/email' => Http::response(['MessageID' => 'pm-123', 'ErrorCode' => 0, 'Message' => 'OK'])]);
        $connection = EmailConnection::factory()->verified()->default()->create(['domain' => 'example.com', 'sender_email' => 'sales@example.com', 'sender_name' => 'Dallas Cooling']);

        Queue::fake();

        $message = app(EmailService::class)->send($connection->organization, 'customer@gmail.com', 'Hi', text: 'Hello');

        // Nothing is sent inside the request; the queued job does it.
        Http::assertNothingSent();
        Queue::assertPushed(SendEmailJob::class);
        $this->runJob($message);

        Http::assertSent(fn (Request $request) => $request['From'] === '"Dallas Cooling" <sales@example.com>');
        $this->assertSame('pm-123', $message->fresh()->provider_message_id);
    }
}
