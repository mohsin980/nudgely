<?php

namespace App\Listeners;

use App\Contracts\Automation\AutomationEvent;
use App\Jobs\EvaluateAutomationJob;
use App\Services\Automation\AutomationExecutionScope;

/**
 * Hands every automation event to the queue. Nothing is evaluated on the request path.
 *
 * An event raised while an automation action runs is queued one level deeper, which is
 * how the engine detects and stops automation chains.
 */
class QueueAutomationEvaluation
{
    public function __construct(private readonly AutomationExecutionScope $scope) {}

    public function handle(AutomationEvent $event): void
    {
        $current = $this->scope->currentDepth();

        EvaluateAutomationJob::dispatch($event, $current === null ? 0 : $current + 1);
    }
}
