<?php

namespace App\Contracts\Automation;

use App\Models\AutomationAction;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;

/**
 * One kind of automation action. Implementations must be safe to retry (idempotent),
 * act only inside the context's organization, and report problems as a failed result
 * rather than throwing for expected cases (bad configuration, missing records).
 */
interface AutomationActionInterface
{
    public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult;
}
