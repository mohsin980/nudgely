<?php

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\AutomationActionInterface;
use App\Models\AutomationAction;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;

/**
 * send_email: registered so the type resolves, but automated email is not enabled yet.
 *
 * When implemented it must go through EmailService::sendToConversation() (verified
 * business sender, queued delivery) and enforce opt-outs, organization email settings,
 * approval and rate limits. It never calls an email provider directly. Sends nothing now.
 */
class SendEmailAction implements AutomationActionInterface
{
    public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult
    {
        return AutomationActionResult::skipped('Automated email is not available yet.', ['reason' => 'not_implemented']);
    }
}
