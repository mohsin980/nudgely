<?php

namespace App\Support;

/**
 * The countries a business can be in, and what each means for the app: the phone code used to turn local numbers
 * into international ones (WhatsApp needs those), a sensible default timezone and currency, what the region is
 * called, and the postal code format when it is checked.
 */
final class Countries
{
    /**
     * code => [name, calling code, national trunk prefix, default timezone, currency, region label, postal regex or null, postal example]
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: ?string, 7: string}>
     */
    private const DATA = [
        'US' => ['United States', '1', '', 'America/Chicago', 'USD', 'State', '/^\d{5}(-\d{4})?$/', '75201'],
        'CA' => ['Canada', '1', '', 'America/Toronto', 'CAD', 'Province', '/^[A-Za-z]\d[A-Za-z] ?\d[A-Za-z]\d$/', 'A1A 1A1'],
        'PK' => ['Pakistan', '92', '0', 'Asia/Karachi', 'PKR', 'Province', '/^\d{5}$/', '54000'],
        'IN' => ['India', '91', '0', 'Asia/Kolkata', 'INR', 'State', '/^\d{6}$/', '110001'],
        'BD' => ['Bangladesh', '880', '0', 'Asia/Dhaka', 'BDT', 'Division', '/^\d{4}$/', '1207'],
        'AE' => ['United Arab Emirates', '971', '0', 'Asia/Dubai', 'AED', 'Emirate', null, ''],
        'SA' => ['Saudi Arabia', '966', '0', 'Asia/Riyadh', 'SAR', 'Region', '/^\d{5}(-\d{4})?$/', '11564'],
        'QA' => ['Qatar', '974', '', 'Asia/Qatar', 'QAR', 'Municipality', null, ''],
        'KW' => ['Kuwait', '965', '', 'Asia/Kuwait', 'KWD', 'Governorate', '/^\d{5}$/', '13001'],
        'OM' => ['Oman', '968', '', 'Asia/Muscat', 'OMR', 'Governorate', '/^\d{3}$/', '112'],
        'BH' => ['Bahrain', '973', '', 'Asia/Bahrain', 'BHD', 'Governorate', null, ''],
        'GB' => ['United Kingdom', '44', '0', 'Europe/London', 'GBP', 'County', '/^[A-Za-z]{1,2}\d[A-Za-z\d]? ?\d[A-Za-z]{2}$/', 'SW1A 1AA'],
        'IE' => ['Ireland', '353', '0', 'Europe/Dublin', 'EUR', 'County', null, ''],
        'DE' => ['Germany', '49', '0', 'Europe/Berlin', 'EUR', 'State', '/^\d{5}$/', '10115'],
        'FR' => ['France', '33', '0', 'Europe/Paris', 'EUR', 'Region', '/^\d{5}$/', '75001'],
        'NL' => ['Netherlands', '31', '0', 'Europe/Amsterdam', 'EUR', 'Province', '/^\d{4} ?[A-Za-z]{2}$/', '1011 AB'],
        'ES' => ['Spain', '34', '', 'Europe/Madrid', 'EUR', 'Province', '/^\d{5}$/', '28001'],
        'IT' => ['Italy', '39', '', 'Europe/Rome', 'EUR', 'Region', '/^\d{5}$/', '00100'],
        'TR' => ['Türkiye', '90', '0', 'Europe/Istanbul', 'TRY', 'Province', '/^\d{5}$/', '34000'],
        'AU' => ['Australia', '61', '0', 'Australia/Sydney', 'AUD', 'State', '/^\d{4}$/', '2000'],
        'NZ' => ['New Zealand', '64', '0', 'Pacific/Auckland', 'NZD', 'Region', '/^\d{4}$/', '1010'],
        'SG' => ['Singapore', '65', '', 'Asia/Singapore', 'SGD', 'Region', '/^\d{6}$/', '018956'],
        'MY' => ['Malaysia', '60', '0', 'Asia/Kuala_Lumpur', 'MYR', 'State', '/^\d{5}$/', '50000'],
        'ZA' => ['South Africa', '27', '0', 'Africa/Johannesburg', 'ZAR', 'Province', '/^\d{4}$/', '2000'],
        'NG' => ['Nigeria', '234', '0', 'Africa/Lagos', 'NGN', 'State', '/^\d{6}$/', '100001'],
        'KE' => ['Kenya', '254', '0', 'Africa/Nairobi', 'KES', 'County', '/^\d{5}$/', '00100'],
        'EG' => ['Egypt', '20', '0', 'Africa/Cairo', 'EGP', 'Governorate', '/^\d{5}$/', '11511'],
    ];

    /** Currencies of these countries, plus a few common ones, for the business's prices. */
    public const CURRENCIES = [
        'USD' => 'US dollar (USD)', 'CAD' => 'Canadian dollar (CAD)', 'PKR' => 'Pakistani rupee (PKR)', 'INR' => 'Indian rupee (INR)',
        'BDT' => 'Bangladeshi taka (BDT)', 'AED' => 'UAE dirham (AED)', 'SAR' => 'Saudi riyal (SAR)', 'QAR' => 'Qatari riyal (QAR)',
        'KWD' => 'Kuwaiti dinar (KWD)', 'OMR' => 'Omani rial (OMR)', 'BHD' => 'Bahraini dinar (BHD)', 'GBP' => 'British pound (GBP)',
        'EUR' => 'Euro (EUR)', 'TRY' => 'Turkish lira (TRY)', 'AUD' => 'Australian dollar (AUD)', 'NZD' => 'New Zealand dollar (NZD)',
        'SGD' => 'Singapore dollar (SGD)', 'MYR' => 'Malaysian ringgit (MYR)', 'ZAR' => 'South African rand (ZAR)', 'NGN' => 'Nigerian naira (NGN)',
        'KES' => 'Kenyan shilling (KES)', 'EGP' => 'Egyptian pound (EGP)',
    ];

    /**
     * @return array<string, string> code => name, in a stable order (most used first)
     */
    public static function names(): array
    {
        return array_map(fn (array $country) => $country[0], self::DATA);
    }

    public static function exists(?string $code): bool
    {
        return isset(self::DATA[strtoupper((string) $code)]);
    }

    /**
     * @return array{0: string, 1: string} calling code and the national trunk prefix (e.g. Pakistan: 92 and "0")
     */
    public static function dialing(?string $code): array
    {
        $country = self::DATA[strtoupper((string) $code)] ?? self::DATA['US'];

        return [$country[1], $country[2]];
    }

    public static function timezone(?string $code): ?string
    {
        return self::DATA[strtoupper((string) $code)][3] ?? null;
    }

    public static function currency(?string $code): string
    {
        return self::DATA[strtoupper((string) $code)][4] ?? 'USD';
    }

    /**
     * "State", "Province", "Emirate"…
     */
    public static function regionLabel(?string $code): string
    {
        return self::DATA[strtoupper((string) $code)][5] ?? 'State / province';
    }

    /**
     * @return array{0: ?string, 1: string} the postal code pattern (null: not checked) and an example
     */
    public static function postal(?string $code): array
    {
        $country = self::DATA[strtoupper((string) $code)] ?? null;

        return [$country[6] ?? null, $country[7] ?? ''];
    }
}
