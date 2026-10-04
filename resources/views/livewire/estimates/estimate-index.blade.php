<div class="space-y-6">
    @php($field = 'block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 focus:ring-inset')
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Estimates</h1>
            <p class="mt-1 text-sm text-gray-600">{{ trans_choice(':count estimate|:count estimates', $estimates->total()) }}{{ $filtering ? ' match your filters' : '' }}</p>
        </div>
        <a href="{{ route('estimates.create') }}" wire:navigate class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">Create Estimate</a>
    </div>

    @if (! $hasAnyEstimate)
        <div class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-10 text-center">
            <p class="text-base font-semibold text-gray-900">No estimates yet.</p>
            <p class="mt-1 text-sm text-gray-600">Create an estimate, send it, and QuoteFollow will help you follow up.</p>
            <a href="{{ route('estimates.create') }}" wire:navigate class="mt-4 inline-flex rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Create Estimate</a>
        </div>
    @else
        <section aria-label="Search and filters" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
            <div>
                <label for="estimate-search" class="sr-only">Search estimates</label>
                <input id="estimate-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search by estimate number, customer name or email, or title" class="{{ $field }}">
            </div>
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
                <div><label for="f-status" class="block text-xs font-medium text-gray-600">Status</label>
                    <select id="f-status" wire:model.live="status" class="mt-1 {{ $field }}"><option value="">All</option><option value="awaiting">Awaiting customer</option>@foreach ($statuses as $s)<option value="{{ $s->value }}">{{ $s->label() }}</option>@endforeach</select></div>
                <div><label for="f-from" class="block text-xs font-medium text-gray-600">Created from</label>
                    <input id="f-from" type="date" wire:model.live="from" class="mt-1 {{ $field }}"></div>
                <div><label for="f-to" class="block text-xs font-medium text-gray-600">Created to</label>
                    <input id="f-to" type="date" wire:model.live="to" class="mt-1 {{ $field }}"></div>
                <div><label for="f-min" class="block text-xs font-medium text-gray-600">Min total ($)</label>
                    <input id="f-min" type="text" inputmode="decimal" wire:model.live.debounce.500ms="min" class="mt-1 {{ $field }}"></div>
                <div><label for="f-max" class="block text-xs font-medium text-gray-600">Max total ($)</label>
                    <input id="f-max" type="text" inputmode="decimal" wire:model.live.debounce.500ms="max" class="mt-1 {{ $field }}"></div>
                <div><label for="f-per-page" class="block text-xs font-medium text-gray-600">Per page</label>
                    <select id="f-per-page" wire:model.live="perPage" class="mt-1 {{ $field }}">@foreach (\App\Services\Estimates\EstimateDirectory::PER_PAGE as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach</select></div>
            </div>
            @if ($filteredCustomer)
                <p class="text-sm text-gray-700">Customer: <span class="font-medium">{{ $filteredCustomer }}</span></p>
            @endif
            @if ($filtering)
                <button type="button" wire:click="clearFilters" class="text-sm font-medium text-indigo-700 hover:underline">Clear filters</button>
            @endif
        </section>

        <div wire:loading.delay.class="opacity-60" class="transition-opacity">
            @if ($estimates->isEmpty())
                <p class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-8 text-center text-sm text-gray-600">No estimates match your search or filters.</p>
            @else
                <ul role="list" class="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm" data-section="estimates">
                    @foreach ($estimates as $estimate)
                        <li wire:key="estimate-{{ $estimate->id }}">
                            <a href="{{ route('estimates.show', $estimate->id) }}" wire:navigate class="grid gap-1 px-4 py-3 hover:bg-gray-50 focus:outline-none focus-visible:bg-gray-50 focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-inset sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto] sm:items-center sm:gap-4">
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2">
                                        <span class="font-semibold text-gray-900">{{ $estimate->displayNumber() }}</span>
                                        <x-estimate-status-badge :status="$estimate->status" />
                                    </p>
                                    <p class="truncate text-sm text-gray-700">{{ $estimate->customer?->name }} · {{ $estimate->title }}</p>
                                </div>
                                <p class="text-xs text-gray-600 sm:text-right">
                                    Created {{ $organization->formatDate($estimate->created_at) }}
                                    @if ($estimate->sent_at)<br class="hidden sm:inline"><span class="sm:hidden"> · </span>Sent {{ $organization->formatDate($estimate->sent_at) }}@endif
                                    @if ($estimate->valid_until)<br class="hidden sm:inline"><span class="sm:hidden"> · </span>Valid until {{ $organization->formatCalendarDate($estimate->valid_until) }}@endif
                                </p>
                                <p class="text-base font-semibold text-gray-900 sm:text-right">{{ $estimate->money('total') }}</p>
                            </a>
                        </li>
                    @endforeach
                </ul>
                <div class="mt-4">{{ $estimates->links() }}</div>
            @endif
        </div>
    @endif
</div>
