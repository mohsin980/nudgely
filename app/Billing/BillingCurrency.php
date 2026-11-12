<?php

namespace App\Billing;

use Illuminate\Http\Request;

/**
 * Which currency a visitor sees and pays in: PKR for visitors from Pakistan, USD everywhere else.
 *
 * The country comes from a header set by the CDN in front of the app (see config 'billing.geo'), never from a
 * call to a third-party service. The result is pinned in the session, so the prices a visitor saw are the
 * prices they pay at checkout, even if their connection changes mid-visit.
 */
final class BillingCurrency
{
    public const SESSION_KEY = 'billing.currency';

    /**
     * @return list<string>
     */
    public function supported(): array
    {
        return config('billing.currencies.supported', ['USD']);
    }

    /**
     * The currency for a country code ("PK" → "PKR"), or the default when there is no rule for it.
     */
    public function forCountry(?string $country): string
    {
        $rules = config('billing.currencies.by_country', []);
        $currency = $country !== null ? ($rules[$country] ?? null) : null;

        return in_array($currency, $this->supported(), true) ? $currency : (string) config('billing.currencies.default', 'USD');
    }

    /**
     * The visitor's country code, from a trusted CDN header, or null when none is trusted or the value is not
     * a real two-letter country (Cloudflare sends "XX" for unknown and "T1" for Tor).
     */
    public function countryFromHeaders(Request $request): ?string
    {
        foreach (config('billing.geo.country_headers', []) as $header) {
            $value = strtoupper(trim((string) $request->header($header, '')));

            if (preg_match('/^[A-Z]{2}$/', $value) === 1 && ! in_array($value, ['XX', 'T1'], true)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The currency for this visitor. Pinned in the session on first use.
     */
    public function forRequest(Request $request): string
    {
        $session = $request->hasSession() ? $request->session() : null;
        $pinned = $session?->get(self::SESSION_KEY);

        if (is_string($pinned) && in_array($pinned, $this->supported(), true)) {
            return $pinned;
        }

        $currency = $this->forCountry($this->countryFromHeaders($request));
        $session?->put(self::SESSION_KEY, $currency);

        return $currency;
    }
}
