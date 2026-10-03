<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money as integer minor units (cents). Never floating point.
 *
 * Amounts arrive as decimal strings (form input or numeric(12,2) columns), become integer
 * cents for arithmetic, and go back to the database as decimal strings.
 */
final class Money
{
    /**
     * Parse a decimal amount ("2500", "2,500.00", "$2,500.5") into cents.
     *
     * @param  int  $decimals  How many decimal places are allowed (2 for money and quantities).
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string|int|null $value, int $decimals = 2): int
    {
        $value = str_replace([',', '$', ' '], '', trim((string) $value));

        if (! preg_match('/^(\d{1,13})(?:\.(\d{0,'.$decimals.'}))?$/', $value, $m)) {
            throw new InvalidArgumentException('Enter an amount like 2500 or 2500.00.');
        }

        $fraction = str_pad($m[2] ?? '', $decimals, '0');

        return (int) ($m[1].$fraction);
    }

    /**
     * Like parse(), but null when the value isn't a valid amount.
     */
    public static function tryParse(string|int|null $value, int $decimals = 2): ?int
    {
        try {
            return self::parse($value, $decimals);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Cents (or other minor units) to a decimal string for a numeric column: 275000 → "2750.00".
     */
    public static function toDecimal(int $minor, int $decimals = 2): string
    {
        $sign = $minor < 0 ? '-' : '';
        $minor = abs($minor);
        $factor = 10 ** $decimals;

        return $sign.intdiv($minor, $factor).($decimals > 0 ? '.'.str_pad((string) ($minor % $factor), $decimals, '0', STR_PAD_LEFT) : '');
    }

    /**
     * For display: 275000 → "$2,750.00". Other currencies are prefixed with their code.
     */
    public static function format(int $cents, string $currency = 'USD'): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        $whole = number_format(intdiv($cents, 100));
        $amount = $whole.'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return $sign.($currency === 'USD' ? '$'.$amount : $currency.' '.$amount);
    }

    /**
     * A (non-negative) decimal string from a numeric column, formatted for display.
     */
    public static function formatDecimal(string|int|null $value, string $currency = 'USD'): string
    {
        return self::format(self::parse($value ?? '0'), $currency);
    }

    /**
     * Integer division rounded half up, for non-negative numbers ($a * $b / $divisor without floats).
     */
    public static function divideRounded(int $numerator, int $divisor): int
    {
        if ($numerator < 0 || $divisor <= 0) {
            throw new InvalidArgumentException('Only non-negative amounts can be rounded.');
        }

        return intdiv($numerator + intdiv($divisor, 2), $divisor);
    }
}
