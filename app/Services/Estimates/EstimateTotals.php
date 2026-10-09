<?php

namespace App\Services\Estimates;

use App\Support\Money;

/**
 * An estimate's calculated amounts, in cents.
 */
final readonly class EstimateTotals
{
    /**
     * @param  list<int>  $lineAmounts  Each item's quantity × unit price, in order.
     */
    public function __construct(
        public array $lineAmounts,
        public int $subtotal,
        public int $discount,
        public int $tax,
        public int $total,
    ) {}

    /**
     * Column values for the estimate (decimal strings).
     *
     * @return array{subtotal: string, discount_amount: string, tax_amount: string, total: string}
     */
    public function toColumns(): array
    {
        return [
            'subtotal' => Money::toDecimal($this->subtotal),
            'discount_amount' => Money::toDecimal($this->discount),
            'tax_amount' => Money::toDecimal($this->tax),
            'total' => Money::toDecimal($this->total),
        ];
    }
}
