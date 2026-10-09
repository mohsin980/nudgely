<?php

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\AutomationActionInterface;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;
use App\Services\Team\ActivityNotifications;
use App\Services\Team\TeamDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * create_task: adds a to-do for the business.
 *
 * Configuration: title (required; placeholders allowed), description, priority (low|medium|high),
 * due_in_hours (0–8760), assign_to ("owner" = the automation's creator, or a user ID in the same organization).
 * Idempotent per action and event through tasks.idempotency_key.
 */
class CreateTaskAction implements AutomationActionInterface
{
    public function execute(AutomationAction $action, AutomationContext $context): AutomationActionResult
    {
        $config = $action->configuration ?? [];

        try {
            $title = is_string($config['title'] ?? null) ? trim($context->render($config['title'])) : '';
            $description = is_string($config['description'] ?? null) ? Str::limit($context->render($config['description']), 2000, '…') : null;
        } catch (InvalidEmailTemplateException $e) {
            return AutomationActionResult::failed($e->getMessage());
        }

        if ($title === '') {
            return AutomationActionResult::failed('A task title is required.');
        }

        $priority = TaskPriority::tryFrom((string) ($config['priority'] ?? 'medium'));
        if ($priority === null) {
            return AutomationActionResult::failed('Invalid task priority.');
        }

        $dueInHours = $config['due_in_hours'] ?? null;
        if ($dueInHours !== null && (! is_int($dueInHours) || $dueInHours < 0 || $dueInHours > 8760)) {
            return AutomationActionResult::failed('due_in_hours must be a whole number from 0 to 8760.');
        }

        $assigneeId = $config['assign_to'] ?? null;
        $team = app(TeamDirectory::class);

        if ($assigneeId === 'owner') {
            // The automation's owner: its creator while active, else the default automation owner.
            $automation = Automation::query()->where('organization_id', $context->organizationId)->find($action->automation_id);
            $assigneeId = $automation === null ? null : $team->automationOwner($automation)?->id;
        } elseif ($assigneeId === null) {
            // Not chosen in the automation: the business's default task assignee, if any.
            $organization = Organization::find($context->organizationId);
            $assigneeId = $organization === null ? null : $team->defaultTaskAssignee($organization)?->id;
        } elseif ($team->activeMember($context->organizationId, $assigneeId) === null) {
            return AutomationActionResult::failed(User::query()->whereKey($assigneeId)->where('organization_id', $context->organizationId)->exists()
                ? 'The assigned person is suspended or no longer on the team.'
                : 'The assigned user is not a member of this organization.');
        }

        if (($context->customerId !== null && $context->customer() === null) || ($context->conversationId !== null && $context->conversation() === null)) {
            return AutomationActionResult::failed('The customer or conversation does not belong to this organization.');
        }

        $key = $context->idempotencyKey($action);

        if ($key !== null && ($existing = Task::query()->where('idempotency_key', $key)->value('id')) !== null) {
            return AutomationActionResult::skipped('Task already created.', ['reason' => 'already_exists', 'task_id' => $existing]);
        }

        try {
            $task = DB::transaction(function () use ($context, $title, $description, $priority, $dueInHours, $assigneeId, $key) {
                $task = new Task;
                $task->forceFill([
                    'organization_id' => $context->organizationId,
                    'customer_id' => $context->customer()?->id,
                    'conversation_id' => $context->conversation()?->id,
                    'assigned_to' => $assigneeId,
                    'title' => Str::limit($title, 255, '…'),
                    'description' => $description,
                    'priority' => $priority,
                    'status' => TaskStatus::Pending,
                    'due_at' => $dueInHours === null ? null : now()->addHours($dueInHours),
                    'idempotency_key' => $key,
                ])->save();

                return $task;
            });
        } catch (UniqueConstraintViolationException) {
            return AutomationActionResult::skipped('Task already created.', ['reason' => 'already_exists', 'task_id' => Task::query()->where('idempotency_key', $key)->value('id')]);
        }

        app(ActivityNotifications::class)->taskAssigned($task);

        return AutomationActionResult::completed("Task created: {$task->title}", ['task_id' => $task->id]);
    }
}
