<div class="space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">Follow-Ups</h1>
            <p class="mt-1 text-sm text-slate-500">Who to follow up with, and when. Times in {{ $organization->timezone() }}.</p>
        </div>
        @unless ($showScheduleForm)
            <button type="button" wire:click="openScheduleForm" class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-emerald-700">Schedule Follow-Up</button>
        @endunless
    </div>

    @include('follow-ups.flash')

    @if ($showScheduleForm)
        @include('follow-ups.schedule-form', ['customers' => $this->customers])
    @endif

    @if ($filter !== '')
        <p class="text-sm text-slate-500">Showing {{ $filter === 'overdue' ? 'overdue' : 'today’s' }} follow-ups only. <a href="{{ route('follow-ups.index') }}" wire:navigate class="font-medium text-violet-700 hover:underline">Show all follow-ups</a></p>
    @endif

    @foreach (array_filter(['overdue' => 'Overdue', 'due_today' => 'Due today', 'upcoming' => 'Upcoming'], fn ($heading, $key) => $filter === '' || ['overdue' => 'overdue', 'today' => 'due_today'][$filter] === $key, ARRAY_FILTER_USE_BOTH) as $key => $heading)
        <section aria-labelledby="{{ $key }}-heading" class="space-y-2" data-section="{{ $key }}">
            <h2 id="{{ $key }}-heading" @class(['text-base font-semibold text-slate-900', 'text-red-600' => $key === 'overdue', 'text-slate-900' => $key !== 'overdue'])>
                {{ $heading }} <span class="font-normal">({{ $this->sectionCounts[$key] }})</span>
            </h2>
            @if ($this->sections[$key]->isEmpty())
                <p class="rounded-2xl border border-dashed border-slate-300 bg-white px-4 py-5 text-sm text-slate-500">{{ $key === 'overdue' ? 'Nothing overdue.' : 'Nothing here.' }}</p>
            @else
                <ul role="list" @class(['divide-y divide-slate-100 rounded-2xl border bg-white shadow-xs', 'border-red-200' => $key === 'overdue', 'border-slate-200' => $key !== 'overdue'])>
                    @foreach ($this->sections[$key] as $followUp)
                        @include('follow-ups.item')
                    @endforeach
                </ul>
                @if ($this->sectionCounts[$key] > $this->sections[$key]->count())
                    <p class="text-xs text-slate-500">Showing the {{ $this->sections[$key]->count() }} soonest of {{ $this->sectionCounts[$key] }}.</p>
                @endif
            @endif
        </section>
    @endforeach

    @foreach ($filter === '' ? ['completed' => ['Completed', $this->completed], 'closed' => ['Cancelled & skipped', $this->closed]] : [] as $key => [$heading, $items])
        <section aria-labelledby="{{ $key }}-heading" class="space-y-2" data-section="{{ $key }}">
            <h2 id="{{ $key }}-heading" class="text-base font-semibold text-slate-900">{{ $heading }}</h2>
            @if ($items->isEmpty())
                <p class="text-sm text-slate-500">Nothing yet.</p>
            @else
                <ul role="list" class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white shadow-xs">
                    @foreach ($items as $followUp)
                        @include('follow-ups.item')
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach
</div>
