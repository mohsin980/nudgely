<?php

namespace App\Jobs;

use App\Services\Automation\AutomationEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Continues an automation run after its WAIT. Safe to deliver twice: the engine claims the
 * run atomically, so only one job ever resumes it.
 */
class ResumeAutomationRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public function __construct(public readonly int $runId)
    {
        $this->onQueue(config('automation.queue'));
    }

    public function handle(AutomationEngine $engine): void
    {
        $engine->resume($this->runId);
    }
}
