<?php

namespace App\Support\Audit;

/**
 * Cleans anything before it is written to the audit log, so the log can never become a place where secrets or
 * personal content accumulate. Applied by PlatformAuditLog::record() to every event: callers cannot skip it.
 *
 * - Keys that name secrets (password, token, key, secret, authorization, card, cvc, ...) or message content (body,
 *   html, content, payload, ...) are replaced by "[redacted]", whatever the value.
 * - String values that look like secrets (Stripe keys and webhook secrets, bearer tokens, JWTs, long opaque tokens,
 *   card numbers) are replaced, and email addresses are masked, wherever they appear, including in free text.
 * - Depth, number of keys and string length are capped; objects are never serialized.
 */
final class AuditRedactor
{
    public const REDACTED = '[redacted]';

    private const MAX_DEPTH = 4;

    private const MAX_KEYS = 50;

    private const MAX_STRING = 500;

    private const SENSITIVE_KEY = '/pass(word|phrase)?|secret|token|api[_-]?key|\bkey\b|_key$|authorization|bearer|cookie|session|credential|private|signature|card|cvc|cvv|iban|account[_-]?number|routing|\bssn\b|otp|pin\b/i';

    private const CONTENT_KEY = '/^(body|html|text|content|raw|payload|message|snippet|attachments?)$|_(body|html|content|payload)$/i';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function sanitize(array $data, int $depth = 0): array
    {
        $clean = [];

        foreach (array_slice($data, 0, self::MAX_KEYS, preserve_keys: true) as $key => $value) {
            if (is_string($key) && (preg_match(self::SENSITIVE_KEY, $key) || preg_match(self::CONTENT_KEY, $key))) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = self::value($value, $depth + 1);
        }

        return $clean;
    }

    /** Free text (such as an administrator's reason): secrets and addresses removed, length capped. */
    public static function text(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        foreach (self::VALUE_PATTERNS() as $pattern) {
            $text = preg_replace($pattern, self::REDACTED, $text) ?? $text;
        }

        $text = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[email]', $text) ?? $text;
        $text = self::maskCardNumbers($text);

        return mb_substr($text, 0, self::MAX_STRING);
    }

    private static function value(mixed $value, int $depth): mixed
    {
        return match (true) {
            is_array($value) => $depth > self::MAX_DEPTH ? '[truncated]' : self::sanitize($value, $depth),
            is_string($value) => self::text($value),
            is_int($value), is_float($value), is_bool($value), $value === null => $value,
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            default => '['.get_debug_type($value).']',
        };
    }

    /** @return list<string> */
    private static function VALUE_PATTERNS(): array
    {
        return [
            '/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]+/',
            '/\bwhsec_[A-Za-z0-9]+/',
            '/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/i',
            '/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]*/',
            '/\b[A-Za-z0-9_\-]{40,}\b/',
        ];
    }

    private static function maskCardNumbers(string $text): string
    {
        return preg_replace_callback('/\b(?:\d[ \-]?){13,19}\b/', function (array $match) {
            $digits = preg_replace('/\D/', '', $match[0]) ?? '';

            return self::luhn($digits) ? self::REDACTED : $match[0];
        }, $text) ?? $text;
    }

    private static function luhn(string $digits): bool
    {
        if (strlen($digits) < 13 || strlen($digits) > 19) {
            return false;
        }

        $sum = 0;

        foreach (array_reverse(str_split($digits)) as $i => $digit) {
            $n = (int) $digit;
            $n = $i % 2 === 1 ? ($n * 2 > 9 ? $n * 2 - 9 : $n * 2) : $n;
            $sum += $n;
        }

        return $sum % 10 === 0;
    }
}
