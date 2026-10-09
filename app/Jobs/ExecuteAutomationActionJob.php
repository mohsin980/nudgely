<?php

namespace App\Jobs;

use App\Services\Automation\AutomationEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Executes one action of an automation run and records the result.
 *
 * Temporary failures are retried with backoff; permanent failures are recorded at once.
 * When retries run out, failed() records the reason on the action run.
 */
class ExecuteAutomationActionJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout = 120;

    public function __construct(public readonly int $actionRunId)
    {
        $this->tries = max(1, (int) config('automation.retries.tries'));
        $this->onQueue(config('automation.queue'));
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return config('automation.retries.backoff');
    }

    public function handle(AutomationEngine $engine): void
    {
        $engine->executeActionRun($this->actionRunId, finalAttempt: $this->attempts() >= $this->tries);
    }

    public function failed(?Throwable $exception): void
    {
        app(AutomationEngine::class)->markActionRunFailed($this->actionRunId, 'The action could not be completed after retrying.');
    }
}
