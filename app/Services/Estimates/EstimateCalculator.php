<?php

namespace App\Services\Estimates;

use App\Enums\DiscountType;
use App\Support\Money;
use InvalidArgumentException;

/**
 * The only place estimate amounts are calculated. Integer arithmetic on cents; rounding is half up.
 *
 *   amount   = quantity × unit price
 *   subtotal = sum of amounts
 *   discount = subtotal × percent, or a fixed amount (never more than the subtotal)
 *   tax      = (subtotal − discount) × tax rate
 *   total    = subtotal − discount + tax
 *
 * Units: quantity in hundredths (1.50 → 150), prices in cents, discount percent in hundredths
 * of a percent (12.5% → 1250), tax rate in thousandths of a percent (8.25% → 8250).
 */
class EstimateCalculator
{
    /** Largest amount a numeric(12,2) column holds, in cents. */
    public const MAX_AMOUNT = 999_999_999_999;

    public const MAX_QUANTITY = 9_999_999;       // 99,999.99

    public const MAX_UNIT_PRICE = 999_999_999;   // 9,999,999.99

    public const MAX_TAX_RATE = 100_000;         // 100.000%

    /**
     * @param  list<array{quantity: int, unit_price: int}>  $lines
     *
     * @throws InvalidArgumentException when a value is out of range or the result would be negative.
     */
    public function calculate(array $lines, ?DiscountType $discountType = null, ?int $discountValue = null, ?int $taxRate = null): EstimateTotals
    {
        $amounts = [];

        foreach ($lines as $line) {
            if ($line['quantity'] <= 0 || $line['quantity'] > self::MAX_QUANTITY) {
                throw new InvalidArgumentException('Quantity must be more than 0 and at most 99,999.99.');
            }

            if ($line['unit_price'] < 0 || $line['unit_price'] > self::MAX_UNIT_PRICE) {
                throw new InvalidArgumentException('Unit price must be from 0 to 9,999,999.99.');
            }

            $amounts[] = self::lineAmount($line['quantity'], $line['unit_price']);
        }

        $subtotal = array_sum($amounts);

        // Checked before multiplying by rates, so integer arithmetic can't overflow.
        if ($subtotal > self::MAX_AMOUNT) {
            throw new InvalidArgumentException('The total is too large.');
        }

        $discount = $this->discount($subtotal, $discountType, $discountValue);

        if ($taxRate !== null && ($taxRate < 0 || $taxRate > self::MAX_TAX_RATE)) {
            throw new InvalidArgumentException('Tax rate must be from 0% to 100%.');
        }

        $tax = $taxRate ? Money::divideRounded(($subtotal - $discount) * $taxRate, 100_000) : 0;
        $total = $subtotal - $discount + $tax;

        if ($total > self::MAX_AMOUNT) {
            throw new InvalidArgumentException('The total is too large.');
        }

        return new EstimateTotals($amounts, $subtotal, $discount, $tax, $total);
    }

    /**
     * quantity (hundredths) × unit price (cents), in cents.
     */
    public static function lineAmount(int $quantity, int $unitPrice): int
    {
        return Money::divideRounded($quantity * $unitPrice, 100);
    }

    private function discount(int $subtotal, ?DiscountType $type, ?int $value): int
    {
        if ($type === null || ! $value) {
            return 0;
        }

        if ($value < 0) {
            throw new InvalidArgumentException('The discount cannot be negative.');
        }

        if ($type === DiscountType::Percent) {
            if ($value > 10_000) {
                throw new InvalidArgumentException('A percentage discount cannot be more than 100%.');
            }

            return Money::divideRounded($subtotal * $value, 10_000);
        }

        if ($value > $subtotal) {
            throw new InvalidArgumentException('The discount cannot be more than the subtotal.');
        }

        return $value;
    }
}
