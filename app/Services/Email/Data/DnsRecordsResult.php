<?php

namespace App\Services\Email\Data;

final readonly class DnsRecordsResult
{
    /**
     * @param  list<DnsRecord>  $records
     */
    public function __construct(
        public array $records,
    ) {}
}
