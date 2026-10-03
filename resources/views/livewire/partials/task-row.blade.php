{{-- One task. Expects $task and $organization; the component provides completeTask(). --}}
@php($done = $task->status === \App\Enums\TaskStatus::Completed)
<li wire:key="task-{{ $task->id }}" class="flex items-start justify-between gap-2 py-2">
    <div class="min-w-0">
        <p @class(['font-medium', 'text-gray-900' => ! $done, 'text-gray-500 line-through' => $done])>{{ $task->title }}</p>
        <p class="text-xs text-gray-600">
            {{ ucfirst($task->priority->value) }} priority
            @if ($task->due_at) · due {{ $organization->localTime($task->due_at)->format('M j, g:i A') }} @endif
            · {{ $done ? 'Completed' : 'Open' }}
            @if ($task->idempotency_key) · by automation @endif
        </p>
    </div>
    @unless ($done)
        <button type="button" wire:click="completeTask({{ $task->id }})" class="shrink-0 rounded-md bg-white px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">Complete<span class="sr-only"> task: {{ $task->title }}</span></button>
    @endunless
</li>
