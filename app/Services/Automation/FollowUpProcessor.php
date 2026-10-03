<?php

namespace App\Services\Automation;

use App\Enums\FollowUpStatus;
use App\Enums\MessageDirection;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Jobs\ProcessFollowUpJob;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Organization;
use App\Models\Task;
use App\Services\Email\EmailService;
use Illuminate\Support\Facades\Log;

/**
 * Processes scheduled follow-ups when they are due.
 *
 * Everything is re-checked at due time: automations still on, the automation still active,
 * no customer reply since the follow-up was scheduled, no opt-out. The follow-up email is
 * sent only when the organization allows unattended automated email and every email
 * safety check passes. Otherwise the business gets a reminder task instead.
 */
class FollowUpProcessor
{
    public const CUSTOMER_REPLIED = 'Follow-up skipped: Customer replied.';

    public function __construct(
        private readonly AutomatedEmailPolicy $policy,
        private readonly EmailService $email,
        private readonly EmailTemplateRenderer $templates,
    ) {}

    /**
     * Queue a job for each due follow-up. Called every minute by the scheduler.
     */
    public function dispatchDue(int $limit = 500): int
    {
        $ids = FollowUp::query()
            ->where('status', FollowUpStatus::Pending)
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->limit($limit)
            ->pluck('id');

        $ids->each(fn (int $id) => ProcessFollowUpJob::dispatch($id));

        return $ids->count();
    }

    /**
     * Skip a conversation's pending follow-ups because the customer replied.
     */
    public function skipForReply(int $organizationId, int $conversationId): int
    {
        return FollowUp::query()
            ->where('organization_id', $organizationId)
            ->where('conversation_id', $conversationId)
            ->where('status', FollowUpStatus::Pending)
            ->update(['status' => FollowUpStatus::Skipped, 'outcome' => self::CUSTOMER_REPLIED, 'processed_at' => now(), 'updated_at' => now()]);
    }

    public function process(int $followUpId): ?FollowUp
    {
        if (! $this->claim($followUpId)) {
            return null; // Not due, already processed, or being processed by another worker.
        }

        $followUp = FollowUp::findOrFail($followUpId);
        [$status, $outcome, $attributes] = $this->handle($followUp);

        $followUp->forceFill(['status' => $status, 'outcome' => $outcome, 'processed_at' => now()] + $attributes)->save();

        Log::info('Follow-up processed.', [
            'organization_id' => $followUp->organization_id,
            'follow_up_id' => $followUp->id,
            'status' => $status->value,
        ]);

        return $followUp;
    }

    public function markFailed(int $followUpId, string $outcome): void
    {
        FollowUp::query()
            ->whereKey($followUpId)
            ->whereIn('status', [FollowUpStatus::Pending, FollowUpStatus::Processing])
            ->update(['status' => FollowUpStatus::Failed, 'outcome' => $outcome, 'processed_at' => now(), 'updated_at' => now()]);
    }

    private function claim(int $followUpId): bool
    {
        return FollowUp::query()
            ->whereKey($followUpId)
            ->where('due_at', '<=', now())
            ->where(fn ($query) => $query
                ->where('status', FollowUpStatus::Pending)
                ->orWhere(fn ($query) => $query
                    ->where('status', FollowUpStatus::Processing)
                    ->where('updated_at', '<', now()->subSeconds((int) config('automation.retries.stale_after_seconds')))))
            ->update(['status' => FollowUpStatus::Processing, 'updated_at' => now()]) > 0;
    }

    /**
     * @return array{0: FollowUpStatus, 1: string, 2: array<string, mixed>}
     */
    private function handle(FollowUp $followUp): array
    {
        $organization = Organization::find($followUp->organization_id);

        if ($organization === null || ! config('automation.enabled') || ! $organization->automations_enabled) {
            return [FollowUpStatus::Skipped, 'Follow-up skipped: Automations are turned off.', []];
        }

        $automation = Automation::query()->forOrganization($organization)->find($followUp->automation_id);

        if ($automation === null || ! $automation->isActive()) {
            return [FollowUpStatus::Skipped, 'Follow-up skipped: The automation is no longer active.', []];
        }

        $conversation = $organization->conversations()->find($followUp->conversation_id);
        $customer = $organization->customers()->find($followUp->customer_id);

        if ($conversation === null || $customer === null || $conversation->customer_id !== $customer->id) {
            return [FollowUpStatus::Failed, 'Follow-up failed: The conversation is not available.', []];
        }

        if ($this->customerRepliedSince($followUp)) {
            return [FollowUpStatus::Skipped, self::CUSTOMER_REPLIED, []];
        }

        if ($customer->hasOptedOutOfEmail()) {
            return [FollowUpStatus::Skipped, 'Follow-up skipped: Customer opted out of email.', []];
        }

        $action = AutomationAction::query()->where('automation_id', $automation->id)->find($followUp->automation_action_id);

        if (! $organization->allowsUnattendedAutomatedEmail() || ($action?->requires_approval ?? true)) {
            $task = $this->reminderTask($followUp, $customer->name);

            return [FollowUpStatus::Completed, 'Automatic emails are off or need approval, so a reminder task was created.', ['task_id' => $task->id]];
        }

        $key = hash('sha256', 'follow_up|'.$followUp->id);

        if (($blocked = $this->policy->checkDelivery($organization, $conversation, $customer, $key)) !== null) {
            return [$blocked->success ? FollowUpStatus::Skipped : FollowUpStatus::Failed, 'Follow-up not sent: '.$blocked->message, []];
        }

        try {
            $text = $this->templates->render($followUp->body, $customer, $organization);
            $message = $this->email->sendToConversation(
                $conversation,
                $this->templates->render($followUp->subject, $customer, $organization),
                '<p>'.nl2br(e($text)).'</p>',
                $text,
                ['type' => 'follow_up', 'automation_key' => $key, 'follow_up_id' => (string) $followUp->id],
            );
        } catch (\InvalidArgumentException|EmailSendingNotAllowedException $e) {
            return [FollowUpStatus::Failed, 'Follow-up not sent: '.$e->getMessage(), []];
        }

        $this->policy->recordSent($organization->id);

        return [FollowUpStatus::Completed, 'Follow-up email sent.', ['message_id' => $message->id]];
    }

    private function customerRepliedSince(FollowUp $followUp): bool
    {
        return Message::query()
            ->where('organization_id', $followUp->organization_id)
            ->where('conversation_id', $followUp->conversation_id)
            ->where('direction', MessageDirection::Inbound)
            ->where('received_at', '>', $followUp->created_at)
            ->exists();
    }

    private function reminderTask(FollowUp $followUp, string $customerName): Task
    {
        $key = hash('sha256', 'follow_up_task|'.$followUp->id);

        return Task::query()->where('idempotency_key', $key)->first() ?? tap(new Task, fn (Task $task) => $task->forceFill([
            'organization_id' => $followUp->organization_id,
            'customer_id' => $followUp->customer_id,
            'conversation_id' => $followUp->conversation_id,
            'title' => "Follow up with {$customerName}",
            'description' => 'Scheduled by an automation. Automatic emails are off, so please follow up yourself.',
            'priority' => TaskPriority::Medium,
            'status' => TaskStatus::Pending,
            'due_at' => now(),
            'idempotency_key' => $key,
        ])->save());
    }
}
