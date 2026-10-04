<?php

namespace App\Services\Automation\Actions;

use App\Contracts\Automation\AutomationActionInterface;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\Task;
use App\Models\User;
use App\Services\Automation\AutomationActionResult;
use App\Services\Automation\AutomationContext;
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
        // "owner": the person who created the automation (unassigned if they left).
        if ($assigneeId === 'owner') {
            $assigneeId = Automation::query()->whereKey($action->automation_id)->value('created_by');
        }

        if ($assigneeId !== null && ! User::query()->whereKey($assigneeId)->where('organization_id', $context->organizationId)->exists()) {
            return AutomationActionResult::failed('The assigned user is not a member of this organization.');
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

        return AutomationActionResult::completed("Task created: {$task->title}", ['task_id' => $task->id]);
    }
}
