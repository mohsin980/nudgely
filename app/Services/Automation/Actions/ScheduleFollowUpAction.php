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
 * schedule_follow_up ("Create follow-up"): plans a follow-up, processed by the scheduler when due.
 *
 * kind "email" (default): an automated follow-up email to the customer (Task 8 safety rules).
 * kind "reminder": a reminder for the team, with a title.
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

        if (! is_int($delayDays) || $delayDays < 1 || $delayDays > self::MAX_DELAY_DAYS) {
            return AutomationActionResult::failed('delay_days must be a whole number from 1 to 60.');
        }

        $automation = Automation::query()->where('organization_id', $context->organizationId)->findOrFail($action->automation_id);
        $customer = $context->customer();

        if (($config['kind'] ?? 'email') === 'reminder') {
            return $this->reminder($automation, $action, $context, $delayDays, (string) ($config['title'] ?? ''));
        }

        $subject = is_string($config['subject'] ?? null) ? trim($config['subject']) : '';
        $body = is_string($config['body'] ?? null) ? trim($config['body']) : '';

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

        if ($conversation === null || $customer === null || $conversation->customer_id !== $customer->id) {
            return AutomationActionResult::failed('A follow-up needs a conversation and customer in this organization.');
        }

        [$followUp, $created] = $this->followUps->scheduleAutomated(
            $automation,
            $conversation,
            now()->addDays($delayDays),
            $subject,
            $body,
            $context->idempotencyKey($action),
            [
                // Linked to the estimate it is about, so accepting or declining it stops the follow-up.
                'estimate_id' => $context->estimate()?->id,
                'automation_action_id' => $action->id,
                'automation_run_id' => $this->runId($action, $context),
            ],
        );

        if (! $created) {
            return AutomationActionResult::skipped('Follow-up already scheduled.', ['reason' => 'already_exists', 'follow_up_id' => $followUp->id]);
        }

        return AutomationActionResult::completed('Follow-up scheduled for '.$followUp->due_at->format('M j, Y').'.', ['follow_up_id' => $followUp->id]);
    }

    /**
     * A reminder for the team (a manual-type follow-up): the scheduler notifies them when due.
     */
    private function reminder(Automation $automation, AutomationAction $action, AutomationContext $context, int $delayDays, string $title): AutomationActionResult
    {
        $customer = $context->customer();

        if ($customer === null) {
            return AutomationActionResult::failed('A follow-up needs a customer in this organization.');
        }

        try {
            $notes = trim($context->render($title));
        } catch (InvalidEmailTemplateException $e) {
            return AutomationActionResult::failed($e->getMessage());
        }

        if ($notes === '') {
            return AutomationActionResult::failed('A follow-up reminder needs a title.');
        }

        [$followUp, $created] = $this->followUps->scheduleReminder(
            $automation,
            $customer,
            $context->conversation(),
            $context->estimate(),
            now()->addDays($delayDays),
            $notes,
            $context->idempotencyKey($action),
            ['automation_action_id' => $action->id, 'automation_run_id' => $this->runId($action, $context)],
        );

        return $created
            ? AutomationActionResult::completed('Follow-up reminder scheduled for '.$followUp->due_at->format('M j, Y').'.', ['follow_up_id' => $followUp->id])
            : AutomationActionResult::skipped('Follow-up already scheduled.', ['reason' => 'already_exists', 'follow_up_id' => $followUp->id]);
    }

    private function runId(AutomationAction $action, AutomationContext $context): ?int
    {
        return $context->eventId === null ? null : AutomationRun::query()
            ->where('organization_id', $context->organizationId)
            ->where('automation_id', $action->automation_id)
            ->where('event_type', $context->triggerType)
            ->where('event_id', $context->eventId)
            ->value('id');
    }
}
