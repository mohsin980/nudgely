<?php

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\AutomationActionInterface;
use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\AutomationRun;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\EmailTemplateRenderer;
use App\Services\FollowUps\FollowUpService;

/**
 * schedule_follow_up: plans a follow-up for the conversation, processed by the scheduler when due.
 *
 * Configuration: delay_days (1–60), subject and body (email templates with {{variables}}).
 * Creates an automated FollowUp (Task 8). When due, FollowUpProcessor re-checks everything;
 * a customer reply first skips it. Idempotent per action and event through follow_ups.idempotency_key.
 */
class ScheduleFollowUpAction implements AutomationActionInterface
{
    public const MAX_DELAY_DAYS = 60;

    public function __construct(
        private readonly EmailTemplateRenderer $templates,
        private readonly FollowUpService $followUps,
    ) {}

    public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult
    {
        $config = $action->configuration ?? [];
        $delayDays = $config['delay_days'] ?? null;
        $subject = is_string($config['subject'] ?? null) ? trim($config['subject']) : '';
        $body = is_string($config['body'] ?? null) ? trim($config['body']) : '';

        if (! is_int($delayDays) || $delayDays < 1 || $delayDays > self::MAX_DELAY_DAYS) {
            return AutomationActionResult::failed('delay_days must be a whole number from 1 to 60.');
        }

        if ($subject === '' || $body === '' || mb_strlen($subject) > SendEmailAction::MAX_SUBJECT || mb_strlen($body) > SendEmailAction::MAX_BODY) {
            return AutomationActionResult::failed('A follow-up subject (up to 200 characters) and body (up to 5000) are required.');
        }

        try {
            $this->templates->validate($subject);
            $this->templates->validate($body);
        } catch (InvalidEmailTemplateException $e) {
            return AutomationActionResult::failed($e->getMessage());
        }

        $conversation = $context->conversation();
        $customer = $context->customer();

        if ($conversation === null || $customer === null || $conversation->customer_id !== $customer->id) {
            return AutomationActionResult::failed('A follow-up needs a conversation and customer in this organization.');
        }

        $automation = Automation::query()->where('organization_id', $context->organizationId)->findOrFail($action->automation_id);

        [$followUp, $created] = $this->followUps->scheduleAutomated(
            $automation,
            $conversation,
            now()->addDays($delayDays),
            $subject,
            $body,
            $context->idempotencyKey($action),
            [
                'automation_action_id' => $action->id,
                'automation_run_id' => $context->eventId === null ? null : AutomationRun::query()
                    ->where('organization_id', $context->organizationId)
                    ->where('automation_id', $action->automation_id)
                    ->where('event_type', $context->triggerType)
                    ->where('event_id', $context->eventId)
                    ->value('id'),
            ],
        );

        if (! $created) {
            return AutomationActionResult::skipped('Follow-up already scheduled.', ['reason' => 'already_exists', 'follow_up_id' => $followUp->id]);
        }

        return AutomationActionResult::completed('Follow-up scheduled for '.$followUp->due_at->format('M j, Y').'.', ['follow_up_id' => $followUp->id]);
    }
}
