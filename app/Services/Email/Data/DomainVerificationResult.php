<?php

namespace App\Services\Email\Data;

final readonly class DomainVerificationResult
{
    /**
     * @param  list<DnsRecord>  $records  The records with their latest per-record verification state.
     */
    public function __construct(
        public bool $verified,
        public array $records,
    ) {}
}
