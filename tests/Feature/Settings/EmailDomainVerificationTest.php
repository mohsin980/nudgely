<?php

namespace Tests\Feature\Settings;

use App\Enums\EmailVerificationStatus;
use App\Livewire\Settings\EmailSettings;
use App\Models\EmailConnection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Drives the settings page through the real Postmark provider with faked HTTP.
 */
class EmailDomainVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'pm-account-token-never-shown';

    private User $admin;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'email.provider' => 'postmark',
            'email.providers.postmark.account_token' => self::TOKEN,
        ]);

        $this->admin = User::factory()->admin()->create();
        $this->organization = $this->admin->organization;
    }

    private function settings(): Testable
    {
        return Livewire::actingAs($this->admin)->test(EmailSettings::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function postmarkDomain(array $overrides = []): array
    {
        return array_merge([
            'ID' => 36735,
            'Name' => 'example.com',
            'DKIMVerified' => false,
            'DKIMHost' => '',
            'DKIMTextValue' => '',
            'DKIMPendingHost' => '20261001pm._domainkey.example.com',
            'DKIMPendingTextValue' => 'k=rsa;p=MIGfPUBLICKEY',
            'ReturnPathDomain' => 'pm-bounces.example.com',
            'ReturnPathDomainVerified' => false,
            'ReturnPathDomainCNAMEValue' => 'pm.mtasv.net',
        ], $overrides);
    }

    private function pendingConnection(array $attributes = []): EmailConnection
    {
        return EmailConnection::factory()->for($this->organization)
            ->create(['domain' => 'example.com', 'sender_email' => 'sales@example.com', 'sender_name' => 'Dallas Cooling', ...$attributes]);
    }

    private function registeredConnection(): EmailConnection
    {
        $connection = $this->pendingConnection();
        $connection->forceFill([
            'provider_domain_id' => '36735',
            'dns_records' => [
                ['type' => 'TXT', 'name' => '20261001pm._domainkey.example.com', 'value' => 'k=rsa;p=MIGfPUBLICKEY', 'purpose' => 'DKIM', 'priority' => null, 'verified' => false],
                ['type' => 'CNAME', 'name' => 'pm-bounces.example.com', 'value' => 'pm.mtasv.net', 'purpose' => 'Return-Path', 'priority' => null, 'verified' => false],
            ],
        ])->save();

        return $connection;
    }

    // Display

    public function test_verify_button_appears_for_a_pending_connection(): void
    {
        $connection = $this->pendingConnection();

        $this->settings()
            ->assertSee('Your domain has not been verified yet.')
            ->assertSeeHtml('wire:click="startVerification('.$connection->id.')"')
            ->assertSee('Verify Domain')
            ->assertDontSee('Check Verification');

        Http::assertNothingSent();
    }

    public function test_registered_connection_offers_dns_records_and_check_verification(): void
    {
        $this->registeredConnection();

        $this->settings()
            ->assertSee('View DNS Records')
            ->assertSee('Check Verification')
            ->assertDontSee('k=rsa;p=MIGfPUBLICKEY');
    }

    public function test_verified_connection_shows_verified_state_only(): void
    {
        EmailConnection::factory()->for($this->organization)->verified()->create();

        $this->settings()
            ->assertSee('Verified')
            ->assertDontSee('Verify Domain')
            ->assertDontSee('Check Verification');
    }

    public function test_failed_connection_shows_a_safe_error(): void
    {
        $connection = $this->pendingConnection();
        $connection->forceFill([
            'verification_status' => EmailVerificationStatus::Failed,
            'verification_error' => 'We couldn\'t connect your domain. Please check that the domain is correct and try again.',
        ])->save();

        $this->settings()
            ->assertSee('Verification Failed')
            ->assertSee('Please check that the domain is correct and try again.')
            ->assertSee('Try Again');
    }

    // Registration

    public function test_verify_domain_registers_the_domain_and_displays_dns_records(): void
    {
        Http::fake([
            'api.postmarkapp.com/domains' => Http::response($this->postmarkDomain()),
            'api.postmarkapp.com/domains/36735' => Http::response($this->postmarkDomain()),
        ]);
        $connection = $this->pendingConnection();

        $component = $this->settings()
            ->call('startVerification', $connection->id)
            ->assertSet('showingDnsRecordsFor', $connection->id)
            ->assertSet('statusType', 'info')
            ->assertSee('Add these DNS records at the DNS provider for')
            ->assertSee('DNS changes can take some time to propagate.')
            ->assertSee('20261001pm._domainkey.example.com')
            ->assertSee('k=rsa;p=MIGfPUBLICKEY')
            ->assertSee('pm-bounces.example.com')
            ->assertSee('pm.mtasv.net')
            ->assertSee('Check Verification')
            ->assertDontSee(self::TOKEN);

        $this->assertStringNotContainsString(self::TOKEN, json_encode($component->snapshot));

        $connection->refresh();
        $this->assertSame('36735', $connection->provider_domain_id);
        $this->assertCount(2, $connection->dns_records);
        $this->assertSame(EmailVerificationStatus::Pending, $connection->verification_status);
        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Postmark-Account-Token', self::TOKEN));
    }

    public function test_dns_records_can_be_toggled_without_provider_calls(): void
    {
        $connection = $this->registeredConnection();

        $this->settings()
            ->call('toggleDnsRecords', $connection->id)
            ->assertSee('k=rsa;p=MIGfPUBLICKEY')
            ->assertSee('Hide DNS Records')
            ->call('toggleDnsRecords', $connection->id)
            ->assertDontSee('k=rsa;p=MIGfPUBLICKEY');

        Http::assertNothingSent();
    }

    // Verification

    public function test_check_verification_marks_the_domain_verified(): void
    {
        $verified = $this->postmarkDomain([
            'DKIMVerified' => true, 'DKIMHost' => '20261001pm._domainkey.example.com', 'DKIMTextValue' => 'k=rsa;p=MIGfPUBLICKEY',
            'DKIMPendingHost' => '', 'DKIMPendingTextValue' => '', 'ReturnPathDomainVerified' => true,
        ]);
        Http::fake(['api.postmarkapp.com/domains/36735/*' => Http::response($verified)]);
        $connection = $this->registeredConnection();

        $this->settings()
            ->call('checkVerification', $connection->id)
            ->assertSet('statusMessage', 'Your domain is verified.')
            ->assertSee('Verified')
            ->assertDontSee('Check Verification');

        $connection->refresh();
        $this->assertSame(EmailVerificationStatus::Verified, $connection->verification_status);
        $this->assertNotNull($connection->verified_at);
    }

    public function test_check_verification_keeps_an_unverified_domain_pending(): void
    {
        Http::fake(['api.postmarkapp.com/domains/36735/*' => Http::response($this->postmarkDomain())]);
        $connection = $this->registeredConnection();

        $this->settings()
            ->call('checkVerification', $connection->id)
            ->assertSet('statusType', 'info')
            ->assertSee('DNS changes can take some time to propagate')
            ->assertSee('Not detected yet');

        $connection->refresh();
        $this->assertSame(EmailVerificationStatus::Pending, $connection->verification_status);
        $this->assertNull($connection->verified_at);
    }

    public function test_provider_errors_are_shown_safely(): void
    {
        Http::fake(['*' => Http::response(['ErrorCode' => 999, 'Message' => 'Internal failure at node pm-7 token='.self::TOKEN], 500)]);
        $connection = $this->registeredConnection();

        $this->settings()
            ->call('checkVerification', $connection->id)
            ->assertSet('statusType', 'error')
            ->assertSet('statusMessage', 'We couldn\'t reach our email provider right now. Please try again in a few minutes.')
            ->assertDontSee('Internal failure')
            ->assertDontSee(self::TOKEN);

        $this->assertSame(EmailVerificationStatus::Pending, $connection->fresh()->verification_status);
    }

    public function test_missing_credentials_are_shown_safely(): void
    {
        config(['email.providers.postmark.account_token' => null]);
        Http::fake();
        $connection = $this->pendingConnection();

        $this->settings()
            ->call('startVerification', $connection->id)
            ->assertSet('statusMessage', 'Domain verification isn\'t available right now. Please contact support.');

        Http::assertNothingSent();
        $this->assertNull($connection->fresh()->provider_domain_id);
    }

    // Authorization

    public function test_cannot_verify_or_view_another_organizations_connection(): void
    {
        Http::fake();
        $foreign = EmailConnection::factory()->create(['provider_domain_id' => '999', 'dns_records' => []]);

        $this->settings()->call('startVerification', $foreign->id)->assertNotFound();
        $this->settings()->call('checkVerification', $foreign->id)->assertNotFound();
        $this->settings()->call('toggleDnsRecords', $foreign->id)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_non_admin_cannot_verify(): void
    {
        Http::fake();
        $connection = $this->registeredConnection();
        $member = User::factory()->for($this->organization)->create();

        foreach (['startVerification', 'checkVerification', 'toggleDnsRecords'] as $action) {
            // Mount as admin, then act as a member: the action itself must be authorized server-side.
            $component = $this->settings();
            $this->actingAs($member);

            $component->call($action, $connection->id)->assertForbidden();
        }

        Http::assertNothingSent();
    }

    // Editing and deleting registered connections

    public function test_changing_the_domain_clears_verification_and_removes_the_old_provider_domain(): void
    {
        Http::fake(['api.postmarkapp.com/domains/36735' => Http::response(['ErrorCode' => 0, 'Message' => 'Domain removed.'])]);
        $connection = $this->registeredConnection();
        $connection->forceFill(['verification_status' => EmailVerificationStatus::Verified, 'verified_at' => now()])->save();

        $this->settings()
            ->call('edit', $connection->id)
            ->set('domain', 'newexample.com')
            ->set('senderEmail', 'sales@newexample.com')
            ->call('save')
            ->assertHasNoErrors();

        $connection->refresh();
        $this->assertNull($connection->provider_domain_id);
        $this->assertNull($connection->dns_records);
        $this->assertNull($connection->verified_at);
        $this->assertSame(EmailVerificationStatus::Pending, $connection->verification_status);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/domains/36735'));
    }

    public function test_changing_sender_email_to_another_domain_is_rejected(): void
    {
        $connection = $this->registeredConnection();

        $this->settings()
            ->call('edit', $connection->id)
            ->set('senderEmail', 'sales@gmail.com')
            ->call('save')
            ->assertHasErrors(['senderEmail']);

        $this->assertSame('36735', $connection->fresh()->provider_domain_id);
    }

    public function test_provider_failure_while_removing_old_domain_keeps_local_state_consistent(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        $connection = $this->registeredConnection();

        $this->settings()
            ->call('edit', $connection->id)
            ->set('domain', 'newexample.com')
            ->set('senderEmail', 'sales@newexample.com')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Email connection updated successfully.');

        $this->assertNull($connection->fresh()->provider_domain_id);
    }

    public function test_deleting_a_registered_connection_removes_its_provider_domain(): void
    {
        Http::fake(['api.postmarkapp.com/domains/36735' => Http::response(['ErrorCode' => 0, 'Message' => 'Domain removed.'])]);
        $connection = $this->registeredConnection();

        $this->settings()
            ->call('confirmDelete', $connection->id)
            ->call('delete');

        $this->assertModelMissing($connection);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
    }
}
