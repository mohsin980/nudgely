{{-- One task. Expects $task and $organization (optional $showCustomer, and $assignable to reassign); the component provides completeTask() and, with $assignable, assignTask(). --}}
@php($done = $task->status === \App\Enums\TaskStatus::Completed)
@php($overdue = ! $done && $task->due_at !== null && $task->due_at->isPast())
<li wire:key="task-{{ $task->id }}" class="flex items-start justify-between gap-2 py-2">
    <div class="min-w-0">
        <p @class(['font-medium', 'text-gray-900' => ! $done, 'text-gray-500 line-through' => $done])>{{ $task->title }}</p>
        <p class="text-xs text-gray-600">
            @if (($showCustomer ?? false) && $task->customer)
                <a href="{{ $task->conversation_id ? route('inbox.show', $task->conversation_id) : route('customers.show', $task->customer_id) }}" wire:navigate class="font-medium text-gray-800 hover:underline">{{ $task->customer->name }}</a> ·
            @endif
            {{ ucfirst($task->priority->value) }} priority
            @if ($task->due_at) · due {{ $organization->localTime($task->due_at)->format('M j, g:i A') }} @endif
            @if ($overdue) · <span class="font-medium text-red-700">Overdue</span> @endif
            · {{ $done ? 'Completed' : 'Open' }}
            · {{ $task->assignee ? 'Assigned to '.$task->assignee->name : 'Unassigned' }}
            @if ($task->idempotency_key) · by automation @endif
        </p>
    </div>
    @if (! $done && isset($assignable))
        <label class="sr-only" for="task-{{ $task->id }}-assignee">Assign task: {{ $task->title }}</label>
        <select id="task-{{ $task->id }}-assignee" wire:change="assignTask({{ $task->id }}, $event.target.value)" class="shrink-0 rounded-md border-gray-300 py-1 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="">Unassigned</option>
            @foreach ($assignable as $userId => $userName)
                <option value="{{ $userId }}" @selected($task->assigned_to === $userId)>{{ $userName }}</option>
            @endforeach
        </select>
    @endif
    @unless ($done)
        <button type="button" wire:click="completeTask({{ $task->id }})" class="shrink-0 rounded-md bg-white px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">Complete<span class="sr-only"> task: {{ $task->title }}</span></button>
    @endunless
</li>
