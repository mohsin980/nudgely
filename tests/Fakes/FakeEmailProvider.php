<?php

namespace Tests\Fakes;

use App\Contracts\Email\EmailProviderInterface;
use App\Exceptions\Email\EmailProviderException;
use App\Services\Email\Data\DnsRecord;
use App\Services\Email\Data\DnsRecordsResult;
use App\Services\Email\Data\DomainVerificationResult;
use App\Services\Email\Data\ProviderDomainResult;

/**
 * In-memory provider for exercising application logic without any HTTP.
 */
class FakeEmailProvider implements EmailProviderInterface
{
    /** @var list<string> */
    public array $registered = [];

    /** @var list<string> */
    public array $verified = [];

    /** @var list<string> */
    public array $removed = [];

    public bool $verifies = false;

    public ?EmailProviderException $failWith = null;

    public function registerDomain(string $domain): ProviderDomainResult
    {
        $this->throwIfFailing();
        $this->registered[] = $domain;

        return new ProviderDomainResult('fake-'.count($this->registered), $domain);
    }

    public function getDomainDnsRecords(string $providerDomainId): DnsRecordsResult
    {
        $this->throwIfFailing();

        return new DnsRecordsResult($this->records($providerDomainId, false));
    }

    public function verifyDomain(string $providerDomainId): DomainVerificationResult
    {
        $this->throwIfFailing();
        $this->verified[] = $providerDomainId;

        return new DomainVerificationResult($this->verifies, $this->records($providerDomainId, $this->verifies));
    }

    public function removeDomain(string $providerDomainId): void
    {
        $this->throwIfFailing();
        $this->removed[] = $providerDomainId;
    }

    /**
     * @return list<DnsRecord>
     */
    private function records(string $providerDomainId, bool $verified): array
    {
        return [
            new DnsRecord('TXT', "pm._domainkey.{$providerDomainId}.test", "k=rsa; p={$providerDomainId}", 'DKIM', verified: $verified),
            new DnsRecord('CNAME', "pm-bounces.{$providerDomainId}.test", 'pm.mtasv.net', 'Return-Path', verified: $verified),
        ];
    }

    private function throwIfFailing(): void
    {
        if ($this->failWith) {
            throw $this->failWith;
        }
    }
}
