<?php

namespace App\Services\Email\Data;

final readonly class ProviderDomainResult
{
    public function __construct(
        public string $providerDomainId,
        public string $domain,
    ) {}
}
