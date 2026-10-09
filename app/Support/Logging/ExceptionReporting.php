<?php

namespace App\Support\Logging;

use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use WeakMap;

/**
 * Logs an unexpected exception once, in a safe form. Returning false from the exception handler's reportable
 * callback stops Laravel's default report, so the full message and trace are never written.
 */
final class ExceptionReporting
{
    /** Exceptions another listener already logged (the queue lifecycle), so they are not logged twice. */
    private static ?WeakMap $logged = null;

    public static function markLogged(Throwable $exception): void
    {
        self::$logged ??= new WeakMap;
        self::$logged[$exception] = true;
    }

    public static function alreadyLogged(Throwable $exception): bool
    {
        return self::$logged !== null && isset(self::$logged[$exception]);
    }

    /**
     * @return false Always: the default report is replaced by this one line.
     */
    public static function report(Throwable $exception): bool
    {
        // Client errors below 500 are answers to the request, not incidents.
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return false;
        }

        // A queued job's failure is logged by the queue lifecycle listener, with the job's name and attempt.
        if (self::alreadyLogged($exception)) {
            return false;
        }

        $context = SensitiveDataRedactor::describe($exception) + [
            'event' => 'exception.unhandled',
            'route' => app()->runningInConsole() ? null : request()->route()?->getName(),
            'user_id' => app()->runningInConsole() ? null : auth()->id(),
        ];

        Log::error('Unhandled exception.', $context);

        return false;
    }
}
