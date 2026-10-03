<?php

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\AutomationActionInterface;
use App\Models\AutomationAction;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;

/**
 * schedule_follow_up: registered so the type resolves, but scheduled follow-ups (storage
 * and the scheduler that raises FollowUpDue) arrive in the next task. Does nothing yet.
 */
class ScheduleFollowUpAction implements AutomationActionInterface
{
    public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult
    {
        return AutomationActionResult::skipped('Scheduling follow-ups is not available yet.', ['reason' => 'not_implemented']);
    }
}
