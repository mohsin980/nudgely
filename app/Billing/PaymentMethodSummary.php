<?php

namespace App\Billing;

/**
 * The only payment details QuoteFollow ever shows: brand, last four digits and expiry.
 */
final readonly class PaymentMethodSummary
{
    public function __construct(
        public string $brand,
        public string $last4,
        public ?int $expMonth = null,
        public ?int $expYear = null,
    ) {}

    public function label(): string
    {
        return ucfirst($this->brand)." ending {$this->last4}";
    }

    public function expiry(): ?string
    {
        return $this->expMonth && $this->expYear ? sprintf('%02d/%d', $this->expMonth, $this->expYear) : null;
    }
}
