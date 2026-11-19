<div class="space-y-6">
    @php($pill = 'h-10 rounded-xl border-0 py-0 pr-9 pl-3.5 text-sm font-medium shadow-none ring-1 ring-inset transition focus:ring-2 focus:ring-violet-500 focus:outline-none')

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Pipeline</h1>
            <p class="mt-1 text-sm text-slate-500">Move each lead through the stages as the sale progresses.</p>
        </div>
        <a href="{{ route('customers.index') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-xs ring-1 ring-slate-200 ring-inset transition hover:bg-slate-50">All customers</a>
    </div>

    {{-- Report: the whole business, not only the tag filter --}}
    <section aria-label="Pipeline report" class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-section="pipeline-report">
        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs" data-report="open">
            <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Open deals</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">{{ number_format($summary['open_count']) }}</p>
            <p class="mt-0.5 text-sm text-slate-500">{{ \App\Support\Money::format($summary['open_value'], $currency) }} in value</p>
        </div>
        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs" data-report="won-month">
            <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Won this month</p>
            <p class="mt-1 text-2xl font-bold text-emerald-700">{{ number_format($summary['won_count']) }}</p>
            <p class="mt-0.5 text-sm text-slate-500">{{ \App\Support\Money::format($summary['won_value'], $currency) }} won</p>
        </div>
        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs" data-report="win-rate">
            <p class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Win rate</p>
            <p class="mt-1 text-2xl font-bold text-slate-900">{{ $summary['win_rate'] !== null ? $summary['win_rate'].'%' : '—' }}</p>
            <p class="mt-0.5 text-sm text-slate-500">Won out of closed deals</p>
        </div>
        <div class="rounded-2xl border border-slate-200/80 bg-white p-4 shadow-xs">
            <label for="pipeline-tag" class="text-xs font-semibold tracking-wide text-slate-500 uppercase">Filter by tag</label>
            <select id="pipeline-tag" wire:change="$set('tag', $event.target.value)" class="{{ $pill }} mt-2 w-full bg-white text-slate-700 ring-slate-200">
                <option value="" @selected($tag === '')>All customers</option>
                @foreach ($tags as $option)
                    <option value="{{ $option->slug }}" @selected($tag === $option->slug)>{{ $option->name }}</option>
                @endforeach
            </select>
        </div>
    </section>

    {{-- One column per stage --}}
    <div class="-mx-4 flex gap-3 overflow-x-auto px-4 pb-3 sm:mx-0 sm:px-0" data-section="pipeline-board">
        @foreach ($board as $column)
            @php($stage = $column['stage'])
            <section aria-label="{{ $stage->label() }} leads" class="flex w-72 shrink-0 flex-col rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200/70" data-stage="{{ $stage->value }}">
                <header class="mb-3 flex items-start justify-between gap-2 px-1">
                    <div class="min-w-0">
                        <h2 class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                            <x-lead-stage-badge :stage="$stage" />
                            <span class="text-slate-500">{{ number_format($column['count']) }}</span>
                        </h2>
                        <p class="mt-1 text-xs text-slate-500">{{ \App\Support\Money::format($column['value'], $currency) }}</p>
                    </div>
                </header>

                <ul class="flex flex-col gap-2.5">
                    @forelse ($column['customers'] as $customer)
                        <li class="rounded-xl bg-white p-3 shadow-xs ring-1 ring-slate-200/80" data-lead="{{ $customer->id }}">
                            <a href="{{ route('customers.show', $customer->id) }}" wire:navigate class="block truncate text-sm font-semibold text-slate-900 hover:text-violet-700 hover:underline">{{ $customer->name }}</a>
                            @if ($customer->company)
                                <p class="truncate text-xs text-slate-500">{{ $customer->company }}</p>
                            @endif
                            <p class="mt-1.5 text-sm font-medium {{ $customer->deal_value_cents === null ? 'text-slate-400' : 'text-slate-800' }}">
                                {{ $customer->deal_value_cents === null ? 'No value set' : \App\Support\Money::format($customer->deal_value_cents, $currency) }}
                            </p>

                            @if ($customer->tags->isNotEmpty())
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($customer->tags as $cardTag)
                                        <span class="rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700 ring-1 ring-violet-200 ring-inset">{{ $cardTag->name }}</span>
                                    @endforeach
                                </div>
                            @endif

                            <label class="sr-only" for="move-{{ $customer->id }}">Move {{ $customer->name }} to a stage</label>
                            <select id="move-{{ $customer->id }}" wire:change="moveLead({{ $customer->id }}, $event.target.value)" class="mt-2.5 w-full rounded-lg border-0 py-1.5 pr-8 pl-2.5 text-xs font-medium text-slate-700 ring-1 ring-slate-200 ring-inset focus:ring-2 focus:ring-violet-600">
                                @foreach ($stages as $option)
                                    <option value="{{ $option->value }}" @selected($customer->lead_stage === $option)>Stage: {{ $option->label() }}</option>
                                @endforeach
                            </select>
                        </li>
                    @empty
                        <li class="rounded-xl border border-dashed border-slate-300 px-3 py-6 text-center text-xs text-slate-400">No leads here</li>
                    @endforelse
                </ul>

                @if ($column['count'] > count($column['customers']))
                    <p class="mt-2 px-1 text-xs text-slate-500">Showing the latest {{ count($column['customers']) }} of {{ number_format($column['count']) }}. Use the customer list to find the rest.</p>
                @endif
            </section>
        @endforeach
    </div>
</div>
