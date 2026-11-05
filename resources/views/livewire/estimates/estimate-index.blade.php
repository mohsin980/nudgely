<div class="space-y-6">
    @php($field = 'block w-full rounded-xl border-0 bg-white px-3.5 py-2.5 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
    @php($columns = 'lg:grid-cols-[minmax(0,1.1fr)_minmax(0,2fr)_minmax(0,1.1fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]')
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">Estimates</h1>
            <p class="mt-1 text-sm text-slate-500">Manage and track your estimates</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('estimates.import') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-xs ring-1 ring-slate-300 ring-inset transition hover:bg-slate-50"><x-ui-icon name="inbox" class="size-4" />Import CSV</a>
            <a href="{{ route('estimates.create') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2"><x-ui-icon name="plus" class="size-4" />Create Estimate</a>
        </div>
    </div>

    @if (! $hasAnyEstimate)
        <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
            <span class="mx-auto grid size-12 place-items-center rounded-xl bg-violet-50 text-violet-600"><x-ui-icon name="document" class="size-6" /></span>
            <p class="mt-4 text-base font-semibold text-slate-900">No estimates yet.</p>
            <p class="mt-1 text-sm text-slate-500">Create an estimate, send it, and QuoteFollow will help you follow up.</p>
            <a href="{{ route('estimates.create') }}" wire:navigate class="mt-5 inline-flex items-center gap-1.5 rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-violet-700">Create Estimate</a>
        </div>
    @else
        {{-- Summary: the whole business, not only the filtered rows --}}
        <section aria-label="Summary" class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            @foreach ([
                ['Total estimates', $this->summary['total'], 'document', 'bg-violet-50 text-violet-600'],
                ['Accepted', $this->summary['accepted'], 'check', 'bg-emerald-50 text-emerald-600'],
                ['Sent', $this->summary['sent'], 'envelope', 'bg-sky-50 text-sky-600'],
                ['Draft', $this->summary['draft'], 'document', 'bg-slate-100 text-slate-600'],
                ['Total value', $this->summary['value'], 'bolt', 'bg-violet-50 text-violet-600'],
            ] as [$label, $value, $icon, $tone])
                <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl {{ $tone }}"><x-ui-icon :name="$icon" class="size-6" /></span>
                    <div class="min-w-0">
                        <p class="truncate text-xl font-semibold tracking-tight text-slate-900">{{ $value }}</p>
                        <p class="text-sm text-slate-500">{{ $label }}</p>
                    </div>
                </div>
            @endforeach
        </section>

        <section aria-label="Search and filters" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs sm:p-5">
            <div class="relative">
                <label for="estimate-search" class="sr-only">Search estimates</label>
                <x-ui-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                <input id="estimate-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search by estimate number, customer name or email, or title" class="{{ $field }} pl-10">
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div><label for="f-status" class="block text-xs font-medium text-slate-500">Status</label>
                    <select id="f-status" wire:model.live="status" class="mt-1 {{ $field }}"><option value="">All</option><option value="awaiting">Awaiting customer</option>@foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select></div>
                <div><label for="f-from" class="block text-xs font-medium text-slate-500">Created from</label>
                    <input id="f-from" type="date" wire:model.live="from" class="mt-1 {{ $field }}"></div>
                <div><label for="f-to" class="block text-xs font-medium text-slate-500">Created to</label>
                    <input id="f-to" type="date" wire:model.live="to" class="mt-1 {{ $field }}"></div>
                <div><label for="f-min" class="block text-xs font-medium text-slate-500">Min total ($)</label>
                    <input id="f-min" type="text" inputmode="decimal" wire:model.live.debounce.500ms="min" placeholder="Any" class="mt-1 {{ $field }}"></div>
                <div><label for="f-max" class="block text-xs font-medium text-slate-500">Max total ($)</label>
                    <input id="f-max" type="text" inputmode="decimal" wire:model.live.debounce.500ms="max" placeholder="Any" class="mt-1 {{ $field }}"></div>
                <div><label for="f-per-page" class="block text-xs font-medium text-slate-500">Per page</label>
                    <select id="f-per-page" wire:model.live="perPage" class="mt-1 {{ $field }}">@foreach (\App\Services\Estimates\EstimateDirectory::PER_PAGE as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select></div>
            </div>
            @if ($filteredCustomer)
                <p class="text-sm text-slate-700">Customer: <span class="font-medium">{{ $filteredCustomer }}</span></p>
            @endif
            @if ($filtering)
                <button type="button" wire:click="clearFilters" class="text-sm font-medium text-violet-700 hover:underline">Clear filters</button>
            @endif
        </section>

        <div wire:loading.delay.class="opacity-60" class="transition-opacity">
            @if ($estimates->isEmpty())
                <p class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-sm text-slate-500">No estimates match your search or filters.</p>
            @else
                <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs" data-section="estimates">
                    <div class="hidden gap-4 border-b border-slate-200 bg-slate-50 px-5 py-3 text-xs font-semibold tracking-wide text-slate-500 uppercase lg:grid {{ $columns }}" aria-hidden="true">
                        <span>Estimate #</span>
                        <span>Customer</span>
                        <span>Status</span>
                        <span>Total</span>
                        <span>Created</span>
                        <span>Expires</span>
                        <span class="text-right">Action</span>
                    </div>
                    <ul role="list" class="divide-y divide-slate-100">
                        @foreach ($estimates as $estimate)
                            <li wire:key="estimate-{{ $estimate->id }}">
                                <a href="{{ route('estimates.show', $estimate->id) }}" wire:navigate class="grid gap-2 px-5 py-4 transition-colors hover:bg-violet-50/50 focus:outline-none focus-visible:bg-violet-50/50 focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-inset lg:items-center lg:gap-4 {{ $columns }}">
                                    <div class="min-w-0">
                                        <p class="font-semibold text-slate-900">{{ $estimate->displayNumber() }}</p>
                                        <p class="truncate text-xs text-slate-500 lg:hidden">{{ $estimate->customer?->name }} · {{ $estimate->title }}</p>
                                    </div>
                                    <div class="hidden min-w-0 lg:block">
                                        <p class="truncate font-medium text-slate-900">{{ $estimate->customer?->name }}</p>
                                        <p class="truncate text-sm text-slate-500">{{ $estimate->title }}</p>
                                    </div>
                                    <div><x-estimate-status-badge :status="$estimate->status" /></div>
                                    <p class="font-semibold text-slate-900">{{ $estimate->money('total') }}</p>
                                    <div class="text-sm">
                                        <p class="text-slate-700">{{ $organization->formatDate($estimate->created_at) }}</p>
                                        @if ($estimate->sent_at)<p class="text-xs text-slate-500">Sent {{ $organization->formatDate($estimate->sent_at) }}</p>@endif
                                    </div>
                                    <p class="text-sm text-slate-700">{{ $estimate->valid_until ? $organization->formatCalendarDate($estimate->valid_until) : '—' }}</p>
                                    <span class="inline-flex items-center justify-end gap-1 text-sm font-medium text-violet-700">View <x-ui-icon name="arrow-right" class="size-4" /></span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-3 text-sm text-slate-500">
                        <span>Showing {{ $estimates->firstItem() }}–{{ $estimates->lastItem() }} of {{ $estimates->total() }} {{ \Illuminate\Support\Str::plural('estimate', $estimates->total()) }}</span>
                        {{ $estimates->links() }}
                    </div>
                </div>
            @endif
        </div>
    @endif
</div>
