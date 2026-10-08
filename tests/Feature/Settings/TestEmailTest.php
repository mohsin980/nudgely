<?php

namespace Tests\Feature\Settings;

use App\Enums\EmailVerificationStatus;
use App\Enums\MessageStatus;
use App\Livewire\Settings\EmailSettings;
use App\Models\EmailConnection;
use App\Models\Organization;
use App\Models\User;
use App\Services\Email\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sends test emails through the real Postmark provider with faked HTTP.
 */
class TestEmailTest extends TestCase
{
    use RefreshDatabase;

    private const SERVER_TOKEN = 'pm-server-token-never-shown';

    private User $admin;

    private Organization $organization;

    /**
     * What the faked Postmark Email API answers with in the current test.
     *
     * @var \Closure(): mixed
     */
    private \Closure $postmark;

    protected function setUp(): void
    {
        parent::setUp();

        config(['email.provider' => 'postmark', 'email.providers.postmark.server_token' => self::SERVER_TOKEN]);
        $this->postmark = fn () => Http::response(['MessageID' => 'pm-msg-1', 'ErrorCode' => 0, 'Message' => 'OK']);
        Http::fake(['api.postmarkapp.com/email' => fn () => ($this->postmark)()]);

        $this->admin = User::factory()->admin()->create(['email' => 'owner@dallascooling.test']);
        $this->organization = $this->admin->organization;
    }

    private function settings(): Testable
    {
        return Livewire::actingAs($this->admin)->test(EmailSettings::class);
    }

    private function connection(EmailVerificationStatus $status = EmailVerificationStatus::Verified, ?Organization $organization = null): EmailConnection
    {
        $connection = EmailConnection::factory()->for($organization ?? $this->organization)->create([
            'domain' => 'example.com', 'sender_email' => 'sales@example.com', 'sender_name' => 'Dallas Cooling',
        ]);
        $connection->forceFill(['verification_status' => $status, 'verified_at' => $status === EmailVerificationStatus::Verified ? now() : null])->save();

        return $connection;
    }

    public function test_verified_connection_shows_sender_and_enabled_test_button(): void
    {
        $connection = $this->connection();

        $this->settings()
            ->assertSee('Dallas Cooling <sales@example.com>')
            ->assertSeeHtml('wire:click="openTestEmail('.$connection->id.')"')
            ->assertDontSeeHtml('wire:click="openTestEmail('.$connection->id.')"'."\n".'                                        disabled');
    }

    public function test_admin_can_send_a_test_email_from_the_business_sender(): void
    {
        $connection = $this->connection();

        $this->settings()
            ->call('openTestEmail', $connection->id)
            ->assertSet('testEmailConnectionId', $connection->id)
            ->assertSet('testRecipient', 'owner@dallascooling.test')
            ->assertSee('Test Email Address')
            ->set('testRecipient', 'Me@Inbox.test')
            ->call('sendTestEmail')
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Test email sent to me@inbox.test.')
            ->assertSet('testEmailConnectionId', null)
            ->assertDontSee(self::SERVER_TOKEN);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['From'] === '"Dallas Cooling" <sales@example.com>'
            && $request['To'] === 'me@inbox.test'
            && $request['Subject'] === EmailService::TEST_EMAIL_SUBJECT
            && $request['TextBody'] === EmailService::TEST_EMAIL_BODY
            && ! isset($request['ReplyTo']));

        $message = $this->organization->messages()->sole();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('pm-msg-1', $message->provider_message_id);
        $this->assertSame('sales@example.com', $message->from_address);
        $this->assertSame(['type' => 'test_email'], $message->metadata);
    }

    public function test_the_test_send_is_logged_without_secrets(): void
    {
        Log::spy();
        $connection = $this->connection();

        $this->settings()->call('openTestEmail', $connection->id)->call('sendTestEmail');

        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'Test email requested.'
            && $context['organization_id'] === $this->organization->id
            && $context['user_id'] === $this->admin->id
            && ! str_contains(json_encode($context), self::SERVER_TOKEN));
    }

    public function test_pending_and_failed_connections_cannot_send_test_emails(): void
    {
        foreach ([EmailVerificationStatus::Pending, EmailVerificationStatus::Failed] as $status) {
            $connection = $this->connection($status);

            $this->settings()
                ->assertSee('Send Test Email')
                ->assertSee('Only verified emails can send email or become the default sender.')
                ->call('openTestEmail', $connection->id)
                ->assertDontSee('Test Email Address')
                ->call('sendTestEmail')
                ->assertSet('statusType', 'error')
                ->assertSet('statusMessage', 'Your business email domain must be verified before emails can be sent.');

            $connection->delete();
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_invalid_recipient_is_rejected(): void
    {
        $connection = $this->connection();

        $this->settings()
            ->call('openTestEmail', $connection->id)
            ->set('testRecipient', 'not-an-email')
            ->call('sendTestEmail')
            ->assertHasErrors(['testRecipient'])
            ->assertSee('The test email address field must be a valid email address.');

        Http::assertNothingSent();
    }

    public function test_non_admin_cannot_send_test_emails(): void
    {
        $connection = $this->connection();
        $component = $this->settings()->call('openTestEmail', $connection->id);

        $this->actingAs(User::factory()->for($this->organization)->create());
        $component->call('sendTestEmail')->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_cannot_use_another_organizations_connection(): void
    {
        $foreign = $this->connection(organization: Organization::factory()->create());

        $this->settings()->call('openTestEmail', $foreign->id)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_test_emails_are_rate_limited_per_organization(): void
    {
        $connection = $this->connection();
        RateLimiter::clear('send-test-email:'.$this->organization->id);
        $component = $this->settings();

        for ($i = 0; $i < EmailSettings::TEST_EMAILS_PER_HOUR; $i++) {
            $component->call('openTestEmail', $connection->id)->call('sendTestEmail')->assertSet('statusType', 'success');
        }

        $component->call('openTestEmail', $connection->id)
            ->call('sendTestEmail')
            ->assertSet('statusType', 'error')
            ->assertSee('sent several test emails recently');

        Http::assertSentCount(EmailSettings::TEST_EMAILS_PER_HOUR);
    }

    public function test_provider_rejection_is_shown_safely(): void
    {
        $this->postmark = fn () => Http::response(['ErrorCode' => 400, 'Message' => 'Sender signature not defined for From address token='.self::SERVER_TOKEN], 422);
        $connection = $this->connection();

        $this->settings()
            ->call('openTestEmail', $connection->id)
            ->call('sendTestEmail')
            ->assertSet('statusType', 'error')
            ->assertSet('statusMessage', 'The email could not be accepted by the provider.')
            ->assertDontSee('Sender signature')
            ->assertDontSee(self::SERVER_TOKEN);

        $this->assertSame(MessageStatus::Failed, $this->organization->messages()->sole()->status);
    }

    public function test_provider_timeout_marks_the_test_email_failed_without_retrying(): void
    {
        $this->postmark = fn () => throw new ConnectionException('timed out');
        $connection = $this->connection();

        $this->settings()
            ->call('openTestEmail', $connection->id)
            ->call('sendTestEmail')
            ->assertSet('statusMessage', 'The email provider did not confirm delivery. It was not resent, to avoid sending it twice.');

        $this->assertSame(MessageStatus::Failed, $this->organization->messages()->sole()->status);
    }
}
