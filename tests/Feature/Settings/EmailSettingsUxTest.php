<?php

namespace Tests\Feature\Settings;

use App\Enums\EmailVerificationStatus;
use App\Livewire\Settings\EmailSettings;
use App\Models\EmailConnection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The redesigned sender cards: what they show, which actions they offer and why one may be unavailable, the guided
 * verification states, and that every rule shown in the page is enforced again by the handlers.
 */
class EmailSettingsUxTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();
        config(['email.provider' => 'postmark', 'email.providers.postmark.server_token' => 'server-token', 'email.providers.postmark.account_token' => 'account-token']);

        $this->admin = User::factory()->admin()->create();
        $this->organization = $this->admin->organization;
    }

    private function settings(?User $user = null): Testable
    {
        return Livewire::actingAs($user ?? $this->admin)->test(EmailSettings::class);
    }

    private function sender(EmailVerificationStatus $status = EmailVerificationStatus::Verified, array $attributes = [], ?Organization $organization = null): EmailConnection
    {
        $factory = EmailConnection::factory()->for($organization ?? $this->organization);

        $factory = match ($status) {
            EmailVerificationStatus::Verified => $factory->verified(),
            default => $factory,
        };

        $connection = $factory->create($attributes + ['sender_email' => 'sales'.uniqid().'@example.com', 'domain' => 'example.com']);

        if ($status === EmailVerificationStatus::Failed) {
            $connection->forceFill(['verification_status' => $status, 'verification_error' => 'Please check that the domain is correct and try again.'])->save();
        }

        return $connection;
    }

    // Cards

    public function test_a_card_shows_name_address_domain_status_and_the_default_marker(): void
    {
        $this->sender(attributes: ['sender_name' => 'Dallas Cooling', 'sender_email' => 'sales@example.com', 'is_default' => true]);

        $this->settings()
            ->assertSeeInOrder(['Dallas Cooling', 'Default sender', 'Verified', 'sales@example.com', 'Domain:', 'example.com'])
            ->assertSee('Edit')
            ->assertSeeHtml('aria-label="More actions for sales@example.com"');
    }

    public function test_pending_failed_and_verified_states_are_distinguishable_by_text(): void
    {
        $this->sender(EmailVerificationStatus::Verified, ['sender_name' => 'Ok Sender']);
        $this->sender(EmailVerificationStatus::Pending, ['sender_name' => 'Waiting Sender']);
        $this->sender(EmailVerificationStatus::Failed, ['sender_name' => 'Broken Sender']);

        $this->settings()
            ->assertSee('Verified')->assertSee('Pending Verification')->assertSee('Verification Failed')
            ->assertSee('Your domain has not been verified yet.')
            ->assertSee('Your domain could not be verified.')
            ->assertSee('Please check that the domain is correct and try again.')
            ->assertSee('Check the domain spelling and your DNS records, then try again.');
    }

    public function test_long_addresses_and_dns_values_are_allowed_to_wrap_and_actions_stack_on_small_screens(): void
    {
        $long = str_repeat('a', 60);
        $this->sender(EmailVerificationStatus::Verified, ['sender_email' => "{$long}@example.com"]);

        $html = $this->settings()->html();

        $this->assertStringContainsString('break-all', $html);
        $this->assertStringContainsString('flex-col', $html);          // stacked below the large breakpoint
        $this->assertStringContainsString('sm:flex-none', $html);      // buttons share the row on phones, shrink on wider screens
        $this->assertStringContainsString('max-w-[calc(100vw-2rem)]', $html); // the menu never exceeds the viewport
    }

    public function test_the_actions_menu_is_labelled_and_disabled_items_explain_themselves(): void
    {
        $this->sender(EmailVerificationStatus::Pending, ['sender_email' => 'billing@example.com']);

        $this->settings()
            ->assertSeeHtml('aria-haspopup="menu"')
            ->assertSeeHtml('role="menu"')
            ->assertSeeHtml('role="menuitem"')
            ->assertSeeHtml('aria-disabled="true"')
            ->assertSee('Verify this domain first. Only verified senders can be the default.')
            ->assertSee('Verify this domain first.');
    }

    // Eligibility, enforced on the server

    public function test_set_default_is_only_for_verified_senders_and_refused_on_the_server_otherwise(): void
    {
        $pending = $this->sender(EmailVerificationStatus::Pending);
        $failed = $this->sender(EmailVerificationStatus::Failed);
        $verified = $this->sender();

        foreach ([$pending, $failed] as $connection) {
            $this->settings()
                ->assertSet('actionStates.'.$connection->id.'.default.allowed', false)
                ->call('setDefault', $connection->id)
                ->assertSet('statusType', 'error');

            $this->assertFalse($connection->fresh()->is_default);
        }

        $this->settings()->assertSet('actionStates.'.$verified->id.'.default.allowed', true)->call('setDefault', $verified->id)->assertSet('statusType', 'success');
        $this->assertTrue($verified->fresh()->is_default);
    }

    public function test_the_current_default_offers_no_set_default_action(): void
    {
        $default = $this->sender(attributes: ['is_default' => true]);

        $this->settings()->assertSet('actionStates.'.$default->id.'.default.reason', 'This is already the default sender.')->assertDontSeeHtml('wire:click="setDefault('.$default->id.')"');
    }

    public function test_test_email_needs_a_verified_sender_and_a_configured_sending_service(): void
    {
        $verified = $this->sender();
        $pending = $this->sender(EmailVerificationStatus::Pending);

        $this->settings()
            ->assertSet('actionStates.'.$verified->id.'.test.allowed', true)
            ->assertSet('actionStates.'.$pending->id.'.test.allowed', false);

        config(['email.providers.postmark.server_token' => null]);

        $this->settings()
            ->assertSet('actionStates.'.$verified->id.'.test.allowed', false)
            ->assertSee('Email sending is not set up on this server yet.')
            ->call('openTestEmail', $verified->id)
            ->assertSet('testEmailConnectionId', null)
            ->assertSet('statusType', 'error');

        Http::assertNothingSent();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_verification_actions_are_refused_and_explained_when_the_provider_is_not_configured(): void
    {
        config(['email.providers.postmark.account_token' => null]);
        $pending = $this->sender(EmailVerificationStatus::Pending);

        $this->settings()
            ->assertSee("Domain verification isn't available right now. Please contact support.", false)
            ->call('startVerification', $pending->id)->assertSet('statusType', 'error')
            ->call('checkVerification', $pending->id)->assertSet('statusType', 'error');

        Http::assertNothingSent();
        $this->assertNull($pending->fresh()->provider_domain_id);
    }

    public function test_a_failed_check_never_shows_verified_and_explains_what_to_do(): void
    {
        $registered = $this->sender(EmailVerificationStatus::Pending, ['provider_domain_id' => '36735', 'dns_records' => []]);
        Http::fake(['api.postmarkapp.com/domains/36735/*' => Http::response(['ID' => 36735, 'Name' => 'example.com', 'DKIMVerified' => false, 'DKIMPendingHost' => '2026pm._domainkey.example.com',
            'DKIMPendingTextValue' => 'k=rsa;p=KEY', 'ReturnPathDomain' => 'pm-bounces.example.com', 'ReturnPathDomainVerified' => false, 'ReturnPathDomainCNAMEValue' => 'pm.mtasv.net'])]);

        $this->settings()->call('checkVerification', $registered->id)->assertSet('statusType', 'info')->assertSee('Check Verification')->assertDontSee('Your domain is verified.');

        $this->assertSame(EmailVerificationStatus::Pending, $registered->fresh()->verification_status);
        $this->assertNull($registered->fresh()->verified_at);
    }

    // Deletion

    public function test_deleting_needs_an_explicit_confirmation_dialog_with_cancel_and_confirm(): void
    {
        $connection = $this->sender();

        $this->settings()
            ->call('confirmDelete', $connection->id)
            ->assertSeeHtml('role="alertdialog"')
            ->assertSee('Cancel')->assertSee('Remove');

        $this->assertModelExists($connection);
    }

    // Validation and duplicate submissions

    public function test_the_same_sender_address_cannot_be_added_twice_in_one_organization(): void
    {
        $this->sender(attributes: ['sender_email' => 'sales@example.com']);

        $this->settings()
            ->set('domain', 'example.com')->set('senderName', 'Copy')->set('senderEmail', 'sales@example.com')
            ->call('save')
            ->assertHasErrors(['senderEmail']);

        $this->assertSame(1, $this->organization->emailConnections()->count());
    }

    public function test_saving_twice_with_the_same_data_creates_one_sender(): void
    {
        $page = $this->settings()->set('domain', 'example.com')->set('senderName', 'Dallas Cooling')->set('senderEmail', 'sales@example.com');

        $page->call('save');
        // A second click replays the same form: the form was reset after saving, so nothing more can be created.
        $page->call('save')->assertHasErrors(['domain', 'senderName', 'senderEmail']);

        $this->assertSame(1, $this->organization->emailConnections()->count());
    }

    public function test_another_organization_may_use_the_same_address_and_editing_keeps_your_own(): void
    {
        $other = Organization::factory()->create();
        $this->sender(attributes: ['sender_email' => 'sales@example.com'], organization: $other);
        $own = $this->sender(attributes: ['sender_email' => 'info@example.com']);

        $this->settings()->set('domain', 'example.com')->set('senderName', 'Sales')->set('senderEmail', 'sales@example.com')->call('save')->assertHasNoErrors();
        $this->settings()->call('edit', $own->id)->set('senderName', 'Renamed')->call('save')->assertHasNoErrors();

        $this->assertSame('Renamed', $own->fresh()->sender_name);
    }

    public function test_validation_errors_stay_attached_to_their_fields(): void
    {
        $this->settings()->call('create')->set('domain', 'not a domain')->set('senderName', '')->set('senderEmail', 'nope')->call('save')
            ->assertHasErrors(['domain', 'senderName', 'senderEmail'])
            ->assertSeeHtml('id="domain-error"')->assertSeeHtml('id="senderName-error"')->assertSeeHtml('id="senderEmail-error"');
    }

    // Authorization and tenant isolation

    public function test_a_member_without_email_permission_cannot_reach_any_action(): void
    {
        $connection = $this->sender();
        $member = User::factory()->staff()->create(['organization_id' => $this->organization->id]);

        Livewire::actingAs($member)->test(EmailSettings::class)->assertForbidden();

        // Demoted after the pages were open: every handler is refused.
        $methods = ['setDefault', 'confirmDelete', 'openTestEmail', 'startVerification', 'checkVerification', 'edit', 'toggleDnsRecords'];
        $pages = collect($methods)->mapWithKeys(fn ($method) => [$method => $this->settings()]);
        $this->admin->forceFill(['role' => 'staff'])->save();

        foreach ($pages as $method => $page) {
            $page->call($method, $connection->id)->assertForbidden();
        }

        $this->assertFalse($connection->fresh()->is_default);
        $this->assertModelExists($connection);
    }

    public function test_another_organizations_senders_are_neither_listed_nor_actionable(): void
    {
        $foreign = $this->sender(attributes: ['sender_email' => 'secret@othertenant.com'], organization: Organization::factory()->create());
        $own = $this->sender();

        $page = $this->settings()->assertDontSee('othertenant.com');

        $this->assertSame([$own->id], array_keys($page->instance()->actionStates));

        foreach (['setDefault', 'confirmDelete', 'openTestEmail', 'startVerification', 'checkVerification', 'edit', 'toggleDnsRecords'] as $method) {
            $this->settings()->call($method, $foreign->id)->assertNotFound();
        }

        $this->assertModelExists($foreign);
    }

    public function test_no_credentials_reach_the_page(): void
    {
        $this->sender(EmailVerificationStatus::Pending, ['provider_domain_id' => 'pm-1', 'dns_records' => []]);

        $this->settings()->assertDontSee('server-token')->assertDontSee('account-token');
    }
}
