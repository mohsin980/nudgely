<div class="space-y-6">
    @php($counts = $this->sectionCounts)
    @php($tabs = ['' => 'All', 'overdue' => 'Overdue', 'today' => 'Due today'])
    @php($kpis = [
        ['overdue', 'Overdue', route('follow-ups.index', ['filter' => 'overdue']), $counts['overdue'] > 0 ? 'text-red-600' : 'text-slate-900', $counts['overdue'] > 0 ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-500', 'exclamation'],
        ['due_today', 'Due today', route('follow-ups.index', ['filter' => 'today']), 'text-slate-900', 'bg-emerald-50 text-emerald-600', 'clock'],
        ['upcoming', 'Coming up', route('follow-ups.index'), 'text-slate-900', 'bg-emerald-50 text-emerald-600', 'bell'],
    ])

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">Reminders</h1>
            <p class="mt-1 text-[15px] text-slate-500">Who to contact, and when. Times in {{ $organization->timezone() }}.</p>
        </div>
        @unless ($showScheduleForm)
            <button type="button" wire:click="openScheduleForm" class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-emerald-700">Schedule Follow-Up</button>
        @endunless
    </div>

    <section aria-label="Summary">
        <ul role="list" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            @foreach ($kpis as [$key, $label, $url, $valueTone, $iconTone, $icon])
                <li>
                    <a href="{{ $url }}" wire:navigate data-reminder-kpi="{{ $key }}"
                       class="flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs transition hover:border-slate-300 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
                        <span class="grid size-11 shrink-0 place-items-center rounded-xl {{ $iconTone }}"><x-ui-icon :name="$icon" class="size-6" /></span>
                        <span>
                            <span class="block text-2xl leading-none font-semibold tracking-tight {{ $valueTone }}">{{ $counts[$key] }}</span>
                            <span class="mt-1 block text-sm text-slate-500">{{ $label }}</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    <nav aria-label="Filter reminders" class="-mx-1 flex gap-1 overflow-x-auto px-1">
        @foreach ($tabs as $key => $label)
            <a href="{{ $key === '' ? route('follow-ups.index') : route('follow-ups.index', ['filter' => $key]) }}" wire:navigate
               @if ($filter === $key) aria-current="page" @endif
               @class([
                   'shrink-0 whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-medium ring-1 ring-inset transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500',
                   'bg-emerald-600 text-white ring-emerald-600' => $filter === $key,
                   'bg-white text-slate-700 ring-slate-300 hover:bg-slate-50' => $filter !== $key,
               ])>{{ $label }}</a>
        @endforeach
    </nav>

    @include('follow-ups.flash')

    @if ($showScheduleForm)
        @include('follow-ups.schedule-form', ['customers' => $this->customers])
    @endif

    @if ($filter !== '')
        <p class="text-sm text-slate-500">Showing {{ $filter === 'overdue' ? 'overdue' : 'today’s' }} follow-ups only. <a href="{{ route('follow-ups.index') }}" wire:navigate class="font-medium text-emerald-700 hover:underline">Show all follow-ups</a></p>
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
