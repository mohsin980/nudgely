<?php

namespace App\Services\Team;

use App\Enums\Automation\AutomationRunStatus;
use App\Enums\FollowUpStatus;
use App\Enums\Team\NotificationType;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The business events team members can be notified about: who hears about each one and what
 * it says. Delivery and preferences are TeamNotifier's job. Never throws: a notification problem
 * must not undo the work that caused it.
 */
class ActivityNotifications
{
    public function __construct(
        private readonly TeamNotifier $notifier,
        private readonly TeamDirectory $team,
    ) {}

    /**
     * A customer replied: the owner and managers, plus whoever has an open follow-up with them.
     */
    public function customerReplied(int $organizationId, int $conversationId, int $messageId): void
    {
        $this->safely(function () use ($organizationId, $conversationId, $messageId) {
            $conversation = Conversation::query()->where('organization_id', $organizationId)->with('customer')->find($conversationId);

            if ($conversation === null) {
                return;
            }

            $assignees = FollowUp::query()->where('organization_id', $organizationId)->where('customer_id', $conversation->customer_id)
                ->whereIn('status', [FollowUpStatus::Pending, FollowUpStatus::Due])->whereNotNull('assigned_to')->pluck('assigned_to');
            $recipients = $this->team->leaders($organizationId)->merge(User::query()->activeIn($organizationId)->whereIn('id', $assignees)->get());

            $this->notifier->notify($conversation->organization, NotificationType::CustomerReply, $recipients,
                ($conversation->customer?->name ?? 'A customer').' replied: '.($conversation->subject ?? '(no subject)'),
                route('inbox.show', $conversation->id), "message:{$messageId}", data: ['conversation_id' => $conversation->id]);
        });
    }

    /**
     * An estimate was accepted or declined: whoever created it, and the owner and managers.
     */
    public function estimateDecided(int $organizationId, int $estimateId, bool $accepted): void
    {
        $this->safely(function () use ($organizationId, $estimateId, $accepted) {
            $estimate = Estimate::query()->where('organization_id', $organizationId)->with(['customer', 'organization'])->find($estimateId);

            if ($estimate === null) {
                return;
            }

            $creator = $this->team->activeMember($organizationId, $estimate->created_by);
            $recipients = $this->recipients($creator, $this->team->leaders($organizationId));

            $this->notifier->notify($estimate->organization, $accepted ? NotificationType::EstimateAccepted : NotificationType::EstimateDeclined, $recipients,
                ($estimate->customer?->name ?? 'A customer').($accepted ? ' accepted' : ' declined').' estimate '.$estimate->displayNumber().'.',
                route('estimates.show', $estimate->id), "estimate:{$estimate->id}:".($accepted ? 'accepted' : 'declined'), data: ['estimate_id' => $estimate->id]);
        });
    }

    /**
     * An automation execution failed: the automation's owner, and the owner and managers.
     */
    public function automationFailed(int $runId): void
    {
        $this->safely(function () use ($runId) {
            $run = AutomationRun::query()->with(['automation', 'customer'])->find($runId);

            if ($run === null || $run->status !== AutomationRunStatus::Failed || $run->automation === null) {
                return;
            }

            $organization = Organization::findOrFail($run->organization_id);
            $recipients = $this->recipients($this->team->automationOwner($run->automation), $this->team->leaders($organization->id));

            $this->notifier->notify($organization, NotificationType::AutomationFailed, $recipients,
                "Automation “{$run->automation->name}” failed".($run->customer ? " for {$run->customer->name}" : '').'.',
                route('automations.logs.show', [$run->automation_id, $run->id]), "automation_run:{$run->id}", data: ['automation_run_id' => $run->id]);
        });
    }

    /**
     * A task was given to someone (by a person or an automation); never when they took it themselves.
     */
    public function taskAssigned(Task $task, ?User $actor = null): void
    {
        $this->safely(function () use ($task, $actor) {
            $assignee = $this->team->activeMember($task->organization_id, $task->assigned_to);

            if ($assignee === null) {
                return;
            }

            $url = $task->conversation_id ? route('inbox.show', $task->conversation_id) : ($task->customer_id ? route('customers.show', $task->customer_id) : route('dashboard'));

            $this->notifier->notify(Organization::findOrFail($task->organization_id), NotificationType::TaskAssigned, [$assignee],
                ($actor ? "{$actor->name} assigned you a task: " : 'New task for you: ').$task->title,
                $url, "task:{$task->id}:assigned:{$assignee->id}", $actor, ['task_id' => $task->id]);
        });
    }

    /**
     * @param  Collection<int, User>  $others
     * @return Collection<int, User>
     */
    private function recipients(?User $first, Collection $others): Collection
    {
        return ($first ? collect([$first]) : collect())->merge($others);
    }

    private function safely(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Team notification failed.', ['exception' => $e::class]);
        }
    }
}
