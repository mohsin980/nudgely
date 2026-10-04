<?php

namespace App\Services\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\Conversations\ConversationActionException;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Task;
use App\Models\User;
use App\Services\Team\ActivityNotifications;
use Illuminate\Support\Facades\Log;

/**
 * People create and complete customer tasks (automations create them too, via CreateTaskAction).
 */
class TaskService
{
    public function __construct(private readonly ActivityNotifications $notifications) {}

    /**
     * Tasks go only to active members of the actor's organization.
     *
     * @throws ConversationActionException
     */
    private function assertAssignable(User $assignee, User $actor): void
    {
        if ($assignee->organization_id !== $actor->organization_id || ! $assignee->isActiveMember()) {
            throw ConversationActionException::invalid(['taskAssignee' => 'Choose an active member of your team.']);
        }
    }

    /**
     * @throws ConversationActionException
     */
    public function create(User $actor, Customer $customer, string $title, TaskPriority $priority = TaskPriority::Medium, ?Conversation $conversation = null, ?\DateTimeInterface $dueAt = null, ?User $assignee = null): Task
    {
        // Unless someone else is chosen, the person creating the task takes it.
        $assignee ??= $actor;
        $this->assertAssignable($assignee, $actor);

        if ($actor->organization_id === null || $customer->organization_id !== $actor->organization_id
            || ($conversation !== null && ($conversation->organization_id !== $actor->organization_id || $conversation->customer_id !== $customer->id))) {
            throw new ConversationActionException('Customer not found.');
        }

        $title = trim(preg_replace('/\s+/', ' ', $title));

        if ($title === '' || mb_strlen($title) > 255) {
            throw ConversationActionException::invalid(['taskTitle' => 'Enter a task (up to 255 characters).']);
        }

        $task = new Task;
        $task->forceFill([
            'organization_id' => $actor->organization_id,
            'customer_id' => $customer->id,
            'conversation_id' => $conversation?->id,
            'created_by' => $actor->id,
            'assigned_to' => $assignee->id,
            'title' => $title,
            'priority' => $priority,
            'status' => TaskStatus::Pending,
            'due_at' => $dueAt,
        ])->save();

        Log::info('Task created.', ['organization_id' => $task->organization_id, 'task_id' => $task->id, 'user_id' => $actor->id]);
        $this->notifications->taskAssigned($task, $actor);

        return $task;
    }

    /**
     * Give an open task to another active team member (or nobody).
     *
     * @throws ConversationActionException
     */
    public function assign(User $actor, Task $task, ?User $assignee): Task
    {
        if ($actor->organization_id === null || $task->organization_id !== $actor->organization_id) {
            throw new ConversationActionException('Task not found.');
        }

        if ($task->status !== TaskStatus::Pending) {
            throw new ConversationActionException('Only open tasks can be reassigned.');
        }

        if ($assignee !== null) {
            $this->assertAssignable($assignee, $actor);
        }

        if ($task->assigned_to !== $assignee?->id) {
            $task->forceFill(['assigned_to' => $assignee?->id])->save();
            Log::info('Task assigned.', ['organization_id' => $task->organization_id, 'task_id' => $task->id, 'user_id' => $actor->id]);
            $this->notifications->taskAssigned($task, $actor);
        }

        return $task;
    }

    /**
     * @throws ConversationActionException
     */
    public function complete(User $actor, Task $task): Task
    {
        if ($actor->organization_id === null || $task->organization_id !== $actor->organization_id) {
            throw new ConversationActionException('Task not found.');
        }

        Task::query()->whereKey($task->id)->where('status', TaskStatus::Pending)->update(['status' => TaskStatus::Completed, 'completed_at' => now(), 'updated_at' => now()]);

        return $task->refresh();
    }
}
