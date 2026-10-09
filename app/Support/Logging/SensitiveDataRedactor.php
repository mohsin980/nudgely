<?php

namespace App\Support\Logging;

use BackedEnum;
use DateTimeInterface;
use Throwable;

/**
 * The one place that decides what a log line may contain.
 *
 * Applied to every log record through RedactSensitiveLogs, so no call site can leak a secret by forgetting
 * to filter it. Keys are matched by name (nested arrays included), values by pattern (emails, bearer and
 * basic credentials, URLs with user info, Stripe-style keys, long opaque tokens). Exceptions are reduced to
 * their class, a sanitized message and the origin file and line; the message is never logged raw.
 */
final class SensitiveDataRedactor
{
    public const REDACTED = '[redacted]';

    /** Key fragments: any key containing one of these is redacted. */
    private const SENSITIVE_FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'signature', 'api_key', 'apikey',
        'session', 'card_number', 'cvc', 'cvv', 'iban', 'routing_number', 'account_number', 'connection_string',
        'dsn', 'private_key', 'server_token', 'account_token',
    ];

    /** Whole keys that carry content: message bodies, attachments, raw payloads and headers. */
    private const CONTENT_KEYS = [
        'body', 'body_text', 'body_html', 'html', 'text', 'content', 'message_body', 'attachments', 'payload', 'raw',
        'headers', 'request', 'response_body', 'customer_message',
    ];

    private const MAX_DEPTH = 6;

    private const MAX_STRING = 500;

    /**
     * @return mixed The value with secrets and personal content replaced.
     */
    public static function redact(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            return self::REDACTED;
        }

        return match (true) {
            is_array($value) => self::redactArray($value, $depth),
            is_string($value) => self::redactString($value),
            $value instanceof Throwable => self::describe($value),
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_RFC3339),
            is_object($value) => '[object '.$value::class.']',
            default => $value,
        };
    }

    /**
     * A safe summary of an exception: class, sanitized message, origin and code. Never the trace or the arguments.
     *
     * @return array{exception: string, message: string, code: int|string|null, location: string}
     */
    public static function describe(Throwable $exception): array
    {
        return [
            'exception' => $exception::class,
            'message' => self::sanitizeMessage($exception->getMessage()),
            'code' => is_int($exception->getCode()) || is_string($exception->getCode()) ? $exception->getCode() : null,
            'location' => basename($exception->getFile()).':'.$exception->getLine(),
        ];
    }

    /**
     * Free text from an exception or provider: scrubbed of anything that looks like a credential or personal data.
     */
    public static function sanitizeMessage(string $message): string
    {
        return mb_substr(self::redactString($message), 0, 300);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function redactArray(array $values, int $depth): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            $name = is_string($key) ? strtolower($key) : null;

            if ($name !== null && (self::isSensitiveKey($name) || in_array($name, self::CONTENT_KEYS, true))) {
                $clean[$key] = self::REDACTED;

                continue;
            }

            $clean[$key] = self::redact($value, $depth + 1);
        }

        return $clean;
    }

    private static function isSensitiveKey(string $name): bool
    {
        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($name, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private static function redactString(string $value): string
    {
        // Credentials in headers or URLs first, so the later patterns don't see them.
        $value = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 '.self::REDACTED, $value) ?? $value;
        $value = preg_replace('#(\b[a-z][a-z0-9+.-]*://)[^\s/@]+@#i', '$1'.self::REDACTED.'@', $value) ?? $value;

        // Key=value pairs in free text: "password=hunter2", "token: abc".
        $value = preg_replace('/\b(password|passwd|secret|token|api[_-]?key|authorization|cookie|signature)(\s*[=:]\s*)[^\s,;&"\']+/i', '$1$2'.self::REDACTED, $value) ?? $value;

        // Query strings carry tokens and personal identifiers; the path is enough to find the route.
        $value = preg_replace('#(\b[a-z][a-z0-9+.-]*://[^\s?]+)\?[^\s]*#i', '$1?'.self::REDACTED, $value) ?? $value;
        $value = preg_replace('/\b(sk|rk|pk|whsec)_(live|test)_[A-Za-z0-9]+\b/', self::REDACTED, $value) ?? $value;
        $value = preg_replace('/\bwhsec_[A-Za-z0-9]+\b/', self::REDACTED, $value) ?? $value;

        // Email addresses: keep the first letter and the domain, which is enough to find the case.
        $value = preg_replace_callback('/\b([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})\b/', fn ($m) => $m[1].'***@'.$m[2], $value) ?? $value;

        // Internal addresses name infrastructure; a message is enough to know which service failed.
        $value = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', self::REDACTED, $value) ?? $value;

        // Long opaque runs (reply tokens, hashes, API secrets) are never useful to read.
        $value = preg_replace('/\b[A-Za-z0-9_\-]{40,}\b/', self::REDACTED, $value) ?? $value;

        return mb_strlen($value) > self::MAX_STRING ? mb_substr($value, 0, self::MAX_STRING).'…' : $value;
    }
}
