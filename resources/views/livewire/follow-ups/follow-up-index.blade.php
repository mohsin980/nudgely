<div class="space-y-6">
    @php($counts = $this->sectionCounts)
    @php($tabs = ['' => 'All', 'overdue' => 'Overdue', 'today' => 'Due today'])
    @php($kpis = [
        ['overdue', 'Overdue', route('follow-ups.index', ['filter' => 'overdue']), 'bg-linear-to-b from-rose-100 to-rose-50 text-rose-600', 'exclamation'],
        ['due_today', 'Due today', route('follow-ups.index', ['filter' => 'today']), 'bg-linear-to-b from-violet-100 to-violet-50 text-violet-600', 'clock'],
        ['upcoming', 'Coming up', route('follow-ups.index'), 'bg-linear-to-b from-emerald-100 to-emerald-50 text-emerald-600', 'bell'],
    ])

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('dashboard') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-semibold text-violet-700 hover:text-violet-800">
                <x-ui-icon name="arrow-left" class="size-4 stroke-2" /> Back to Dashboard
            </a>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900 sm:text-[34px]">Reminders</h1>
            <p class="mt-1 text-[15px] text-slate-500">Who to contact, and when. Times in {{ $organization->timezone() }}.</p>
        </div>
        <div class="flex items-center gap-4">
            @unless ($showScheduleForm)
                <button type="button" wire:click="openScheduleForm" class="inline-flex items-center gap-2 rounded-xl bg-linear-to-r from-violet-600 to-violet-500 px-5 py-3 text-[15px] font-semibold text-white shadow-[0_10px_24px_-8px_rgba(109,40,217,0.65)] transition hover:from-violet-700 hover:to-violet-600">
                    <x-ui-icon name="calendar" class="size-5" /> Schedule Follow-Up
                </button>
                <x-illustration name="calendar-clock" class="hidden size-24 -my-4 sm:block" />
            @endunless
        </div>
    </div>

    <section aria-label="Summary">
        <ul role="list" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            @foreach ($kpis as [$key, $label, $url, $iconTone, $icon])
                <li>
                    <a href="{{ $url }}" wire:navigate data-reminder-kpi="{{ $key }}"
                       class="group flex items-center gap-4 rounded-2xl border border-white bg-white/90 p-5 shadow-[0_8px_30px_-12px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70 transition hover:-translate-y-0.5 hover:shadow-[0_14px_34px_-12px_rgba(76,29,149,0.28)] focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
                        <span class="grid size-14 shrink-0 place-items-center rounded-2xl {{ $iconTone }}"><x-ui-icon :name="$icon" class="size-7" /></span>
                        <span class="flex-1">
                            <span class="block text-3xl leading-none font-extrabold tracking-tight text-slate-900">{{ $counts[$key] }}</span>
                            <span class="mt-1.5 block text-[15px] text-slate-500">{{ $label }}</span>
                        </span>
                        <span class="grid size-9 place-items-center rounded-full bg-slate-50 text-slate-500 transition group-hover:bg-violet-100 group-hover:text-violet-700"><x-ui-icon name="chevron-right" class="size-4 stroke-2" /></span>
                    </a>
                </li>
            @endforeach
        </ul>
    </section>

    <nav aria-label="Filter reminders" class="-mx-1 flex gap-2 overflow-x-auto px-1">
        @foreach ($tabs as $key => $label)
            <a href="{{ $key === '' ? route('follow-ups.index') : route('follow-ups.index', ['filter' => $key]) }}" wire:navigate
               @if ($filter === $key) aria-current="page" @endif
               @class([
                   'shrink-0 whitespace-nowrap rounded-full px-5 py-2 text-[15px] font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500',
                   'bg-linear-to-r from-violet-600 to-violet-500 text-white shadow-[0_8px_18px_-8px_rgba(109,40,217,0.7)]' => $filter === $key,
                   'bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50' => $filter !== $key,
               ])>{{ $label }}@if ($key === '') ({{ array_sum($counts) }})@endif</a>
        @endforeach
    </nav>

    @include('follow-ups.flash')

    @if ($showScheduleForm)
        @include('follow-ups.schedule-form', ['customers' => $this->customers, 'withTips' => true])
    @endif

    @if ($filter !== '')
        <p class="text-sm text-slate-500">Showing {{ $filter === 'overdue' ? 'overdue' : 'today’s' }} follow-ups only. <a href="{{ route('follow-ups.index') }}" wire:navigate class="font-medium text-violet-700 hover:underline">Show all follow-ups</a></p>
    @endif

    @php($look = [
        'overdue' => ['Overdue', 'Nothing overdue.', 'exclamation', 'bg-rose-100 text-rose-600', 'from-rose-50', 'calendar-alert'],
        'due_today' => ['Due today', 'Nothing due today.', 'clock', 'bg-violet-100 text-violet-600', 'from-violet-50', 'calendar-clock'],
        'upcoming' => ['Upcoming', 'Nothing upcoming.', 'arrow-path', 'bg-emerald-100 text-emerald-600', 'from-emerald-50', 'calendar-plus'],
        'completed' => ['Completed', 'Nothing yet.', 'check', 'bg-slate-100 text-slate-500', 'from-slate-100', 'check'],
        'closed' => ['Cancelled & skipped', 'Nothing yet.', 'close', 'bg-slate-100 text-slate-500', 'from-slate-100', null],
    ])
    @php($open = array_filter(['overdue', 'due_today', 'upcoming'], fn ($key) => $filter === '' || ['overdue' => 'overdue', 'today' => 'due_today'][$filter] === $key))
    @php($history = $filter === '' ? ['completed' => $this->completed, 'closed' => $this->closed] : [])

    <div class="space-y-4">
        @foreach (array_merge(array_fill_keys($open, null), $history) as $key => $historyItems)
            @php([$heading, $empty, $icon, $iconTone, $wash, $art] = $look[$key])
            @php($items = $historyItems ?? $this->sections[$key])
            @php($total = $historyItems === null ? $this->sectionCounts[$key] : null)
            @continue($key === 'closed' && $items->isEmpty())
            <section aria-labelledby="{{ $key }}-heading" data-section="{{ $key }}"
                     class="overflow-hidden rounded-2xl bg-white shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70">
                <div class="relative flex items-center gap-4 bg-linear-to-l {{ $wash }} via-white to-white px-5 py-4 sm:px-6">
                    <span class="grid size-14 shrink-0 place-items-center rounded-full {{ $iconTone }}"><x-ui-icon :name="$icon" class="size-7 stroke-2" /></span>
                    <div class="min-w-0 flex-1">
                        <h2 id="{{ $key }}-heading" class="text-lg font-bold text-slate-900">{{ $heading }}@if ($total !== null) <span>({{ $total }})</span>@endif</h2>
                        <p class="text-[15px] text-slate-500">
                            @if ($items->isEmpty())
                                {{ $empty }}
                            @elseif ($total !== null && $total > $items->count())
                                Showing the {{ $items->count() }} soonest of {{ $total }}.
                            @else
                                {{ trans_choice(':count follow-up|:count follow-ups', $items->count()) }}
                            @endif
                        </p>
                    </div>
                    @if ($art)
                        <x-illustration :name="$art" class="hidden size-16 shrink-0 sm:block" />
                    @endif
                </div>
                @if ($items->isNotEmpty())
                    <ul role="list" class="divide-y divide-slate-100 border-t border-slate-100">
                        @foreach ($items as $followUp)
                            @include('follow-ups.item')
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>
</div>
