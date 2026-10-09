<?php

namespace App\Services\Email;

use App\Contracts\Email\EmailProviderInterface;
use App\Enums\EmailProvider;
use App\Enums\EmailVerificationStatus;
use App\Exceptions\Email\EmailProviderException;
use App\Models\EmailConnection;
use App\Services\Email\Data\DnsRecord;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Registers organizations' sending domains with the configured provider and tracks verification.
 *
 * Callers are responsible for authorizing access to the connection.
 */
class EmailDomainService
{
    public function __construct(private readonly EmailProviderManager $providers) {}

    /**
     * Register the connection's domain with its provider (idempotently) and store the DNS records to publish.
     *
     * @throws EmailProviderException
     */
    public function registerDomain(EmailConnection $connection): EmailConnection
    {
        return $this->withDomainLock($connection, function () use ($connection) {
            $connection->refresh();

            if (! $connection->isRegisteredWithProvider()) {
                $providerDomainId = $this->providerDomainIdSharedWithinOrganization($connection)
                    ?? $this->registerWithProvider($connection);

                $connection->forceFill([
                    'provider_domain_id' => $providerDomainId,
                    'verification_status' => EmailVerificationStatus::Pending,
                    'verification_error' => null,
                    'verified_at' => null,
                ])->save();
            }

            return $this->refreshDnsRecords($connection);
        });
    }

    /**
     * Fetch the DNS records from the provider and store them on the connection.
     *
     * @throws EmailProviderException
     */
    public function refreshDnsRecords(EmailConnection $connection): EmailConnection
    {
        $result = $this->handleMissingProviderDomain($connection, fn () => $this->provider($connection)
            ->getDomainDnsRecords($connection->provider_domain_id));

        $this->storeRecords($connection, $result->records);

        return $connection;
    }

    /**
     * Stored DNS records for display; makes no provider calls.
     *
     * @return list<DnsRecord>
     */
    public function getDnsRecords(EmailConnection $connection): array
    {
        return $connection->dnsRecords();
    }

    /**
     * Ask the provider to verify the domain. Only the provider's answer can mark it verified.
     *
     * @throws EmailProviderException
     */
    public function verifyDomain(EmailConnection $connection): bool
    {
        if (! $connection->isRegisteredWithProvider()) {
            $this->registerDomain($connection);
        }

        $result = $this->handleMissingProviderDomain($connection, fn () => $this->provider($connection)
            ->verifyDomain($connection->provider_domain_id));

        $connection->forceFill([
            'dns_records' => array_map(fn (DnsRecord $record) => $record->toArray(), $result->records),
            'verification_status' => $result->verified ? EmailVerificationStatus::Verified : EmailVerificationStatus::Pending,
            'verification_error' => null,
            'verified_at' => $result->verified ? ($connection->verified_at ?? now()) : null,
        ])->save();

        return $result->verified;
    }

    /**
     * Delete the connection, then remove its domain from the provider if nothing else uses it.
     */
    public function deleteConnection(EmailConnection $connection): void
    {
        $connection->delete();

        if ($connection->provider_domain_id !== null) {
            $this->releaseProviderDomain($connection->provider, $connection->provider_domain_id);
        }
    }

    /**
     * Remove a provider domain no connection references any more.
     *
     * Never throws: local state stays consistent even when the provider cannot be reached.
     */
    public function releaseProviderDomain(EmailProvider $provider, string $providerDomainId): void
    {
        $stillUsed = EmailConnection::query()
            ->where('provider', $provider)
            ->where('provider_domain_id', $providerDomainId)
            ->exists();

        if ($stillUsed) {
            return;
        }

        try {
            $this->providers->for($provider)->removeDomain($providerDomainId);
        } catch (EmailProviderException $e) {
            Log::warning('Could not remove provider domain; it may need manual cleanup.', [
                'provider' => $provider->value,
                'provider_domain_id' => $providerDomainId,
                'reason' => $e->reason,
            ]);
        }
    }

    private function registerWithProvider(EmailConnection $connection): string
    {
        try {
            return $this->provider($connection)->registerDomain($connection->domain)->providerDomainId;
        } catch (EmailProviderException $e) {
            if ($e->reason === EmailProviderException::REJECTED) {
                $this->markFailed($connection, $e);
            }

            throw $e;
        }
    }

    /**
     * Connections in the same organization on the same domain share one provider domain.
     * A domain already registered by another organization cannot be claimed.
     */
    private function providerDomainIdSharedWithinOrganization(EmailConnection $connection): ?string
    {
        $registered = EmailConnection::query()
            ->whereKeyNot($connection->getKey())
            ->where('provider', $connection->provider)
            ->where('domain', $connection->domain)
            ->whereNotNull('provider_domain_id')
            ->get(['organization_id', 'provider_domain_id']);

        if ($registered->contains(fn (EmailConnection $other) => $other->organization_id !== $connection->organization_id)) {
            throw EmailProviderException::domainInUse($connection->domain);
        }

        return $registered->first()?->provider_domain_id;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function handleMissingProviderDomain(EmailConnection $connection, Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (EmailProviderException $e) {
            if ($e->reason === EmailProviderException::DOMAIN_NOT_FOUND) {
                $connection->forceFill(['provider_domain_id' => null, 'dns_records' => null, 'verified_at' => null])->save();
                $this->markFailed($connection, $e);
            }

            throw $e;
        }
    }

    /**
     * @param  list<DnsRecord>  $records
     */
    private function storeRecords(EmailConnection $connection, array $records): void
    {
        $connection->forceFill([
            'dns_records' => array_map(fn (DnsRecord $record) => $record->toArray(), $records),
        ])->save();
    }

    private function markFailed(EmailConnection $connection, EmailProviderException $e): void
    {
        $connection->forceFill([
            'verification_status' => EmailVerificationStatus::Failed,
            'verification_error' => $e->userMessage(),
            'verified_at' => null,
        ])->save();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withDomainLock(EmailConnection $connection, Closure $callback): mixed
    {
        $lock = Cache::lock("email-domain:{$connection->provider->value}:{$connection->domain}", 60);

        if (! $lock->get()) {
            throw EmailProviderException::inProgress($connection->domain);
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    private function provider(EmailConnection $connection): EmailProviderInterface
    {
        return $this->providers->for($connection->provider);
    }
}
