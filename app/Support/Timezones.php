<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Timezones for the pickers: the business's country first, then popular ones (US, Pakistan, Gulf, UK, India...),
 * then every other zone, each with its UTC offset. The business can use any timezone; its country only decides
 * what is suggested.
 */
class Timezones
{
    /** Shown after the country's own zones. */
    public const POPULAR = [
        'America/New_York' => 'US Eastern', 'America/Chicago' => 'US Central', 'America/Denver' => 'US Mountain',
        'America/Phoenix' => 'Arizona', 'America/Los_Angeles' => 'US Pacific', 'America/Anchorage' => 'Alaska',
        'Pacific/Honolulu' => 'Hawaii', 'America/Toronto' => 'Toronto', 'Asia/Karachi' => 'Pakistan',
        'Asia/Dubai' => 'UAE', 'Asia/Riyadh' => 'Saudi Arabia', 'Asia/Qatar' => 'Qatar', 'Asia/Kolkata' => 'India',
        'Asia/Dhaka' => 'Bangladesh', 'Europe/London' => 'UK', 'Europe/Berlin' => 'Central Europe', 'Australia/Sydney' => 'Sydney',
    ];

    /**
     * Grouped for a <select>: group label => [zone => label].
     *
     * @return array<string, array<string, string>>
     */
    public static function grouped(?string $country): array
    {
        $country = strtoupper((string) $country);
        $own = $country !== '' ? self::forCountry($country) : [];
        $groups = [];

        if ($own !== []) {
            $groups[(Countries::names()[$country] ?? $country)] = collect($own)->mapWithKeys(fn ($zone) => [$zone => self::label($zone)])->sortBy(fn ($label, $zone) => self::offsetSeconds($zone))->all();
        }

        $groups['Popular'] = collect(self::POPULAR)->except($own)->mapWithKeys(fn ($name, $zone) => [$zone => self::label($zone, $name)])->all();

        $listed = array_merge($own, array_keys(self::POPULAR));
        $groups['All timezones'] = collect(DateTimeZone::listIdentifiers())->diff($listed)
            ->mapWithKeys(fn ($zone) => [$zone => self::label($zone)])->all();

        return $groups;
    }

    /**
     * @return list<string>
     */
    public static function forCountry(string $country): array
    {
        try {
            return DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, strtoupper($country));
        } catch (Throwable) {
            return [];
        }
    }

    public static function belongsTo(string $zone, ?string $country): bool
    {
        return $country !== null && in_array($zone, self::forCountry($country), true);
    }

    /**
     * The country a timezone is in, if QuoteFlow supports that country (e.g. Asia/Karachi → PK).
     */
    public static function countryOf(?string $zone): ?string
    {
        if (! self::valid($zone)) {
            return null;
        }

        $code = (new DateTimeZone($zone))->getLocation()['country_code'] ?? null;

        return is_string($code) && isset(Countries::names()[$code]) ? $code : null;
    }

    public static function valid(?string $zone): bool
    {
        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * "(UTC+05:00) Karachi" or, with a name, "(UTC-06:00) US Central – Chicago".
     */
    public static function label(string $zone, ?string $name = null): string
    {
        $city = str_replace('_', ' ', substr($zone, strrpos($zone, '/') !== false ? strrpos($zone, '/') + 1 : 0));

        return '('.self::offset($zone).') '.($name !== null && $name !== $city ? $name.' – '.$city : $city);
    }

    public static function offset(string $zone): string
    {
        $seconds = self::offsetSeconds($zone);

        return 'UTC'.($seconds < 0 ? '-' : '+').sprintf('%02d:%02d', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60));
    }

    private static function offsetSeconds(string $zone): int
    {
        return (new DateTimeImmutable('now', new DateTimeZone($zone)))->getOffset();
    }
}
