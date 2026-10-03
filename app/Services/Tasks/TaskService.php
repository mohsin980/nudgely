<?php

namespace App\Services\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Exceptions\Conversations\ConversationActionException;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * People create and complete customer tasks (automations create them too, via CreateTaskAction).
 */
class TaskService
{
    /**
     * @throws ConversationActionException
     */
    public function create(User $actor, Customer $customer, string $title, TaskPriority $priority = TaskPriority::Medium, ?Conversation $conversation = null, ?\DateTimeInterface $dueAt = null): Task
    {
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
            'assigned_to' => $actor->id,
            'title' => $title,
            'priority' => $priority,
            'status' => TaskStatus::Pending,
            'due_at' => $dueAt,
        ])->save();

        Log::info('Task created.', ['organization_id' => $task->organization_id, 'task_id' => $task->id, 'user_id' => $actor->id]);

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
