<?php

namespace App\Services\Billing;

/**
 * Verifies the Stripe-Signature header: "t=<timestamp>,v1=<hex hmac>[,v1=…]" where the HMAC is
 * SHA-256 of "<timestamp>.<raw body>" with the webhook signing secret. Rejects old timestamps
 * (replay protection) and compares in constant time.
 */
class StripeWebhookVerifier
{
    public function __construct(private readonly int $toleranceSeconds = 300) {}

    public function verify(string $payload, ?string $header, ?string $secret): bool
    {
        if (blank($secret) || blank($header)) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs(time() - $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
