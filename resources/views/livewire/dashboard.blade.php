<div class="space-y-5" wire:poll.{{ (int) config('dashboard.refresh_seconds') }}s.visible>
    @php
        $card = 'rounded-2xl bg-white p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70';
        $heading = 'text-lg font-bold text-slate-900';
        $badge = 'grid size-11 shrink-0 place-items-center rounded-full';
        $bigBadge = 'grid size-16 shrink-0 place-items-center rounded-full';
        $pill = 'inline-flex shrink-0 items-center gap-1 rounded-full bg-violet-50 px-4 py-2 text-sm font-semibold text-violet-700 ring-1 ring-violet-100 transition hover:bg-violet-100';
        $row = 'flex items-center justify-between gap-4 py-2.5';
        $link = 'inline-flex items-center gap-1 text-sm font-semibold text-violet-700 hover:text-violet-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 rounded';
        $arrow = '<svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3"/></svg>';
        $button = 'inline-flex items-center gap-1.5 rounded-xl bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-xs ring-1 ring-slate-300 ring-inset transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500';
        $tab = 'border-b-2 px-1 pb-2.5 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 rounded-t';
        $pipeline = $d->estimatePipeline;
        $health = $d->automationHealth;
        $money = fn (int $cents) => \App\Support\Money::format($cents, $organization->currency);
        $tones = [
            'violet' => 'bg-violet-100 text-violet-600', 'red' => 'bg-red-100 text-red-600', 'amber' => 'bg-amber-100 text-amber-600',
            'sky' => 'bg-sky-100 text-sky-600', 'emerald' => 'bg-emerald-100 text-emerald-600', 'slate' => 'bg-slate-100 text-slate-500',
        ];
        $waves = ['violet' => '#c4b5fd', 'red' => '#fca5a5', 'amber' => '#fcd34d', 'sky' => '#93c5fd', 'emerald' => '#86efac', 'slate' => '#cbd5e1'];
        $badgeTones = ['violet' => 'bg-violet-100 text-violet-700', 'red' => 'bg-red-100 text-red-700', 'amber' => 'bg-amber-100 text-amber-700', 'sky' => 'bg-sky-100 text-sky-700'];
        $followTab = $d->todaysFollowUps->isNotEmpty() || $d->overdueFollowUps->isEmpty() ? 'today' : 'overdue';
        $needsReply = $d->recentReplies->filter(fn ($reply) => $reply->conversation?->isWaitingForBusiness())->count();
        $workload = collect([
            $d->summary['waiting'] ? trans_choice(':count reply waiting|:count replies waiting', $d->summary['waiting']) : null,
            $d->summary['due_today'] ? trans_choice(':count follow-up due today|:count follow-ups due today', $d->summary['due_today']) : null,
            $d->summary['overdue'] ? $d->summary['overdue'].' overdue' : null,
        ])->filter()->implode(' · ');
    @endphp

    {{-- Search and notifications --}}
    <div class="flex items-center gap-3">
        <form method="GET" action="{{ route('customers.index') }}" role="search" class="relative min-w-0 flex-1 sm:max-w-xl">
            <label for="dashboard-search" class="sr-only">Search customers</label>
            <x-ui-icon name="search" class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-slate-400" />
            <input id="dashboard-search" type="search" name="search" placeholder="Search customers by name, email or phone…"
                   class="block w-full rounded-2xl border-0 bg-white/90 py-3 pr-3 pl-12 text-[15px] text-slate-900 shadow-[0_6px_20px_-10px_rgba(76,29,149,0.25)] ring-1 ring-slate-200/70 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-500">
        </form>
        <a href="#notifications" class="relative ml-auto grid size-12 shrink-0 place-items-center rounded-2xl bg-white text-slate-600 shadow-[0_6px_20px_-10px_rgba(76,29,149,0.25)] ring-1 ring-slate-200/70 ring-inset hover:text-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
            <x-ui-icon name="bell" class="size-5" />
            @if ($d->unreadNotifications)
                <span class="absolute -top-1.5 -right-1.5 grid min-w-5 place-items-center rounded-full bg-red-500 px-1 text-[11px] leading-5 font-semibold text-white" data-bell-count>{{ $d->unreadNotifications }}</span>
            @endif
            <span class="sr-only">Notifications{{ $d->unreadNotifications ? ': '.$d->unreadNotifications.' unread' : '' }}</span>
        </a>
        <a href="{{ route('estimates.create') }}" wire:navigate class="hidden items-center gap-1.5 rounded-2xl bg-linear-to-r from-violet-600 to-violet-500 px-5 py-3 text-[15px] font-semibold text-white shadow-[0_10px_24px_-8px_rgba(109,40,217,0.65)] transition hover:from-violet-700 hover:to-violet-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 sm:inline-flex"><x-ui-icon name="plus" class="size-5 stroke-2" />Create Estimate</a>
    </div>

    {{-- Greeting --}}
    <div class="relative flex flex-wrap items-center justify-between gap-4 overflow-hidden rounded-3xl bg-linear-to-r from-violet-100 via-violet-50 to-fuchsia-100/70 px-6 py-7 ring-1 ring-white shadow-[0_14px_40px_-20px_rgba(91,33,182,0.35)] sm:px-8">
        <div aria-hidden="true" class="absolute -top-16 right-1/4 size-64 rounded-full bg-white/50 blur-3xl"></div>
        <div class="relative">
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-[34px]">{{ $greeting }}, {{ $firstName }}! <span aria-hidden="true">👋</span></h1>
            <p class="mt-1.5 text-[15px] text-slate-600">{{ "Here's what's happening at ".$organization->name.' today.' }}@if ($workload) <span class="font-medium text-slate-700" data-workload>{{ $workload }}.</span>@endif</p>
        </div>
        <x-illustration name="hero" class="relative hidden h-32 w-auto lg:block" />
        <div class="relative flex flex-wrap items-center gap-2 lg:hidden">
            <p class="rounded-full bg-white/80 px-3.5 py-2 text-sm text-slate-600 shadow-xs ring-1 ring-slate-200">
                <time datetime="{{ $d->localNow->toDateString() }}">{{ $d->localNow->format('D, M j, Y') }}</time>
                <span class="sr-only">({{ $organization->timezone() }})</span>
                <span wire:loading.delay class="ml-2 text-xs text-slate-500" role="status">Updating…</span>
            </p>
        </div>
    </div>

    {{-- Quick actions --}}
    <section aria-labelledby="quick-heading">
        <h2 id="quick-heading" class="sr-only">Quick actions</h2>
        <ul role="list" class="flex flex-wrap gap-2">
            <li>
                <button type="button" wire:click="$toggle('showAddCustomer')" aria-expanded="{{ $showAddCustomer ? 'true' : 'false' }}" aria-controls="add-customer-panel" class="{{ $button }}"><x-ui-icon name="user-plus" class="size-4 text-violet-600" />Add a customer</button>
            </li>
            <li><a href="{{ route('estimates.create') }}" wire:navigate class="{{ $button }}"><x-ui-icon name="document" class="size-4 text-sky-600" />Create a quote</a></li>
            <li><a href="{{ route('inbox.index') }}" wire:navigate class="{{ $button }}"><x-ui-icon name="chat" class="size-4 text-emerald-600" />Reply to a message</a></li>
            <li><a href="{{ route('follow-ups.index', ['schedule' => 1]) }}" wire:navigate class="{{ $button }}"><x-ui-icon name="bell" class="size-4 text-amber-600" />Set a reminder</a></li>
        </ul>

        @if ($showAddCustomer)
            <div id="add-customer-panel" class="mt-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs sm:p-6">
                <livewire:customers.create-customer-form />
            </div>
        @endif
    </section>

    @if (! $d->hasCustomers)
        @if ($checklist)
            {{-- Getting started: disappears by itself once everything is done. --}}
            <section aria-labelledby="checklist-heading" class="{{ $card }}" data-section="getting-started">
                <h2 id="checklist-heading" class="{{ $heading }}">Setup checklist</h2>
                <ul role="list" class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                    @foreach ($checklist as $item)
                        <li class="flex items-center gap-2">
                            <span aria-hidden="true" @class(['inline-flex h-5 w-5 items-center justify-center rounded-full text-xs', 'bg-green-100 text-green-800' => $item['done'], 'ring-1 ring-slate-300 text-slate-400' => ! $item['done']])>{{ $item['done'] ? '✓' : '○' }}</span>
                            @if ($item['done'])
                                <span class="text-slate-500"><span class="sr-only">Done: </span>{{ $item['label'] }}</span>
                            @else
                                <a href="{{ $item['url'] }}" wire:navigate class="font-medium text-violet-700 hover:underline">{{ $item['label'] }}</a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        {{-- New business: one clear next step instead of a wall of zeros. --}}
        <section aria-labelledby="welcome-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
            <div class="flex flex-col gap-5 bg-linear-to-br from-violet-50 via-white to-emerald-50 p-6 sm:p-8">
                <div class="flex items-start gap-4">
                    <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-white text-violet-600 shadow-xs ring-1 ring-slate-200"><x-ui-icon name="users" class="size-6" /></span>
                    <div>
                        <h2 id="welcome-heading" class="text-xl font-semibold text-slate-900">No customers yet.</h2>
                        <p class="mt-1 max-w-xl text-[15px] text-slate-600">Add your first customer to get started. Let's get your first customer workflow running.</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2" data-empty-actions>
                    <a href="{{ route('customers.create') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-emerald-700"><x-ui-icon name="plus" class="size-4" />Add Customer</a>
                    <a href="{{ route('estimates.create') }}" wire:navigate class="{{ $button }}">Create Estimate</a>
                    <a href="{{ route('automations.index') }}" wire:navigate class="{{ $button }}">Create Automation</a>
                </div>
            </div>
            <div class="border-t border-slate-100 p-5 sm:p-8">
                <h3 class="text-sm font-semibold text-slate-900">Or add one right here</h3>
                <p class="mb-4 text-sm text-slate-500">Just a name and an email is enough.</p>
                <livewire:customers.create-customer-form />
            </div>
        </section>
    @else
        {{-- Key numbers. Each card opens the matching list. --}}
        <section aria-labelledby="glance-heading">
            <h2 id="glance-heading" class="sr-only">Key numbers</h2>
            <ul role="list" class="grid grid-cols-2 gap-4 md:grid-cols-3 min-[1600px]:grid-cols-6">
                @php
                    $trend = $pipeline['sent_trend'];
                    $kpis = [
                        ['estimates_sent', 'Estimates sent', $pipeline['sent_7d'], 'last 7 days', route('estimates.index'), 'violet', 'document'],
                        ['awaiting', 'Awaiting response', $pipeline['outstanding_count'], $money($pipeline['outstanding_cents']).' outstanding', route('estimates.index', ['status' => 'awaiting']), 'sky', 'chat'],
                        ['due_today', 'Follow-ups due', $d->summary['due_today'], 'today', route('follow-ups.index', ['filter' => 'today']), 'emerald', 'clock'],
                        ['overdue', 'Overdue follow-ups', $d->summary['overdue'], $d->summary['overdue'] ? 'need action' : 'none overdue', route('follow-ups.index', ['filter' => 'overdue']), $d->summary['overdue'] > 0 ? 'red' : 'slate', 'exclamation'],
                        ['new_replies', 'New replies', $d->summary['new_replies'], 'last '.(int) config('dashboard.new_replies_hours').'h', route('inbox.index'), 'amber', 'envelope'],
                        ['accepted_value', 'Accepted value', $money($pipeline['accepted_recent_cents']), $pipeline['accepted_recent_count'].' accepted, last 30 days', route('estimates.index', ['status' => 'accepted']), 'emerald', 'check'],
                    ];
                @endphp
                @foreach ($kpis as [$key, $label, $value, $note, $url, $tone, $icon])
                    <li>
                        <a href="{{ $url }}" wire:navigate data-card="{{ $key }}"
                           class="relative flex h-full flex-col overflow-hidden rounded-2xl bg-white p-4 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.2)] ring-1 ring-slate-200/70 transition hover:-translate-y-0.5 hover:shadow-[0_14px_34px_-12px_rgba(76,29,149,0.3)] focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
                            <svg aria-hidden="true" class="absolute right-0 bottom-0 h-14 w-2/3" viewBox="0 0 120 50" preserveAspectRatio="none"><defs><linearGradient id="wave-{{ $key }}" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="{{ $waves[$tone] }}" stop-opacity=".55"/><stop offset="1" stop-color="{{ $waves[$tone] }}" stop-opacity="0"/></linearGradient></defs><path d="M0 50C20 46 30 40 45 30S75 4 95 8 115 26 120 24V50Z" fill="url(#wave-{{ $key }})"/><path d="M0 50C20 46 30 40 45 30S75 4 95 8 115 26 120 24" fill="none" stroke="{{ $waves[$tone] }}" stroke-width="1.5"/></svg>
                            <span class="relative flex items-start gap-3">
                                <span class="grid size-11 shrink-0 place-items-center rounded-full {{ $tones[$tone] }}"><x-ui-icon :name="$icon" class="size-5 stroke-2" /></span>
                                <span class="min-w-0">
                                    <span class="block text-sm leading-tight font-semibold text-slate-800">{{ $label }}</span>
                                    <span class="mt-1 flex flex-wrap items-baseline gap-x-2">
                                <span @class(['leading-tight font-extrabold tracking-tight text-slate-900', 'text-[28px]' => $key !== 'accepted_value', 'text-xl sm:text-2xl xl:text-xl 2xl:text-2xl' => $key === 'accepted_value']) data-count="{{ $key }}">{{ $value }}</span>
                                @if ($key === 'estimates_sent' && $trend !== null)
                                    <span @class(['text-xs font-semibold', 'text-emerald-600' => $trend >= 0, 'text-red-600' => $trend < 0]) data-trend="{{ $trend }}">{{ $trend >= 0 ? '↑' : '↓' }} {{ abs($trend) }}%</span>
                                @endif
                                    </span>
                                </span>
                            </span>
                            <span class="relative mt-2 text-xs text-slate-500">{{ $note }}@if ($key === 'estimates_sent' && $trend !== null) vs previous 7 days @endif</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            <div class="min-w-0 space-y-5 lg:col-span-2">
                {{-- Action required --}}
                <section aria-labelledby="attention-heading" class="{{ $card }}">
                    <div class="flex items-center gap-3">
                        <span class="{{ $badge }} bg-red-100 text-red-600"><x-ui-icon name="exclamation" class="size-6 stroke-2" /></span>
                        <div>
                            <h2 id="attention-heading" class="{{ $heading }}">Action required</h2>
                            <p class="text-sm text-slate-500">Items that need your attention today.</p>
                        </div>
                    </div>

                    @if ($d->attention->isEmpty() && $d->alerts->isEmpty())
                        <div class="mt-4 rounded-xl bg-green-50 p-4 text-sm text-green-800" role="status">
                            <p class="font-medium"><span aria-hidden="true">✓</span> {{ "You're all caught up." }}</p>
                            <p>No customer action is currently required.</p>
                        </div>
                    @endif

                    @if ($d->alerts->isNotEmpty())
                        <ul role="list" class="mt-4 space-y-2.5" data-section="alerts">
                            @foreach ($d->alerts as $alert)
                                <li wire:key="alert-{{ $alert['key'] }}" @class(['flex items-center gap-3.5 rounded-2xl p-4 ring-1', 'bg-linear-to-r from-rose-50 to-white ring-rose-100' => $alert['tone'] === 'red', 'bg-linear-to-r from-amber-50 to-white ring-amber-100' => $alert['tone'] === 'amber', 'bg-linear-to-r from-violet-50 to-white ring-violet-100' => ! in_array($alert['tone'], ['red', 'amber'], true)]) data-alert="{{ $alert['key'] }}">
                                    <span class="grid size-11 shrink-0 place-items-center rounded-full {{ $tones[$alert['tone']] }}"><x-ui-icon :name="$alert['icon']" class="size-5 stroke-2" /></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-semibold text-slate-900">@if ($alert['url'])<a href="{{ $alert['url'] }}" wire:navigate class="hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 rounded">{{ $alert['title'] }}</a>@else{{ $alert['title'] }}@endif</p>
                                        <p class="text-sm text-slate-500">{{ $alert['description'] }}</p>
                                        @if ($alert['hint'])
                                            <p class="mt-0.5 text-xs font-medium text-slate-600" data-alert-hint>{{ $alert['hint'] }}</p>
                                        @endif
                                    </div>
                                    <span class="grid min-w-7 shrink-0 place-items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $badgeTones[$alert['tone']] ?? 'bg-slate-100 text-slate-700' }}">{{ $alert['count'] }}</span>
                                    @if ($alert['url'])
                                        <a href="{{ $alert['url'] }}" wire:navigate class="{{ $button }} shrink-0 max-sm:hidden" aria-label="{{ $alert['action'] }}: {{ $alert['title'] }}">{{ $alert['action'] }}</a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($d->attention->isNotEmpty())
                        <h3 class="mt-4 border-t border-slate-100 pt-4 text-xs font-semibold tracking-wide text-slate-500 uppercase">Customers to contact first</h3>
                        <ol role="list" class="mt-1 divide-y divide-slate-100" data-section="attention">
                            @foreach ($d->attention as $item)
                                <li wire:key="attention-{{ $item->kind }}-{{ $loop->index }}" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0 space-y-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ route('customers.show', $item->customerId) }}" wire:navigate class="font-semibold text-slate-900 hover:underline">{{ $item->customerName }}</a>
                                            <x-priority-badge :priority="$item->priority" />
                                        </div>
                                        @if ($item->excerpt)
                                            <p class="truncate text-sm text-slate-700">“{{ $item->excerpt }}”</p>
                                        @endif
                                        <p class="flex flex-wrap items-center gap-x-2 text-sm text-slate-500">
                                            @if ($item->intent)
                                                <x-intent-badge :intent="$item->intent" />
                                            @else
                                                <span class="font-medium {{ $item->overdue ? 'text-red-700' : 'text-slate-800' }}">{{ $item->reason }}</span>
                                            @endif
                                            @if ($item->confidence !== null)
                                                <span>{{ (int) round($item->confidence * 100) }}% confidence</span>
                                            @endif
                                            <span aria-hidden="true">·</span>
                                            <time datetime="{{ \Carbon\CarbonImmutable::instance($item->at)->toIso8601String() }}">{{ \Carbon\CarbonImmutable::instance($item->at)->diffForHumans() }}</time>
                                        </p>
                                    </div>
                                    <a href="{{ $item->url }}" wire:navigate class="{{ $button }} shrink-0 self-start">{{ $item->actionLabel }}</a>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>

                <div class="grid grid-cols-1 items-start gap-5 2xl:grid-cols-2">
                    {{-- Upcoming & overdue follow-ups --}}
                    <section aria-labelledby="today-heading" class="{{ $card }} min-w-0" x-data="{ tab: '{{ $followTab }}' }">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-3">
                                <span class="{{ $badge }} bg-violet-100 text-violet-600"><x-ui-icon name="clock" class="size-6" /></span>
                                <h2 id="today-heading" class="{{ $heading }}">Upcoming &amp; overdue follow-ups</h2>
                            </div>
                            <a href="{{ route('follow-ups.index') }}" wire:navigate class="{{ $link }}">View All Follow-Ups {!! $arrow !!}</a>
                        </div>

                        <div role="tablist" aria-label="Follow-ups" class="mt-4 flex gap-4 border-b border-slate-200">
                            <button type="button" role="tab" @click="tab = 'today'" :aria-selected="tab === 'today'" :class="tab === 'today' ? 'border-violet-600 text-violet-700' : 'border-transparent text-slate-500 hover:text-slate-700'" class="{{ $tab }}">Today ({{ $d->summary['due_today'] }})</button>
                            <button type="button" role="tab" @click="tab = 'upcoming'" :aria-selected="tab === 'upcoming'" :class="tab === 'upcoming' ? 'border-violet-600 text-violet-700' : 'border-transparent text-slate-500 hover:text-slate-700'" class="{{ $tab }}">Upcoming ({{ $d->upcomingCount }})</button>
                            <button type="button" role="tab" @click="tab = 'overdue'" :aria-selected="tab === 'overdue'" :class="tab === 'overdue' ? 'border-red-600 text-red-700' : 'border-transparent text-slate-500 hover:text-slate-700'" class="{{ $tab }}">Overdue ({{ $d->summary['overdue'] }})</button>
                        </div>

                        <div x-show="tab === 'today'" role="tabpanel" @if ($followTab !== 'today') x-cloak @endif>
                            @if ($d->todaysFollowUps->isEmpty())
                                <div class="mt-5 flex flex-col items-center pb-2 text-center text-sm text-slate-500" role="status">
                                    <x-illustration name="calendar-check" class="size-20" />
                                    <p class="mt-2 text-[15px] font-bold text-slate-900">{{ "You're all caught up." }}</p>
                                    <p>{{ $d->hasOpenFollowUps ? 'Nothing is due today.' : 'No follow-ups are currently scheduled.' }}</p>
                                </div>
                            @else
                                <ul role="list" class="mt-1 divide-y divide-slate-100" data-section="today">
                                    @foreach ($d->todaysFollowUps as $followUp)
                                        @include('livewire.partials.dashboard-follow-up', ['overdue' => false, 'showDate' => false])
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        <div x-show="tab === 'upcoming'" role="tabpanel" x-cloak>
                            @if ($d->upcomingFollowUps->isEmpty())
                                <p class="mt-3 text-sm text-slate-500">Nothing is scheduled in the next 7 days.</p>
                            @else
                                <ul role="list" class="mt-1 divide-y divide-slate-100" data-section="upcoming">
                                    @foreach ($d->upcomingFollowUps as $followUp)
                                        @include('livewire.partials.dashboard-follow-up', ['overdue' => false, 'showDate' => true])
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        <div x-show="tab === 'overdue'" role="tabpanel" @if ($followTab !== 'overdue') x-cloak @endif>
                            @if ($d->overdueFollowUps->isEmpty())
                                <p class="mt-3 text-sm text-slate-500">No overdue follow-ups.</p>
                            @else
                                <ul role="list" class="mt-1 divide-y divide-slate-100" data-section="overdue">
                                    @foreach ($d->overdueFollowUps as $followUp)
                                        @include('livewire.partials.dashboard-follow-up', ['overdue' => true, 'showDate' => false])
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </section>

                    {{-- Recent conversations --}}
                    <section aria-labelledby="replies-heading" class="{{ $card }} min-w-0" x-data="{ tab: 'all' }">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex items-center gap-3">
                                <span class="{{ $badge }} bg-violet-100 text-violet-600"><x-ui-icon name="chat" class="size-6" /></span>
                                <h2 id="replies-heading" class="{{ $heading }}">Recent conversations</h2>
                            </div>
                            <a href="{{ route('inbox.index') }}" wire:navigate class="{{ $link }}">View all {!! $arrow !!}</a>
                        </div>

                        <div role="tablist" aria-label="Conversations" class="mt-4 flex gap-4 border-b border-slate-200">
                            <button type="button" role="tab" @click="tab = 'all'" :aria-selected="tab === 'all'" :class="tab === 'all' ? 'border-violet-600 text-violet-700' : 'border-transparent text-slate-500 hover:text-slate-700'" class="{{ $tab }}">All ({{ $d->recentReplies->count() }})</button>
                            <button type="button" role="tab" @click="tab = 'needs-reply'" :aria-selected="tab === 'needs-reply'" :class="tab === 'needs-reply' ? 'border-violet-600 text-violet-700' : 'border-transparent text-slate-500 hover:text-slate-700'" class="{{ $tab }}">Need reply ({{ $needsReply }})</button>
                        </div>

                        @if ($d->recentReplies->isEmpty())
                            <div class="mt-5 flex flex-col items-center pb-2 text-center">
                                <x-illustration name="chats" class="size-20" />
                                <p class="mt-2 text-sm text-slate-500">No customer replies yet.</p>
                            </div>
                        @else
                            <ul role="list" class="mt-1 divide-y divide-slate-100" data-section="replies">
                                @foreach ($d->recentReplies as $reply)
                                    @php
                                        $waiting = (bool) $reply->conversation?->isWaitingForBusiness();
                                    @endphp
                                    <li wire:key="reply-{{ $reply->id }}" x-show="tab === 'all' || {{ $waiting ? 'true' : 'false' }}">
                                        <a href="{{ route('inbox.show', $reply->conversation_id) }}" wire:navigate class="-mx-2 flex items-start gap-3 rounded-lg px-2 py-2.5 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
                                            <span aria-hidden="true" class="grid size-9 shrink-0 place-items-center rounded-full bg-violet-100 text-xs font-semibold text-violet-700">{{ \Illuminate\Support\Str::of($reply->conversation?->customer?->name ?? '?')->explode(' ')->map(fn ($part) => \Illuminate\Support\Str::substr($part, 0, 1))->take(2)->implode('') }}</span>
                                            <span class="min-w-0 flex-1">
                                                <span class="flex flex-wrap items-baseline justify-between gap-x-3">
                                                    <span class="font-medium text-slate-900">{{ $reply->conversation?->customer?->name }}</span>
                                                    <time class="text-xs text-slate-500" datetime="{{ $reply->received_at->toIso8601String() }}">{{ $reply->received_at->diffForHumans() }}</time>
                                                </span>
                                                <span class="block truncate text-sm text-slate-700">“{{ \Illuminate\Support\Str::limit(trim(strtok((string) $reply->excerpt, "\n")), 120) }}”</span>
                                                <span class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
                                                    @if ($waiting)<span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Needs reply</span>@endif
                                                    @if ($reply->latestClassification?->intent)
                                                        <x-intent-badge :intent="$reply->latestClassification->intent" /> {{ (int) round($reply->latestClassification->confidence * 100) }}%
                                                    @endif
                                                </span>
                                            </span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </section>
                </div>

                {{-- Notifications --}}
                <section id="notifications" aria-labelledby="notifications-heading" class="relative overflow-hidden {{ $card }}">
                    <div aria-hidden="true" class="pointer-events-none absolute -top-16 left-1/3 h-40 w-72 rounded-full bg-violet-100/50 blur-3xl"></div>
                    <div class="relative flex items-start justify-between gap-3">
                        <div class="flex items-center gap-4">
                            <span class="{{ $bigBadge }} bg-linear-to-b from-violet-100 to-violet-200/70"><span class="grid size-9 place-items-center rounded-xl bg-linear-to-b from-violet-500 to-violet-700 text-white shadow-[0_6px_14px_-6px_rgba(109,40,217,0.9)]"><x-ui-icon name="clipboard-check" class="size-5 stroke-2" /></span></span>
                            <div>
                                <h2 id="notifications-heading" class="{{ $heading }}">Notifications @if ($d->unreadNotifications)<span class="font-normal">({{ $d->unreadNotifications }} unread)</span>@endif</h2>
                                <p class="text-[15px] text-slate-600">Stay informed about your account activity.</p>
                            </div>
                        </div>
                        @if ($d->unreadNotifications > 1)
                            <button type="button" wire:click="markAllNotificationsRead" class="{{ $pill }}">Mark all read</button>
                        @endif
                    </div>
                    @if ($d->notifications->isEmpty())
                        <p class="relative mt-4 flex items-center gap-2.5 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600 ring-1 ring-slate-100"><x-ui-icon name="info" class="size-5 shrink-0 text-violet-500" />No new notifications.</p>
                    @else
                        <ul role="list" class="relative mt-3 divide-y divide-slate-100" data-section="notifications">
                            @foreach ($d->notifications as $notification)
                                <li wire:key="notification-{{ $notification->id }}" class="flex items-start justify-between gap-2 py-2.5 text-sm">
                                    <div>
                                        @if (! empty($notification->data['url']))
                                            <a href="{{ $notification->data['url'] }}" wire:navigate class="font-medium text-slate-900 hover:underline">{{ $notification->data['message'] ?? 'Notification' }}</a>
                                        @else
                                            <p class="font-medium text-slate-900">{{ $notification->data['message'] ?? 'Notification' }}</p>
                                        @endif
                                        @if (! empty($notification->data['action']) && ! empty($notification->data['url']))
                                            <a href="{{ $notification->data['url'] }}" wire:navigate class="block text-xs font-semibold text-violet-700 hover:underline">{{ $notification->data['action'] }}</a>
                                        @endif
                                        <time class="text-xs text-slate-500" datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
                                    </div>
                                    <button type="button" wire:click="markNotificationRead('{{ $notification->id }}')" class="shrink-0 rounded px-1 text-xs font-medium text-slate-500 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
                                        Dismiss<span class="sr-only"> notification</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- Open tasks --}}
                <section aria-labelledby="tasks-heading" class="relative overflow-hidden {{ $card }}">
                    @if ($d->tasks->isEmpty())
                        <x-illustration name="calendar-check" class="pointer-events-none absolute top-1/2 right-6 size-24 -translate-y-1/2 opacity-60" />
                    @endif
                    <div class="relative flex items-center gap-4">
                        <span class="{{ $bigBadge }} bg-linear-to-b from-violet-50 to-violet-100 text-violet-600"><x-ui-icon name="bolt" class="size-8" /></span>
                        <div>
                            <h2 id="tasks-heading" class="{{ $heading }}">Tasks{{ $d->taskCounts['due'] ? ' ('.$d->taskCounts['due'].')' : '' }}</h2>
                            @if ($d->tasks->isEmpty())<p class="text-[15px] text-slate-600">No tasks to do today.</p>@endif
                        </div>
                    </div>
                    @if ($d->tasks->isEmpty())
                        <div class="relative mt-1 pl-20 text-sm text-slate-500" role="status">
                            <p class="sr-only">No tasks to do today.</p>
                            @if ($d->taskCounts['later'])
                                <p>{{ trans_choice(':count task is scheduled for later.|:count tasks are scheduled for later.', $d->taskCounts['later']) }}</p>
                            @endif
                        </div>
                    @else
                        <ul role="list" class="mt-1 divide-y divide-slate-100 text-sm" data-section="tasks">
                            @foreach ($d->tasks as $task)
                                @include('livewire.partials.task-row', ['showCustomer' => true])
                            @endforeach
                        </ul>
                        @if ($d->taskCounts['due'] > $d->tasks->count() || $d->taskCounts['later'])
                            <p class="mt-2 text-xs text-slate-500">
                                {{ collect([
                                    $d->taskCounts['due'] > $d->tasks->count() ? 'Showing '.$d->tasks->count().' of '.$d->taskCounts['due'].'.' : null,
                                    $d->taskCounts['later'] ? trans_choice(':count more task is scheduled for later.|:count more tasks are scheduled for later.', $d->taskCounts['later']) : null,
                                ])->filter()->implode(' ') }}
                                All tasks are on each customer's page.
                            </p>
                        @endif
                    @endif
                </section>

                {{-- Automation activity --}}
                <section aria-labelledby="automation-heading" class="{{ $card }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-4">
                            <span class="{{ $bigBadge }} bg-linear-to-b from-violet-50 to-violet-100 text-violet-600"><x-ui-icon name="bolt" class="size-8" /></span>
                            <h2 id="automation-heading" class="{{ $heading }}">Automation activity</h2>
                        </div>
                        @can('viewAny', \App\Models\Automation::class)
                            <a href="{{ route('automations.index') }}" wire:navigate class="{{ $link }}">View Automation Activity {!! $arrow !!}</a>
                        @endcan
                    </div>
                    <dl class="mt-4 grid grid-cols-[1fr_auto] border-t border-slate-100 text-[15px] [&>dd]:pl-4 [&>dd]:border-b [&>dd]:border-slate-100 [&>dd]:py-2.5 [&>dt]:border-b [&>dt]:border-slate-100 [&>dt]:py-2.5" data-section="automation-health">
                        <dt class="text-slate-500">Health</dt>
                        <dd class="text-right font-semibold" data-health="status"><span @class(['text-emerald-700' => $health['status'] === 'healthy', 'text-red-700' => $health['status'] === 'failing', 'text-amber-700' => ! in_array($health['status'], ['healthy', 'failing'], true)])>{{ match ($health['status']) { 'healthy' => 'Running and succeeding', 'failing' => 'Runs are failing', 'no_recent_runs' => 'No completed runs in 7 days', 'email_not_ready' => 'Email sending not ready', default => 'No active automations' } }}</span></dd>
                        <dt class="text-slate-500">Active automations</dt>
                        <dd class="text-right font-semibold text-slate-900" data-health="active">{{ $health['active'] }}</dd>
                        <dt class="text-slate-500">Paused</dt>
                        <dd class="text-right font-semibold text-slate-900" data-health="paused">{{ $health['paused'] }}</dd>
                        <dt class="text-slate-500">Follow-ups scheduled</dt>
                        <dd class="text-right font-semibold text-slate-900" data-health="scheduled">{{ $health['scheduled_follow_ups'] }}</dd>
                        <dt class="text-slate-500">Completed, last 7 days</dt>
                        <dd class="text-right font-semibold text-slate-900" data-health="completed">{{ $health['completed_7d'] }}</dd>
                        <dt class="text-slate-500">Failed, last 7 days</dt>
                        <dd class="text-right font-semibold text-slate-900" data-health="failed">{{ $health['failed_7d'] }}</dd>
                        <dt class="text-slate-500">Email sending</dt>
                        <dd class="text-right font-semibold" data-health="email">{{ $health['email_ready'] ? 'Ready' : 'Not ready' }}</dd>
                    </dl>
                    @if ($d->automationFailures > 0)
                        <p class="mt-3 rounded-md bg-red-50 p-2 text-sm text-red-800" role="alert" data-automation-failures>
                            <span class="font-semibold">Automation issue:</span> {{ trans_choice(':count execution failed|:count executions failed', $d->automationFailures) }} in the last 7 days.
                            @can('viewAny', \App\Models\Automation::class)<a href="{{ route('automations.index') }}" wire:navigate class="font-medium underline">View</a>@endcan
                        </p>
                    @endif
                    @if ($d->automationRuns->isEmpty())
                        <p class="mt-4 flex items-center gap-2.5 rounded-xl bg-violet-50/60 px-4 py-3 text-sm text-slate-700"><x-ui-icon name="info" class="size-5 shrink-0 text-violet-600" />No automations have run yet.</p>
                    @else
                        <ol role="list" class="mt-2 space-y-3" data-section="automation">
                            @foreach ($d->automationRuns as $run)
                                <li wire:key="run-{{ $run->id }}" class="text-sm">
                                    <p class="flex flex-wrap items-center gap-2">
                                        <time class="text-xs text-slate-500" datetime="{{ $run->created_at->toIso8601String() }}">{{ $organization->localTime($run->created_at)->format('g:i A') }}</time>
                                        <span class="font-medium text-slate-900">{{ $run->automation?->name ?? 'Deleted automation' }}</span>
                                    </p>
                                    @if ($run->customer)
                                        <p class="text-slate-700">{{ $run->customer->name }}</p>
                                    @endif
                                    <ul role="list" class="mt-0.5 text-slate-500">
                                        @forelse ($run->actionRuns as $actionRun)
                                            <li>
                                                <span aria-hidden="true">{{ match ($actionRun->status->value) { 'completed' => '✓', 'failed' => '✗', 'skipped' => '–', default => '…' } }}</span>
                                                <span class="sr-only">{{ $actionRun->status->label() }}:</span>
                                                {{ $actionRun->result['message'] ?? $actionRun->action_type->label() }}
                                            </li>
                                        @empty
                                            <li>{{ $run->failure_reason ?? ($run->status === \App\Enums\Automation\AutomationRunStatus::Waiting && $run->resume_at ? 'Waiting until '.$organization->localTime($run->resume_at)->format('M j, g:i A') : $run->status->label()) }}</li>
                                        @endforelse
                                    </ul>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            </div>

            <div class="min-w-0 space-y-5">
                {{-- AI assistant: this period's chats, and leads waiting for Won / Lost --}}
                @if ($assistant)
                    <section aria-labelledby="assistant-heading" class="{{ $card }}" data-section="assistant">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-3">
                                <span class="{{ $badge }} bg-violet-100 text-violet-600"><x-ui-icon name="sparkles" class="size-6" /></span>
                                <h2 id="assistant-heading" class="{{ $heading }}">{{ $assistant['name'] }} (AI assistant)</h2>
                            </div>
                            <span @class(['rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset', 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $assistant['on'], 'bg-slate-100 text-slate-600 ring-slate-500/20' => ! $assistant['on']])>{{ $assistant['on'] ? 'On' : 'Off' }}</span>
                        </div>
                        <dl class="mt-4 grid grid-cols-3 gap-2 text-center">
                            @foreach (['chats' => 'Chats', 'qualified' => 'Qualified', 'handed_over' => 'Handed over'] as $key => $statLabel)
                                <div class="rounded-xl bg-slate-50 px-2 py-3 ring-1 ring-slate-200/70 ring-inset">
                                    <dt class="text-xs text-slate-500">{{ $statLabel }}</dt>
                                    <dd class="text-xl font-bold text-slate-900" data-assistant-stat="{{ $key }}">{{ number_format($assistant[$key]) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                        <p class="mt-2 text-xs text-slate-500">This billing period.</p>
                        @if ($assistant['awaiting_outcome'] > 0)
                            <a href="{{ route('customers.index', ['stage' => 'qualified']) }}" wire:navigate class="mt-3 flex items-center justify-between gap-2 rounded-xl bg-amber-50 px-3 py-2.5 text-sm font-medium text-amber-900 ring-1 ring-amber-200 ring-inset hover:bg-amber-100" data-awaiting-outcome>
                                <span>{{ trans_choice(':count lead is waiting for Won or Lost|:count leads are waiting for Won or Lost', $assistant['awaiting_outcome']) }}</span>
                                <x-ui-icon name="chevron-right" class="size-4" />
                            </a>
                        @endif
                    </section>
                @endif

                {{-- Your business at a glance --}}
                <section aria-labelledby="glance-business-heading" class="{{ $card }}" data-section="business">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-3">
                            <span class="{{ $badge }} bg-violet-100 text-violet-600"><x-ui-icon name="building" class="size-6" /></span>
                            <h2 id="glance-business-heading" class="{{ $heading }}">Your business at a glance</h2>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center justify-between gap-2">
                        <p class="flex min-w-0 flex-wrap items-center gap-2 text-sm font-semibold text-slate-900"><span class="truncate">{{ $organization->name }}</span> <span class="rounded-full bg-violet-100 px-2 py-0.5 text-xs font-semibold text-violet-700" data-plan>{{ $d->planUsage['plan'] }}</span></p>
                        @can('manage-billing')<a href="{{ route('settings.billing') }}" wire:navigate class="{{ $link }}">Manage</a>@endcan
                    </div>
                    @if ($d->planUsage['percent'] !== null)
                        <div class="mt-3">
                            <div class="flex items-center justify-between gap-2 text-xs text-slate-500"><span>Closest to a limit: <span class="font-medium text-slate-700" data-plan-fullest>{{ $d->planUsage['fullest'] }}</span></span><span class="font-semibold text-slate-700" data-plan-percent>{{ $d->planUsage['percent'] }}%</span></div>
                            <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-violet-100/70" role="progressbar" aria-label="Closest to a plan limit: {{ $d->planUsage['fullest'] }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $d->planUsage['percent'] }}">
                                <div @class(['h-2 rounded-full', 'bg-linear-to-r from-violet-600 to-fuchsia-500' => $d->planUsage['percent'] < 80, 'bg-amber-500' => $d->planUsage['percent'] >= 80 && $d->planUsage['percent'] < 100, 'bg-red-500' => $d->planUsage['percent'] >= 100]) style="width: {{ $d->planUsage['percent'] }}%"></div>
                            </div>
                        </div>
                    @endif
                    <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        @foreach ($d->planUsage['rows'] as $row)
                            @php
                                $usageLabel = strtolower($row['label']);
                                $usageIcon = match (true) { str_contains($usageLabel, 'estimate') => 'document', str_contains($usageLabel, 'automation') => 'bolt', str_contains($usageLabel, 'email') => 'envelope', default => 'users' };
                            @endphp
                            <div class="flex items-center gap-3 rounded-xl bg-slate-50/80 p-3 ring-1 ring-slate-100" data-usage="{{ \Illuminate\Support\Str::slug($row['label']) }}">
                                <x-ui-icon :name="$usageIcon" class="size-6 shrink-0 text-violet-500" />
                                <div class="min-w-0">
                                <dt class="text-xs text-slate-500">{{ $row['label'] }}</dt>
                                <dd class="mt-0.5 font-bold text-slate-900">{{ $row['used'] }}@if ($row['limit'] !== null) <span class="font-normal text-slate-500">/ {{ $row['limit'] }}</span>@else <span class="font-normal text-slate-500">· Unlimited</span>@endif</dd>
                                </div>
                            </div>
                        @endforeach
                    </dl>
                </section>

                {{-- Setup checklist --}}
                @if ($checklist)
                    @php
                        $doneCount = collect($checklist)->where('done', true)->count();
                    @endphp
                    <section aria-labelledby="checklist-heading" class="{{ $card }}" data-section="getting-started">
                        <h2 id="checklist-heading" class="{{ $heading }}">Setup checklist</h2>
                        <p class="text-sm text-slate-500">{{ $doneCount }} of {{ count($checklist) }} complete</p>
                        <ul role="list" class="mt-3 space-y-2 text-sm">
                            @foreach ($checklist as $item)
                                <li class="flex items-center justify-between gap-2">
                                    <span class="flex items-center gap-2">
                                        <span aria-hidden="true" @class(['inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-xs', 'bg-green-100 text-green-800' => $item['done'], 'ring-1 ring-slate-300 text-slate-400' => ! $item['done']])>{{ $item['done'] ? '✓' : '○' }}</span>
                                        <span @class(['text-slate-500' => $item['done'], 'font-medium text-slate-900' => ! $item['done']])><span class="sr-only">{{ $item['done'] ? 'Done: ' : 'To do: ' }}</span>{{ $item['label'] }}</span>
                                    </span>
                                    <a href="{{ $item['url'] }}" wire:navigate class="{{ $link }} shrink-0">{{ $item['done'] ? 'View' : 'Set up' }}<span class="sr-only"> {{ $item['label'] }}</span></a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- Recent estimates --}}
                <section aria-labelledby="recent-estimates-heading" class="{{ $card }}" data-section="recent-estimates">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-3">
                            <span class="{{ $badge }} bg-violet-100 text-violet-600"><x-ui-icon name="chart-bar" class="size-6" /></span>
                            <h2 id="recent-estimates-heading" class="{{ $heading }}">Recent estimates</h2>
                        </div>
                        <a href="{{ route('estimates.index') }}" wire:navigate class="{{ $link }}">View all {!! $arrow !!}</a>
                    </div>
                    @if ($d->recentEstimates->isEmpty())
                        <p class="mt-3 text-sm text-slate-500">No estimates yet. <a href="{{ route('estimates.create') }}" wire:navigate class="{{ $link }}">Create your first estimate</a></p>
                    @else
                        <ul role="list" class="mt-2 divide-y divide-slate-100">
                            @foreach ($d->recentEstimates as $estimate)
                                <li wire:key="recent-estimate-{{ $estimate->id }}">
                                    <a href="{{ route('estimates.show', $estimate->id) }}" wire:navigate class="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-2.5 text-sm hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
                                        <span class="min-w-0">
                                            <span class="block font-medium text-slate-900">{{ $estimate->displayNumber() }} <span class="font-normal text-slate-500">{{ $estimate->customer?->name }}</span></span>
                                            <span class="block truncate text-xs text-slate-500">{{ $estimate->title }} · {{ $organization->localTime($estimate->created_at)->format('M j, Y') }}</span>
                                        </span>
                                        <span class="flex shrink-0 flex-col items-end gap-1">
                                            <span class="font-semibold text-slate-900">{{ \App\Support\Money::formatDecimal($estimate->total, $estimate->currency ?? $organization->currency) }}</span>
                                            <x-estimate-status-badge :status="$estimate->status" />
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- Estimate pipeline --}}
                <section aria-labelledby="estimates-heading" class="rounded-2xl bg-linear-to-br from-sky-50 via-white to-violet-50/60 p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-white">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="estimates-heading" class="{{ $heading }} flex items-center gap-3"><span class="{{ $bigBadge }} bg-linear-to-b from-sky-100 to-sky-200/70 text-sky-600"><x-ui-icon name="document" class="size-8" /></span> Estimate pipeline</h2>
                        <a href="{{ route('estimates.index') }}" wire:navigate class="{{ $link }}">View Estimates {!! $arrow !!}</a>
                    </div>
                    <dl class="mt-4 grid grid-cols-[1fr_auto] gap-x-4 gap-y-3 pl-1 text-[15px] [&>dt]:text-slate-600" data-section="estimates">
                        <dt class="text-slate-500"><a href="{{ route('estimates.index', ['status' => 'awaiting']) }}" wire:navigate class="hover:underline">Awaiting customer</a></dt>
                        <dd class="text-right font-semibold text-slate-900" data-estimates="awaiting">{{ $pipeline['outstanding_count'] }}</dd>
                        <dt class="text-slate-500">Outstanding value</dt>
                        <dd class="text-right font-semibold text-slate-900" data-estimates="outstanding-value">{{ $money($pipeline['outstanding_cents']) }}</dd>
                        <dt class="text-slate-500"><a href="{{ route('estimates.index', ['status' => 'accepted']) }}" wire:navigate class="hover:underline">Accepted, last 30 days</a></dt>
                        <dd class="text-right font-semibold text-slate-900" data-estimates="accepted-recent">{{ $pipeline['accepted_recent_count'] }} · {{ $money($pipeline['accepted_recent_cents']) }}</dd>
                        @foreach (['draft' => 'Draft', 'sent' => 'Sent, not yet viewed', 'viewed' => 'Viewed by customer', 'declined' => 'Declined', 'expired' => 'Expired'] as $key => $label)
                            <dt class="text-slate-500"><a href="{{ route('estimates.index', ['status' => $key]) }}" wire:navigate class="hover:underline">{{ $label }}</a></dt>
                            <dd class="text-right font-semibold text-slate-900" data-estimates="{{ $key }}">{{ $pipeline[$key] }}</dd>
                        @endforeach
                    </dl>
                    <p class="mt-3 text-xs text-slate-500">Values are the totals of your estimates. They are not payments collected.</p>
                </section>

                {{-- Today --}}
                <section aria-labelledby="summary-heading" class="rounded-2xl bg-linear-to-br from-emerald-50 via-white to-emerald-50/40 p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-white">
                    <h2 id="summary-heading" class="{{ $heading }} flex items-center gap-3"><span class="{{ $bigBadge }} bg-linear-to-b from-emerald-100 to-emerald-200/70 text-emerald-600"><x-ui-icon name="check" class="size-8 stroke-[2.5]" /></span> Today's activity</h2>
                    <dl class="mt-4 grid grid-cols-[1fr_auto] gap-x-4 gap-y-3 pl-1 text-[15px] [&>dt]:text-slate-600" data-section="today-summary">
                        @foreach ([
                            'new_customers' => 'New customers',
                            'customer_replies' => 'Customer replies',
                            'follow_ups_completed' => 'Follow-ups completed',
                            'follow_ups_due' => 'Follow-ups due',
                            'emails_sent' => 'Emails sent',
                        ] as $key => $label)
                            <dt class="text-slate-500">{{ $label }}</dt>
                            <dd class="text-right font-semibold text-slate-900" data-stat="{{ $key }}">{{ $d->today[$key] }}</dd>
                        @endforeach
                        <dt class="text-slate-500">Estimates sent</dt>
                        <dd class="text-right font-semibold text-slate-900" data-stat="estimates_sent">{{ $d->estimates['sent_today'] }}</dd>
                    </dl>
                </section>

                {{-- Conversations --}}
                <section aria-labelledby="conversations-heading" class="rounded-2xl bg-linear-to-br from-amber-50 via-white to-orange-50/50 p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-white">
                    <h2 id="conversations-heading" class="{{ $heading }} flex items-center gap-3"><span class="{{ $bigBadge }} bg-linear-to-b from-amber-100 to-orange-200/70 text-orange-600"><x-ui-icon name="chat" class="size-8" /></span> Conversations</h2>
                    <dl class="mt-4 grid grid-cols-[1fr_auto] gap-x-4 gap-y-3 pl-1 text-[15px] [&>dt]:text-slate-600" data-section="conversation-statuses">
                        @foreach (\App\Enums\ConversationStatus::cases() as $status)
                            <dt class="text-slate-500">{{ $status === \App\Enums\ConversationStatus::Open ? 'Open' : $status->label() }}</dt>
                            <dd class="text-right font-semibold text-slate-900" data-status="{{ $status->value }}">{{ $d->conversationStatuses[$status->value] ?? 0 }}</dd>
                        @endforeach
                    </dl>
                </section>
            </div>
        </div>

        {{-- Automation status: from execution results and sending readiness, not from whether automations are enabled. --}}
        <section aria-label="Automation status" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-linear-to-r from-violet-100 via-violet-50 to-fuchsia-50 px-5 py-4 ring-1 ring-violet-100" data-section="automation-status">
            <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                <span class="inline-flex items-center gap-2 font-semibold text-slate-900"><x-ui-icon name="bolt" class="size-5 text-violet-600" />Automation status</span>
                <span @class(['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold', 'bg-emerald-100 text-emerald-800' => $health['status'] === 'healthy', 'bg-red-100 text-red-800' => $health['status'] === 'failing', 'bg-amber-100 text-amber-800' => in_array($health['status'], ['no_recent_runs', 'email_not_ready'], true), 'bg-slate-200 text-slate-700' => $health['status'] === 'idle']) data-automation-status="{{ $health['status'] }}">{{ match ($health['status']) { 'healthy' => 'Running', 'failing' => 'Needs attention', 'no_recent_runs' => 'No recent runs', 'email_not_ready' => 'Email not ready', default => 'Not set up' } }}</span>
                <span class="text-slate-600">{{ match ($health['status']) {
                    'healthy' => 'Your follow-ups and email sequences are running smoothly.',
                    'failing' => trans_choice(':count automation execution failed in the last 7 days.|:count automation executions failed in the last 7 days.', $health['failed_7d']),
                    'no_recent_runs' => 'Automations are active, but none completed in the last 7 days.',
                    'email_not_ready' => 'Connect and verify a sending email so automated follow-ups can go out.',
                    default => 'No automations are active yet.',
                } }}</span>
            </p>
            @can('viewAny', \App\Models\Automation::class)
                <a href="{{ route('automations.index') }}" wire:navigate class="{{ $button }}">Manage automations <x-ui-icon name="arrow-right" class="size-4" /></a>
            @endcan
        </section>
    @endif
</div>
