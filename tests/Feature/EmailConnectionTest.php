<?php

namespace Tests\Feature;

use App\Enums\EmailProvider;
use App\Enums\EmailVerificationStatus;
use App\Models\EmailConnection;
use App\Models\Organization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmailConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_connection_can_be_created(): void
    {
        $organization = Organization::factory()->create();

        $connection = $organization->emailConnections()->create([
            'provider' => EmailProvider::Postmark,
            'domain' => 'AcmeHVAC.com ',
            'sender_email' => 'Sales@AcmeHVAC.com',
            'sender_name' => 'Acme HVAC',
        ]);

        $this->assertDatabaseHas('email_connections', [
            'id' => $connection->id,
            'organization_id' => $organization->id,
            'provider' => 'postmark',
            'domain' => 'acmehvac.com',
            'sender_email' => 'sales@acmehvac.com',
            'sender_name' => 'Acme HVAC',
            'verification_status' => 'pending',
            'is_default' => false,
            'verified_at' => null,
        ]);
    }

    public function test_new_connection_defaults_to_pending_and_not_default(): void
    {
        $connection = EmailConnection::factory()->create()->fresh();

        $this->assertSame(EmailVerificationStatus::Pending, $connection->verification_status);
        $this->assertFalse($connection->isVerified());
        $this->assertFalse($connection->isDefault());
    }

    public function test_factory_sender_email_matches_domain(): void
    {
        $connection = EmailConnection::factory()->make();

        $this->assertStringEndsWith('@'.$connection->domain, $connection->sender_email);
    }

    public function test_email_connection_belongs_to_an_organization(): void
    {
        $organization = Organization::factory()->create();
        $connection = EmailConnection::factory()->for($organization)->create();

        $this->assertTrue($connection->organization->is($organization));
    }

    public function test_organization_has_many_email_connections(): void
    {
        $organization = Organization::factory()->create();
        EmailConnection::factory()->count(2)->for($organization)->create();
        EmailConnection::factory()->create();

        $this->assertCount(2, $organization->emailConnections);
        $this->assertContainsOnlyInstancesOf(EmailConnection::class, $organization->emailConnections);
    }

    public function test_organization_id_is_not_mass_assignable(): void
    {
        $connection = new EmailConnection(['organization_id' => 123]);

        $this->assertNull($connection->organization_id);
    }

    public function test_provider_is_cast_to_enum(): void
    {
        $connection = EmailConnection::factory()->create(['provider' => 'resend'])->fresh();

        $this->assertSame(EmailProvider::Resend, $connection->provider);
    }

    public function test_verification_status_is_cast_to_enum(): void
    {
        $connection = EmailConnection::factory()->verified()->create()->fresh();

        $this->assertSame(EmailVerificationStatus::Verified, $connection->verification_status);
        $this->assertTrue($connection->isVerified());
    }

    public function test_is_default_is_cast_to_boolean(): void
    {
        $connection = EmailConnection::factory()->default()->create()->fresh();

        $this->assertTrue($connection->is_default);
        $this->assertTrue($connection->isDefault());
    }

    public function test_verified_at_is_cast_to_datetime(): void
    {
        $connection = EmailConnection::factory()->verified()->create()->fresh();

        $this->assertInstanceOf(Carbon::class, $connection->verified_at);
    }

    public function test_verified_and_default_scopes(): void
    {
        $organization = Organization::factory()->create();
        $verified = EmailConnection::factory()->for($organization)->verified()->create();
        $default = EmailConnection::factory()->for($organization)->default()->create();

        $this->assertEquals([$verified->id], $organization->emailConnections()->verified()->pluck('id')->all());
        $this->assertEquals([$default->id], $organization->emailConnections()->default()->pluck('id')->all());
    }

    public function test_marking_a_connection_as_default_unsets_the_previous_default_in_the_same_organization(): void
    {
        $organization = Organization::factory()->create();
        $previous = EmailConnection::factory()->for($organization)->default()->create();
        $connection = EmailConnection::factory()->for($organization)->create();

        $connection->markAsDefault();

        $this->assertTrue($connection->fresh()->is_default);
        $this->assertFalse($previous->fresh()->is_default);
        $this->assertSame(1, $organization->emailConnections()->default()->count());
    }

    public function test_creating_a_default_connection_unsets_the_previous_default(): void
    {
        $organization = Organization::factory()->create();
        $previous = EmailConnection::factory()->for($organization)->default()->create();

        $connection = EmailConnection::factory()->for($organization)->default()->create();

        $this->assertTrue($connection->fresh()->is_default);
        $this->assertFalse($previous->fresh()->is_default);
    }

    public function test_marking_a_connection_as_default_does_not_affect_other_organizations(): void
    {
        $otherDefault = EmailConnection::factory()->default()->create();
        $connection = EmailConnection::factory()->create();

        $connection->markAsDefault();

        $this->assertTrue($connection->fresh()->is_default);
        $this->assertTrue($otherDefault->fresh()->is_default);
    }

    public function test_database_rejects_two_defaults_for_one_organization(): void
    {
        $organization = Organization::factory()->create();
        EmailConnection::factory()->for($organization)->default()->create();
        $connection = EmailConnection::factory()->for($organization)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        // Bypass the model safeguard to prove the database index enforces the invariant.
        DB::table('email_connections')->where('id', $connection->id)->update(['is_default' => true]);
    }

    public function test_deleting_an_organization_deletes_its_email_connections(): void
    {
        $organization = Organization::factory()->create();
        EmailConnection::factory()->count(2)->for($organization)->create();
        $otherConnection = EmailConnection::factory()->create();

        $organization->delete();

        $this->assertDatabaseMissing('email_connections', ['organization_id' => $organization->id]);
        $this->assertModelExists($otherConnection);
    }
}
