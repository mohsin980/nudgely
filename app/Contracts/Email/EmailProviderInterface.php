<?php

namespace App\Contracts\Email;

use App\Exceptions\Email\EmailProviderException;
use App\Services\Email\Data\DnsRecordsResult;
use App\Services\Email\Data\DomainVerificationResult;
use App\Services\Email\Data\ProviderDomainResult;

/**
 * A transactional email provider that can register and verify sending domains.
 *
 * Implementations must normalize provider responses into the result objects
 * and throw EmailProviderException for every failure.
 */
interface EmailProviderInterface
{
    /**
     * @throws EmailProviderException
     */
    public function registerDomain(string $domain): ProviderDomainResult;

    /**
     * @throws EmailProviderException
     */
    public function getDomainDnsRecords(string $providerDomainId): DnsRecordsResult;

    /**
     * Ask the provider to check the domain's DNS records now.
     *
     * @throws EmailProviderException
     */
    public function verifyDomain(string $providerDomainId): DomainVerificationResult;

    /**
     * Removing a domain that no longer exists at the provider is not an error.
     *
     * @throws EmailProviderException
     */
    public function removeDomain(string $providerDomainId): void;
}
