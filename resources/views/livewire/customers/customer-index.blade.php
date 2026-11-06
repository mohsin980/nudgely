<div class="space-y-6">
    @php($columns = 'md:grid-cols-[minmax(0,2.3fr)_minmax(0,1.4fr)_minmax(0,1.5fr)_minmax(0,1.1fr)_auto]')
    @php($pill = 'h-10 w-full appearance-none rounded-xl border-0 py-0 pr-9 pl-3.5 text-sm font-medium shadow-none ring-1 ring-inset transition focus:ring-2 focus:ring-violet-500 focus:outline-none')
    @php($pillIdle = 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50')
    @php($pillActive = 'bg-violet-50 text-violet-800 ring-violet-300')
    @php($pillWidths = ['f-status' => 'sm:w-32', 'f-conversation' => 'sm:w-40', 'f-follow-up' => 'sm:w-36', 'f-intent' => 'sm:w-36'])
    @php($avatarTones = ['bg-violet-100 text-violet-700', 'bg-sky-100 text-sky-700', 'bg-emerald-100 text-emerald-700', 'bg-amber-100 text-amber-800', 'bg-rose-100 text-rose-700', 'bg-indigo-100 text-indigo-700'])

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Customers</h1>
            <p class="mt-1 text-sm text-slate-500">{{ trans_choice(':count customer|:count customers', $customers->total()) }}{{ $filtering ? ' match your filters' : '' }}</p>
        </div>
        <a href="{{ route('customers.create') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-[0_10px_24px_-10px_rgba(109,40,217,0.7)] transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2" style="background: linear-gradient(90deg, #6a2cf2, #8b5cf6);"><x-ui-icon name="plus" class="size-4 stroke-2" />Add Customer</a>
    </div>

    @if (! $hasAnyCustomer)
        <div class="rounded-2xl border border-dashed border-violet-200 bg-white px-6 py-14 text-center">
            <span class="mx-auto grid size-14 place-items-center rounded-2xl bg-violet-50 text-violet-600"><x-ui-icon name="user-plus" class="size-7" /></span>
            <p class="mt-4 text-base font-semibold text-slate-900">No customers yet.</p>
            <p class="mt-1 text-sm text-slate-500">Add your first customer to start a conversation.</p>
            <a href="{{ route('customers.create') }}" wire:navigate class="mt-5 inline-flex items-center gap-1.5 rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-violet-700"><x-ui-icon name="plus" class="size-4 stroke-2" />Add Customer</a>
        </div>
    @else
        {{-- Summary: the whole business, not only the filtered rows --}}
        <section aria-label="Summary" class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5" data-section="customer-summary">
            @foreach ([
                ['Total customers', $this->summary['total'], 'users', 'bg-violet-100 text-violet-600', 'text-violet-500', 'total'],
                ['Active', $this->summary['active'], 'check', 'bg-emerald-100 text-emerald-600', 'text-emerald-500', 'active'],
                ['With follow-up', $this->summary['follow_up'], 'clock', 'bg-sky-100 text-sky-600', 'text-sky-500', 'follow-up'],
                ['No follow-up', $this->summary['no_follow_up'], 'bell', 'bg-amber-100 text-amber-700', 'text-amber-500', 'no-follow-up'],
                ['Inactive', $this->summary['inactive'], 'user', 'bg-slate-100 text-slate-500', 'text-slate-400', 'inactive'],
            ] as [$cardLabel, $count, $icon, $tone, $wave, $key])
                <div @class(['relative flex items-center gap-3 overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-3.5 pb-6 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_8px_24px_-12px_rgba(76,29,149,0.12)] sm:gap-3.5 sm:p-4 sm:pb-7', 'col-span-2 sm:col-span-1' => $loop->first]) data-summary="{{ $key }}">
                    <span class="relative z-10 grid size-10 shrink-0 place-items-center rounded-full sm:size-11 {{ $tone }}"><x-ui-icon :name="$icon" class="size-5 sm:size-6" /></span>
                    <div class="relative z-10 min-w-0">
                        <p class="text-2xl leading-tight font-bold tracking-tight text-slate-900">{{ number_format($count) }}</p>
                        <p class="text-[13px] leading-snug text-slate-500 sm:text-sm">{{ $cardLabel }}</p>
                    </div>
                    <svg aria-hidden="true" viewBox="0 0 200 48" preserveAspectRatio="none" class="pointer-events-none absolute inset-x-0 bottom-0 h-6 w-full {{ $wave }}">
                        <path d="M0 38 C 24 26, 42 42, 64 30 S 104 14, 128 26 S 166 40, 200 12 L 200 48 L 0 48 Z" fill="currentColor" fill-opacity="0.12" />
                        <path d="M0 38 C 24 26, 42 42, 64 30 S 104 14, 128 26 S 166 40, 200 12" fill="none" stroke="currentColor" stroke-opacity="0.45" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                    </svg>
                </div>
            @endforeach
        </section>

        {{-- Lead stages: the pipeline at a glance, and a one-click filter --}}
        <nav aria-label="Lead stages" class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1" data-section="lead-stages">
            @php($stageTotal = $stageCounts->sum())
            @foreach (array_merge([null], $leadStages) as $leadStage)
                @php($value = $leadStage?->value ?? '')
                <button type="button" wire:click="$set('stage', '{{ $value }}')" @if ($stage === $value) aria-current="true" @endif
                        @class([
                            'inline-flex shrink-0 items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500',
                            'bg-linear-to-r from-violet-600 to-violet-500 text-white shadow-[0_8px_18px_-8px_rgba(109,40,217,0.7)]' => $stage === $value,
                            'bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50' => $stage !== $value,
                        ])>
                    {{ $leadStage?->label() ?? 'All leads' }}
                    <span @class(['rounded-full px-1.5 text-xs', 'bg-white/25' => $stage === $value, 'bg-slate-100 text-slate-600' => $stage !== $value])>{{ number_format($leadStage ? (int) ($stageCounts[$value] ?? 0) : $stageTotal) }}</span>
                </button>
            @endforeach
        </nav>

        <section aria-label="Search and filters" class="space-y-3 rounded-2xl border border-slate-200/80 bg-white p-3 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_8px_24px_-12px_rgba(76,29,149,0.12)] sm:p-4">
            <div class="relative">
                <label for="customer-search" class="sr-only">Search customers</label>
                <x-ui-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                <input id="customer-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search by name, email or phone…" class="block h-11 w-full rounded-xl border-0 bg-slate-50 pr-4 pl-11 text-[15px] text-slate-900 shadow-none ring-1 ring-slate-200 ring-inset placeholder:text-slate-400 focus:bg-white focus:ring-2 focus:ring-violet-500">
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @foreach ([
                    ['f-status', 'status', 'Status', collect($customerStatuses)->map(fn ($s) => [$s->value, $s->label()])],
                    ['f-conversation', 'conversation', 'Conversation', collect($conversationStatuses)->map(fn ($s) => [$s->value, $s->label()])],
                    ['f-follow-up', 'followUp', 'Follow-up', collect([['overdue', 'Overdue'], ['due_today', 'Due today'], ['upcoming', 'Upcoming'], ['none', 'None']])],
                    ['f-intent', 'intent', 'AI intent', collect($intents)->map(fn ($i) => [$i->value, $i->label()])->push(['other', 'Other'])],
                ] as [$id, $property, $filterLabel, $options])
                    <div class="relative w-[calc(50%-0.25rem)] {{ $pillWidths[$id] }}">
                        <label for="{{ $id }}" class="sr-only">{{ $filterLabel }}</label>
                        <select id="{{ $id }}" wire:model.live="{{ $property }}" class="truncate {{ $pill }} {{ $this->{$property} !== '' ? $pillActive : $pillIdle }}">
                            <option value="">{{ $filterLabel }}</option>
                            @foreach ($options as [$value, $optionLabel])
                                <option value="{{ $value }}">{{ $filterLabel }}: {{ $optionLabel }}</option>
                            @endforeach
                        </select>
                        <x-ui-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-slate-500" />
                    </div>
                @endforeach
                <div class="relative w-[calc(50%-0.25rem)] sm:w-52">
                    <label for="f-sort" class="sr-only">Sort</label>
                    <select id="f-sort" wire:model.live="sort" class="{{ $pill }} {{ $pillIdle }}">
                        @foreach (\App\Services\Customers\CustomerDirectory::SORTS as $key => $sortLabel)<option value="{{ $key }}">Sort: {{ $sortLabel }}</option>@endforeach
                    </select>
                    <x-ui-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-slate-500" />
                </div>
                <div class="relative w-[calc(50%-0.25rem)] sm:w-36">
                    <label for="f-per-page" class="sr-only">Per page</label>
                    <select id="f-per-page" wire:model.live="perPage" class="{{ $pill }} {{ $pillIdle }}">
                        @foreach (\App\Services\Customers\CustomerDirectory::PER_PAGE as $n)<option value="{{ $n }}">{{ $n }} per page</option>@endforeach
                    </select>
                    <x-ui-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-slate-500" />
                </div>
                @if ($filtering)
                    <button type="button" wire:click="clearFilters" class="inline-flex h-10 items-center gap-1 rounded-xl px-3 text-sm font-medium text-violet-700 hover:bg-violet-50"><x-ui-icon name="close" class="size-4" />Clear filters</button>
                @endif
            </div>
        </section>

        <div wire:loading.delay.class="opacity-60" class="transition-opacity">
            @if ($customers->isEmpty())
                <p class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-sm text-slate-500">No customers match your search or filters.</p>
            @else
                <div class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-[0_1px_2px_rgba(16,24,40,0.04),0_8px_24px_-12px_rgba(76,29,149,0.12)]" data-section="customers">
                    <div class="hidden gap-4 border-b border-slate-100 px-5 py-3.5 text-xs font-semibold tracking-wider text-slate-500 uppercase md:grid {{ $columns }}" aria-hidden="true">
                        <span>Customer</span>
                        <span>Conversation</span>
                        <span>Follow-up</span>
                        <span>Added</span>
                        <span class="text-right">Action</span>
                    </div>
                    <ul role="list" class="divide-y divide-slate-100">
                        @foreach ($customers as $customer)
                            @php($initials = collect(preg_split('/\s+/', trim($customer->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode(''))
                            <li wire:key="customer-{{ $customer->id }}">
                                <a href="{{ route('customers.show', $customer->id) }}" wire:navigate class="group flex flex-wrap items-center gap-x-3 gap-y-2.5 px-5 py-4 transition-colors hover:bg-violet-50/40 focus:outline-none focus-visible:bg-violet-50/40 focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-inset md:grid md:gap-4 {{ $columns }}">
                                    <div class="flex w-full min-w-0 items-center gap-3 md:w-auto">
                                        <span aria-hidden="true" class="grid size-10 shrink-0 place-items-center rounded-full text-sm font-bold {{ $avatarTones[crc32($customer->name) % count($avatarTones)] }}">{{ $initials ?: '?' }}</span>
                                        <div class="min-w-0">
                                            <p class="flex flex-wrap items-center gap-2 font-semibold text-slate-900">
                                                <span class="truncate">{{ $customer->name }}</span>
                                                <x-lead-stage-badge :stage="$customer->lead_stage" />
                                                @if ($customer->is_demo)<span class="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800" data-sample-badge>Sample</span>@endif
                                                @if (! $customer->isActive())<span class="rounded-lg bg-slate-100 px-1.5 py-0.5 text-xs font-medium text-slate-500">Inactive</span>@endif
                                            </p>
                                            <p class="truncate text-sm text-slate-500">{{ $customer->email }}@if ($customer->phone) · {{ $customer->phone }}@endif</p>
                                        </div>
                                    </div>
                                    <div class="flex flex-wrap items-center gap-1.5 text-sm">
                                        @if ($customer->conversation_status)
                                            <x-conversation-status-badge :status="\App\Enums\ConversationStatus::from($customer->conversation_status)" />
                                        @else
                                            <span class="text-slate-500">No conversation</span>
                                        @endif
                                        @if ($customer->latest_intent && ($intentEnum = \App\Enums\CustomerReplyIntent::tryFrom($customer->latest_intent)))
                                            <x-intent-badge :intent="$intentEnum" />
                                        @endif
                                    </div>
                                    <div class="text-sm">
                                        @if ($customer->next_follow_up_at)
                                            @php($due = $organization->localTime(\Carbon\CarbonImmutable::parse($customer->next_follow_up_at)))
                                            @php($today = $organization->localTime(now()))
                                            @if ($due->lt($today))
                                                <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700 ring-1 ring-red-100 ring-inset" title="{{ $due->format('M j, Y g:i A') }}"><span class="size-1.5 rounded-full bg-red-500"></span>Overdue · {{ $due->format('M j') }}</span>
                                            @elseif ($due->isSameDay($today))
                                                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 ring-1 ring-amber-100 ring-inset" title="{{ $due->format('M j, Y g:i A') }}"><span class="size-1.5 rounded-full bg-amber-500"></span>Due today</span>
                                            @else
                                                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-100 ring-inset" title="{{ $due->format('M j, Y g:i A') }}"><span class="size-1.5 rounded-full bg-emerald-500"></span>Follow-up in {{ $today->startOfDay()->diffForHumans($due->startOfDay(), ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1]) }}</span>
                                            @endif
                                        @else
                                            <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-500">None scheduled</span>
                                        @endif
                                    </div>
                                    <div class="text-sm">
                                        <p class="text-slate-700"><span class="text-slate-500 md:hidden">Added </span>{{ $organization->localTime($customer->created_at)->format('M j, Y') }}</p>
                                        @if ($customer->last_activity_at)<p class="hidden text-xs text-slate-500 md:block">Active {{ $customer->last_activity_at->diffForHumans() }}</p>@endif
                                    </div>
                                    <span class="ml-auto inline-flex items-center justify-end gap-1 text-sm font-semibold text-violet-700 md:ml-0">View <x-ui-icon name="arrow-right" class="size-4 transition-transform group-hover:translate-x-0.5" /></span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-3.5 text-sm text-slate-500">
                        <span>Showing {{ $customers->firstItem() }}–{{ $customers->lastItem() }} of {{ trans_choice(':count customer|:count customers', $customers->total()) }}</span>
                        @if ($customers->hasPages())
                            <nav aria-label="Pagination" class="flex items-center gap-1.5">
                                <button type="button" wire:click="previousPage" @disabled($customers->onFirstPage()) aria-label="Previous page" class="grid size-9 place-items-center rounded-lg bg-white text-slate-500 ring-1 ring-slate-200 ring-inset transition hover:bg-slate-50 disabled:opacity-40"><x-ui-icon name="chevron-left" class="size-4" /></button>
                                @for ($page = max(1, $customers->currentPage() - 2); $page <= min($customers->lastPage(), $customers->currentPage() + 2); $page++)
                                    <button type="button" wire:click="gotoPage({{ $page }})" @if ($page === $customers->currentPage()) aria-current="page" @endif class="{{ $page === $customers->currentPage() ? 'bg-violet-600 text-white shadow-sm' : 'bg-white text-slate-700 ring-1 ring-slate-200 ring-inset hover:bg-slate-50' }} grid h-9 min-w-9 place-items-center rounded-lg px-2 font-semibold transition">{{ $page }}</button>
                                @endfor
                                <button type="button" wire:click="nextPage" @disabled($customers->currentPage() === $customers->lastPage()) aria-label="Next page" class="grid size-9 place-items-center rounded-lg bg-white text-slate-500 ring-1 ring-slate-200 ring-inset transition hover:bg-slate-50 disabled:opacity-40"><x-ui-icon name="chevron-right" class="size-4" /></button>
                            </nav>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
