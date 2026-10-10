{{-- One follow-up row on the dashboard. Expects $followUp, $organization and $overdue. --}}
<li wire:key="dashboard-follow-up-{{ $followUp->id }}" class="flex items-center justify-between gap-3 py-2.5 text-sm">
    <div class="min-w-0">
        <p class="flex flex-wrap items-center gap-x-2">
            <span @class(['font-medium', 'text-red-700' => $overdue, 'text-gray-900' => ! $overdue])>
                @if ($overdue)
                    <span class="sr-only">Overdue, was due</span>
                    <x-follow-up-due :at="$followUp->due_at" :organization="$organization" />
                @else
                    <time datetime="{{ $organization->localTime($followUp->due_at)->toIso8601String() }}">{{ $organization->localTime($followUp->due_at)->format('g:i A') }}</time>
                @endif
            </span>
            <span class="font-semibold text-gray-900">{{ $followUp->customer?->name }}</span>
        </p>
        <p class="truncate text-gray-600">{{ $followUp->reason() }}</p>
    </div>
    <a href="{{ route('follow-ups.index', ['filter' => $overdue ? 'overdue' : 'today']) }}#follow-up-{{ $followUp->id }}" wire:navigate
       class="shrink-0 rounded-md bg-white px-2.5 py-1 font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
        Open<span class="sr-only"> follow-up with {{ $followUp->customer?->name }}</span>
    </a>
</li>
