<?php

namespace App\Billing;

use Carbon\CarbonImmutable;

/**
 * An invoice as the provider reports it (amounts in minor units). The URLs are the provider's hosted pages.
 */
final readonly class InvoiceSummary
{
    public function __construct(
        public string $id,
        public ?string $number,
        public CarbonImmutable $date,
        public int $amountCents,
        public string $currency,
        public string $status,
        public ?string $viewUrl = null,
        public ?string $pdfUrl = null,
    ) {}
}
