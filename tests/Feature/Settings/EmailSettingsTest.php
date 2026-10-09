<?php

namespace Tests\Feature\Settings;

use App\Enums\EmailProvider;
use App\Enums\EmailVerificationStatus;
use App\Livewire\Settings\EmailSettings;
use App\Models\EmailConnection;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class EmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests();

        $this->admin = User::factory()->admin()->create();
        $this->organization = $this->admin->organization;
    }

    private function settings(): Testable
    {
        return Livewire::actingAs($this->admin)->test(EmailSettings::class);
    }

    private function verifiedConnection(?Organization $organization = null): EmailConnection
    {
        return EmailConnection::factory()
            ->for($organization ?? $this->organization)
            ->verified()
            ->create(['provider_domain_id' => 'pm-domain-123']);
    }

    // Access

    public function test_admin_can_view_email_settings(): void
    {
        $this->actingAs($this->admin)
            ->get(route('settings.email'))
            ->assertOk()
            ->assertSeeLivewire(EmailSettings::class)
            ->assertSee('Email Settings')
            ->assertSee('Configure the email address QuoteFlow will use when communicating with your customers.');
    }

    public function test_guest_is_redirected_away_from_email_settings(): void
    {
        $this->get(route('settings.email'))->assertRedirect(route('login'));
    }

    public function test_non_admin_member_cannot_view_email_settings(): void
    {
        $member = User::factory()->for($this->organization)->create();

        $this->actingAs($member)->get(route('settings.email'))->assertForbidden();
        Livewire::actingAs($member)->test(EmailSettings::class)->assertForbidden();
    }

    public function test_user_without_an_organization_cannot_view_email_settings(): void
    {
        $user = User::factory()->admin()->create(['organization_id' => null]);

        $this->actingAs($user)->get(route('settings.email'))->assertForbidden();
    }

    public function test_admin_demoted_mid_session_cannot_mutate(): void
    {
        $connection = EmailConnection::factory()->for($this->organization)->create();
        $component = $this->settings();

        $this->admin->forceFill(['role' => 'staff'])->save();

        $component->call('confirmDelete', $connection->id)->assertForbidden();
        $this->assertModelExists($connection);
    }

    // Rendering

    public function test_empty_state_renders(): void
    {
        $this->settings()
            ->assertSee('Connect your business email')
            ->assertSee('Add your business domain and sender address so QuoteFlow can eventually send automated follow-ups from your own email.')
            ->assertSee('Add Business Email');
    }

    public function test_multiple_connections_render(): void
    {
        EmailConnection::factory()->for($this->organization)->verified()->default()
            ->create(['sender_name' => 'Dallas Cooling', 'domain' => 'example.com', 'sender_email' => 'sales@example.com']);
        EmailConnection::factory()->for($this->organization)
            ->create(['sender_name' => 'Billing', 'domain' => 'example.com', 'sender_email' => 'billing@example.com']);

        $this->settings()
            ->assertDontSee('Connect your business email')
            ->assertSeeInOrder(['Dallas Cooling', 'Default', 'sales@example.com', 'Verified', 'Billing', 'billing@example.com', 'Pending Verification']);
    }

    public function test_other_organizations_connections_are_not_listed(): void
    {
        EmailConnection::factory()->create(['sender_email' => 'sales@othertenant.com', 'domain' => 'othertenant.com']);

        $this->settings()
            ->assertDontSee('othertenant.com')
            ->assertSee('Connect your business email');
    }

    // Create

    public function test_admin_can_create_a_pending_non_default_connection(): void
    {
        $this->settings()
            ->call('create')
            ->assertSet('showForm', true)
            ->set('domain', 'example.com')
            ->set('senderName', 'Dallas Cooling')
            ->set('senderEmail', 'sales@example.com')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Email connection added successfully.')
            ->assertSet('showForm', false)
            ->assertSet('domain', '')
            ->assertSee('sales@example.com');

        $connection = $this->organization->emailConnections()->sole();
        $this->assertSame('example.com', $connection->domain);
        $this->assertSame('Dallas Cooling', $connection->sender_name);
        $this->assertSame(EmailProvider::default(), $connection->provider);
        $this->assertSame(EmailVerificationStatus::Pending, $connection->verification_status);
        $this->assertNull($connection->verified_at);
        $this->assertFalse($connection->is_default);
        Http::assertNothingSent();
    }

    public function test_domain_is_normalized(): void
    {
        $this->settings()
            ->set('domain', '  https://Example.com/ ')
            ->set('senderName', '  Dallas Cooling  ')
            ->set('senderEmail', 'sales@example.com')
            ->call('save')
            ->assertHasNoErrors();

        $connection = $this->organization->emailConnections()->sole();
        $this->assertSame('example.com', $connection->domain);
        $this->assertSame('Dallas Cooling', $connection->sender_name);
    }

    public function test_domain_with_a_path_is_rejected(): void
    {
        $this->settings()
            ->set('domain', 'https://example.com/contact')
            ->set('senderName', 'Dallas Cooling')
            ->set('senderEmail', 'sales@example.com')
            ->call('save')
            ->assertHasErrors(['domain']);

        $this->assertDatabaseCount('email_connections', 0);
    }

    public function test_sender_email_is_normalized(): void
    {
        $this->settings()
            ->set('domain', 'Example.com')
            ->set('senderName', 'Dallas Cooling')
            ->set('senderEmail', ' Sales@Example.com ')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('sales@example.com', $this->organization->emailConnections()->sole()->sender_email);
    }

    public function test_sender_email_must_match_domain(): void
    {
        $this->settings()
            ->call('create')
            ->set('domain', 'example.com')
            ->set('senderName', 'Dallas Cooling')
            ->set('senderEmail', 'sales@gmail.com')
            ->call('save')
            ->assertHasErrors(['senderEmail'])
            ->assertSee('The sender email must be an address on example.com.');

        $this->assertDatabaseCount('email_connections', 0);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->settings()
            ->call('save')
            ->assertHasErrors(['domain' => 'required', 'senderName' => 'required', 'senderEmail' => 'required']);
    }

    public function test_member_cannot_create_a_connection(): void
    {
        $member = User::factory()->for($this->organization)->create();
        $component = $this->settings();

        // Even with a valid component snapshot, a non-admin request is rejected server-side.
        $this->actingAs($member);

        $component
            ->set('domain', 'example.com')
            ->set('senderName', 'Dallas Cooling')
            ->set('senderEmail', 'sales@example.com')
            ->call('save')
            ->assertForbidden();

        $this->assertDatabaseCount('email_connections', 0);
    }

    // Edit

    public function test_admin_can_edit_a_connection(): void
    {
        $connection = $this->verifiedConnection();

        $this->settings()
            ->call('edit', $connection->id)
            ->assertSet('editingConnectionId', $connection->id)
            ->assertSet('domain', $connection->domain)
            ->set('senderName', 'Dallas Cooling Sales')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Email connection updated successfully.')
            ->assertSet('editingConnectionId', null);

        $connection->refresh();
        $this->assertSame('Dallas Cooling Sales', $connection->sender_name);
        // Only the name changed, so verification is kept.
        $this->assertTrue($connection->isVerified());
        $this->assertNotNull($connection->verified_at);
    }

    public function test_editing_the_domain_resets_verification(): void
    {
        $connection = $this->verifiedConnection();

        $this->settings()
            ->call('edit', $connection->id)
            ->set('domain', 'newexample.com')
            ->set('senderEmail', 'sales@newexample.com')
            ->call('save')
            ->assertHasNoErrors();

        $connection->refresh();
        $this->assertSame('newexample.com', $connection->domain);
        $this->assertSame(EmailVerificationStatus::Pending, $connection->verification_status);
        $this->assertNull($connection->verified_at);
        $this->assertNull($connection->provider_domain_id);
    }

    public function test_editing_the_sender_email_resets_verification(): void
    {
        $connection = $this->verifiedConnection();

        $this->settings()
            ->call('edit', $connection->id)
            ->set('senderEmail', 'billing@'.$connection->domain)
            ->call('save')
            ->assertHasNoErrors();

        $connection->refresh();
        $this->assertSame('billing@'.$connection->domain, $connection->sender_email);
        $this->assertSame(EmailVerificationStatus::Pending, $connection->verification_status);
        $this->assertNull($connection->verified_at);
        $this->assertNull($connection->provider_domain_id);
    }

    public function test_cannot_edit_another_organizations_connection(): void
    {
        $foreign = $this->verifiedConnection(Organization::factory()->create());

        $this->settings()->call('edit', $foreign->id)->assertNotFound();

        $this->assertSame($foreign->sender_name, $foreign->fresh()->sender_name);
    }

    public function test_editing_connection_id_cannot_be_tampered_with(): void
    {
        $foreign = $this->verifiedConnection(Organization::factory()->create());

        $this->expectException(CannotUpdateLockedPropertyException::class);

        $this->settings()->set('editingConnectionId', $foreign->id);
    }

    // Delete

    public function test_admin_can_delete_own_connection(): void
    {
        $connection = EmailConnection::factory()->for($this->organization)->create();

        $this->settings()
            ->call('confirmDelete', $connection->id)
            ->assertSet('confirmingDeletionId', $connection->id)
            ->assertSee('Are you sure you want to remove this email connection?')
            ->call('delete')
            ->assertSet('confirmingDeletionId', null)
            ->assertSet('statusMessage', 'Email connection removed.')
            ->assertSee('Connect your business email');

        $this->assertModelMissing($connection);
    }

    public function test_cancelling_deletion_keeps_the_connection(): void
    {
        $connection = EmailConnection::factory()->for($this->organization)->create();

        $this->settings()
            ->call('confirmDelete', $connection->id)
            ->call('cancelDelete')
            ->assertSet('confirmingDeletionId', null);

        $this->assertModelExists($connection);
    }

    public function test_deleting_the_default_connection_warns_and_leaves_no_default(): void
    {
        $default = $this->verifiedConnection();
        $default->markAsDefault();
        $other = $this->verifiedConnection();

        $this->settings()
            ->call('confirmDelete', $default->id)
            ->assertSee('This is your default sender.')
            ->call('delete');

        $this->assertModelMissing($default);
        $this->assertFalse($other->fresh()->is_default);
        $this->assertSame(0, $this->organization->emailConnections()->default()->count());
    }

    public function test_cannot_delete_another_organizations_connection(): void
    {
        $foreign = EmailConnection::factory()->create();

        $this->settings()->call('confirmDelete', $foreign->id)->assertNotFound();

        $this->assertModelExists($foreign);
    }

    // Default

    public function test_admin_can_set_a_verified_connection_as_default(): void
    {
        $connection = $this->verifiedConnection();

        $this->settings()
            ->call('setDefault', $connection->id)
            ->assertSet('statusMessage', 'Default sender updated.');

        $this->assertTrue($connection->fresh()->is_default);
    }

    public function test_pending_connection_cannot_be_set_as_default(): void
    {
        $connection = EmailConnection::factory()->for($this->organization)->create();

        $this->settings()
            ->call('setDefault', $connection->id)
            ->assertSet('statusType', 'error')
            ->assertSet('statusMessage', 'Only verified email connections can be set as the default sender.');

        $this->assertFalse($connection->fresh()->is_default);
    }

    public function test_setting_default_removes_the_previous_default(): void
    {
        $previous = $this->verifiedConnection();
        $previous->markAsDefault();
        $connection = $this->verifiedConnection();

        $this->settings()->call('setDefault', $connection->id);

        $this->assertTrue($connection->fresh()->is_default);
        $this->assertFalse($previous->fresh()->is_default);
    }

    public function test_setting_default_does_not_affect_another_organization(): void
    {
        $foreignDefault = $this->verifiedConnection(Organization::factory()->create());
        $foreignDefault->markAsDefault();
        $connection = $this->verifiedConnection();

        $this->settings()->call('setDefault', $connection->id);

        $this->assertTrue($connection->fresh()->is_default);
        $this->assertTrue($foreignDefault->fresh()->is_default);
    }

    public function test_cannot_set_another_organizations_connection_as_default(): void
    {
        $foreign = $this->verifiedConnection(Organization::factory()->create());

        $this->settings()->call('setDefault', $foreign->id)->assertNotFound();

        $this->assertFalse($foreign->fresh()->is_default);
    }
}
