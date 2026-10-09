<?php

namespace Tests\Feature\Email;

use App\Enums\EmailVerificationStatus;
use App\Exceptions\Email\EmailProviderException;
use App\Models\EmailConnection;
use App\Models\Organization;
use App\Services\Email\EmailDomainService;
use App\Services\Email\EmailProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Fakes\FakeEmailProvider;
use Tests\TestCase;

class EmailDomainServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeEmailProvider $provider;

    private EmailDomainService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $provider = $this->provider = new FakeEmailProvider;
        app(EmailProviderManager::class)->extend('postmark', fn () => $provider);
        $this->service = app(EmailDomainService::class);
    }

    private function connection(array $attributes = []): EmailConnection
    {
        return EmailConnection::factory()->create(['domain' => 'example.com', 'sender_email' => 'sales@example.com', ...$attributes]);
    }

    // Registration

    public function test_registering_saves_the_provider_domain_id_and_dns_records(): void
    {
        $connection = $this->connection();

        $this->service->registerDomain($connection);

        $connection->refresh();
        $this->assertSame(['example.com'], $this->provider->registered);
        $this->assertSame('fake-1', $connection->provider_domain_id);
        $this->assertCount(2, $connection->dns_records);
        $this->assertSame('TXT', $connection->dnsRecords()[0]->type);
        $this->assertSame('pm.mtasv.net', $connection->dnsRecords()[1]->value);
        $this->assertSame(EmailVerificationStatus::Pending, $connection->verification_status);
        $this->assertNull($connection->verified_at);
    }

    public function test_registering_twice_does_not_create_a_duplicate_provider_domain(): void
    {
        $connection = $this->connection();

        $this->service->registerDomain($connection);
        $this->service->registerDomain($connection);

        $this->assertCount(1, $this->provider->registered);
        $this->assertSame('fake-1', $connection->fresh()->provider_domain_id);
    }

    public function test_connections_on_the_same_domain_in_one_organization_share_the_provider_domain(): void
    {
        $first = $this->connection();
        $second = $this->connection(['organization_id' => $first->organization_id, 'sender_email' => 'billing@example.com']);

        $this->service->registerDomain($first);
        $this->service->registerDomain($second);

        $this->assertCount(1, $this->provider->registered);
        $this->assertSame('fake-1', $second->fresh()->provider_domain_id);
    }

    public function test_a_domain_registered_by_another_organization_cannot_be_claimed(): void
    {
        $this->service->registerDomain($this->connection());
        $other = $this->connection(['organization_id' => Organization::factory()]);

        try {
            $this->service->registerDomain($other);
            $this->fail('Expected exception.');
        } catch (EmailProviderException $e) {
            $this->assertSame(EmailProviderException::DOMAIN_IN_USE, $e->reason);
        }

        $this->assertNull($other->fresh()->provider_domain_id);
        $this->assertCount(1, $this->provider->registered);
    }

    public function test_concurrent_registration_is_refused(): void
    {
        $connection = $this->connection();
        $lock = Cache::lock('email-domain:postmark:example.com', 60);
        $lock->get();

        try {
            $this->service->registerDomain($connection);
            $this->fail('Expected exception.');
        } catch (EmailProviderException $e) {
            $this->assertSame(EmailProviderException::IN_PROGRESS, $e->reason);
        } finally {
            $lock->release();
        }

        $this->assertSame([], $this->provider->registered);
    }

    public function test_rejected_registration_marks_the_connection_failed_with_a_safe_message(): void
    {
        $connection = $this->connection();
        $this->provider->failWith = EmailProviderException::rejected('Postmark register domain failed with HTTP 422 (error code 300).');

        $this->expectException(EmailProviderException::class);

        try {
            $this->service->registerDomain($connection);
        } finally {
            $connection->refresh();
            $this->assertSame(EmailVerificationStatus::Failed, $connection->verification_status);
            $this->assertStringNotContainsString('HTTP 422', $connection->verification_error);
        }
    }

    public function test_provider_outage_leaves_the_connection_unchanged(): void
    {
        $connection = $this->connection();
        $this->provider->failWith = EmailProviderException::unavailable('timeout');

        try {
            $this->service->registerDomain($connection);
        } catch (EmailProviderException) {
        }

        $connection->refresh();
        $this->assertSame(EmailVerificationStatus::Pending, $connection->verification_status);
        $this->assertNull($connection->provider_domain_id);
        $this->assertNull($connection->verification_error);
    }

    // Verification

    public function test_unverified_domain_remains_pending(): void
    {
        $connection = $this->connection();
        $this->service->registerDomain($connection);

        $this->assertFalse($this->service->verifyDomain($connection));

        $connection->refresh();
        $this->assertSame(EmailVerificationStatus::Pending, $connection->verification_status);
        $this->assertNull($connection->verified_at);
        $this->assertFalse($connection->dnsRecords()[0]->verified);
    }

    public function test_verified_domain_becomes_verified(): void
    {
        $connection = $this->connection();
        $this->service->registerDomain($connection);
        $this->provider->verifies = true;

        $this->assertTrue($this->service->verifyDomain($connection));

        $connection->refresh();
        $this->assertSame(EmailVerificationStatus::Verified, $connection->verification_status);
        $this->assertNotNull($connection->verified_at);
        $this->assertTrue($connection->dnsRecords()[0]->verified);
        $this->assertSame(['fake-1'], $this->provider->verified);
    }

    public function test_verifying_an_unregistered_connection_registers_it_first(): void
    {
        $connection = $this->connection();

        $this->service->verifyDomain($connection);

        $this->assertSame(['example.com'], $this->provider->registered);
        $this->assertSame(['fake-1'], $this->provider->verified);
    }

    public function test_provider_failure_does_not_mark_the_domain_verified(): void
    {
        $connection = $this->connection();
        $this->service->registerDomain($connection);
        $this->provider->failWith = EmailProviderException::unavailable('HTTP 500');

        try {
            $this->service->verifyDomain($connection);
            $this->fail('Expected exception.');
        } catch (EmailProviderException) {
        }

        $connection->refresh();
        $this->assertSame(EmailVerificationStatus::Pending, $connection->verification_status);
        $this->assertNull($connection->verified_at);
        $this->assertSame('fake-1', $connection->provider_domain_id);
    }

    public function test_domain_missing_at_the_provider_clears_the_registration(): void
    {
        $connection = $this->connection();
        $this->service->registerDomain($connection);
        $this->provider->failWith = EmailProviderException::domainNotFound('HTTP 404');

        try {
            $this->service->verifyDomain($connection);
            $this->fail('Expected exception.');
        } catch (EmailProviderException) {
        }

        $connection->refresh();
        $this->assertNull($connection->provider_domain_id);
        $this->assertNull($connection->dns_records);
        $this->assertSame(EmailVerificationStatus::Failed, $connection->verification_status);
        $this->assertNotNull($connection->verification_error);
    }

    // Editing and removal

    public function test_changing_the_domain_clears_provider_state_and_never_reuses_the_old_id(): void
    {
        $connection = $this->connection();
        $this->service->registerDomain($connection);
        $this->provider->verifies = true;
        $this->service->verifyDomain($connection);

        $connection->update(['domain' => 'newexample.com', 'sender_email' => 'sales@newexample.com']);

        $this->assertNull($connection->provider_domain_id);
        $this->assertNull($connection->dns_records);
        $this->assertNull($connection->verified_at);
        $this->assertSame(EmailVerificationStatus::Pending, $connection->verification_status);

        $this->service->registerDomain($connection);
        $this->assertSame(['example.com', 'newexample.com'], $this->provider->registered);
        $this->assertSame('fake-2', $connection->fresh()->provider_domain_id);
    }

    public function test_releasing_removes_unused_provider_domains_only(): void
    {
        $first = $this->connection();
        $second = $this->connection(['organization_id' => $first->organization_id, 'sender_email' => 'billing@example.com']);
        $this->service->registerDomain($first);
        $this->service->registerDomain($second);

        $this->service->deleteConnection($first);
        $this->assertSame([], $this->provider->removed);

        $this->service->deleteConnection($second);
        $this->assertSame(['fake-1'], $this->provider->removed);
    }

    public function test_provider_failure_while_releasing_does_not_break_local_state(): void
    {
        $connection = $this->connection();
        $this->service->registerDomain($connection);
        $this->provider->failWith = EmailProviderException::unavailable('timeout');

        $this->service->deleteConnection($connection);

        $this->assertModelMissing($connection);
    }
}
