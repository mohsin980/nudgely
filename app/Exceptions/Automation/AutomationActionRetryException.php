<?php

namespace App\Exceptions\Automation;

use RuntimeException;

/**
 * Thrown by the engine so the queue retries an action that failed temporarily.
 */
class AutomationActionRetryException extends RuntimeException
{
    public static function forActionRun(int $actionRunId): self
    {
        return new self("Automation action run {$actionRunId} failed temporarily and will be retried.");
    }
}
