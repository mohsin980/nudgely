<div class="space-y-6">
    @php($field = 'block w-full rounded-xl border-0 bg-white px-3.5 py-2.5 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
    @php($filterInput = 'mt-0.5 block w-full border-0 bg-transparent p-0 text-sm text-slate-900 focus:ring-0')
    @php($columns = 'lg:grid-cols-[minmax(0,1.1fr)_minmax(0,2fr)_minmax(0,1.1fr)_minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1fr)_auto]')

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">Estimates</h1>
            <p class="mt-1 text-sm text-slate-500">Manage and track your estimates</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('estimates.import') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-800 shadow-sm ring-1 ring-slate-200 ring-inset transition hover:bg-slate-50"><x-ui-icon name="download" class="size-5" />Import CSV</a>
            <a href="{{ route('estimates.create') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2"><x-ui-icon name="plus" class="size-5" />Create Estimate</a>
        </div>
    </div>

    @if (! $hasAnyEstimate)
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
            <span class="mx-auto grid size-12 place-items-center rounded-xl bg-violet-50 text-violet-600"><x-ui-icon name="document" class="size-6" /></span>
            <p class="mt-4 text-base font-semibold text-slate-900">No estimates yet.</p>
            <p class="mt-1 text-sm text-slate-500">Create an estimate, send it, and QuoteFollow will help you follow up.</p>
            <a href="{{ route('estimates.create') }}" wire:navigate class="mt-5 inline-flex items-center gap-1.5 rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-violet-700">Create Estimate</a>
        </div>
    @else
        {{-- Summary: the whole business, not only the filtered rows --}}
        <section aria-label="Summary" class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            @foreach ([
                ['Total estimates', $this->summary['total'], 'document', 'bg-violet-100 text-violet-600', 'text-violet-500'],
                ['Accepted', $this->summary['accepted'], 'check', 'bg-emerald-100 text-emerald-600', 'text-emerald-500'],
                ['Sent', $this->summary['sent'], 'paper-airplane', 'bg-sky-100 text-sky-600', 'text-sky-500'],
                ['Draft', $this->summary['draft'], 'pencil', 'bg-slate-100 text-slate-500', 'text-slate-400'],
                ['Total value', $this->summary['value'], 'dollar', 'bg-violet-100 text-violet-600', 'text-violet-500'],
            ] as [$label, $value, $icon, $tone, $wave])
                <div class="relative flex items-center gap-4 overflow-hidden rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <span class="grid size-12 shrink-0 place-items-center rounded-full {{ $tone }}"><x-ui-icon :name="$icon" class="size-6" /></span>
                    <div class="relative min-w-0">
                        <p class="truncate text-2xl font-semibold tracking-tight text-slate-900">{{ $value }}</p>
                        <p class="text-sm text-slate-500">{{ $label }}</p>
                    </div>
                    <svg aria-hidden="true" viewBox="0 0 200 48" preserveAspectRatio="none" class="pointer-events-none absolute inset-x-0 bottom-0 h-12 w-full {{ $wave }}">
                        <path d="M0 38 C 24 26, 42 42, 64 30 S 104 14, 128 26 S 166 40, 200 12 L 200 48 L 0 48 Z" fill="currentColor" fill-opacity="0.14" />
                        <path d="M0 38 C 24 26, 42 42, 64 30 S 104 14, 128 26 S 166 40, 200 12" fill="none" stroke="currentColor" stroke-opacity="0.5" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                    </svg>
                </div>
            @endforeach
        </section>

        <section aria-label="Search and filters" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="relative">
                <label for="estimate-search" class="sr-only">Search estimates</label>
                <x-ui-icon name="search" class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-slate-400" />
                <input id="estimate-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search by estimate number, customer name or email..." class="block w-full rounded-xl border-0 bg-white py-3 pr-4 pl-11 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-600 focus:ring-inset">
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div class="rounded-xl bg-white px-3 pt-2 pb-1.5 ring-1 ring-slate-300 ring-inset focus-within:ring-2 focus-within:ring-violet-600">
                    <label for="f-status" class="block text-xs font-medium text-slate-500">Status</label>
                    <div class="relative">
                        <select id="f-status" wire:model.live="status" class="{{ $filterInput }} appearance-none pr-6"><option value="">All</option><option value="awaiting">Awaiting customer</option>@foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select>
                        <x-ui-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-0 size-4 -translate-y-1/2 text-slate-500" />
                    </div>
                </div>
                <div class="rounded-xl bg-white px-3 pt-2 pb-1.5 ring-1 ring-slate-300 ring-inset focus-within:ring-2 focus-within:ring-violet-600">
                    <label for="f-from" class="block text-xs font-medium text-slate-500">Created from</label>
                    <input id="f-from" type="date" wire:model.live="from" class="{{ $filterInput }}">
                </div>
                <div class="rounded-xl bg-white px-3 pt-2 pb-1.5 ring-1 ring-slate-300 ring-inset focus-within:ring-2 focus-within:ring-violet-600">
                    <label for="f-to" class="block text-xs font-medium text-slate-500">Created to</label>
                    <input id="f-to" type="date" wire:model.live="to" class="{{ $filterInput }}">
                </div>
                <div class="rounded-xl bg-white px-3 pt-2 pb-1.5 ring-1 ring-slate-300 ring-inset focus-within:ring-2 focus-within:ring-violet-600">
                    <label for="f-min" class="block text-xs font-medium text-slate-500">Min total ($)</label>
                    <input id="f-min" type="text" inputmode="decimal" wire:model.live.debounce.500ms="min" placeholder="Any" class="{{ $filterInput }} placeholder:text-slate-400">
                </div>
                <div class="rounded-xl bg-white px-3 pt-2 pb-1.5 ring-1 ring-slate-300 ring-inset focus-within:ring-2 focus-within:ring-violet-600">
                    <label for="f-max" class="block text-xs font-medium text-slate-500">Max total ($)</label>
                    <input id="f-max" type="text" inputmode="decimal" wire:model.live.debounce.500ms="max" placeholder="Any" class="{{ $filterInput }} placeholder:text-slate-400">
                </div>
                <div class="rounded-xl bg-white px-3 pt-2 pb-1.5 ring-1 ring-slate-300 ring-inset focus-within:ring-2 focus-within:ring-violet-600">
                    <label for="f-per-page" class="block text-xs font-medium text-slate-500">Per page</label>
                    <div class="relative">
                        <select id="f-per-page" wire:model.live="perPage" class="{{ $filterInput }} appearance-none pr-6">@foreach (\App\Services\Estimates\EstimateDirectory::PER_PAGE as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select>
                        <x-ui-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-0 size-4 -translate-y-1/2 text-slate-500" />
                    </div>
                </div>
            </div>
            @if ($filteredCustomer || $filtering)
                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <p class="text-slate-700">@if ($filteredCustomer)Customer: <span class="font-medium">{{ $filteredCustomer }}</span>@endif</p>
                    @if ($filtering)
                        <button type="button" wire:click="clearFilters" class="font-medium text-violet-700 hover:underline">Clear filters</button>
                    @endif
                </div>
            @endif
        </section>

        <div wire:loading.delay.class="opacity-60" class="transition-opacity">
            @if ($estimates->isEmpty())
                <p class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-sm text-slate-500">No estimates match your search or filters.</p>
            @else
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" data-section="estimates">
                    <div class="hidden gap-4 border-b border-slate-200 px-5 py-3.5 text-sm font-semibold text-slate-500 lg:grid {{ $columns }}" aria-hidden="true">
                        <span>Estimate #</span>
                        <span>Customer</span>
                        <span>Status</span>
                        <span>Total</span>
                        <span>Created</span>
                        <span>Expires</span>
                        <span class="text-right">Actions</span>
                    </div>
                    <ul role="list" class="divide-y divide-slate-100">
                        @foreach ($estimates as $estimate)
                            <li wire:key="estimate-{{ $estimate->id }}" class="grid gap-3 px-5 py-4 lg:items-center lg:gap-4 {{ $columns }}">
                                <div class="min-w-0">
                                    <a href="{{ route('estimates.show', $estimate->id) }}" wire:navigate class="font-semibold text-slate-900 hover:text-violet-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">{{ $estimate->displayNumber() }}</a>
                                    <p class="truncate text-sm text-slate-500 lg:hidden">{{ $estimate->customer?->name }}</p>
                                </div>
                                <div class="hidden min-w-0 lg:block">
                                    <p class="truncate font-medium text-slate-900">{{ $estimate->customer?->name }}</p>
                                    <p class="truncate text-sm text-slate-500">{{ $estimate->customer?->email }}</p>
                                </div>
                                <div><x-estimate-status-badge :status="$estimate->status" /></div>
                                <p class="font-medium text-slate-900">{{ $estimate->money('total') }}</p>
                                <p class="text-sm text-slate-700">{{ $organization->formatDateTime($estimate->created_at) }}</p>
                                <p class="text-sm text-slate-700">{{ $estimate->valid_until ? $organization->formatCalendarDate($estimate->valid_until) : '—' }}</p>
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('estimates.show', $estimate->id) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-1.5 text-sm font-medium text-slate-800 ring-1 ring-slate-300 ring-inset transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500"><x-ui-icon name="eye" class="size-4" />View</a>
                                    <details class="relative">
                                        <summary aria-label="More actions for {{ $estimate->displayNumber() }}" class="grid size-9 cursor-pointer list-none place-items-center rounded-lg bg-white text-slate-500 ring-1 ring-slate-300 ring-inset transition hover:bg-slate-50 [&::-webkit-details-marker]:hidden"><x-ui-icon name="ellipsis" class="size-5" /></summary>
                                        <div class="absolute right-0 z-10 mt-2 w-44 rounded-xl bg-white p-1 shadow-lg ring-1 ring-slate-200">
                                            <a href="{{ route('estimates.show', $estimate->id) }}" wire:navigate class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">View details</a>
                                            @if ($estimate->status === \App\Enums\EstimateStatus::Draft)
                                                <a href="{{ route('estimates.edit', $estimate->id) }}" wire:navigate class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">Edit</a>
                                            @endif
                                        </div>
                                    </details>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-4 text-sm text-slate-500">
                        <span>Showing {{ $estimates->firstItem() }}–{{ $estimates->lastItem() }} of {{ $estimates->total() }} {{ \Illuminate\Support\Str::plural('estimate', $estimates->total()) }}</span>
                        @if ($estimates->hasPages())
                            <nav aria-label="Pagination" class="flex items-center gap-1.5">
                                <button type="button" wire:click="previousPage" @disabled($estimates->onFirstPage()) aria-label="Previous page" class="grid size-9 place-items-center rounded-lg bg-white text-slate-500 ring-1 ring-slate-200 ring-inset transition hover:bg-slate-50 disabled:opacity-40"><x-ui-icon name="chevron-left" class="size-4" /></button>
                                @for ($page = max(1, $estimates->currentPage() - 2); $page <= min($estimates->lastPage(), $estimates->currentPage() + 2); $page++)
                                    <button type="button" wire:click="gotoPage({{ $page }})" @if ($page === $estimates->currentPage()) aria-current="page" @endif class="{{ $page === $estimates->currentPage() ? 'bg-violet-600 text-white shadow-sm' : 'bg-white text-slate-700 ring-1 ring-slate-200 ring-inset hover:bg-slate-50' }} grid h-9 min-w-9 place-items-center rounded-lg px-2 font-medium transition">{{ $page }}</button>
                                @endfor
                                <button type="button" wire:click="nextPage" @disabled($estimates->currentPage() === $estimates->lastPage()) aria-label="Next page" class="grid size-9 place-items-center rounded-lg bg-white text-slate-500 ring-1 ring-slate-200 ring-inset transition hover:bg-slate-50 disabled:opacity-40"><x-ui-icon name="chevron-right" class="size-4" /></button>
                            </nav>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
