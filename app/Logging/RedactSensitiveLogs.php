<?php

namespace App\Logging;

use App\Support\Logging\SensitiveDataRedactor;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Laravel "tap" for log channels: every handler redacts each record's context and extra data before it is written.
 * Runs once per handler; a handler shared by a stack is wrapped again harmlessly, because redaction is idempotent.
 */
final class RedactSensitiveLogs
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            if (method_exists($handler, 'pushProcessor')) {
                $handler->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(
                    context: SensitiveDataRedactor::redact($record->context),
                    extra: SensitiveDataRedactor::redact($record->extra),
                ));
            }
        }
    }
}
