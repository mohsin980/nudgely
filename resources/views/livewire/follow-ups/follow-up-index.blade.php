<div class="space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Follow-Ups</h1>
            <p class="mt-1 text-sm text-gray-600">Who to follow up with, and when. Times in {{ $organization->timezone() }}.</p>
        </div>
        @unless ($showScheduleForm)
            <button type="button" wire:click="openScheduleForm" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">Schedule Follow-Up</button>
        @endunless
    </div>

    @include('follow-ups.flash')

    @if ($showScheduleForm)
        @include('follow-ups.schedule-form', ['customers' => $this->customers])
    @endif

    @if ($filter !== '')
        <p class="text-sm text-gray-600">Showing {{ $filter === 'overdue' ? 'overdue' : 'today’s' }} follow-ups only. <a href="{{ route('follow-ups.index') }}" wire:navigate class="font-medium text-indigo-700 hover:underline">Show all follow-ups</a></p>
    @endif

    @foreach (array_filter(['overdue' => 'Overdue', 'due_today' => 'Due today', 'upcoming' => 'Upcoming'], fn ($heading, $key) => $filter === '' || ['overdue' => 'overdue', 'today' => 'due_today'][$filter] === $key, ARRAY_FILTER_USE_BOTH) as $key => $heading)
        <section aria-labelledby="{{ $key }}-heading" class="space-y-2" data-section="{{ $key }}">
            <h2 id="{{ $key }}-heading" @class(['text-sm font-semibold tracking-wide uppercase', 'text-red-700' => $key === 'overdue', 'text-gray-500' => $key !== 'overdue'])>
                {{ $heading }} <span class="font-normal">({{ $this->sectionCounts[$key] }})</span>
            </h2>
            @if ($this->sections[$key]->isEmpty())
                <p class="rounded-lg border border-dashed border-gray-300 bg-white px-4 py-4 text-sm text-gray-500">{{ $key === 'overdue' ? 'Nothing overdue.' : 'Nothing here.' }}</p>
            @else
                <ul role="list" @class(['divide-y divide-gray-100 rounded-lg border bg-white shadow-sm', 'border-red-200' => $key === 'overdue', 'border-gray-200' => $key !== 'overdue'])>
                    @foreach ($this->sections[$key] as $followUp)
                        @include('follow-ups.item')
                    @endforeach
                </ul>
                @if ($this->sectionCounts[$key] > $this->sections[$key]->count())
                    <p class="text-xs text-gray-500">Showing the {{ $this->sections[$key]->count() }} soonest of {{ $this->sectionCounts[$key] }}.</p>
                @endif
            @endif
        </section>
    @endforeach

    @foreach ($filter === '' ? ['completed' => ['Completed', $this->completed], 'closed' => ['Cancelled & skipped', $this->closed]] : [] as $key => [$heading, $items])
        <section aria-labelledby="{{ $key }}-heading" class="space-y-2" data-section="{{ $key }}">
            <h2 id="{{ $key }}-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">{{ $heading }}</h2>
            @if ($items->isEmpty())
                <p class="text-sm text-gray-500">Nothing yet.</p>
            @else
                <ul role="list" class="divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white">
                    @foreach ($items as $followUp)
                        @include('follow-ups.item')
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach
</div>
