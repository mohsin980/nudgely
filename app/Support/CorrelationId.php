<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ties one operation together in the logs: the HTTP request, the job, the provider call and the webhook that
 * comes back. It is a tracing label, never an authentication token, so it is safe to log and to return.
 *
 * AssignCorrelationId sets it once per request; everything below reads that value, so the controllers and the
 * middleware always agree.
 */
final class CorrelationId
{
    public const ATTRIBUTE = 'correlation_id';

    public const HEADER = 'X-Request-Id';

    /**
     * The request's ID: the one the middleware assigned, else the caller's request ID when it is a sane label,
     * otherwise a fresh one.
     */
    public static function fromRequest(Request $request): string
    {
        if (is_string($assigned = $request->attributes->get(self::ATTRIBUTE))) {
            return $assigned;
        }

        return self::sanitize((string) $request->headers->get(self::HEADER, '')) ?? self::new();
    }

    /**
     * A caller-supplied ID is accepted only if it is short and made of label characters. Anything else is
     * replaced, which keeps newlines and other log-injection characters out of every log line.
     */
    public static function sanitize(string $candidate): ?string
    {
        return preg_match('/^[A-Za-z0-9._-]{8,64}$/', $candidate) === 1 ? $candidate : null;
    }

    public static function new(): string
    {
        return (string) Str::uuid();
    }
}
