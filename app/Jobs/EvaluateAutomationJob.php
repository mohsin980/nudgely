<?php

namespace App\Jobs;

use App\Contracts\Automation\AutomationEvent;
use App\Services\Automation\AutomationEngine;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Finds and starts the automations matching one event, off the request path.
 *
 * Safe to retry or deliver twice: runs are unique per automation and event.
 */
class EvaluateAutomationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly AutomationEvent $event,
        public readonly int $depth = 0,
    ) {
        $this->onQueue(config('automation.queue'));
    }

    public function handle(AutomationEngine $engine): void
    {
        $engine->evaluate($this->event, $this->depth);
    }
}
