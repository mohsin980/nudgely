<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ties one operation together in the logs: the HTTP request, the job, the provider call and the
 * webhook that comes back. It is a tracing label, never an authentication token, so it is safe to log.
 */
final class CorrelationId
{
    /**
     * The caller's request ID when it is a sane label, otherwise a fresh one.
     */
    public static function fromRequest(Request $request): string
    {
        $header = (string) $request->headers->get('X-Request-Id', '');

        return preg_match('/^[A-Za-z0-9._-]{8,64}$/', $header) === 1 ? $header : self::new();
    }

    public static function new(): string
    {
        return (string) Str::uuid();
    }
}
