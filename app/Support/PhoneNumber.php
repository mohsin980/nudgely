<?php

namespace App\Support;

/**
 * Phone numbers in international (E.164) form, which WhatsApp needs: "+" and up to 15 digits.
 *
 * Numbers written with a country code ("+92 300 1234567", "0092 300…") are kept as they are. Local numbers
 * get the business country's code: "(214) 555-0142" in the US becomes +12145550142. Best effort, not a full
 * numbering-plan check: anything that can't be made into a plausible number returns null.
 */
final class PhoneNumber
{
    public static function toE164(?string $phone, string $country = 'US'): ?string
    {
        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $phone);

        if (str_starts_with($phone, '+')) {
            $international = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $international = substr($digits, 2);
        } else {
            [$code, $trunk] = Countries::dialing($country);

            if ($code === '1' && strlen($digits) === 11 && str_starts_with($digits, '1')) {
                $international = $digits; // "1 214 555 0142"
            } else {
                $national = $trunk !== '' && str_starts_with($digits, $trunk) ? substr($digits, strlen($trunk)) : $digits;
                $international = $code.$national;
            }
        }

        return preg_match('/^[1-9]\d{7,14}$/', $international) ? '+'.$international : null;
    }
}
