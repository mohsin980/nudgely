<div class="space-y-6">
    @php($card = 'rounded-2xl border border-slate-200 bg-white p-5 shadow-xs')
    @php($heading = 'text-base font-semibold text-slate-900')
    @php($button = 'inline-flex items-center justify-center rounded-xl bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-xs ring-1 ring-slate-300 ring-inset hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500')
    @php($automation = $this->automation)
    @php($stats = $this->stats)

    <div class="space-y-3">
        <a href="{{ route('automations.index') }}" wire:navigate class="text-sm font-medium text-violet-700 hover:underline">&larr; Automations</a>
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="flex min-w-0 items-start gap-3">
                <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-600"><x-automation-icon name="bolt" class="size-6" /></span>
                <div class="min-w-0">
                    <h1 class="text-2xl font-semibold tracking-tight text-slate-900">{{ $automation->name }}</h1>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500"><x-automation-status-badge :status="$automation->status" />@if ($automation->description)<span>{{ $automation->description }}</span>@endif</p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                @cannot('update', $automation)
                    <a href="{{ route('automations.logs', $automation->id) }}" wire:navigate class="{{ $button }}">View Logs</a>
                @else
                @unless ($archived)
                    <a href="{{ route('automations.edit', $automation->id) }}" wire:navigate class="{{ $button }}">Edit</a>
                    @if ($automation->isActive())
                        <button type="button" wire:click="pause" class="inline-flex items-center justify-center rounded-xl bg-violet-600 px-3.5 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2">Pause</button>
                    @else
                        <button type="button" wire:click="activate" class="inline-flex items-center justify-center rounded-xl bg-violet-600 px-3.5 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2">Activate</button>
                    @endif
                @endunless
                <button type="button" wire:click="duplicate" class="{{ $button }}">Duplicate</button>
                <details class="relative">
                    <summary aria-label="More actions" class="grid size-10 cursor-pointer list-none place-items-center rounded-xl bg-white text-slate-500 shadow-xs ring-1 ring-slate-300 ring-inset hover:bg-slate-50 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 [&::-webkit-details-marker]:hidden">
                        <svg aria-hidden="true" viewBox="0 0 20 20" fill="currentColor" class="size-5"><path d="M3 10a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0zm5.5 0a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0zm5.5 0a1.5 1.5 0 113 0 1.5 1.5 0 01-3 0z"/></svg>
                    </summary>
                    <div class="absolute right-0 z-10 mt-1 w-48 space-y-0.5 rounded-xl bg-white p-1 shadow-lg ring-1 ring-slate-200">
                        <a href="{{ route('automations.logs', $automation->id) }}" wire:navigate class="block rounded-lg px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">View Logs</a>
                        @if ($archived)
                            <button type="button" onclick="this.closest('details').open = false" wire:click="restore" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">Restore as Draft</button>
                        @else
                            <button type="button" onclick="this.closest('details').open = false" wire:click="$set('confirmArchive', true)" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">Archive</button>
                        @endif
                    </div>
                </details>
                @endcannot
            </div>
        </div>
    </div>

    @include('livewire.automations.partials.flash')

    @if ($confirmArchive)
        <div class="{{ $card }} space-y-3" role="alertdialog" aria-labelledby="archive-q">
            <p id="archive-q" class="text-sm text-slate-800">Archive “{{ $automation->name }}”? It stops running (including waiting runs). Its history and logs are kept, and you can restore it as a draft.</p>
            <div class="flex gap-2">
                <button type="button" wire:click="archive" class="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-slate-700">Archive</button>
                <button type="button" wire:click="$set('confirmArchive', false)" class="rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:underline">Keep it</button>
            </div>
        </div>
    @endif

    @if (! $automation->isActive() && ! $archived && $this->activationErrors !== [])
        <div role="status" class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900" data-problems>
            <p class="font-medium">To activate, fix:</p>
            <ul class="mt-1 list-inside list-disc">@foreach ($this->activationErrors as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    {{-- Stats --}}
    <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4" data-section="stats">
        <div class="{{ $card }}"><dt class="text-sm text-slate-500">Runs</dt><dd class="mt-1 text-2xl font-semibold tracking-tight text-slate-900" data-stat="executions">{{ $stats['executions'] }}</dd></div>
        <div class="{{ $card }}"><dt class="text-sm text-slate-500">Failed</dt><dd @class(['mt-1 text-2xl font-semibold tracking-tight', 'text-red-700' => $stats['failed'] > 0, 'text-slate-900' => $stats['failed'] === 0])>{{ $stats['failed'] }}</dd></div>
        <div class="{{ $card }}"><dt class="text-sm text-slate-500">Last run</dt><dd class="mt-1 text-base font-semibold text-slate-900">{{ $stats['last'] ? \Illuminate\Support\Carbon::parse($stats['last'])->diffForHumans() : 'Never' }}</dd></div>
        <div class="{{ $card }}"><dt class="text-sm text-slate-500">Next run</dt><dd class="mt-1 text-base font-semibold text-slate-900">{{ $stats['next'] ? $organization->localTime(\Illuminate\Support\Carbon::parse($stats['next']))->format('M j, g:i A').' ('.$waitingRuns.' waiting)' : '—' }}</dd></div>
    </dl>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            {{-- Flow: When, If, Then --}}
            <section aria-labelledby="flow-heading" class="{{ $card }}" aria-label="What it does">
                <h2 id="flow-heading" class="{{ $heading }}">Automation flow</h2>
                <ol class="mt-4 space-y-0" data-section="summary">
                    <li class="flex gap-4">
                        <div class="flex flex-col items-center"><span class="grid size-10 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-600"><x-automation-icon name="bolt" /></span><span class="my-1 w-px flex-1 bg-slate-200"></span></div>
                        <div class="min-w-0 pb-5"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">When</p><p class="mt-0.5 text-sm font-medium text-slate-900">{{ $summary['when'] }}</p>@if ($summary['wait'])<p class="mt-1 text-sm text-slate-600">Wait {{ $summary['wait'] }}</p>@endif</div>
                    </li>
                    <li class="flex gap-4">
                        <div class="flex flex-col items-center"><span class="grid size-10 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-600"><x-automation-icon name="filter" /></span><span class="my-1 w-px flex-1 bg-slate-200"></span></div>
                        <div class="min-w-0 pb-5"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">If {{ count($summary['conditions']) > 1 ? ($summary['match'] === 'any' ? '(any)' : '(all)') : '' }}</p>
                            @forelse ($summary['conditions'] as $line)<p class="mt-0.5 text-sm text-slate-900">{{ $line }}</p>@empty<p class="mt-0.5 text-sm text-slate-500">No conditions</p>@endforelse</div>
                    </li>
                    <li class="flex gap-4">
                        <div class="flex flex-col items-center"><span class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-50 text-emerald-600"><x-automation-icon name="play" /></span></div>
                        <div class="min-w-0"><p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Then</p>
                            <ol class="mt-0.5 list-inside list-decimal space-y-0.5 text-sm text-slate-900">@forelse ($summary['actions'] as $line)<li>{{ $line }}</li>@empty<li class="list-none text-slate-500">No actions</li>@endforelse</ol></div>
                    </li>
                </ol>
                <p class="mt-2 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">{{ $summary['sentence'] }}</p>
            </section>

            <section aria-labelledby="history-heading" class="{{ $card }}">
                <h2 id="history-heading" class="{{ $heading }}">History</h2>
                @if ($history->isEmpty())
                    <p class="mt-2 text-sm text-slate-500">No changes recorded.</p>
                @else
                    <ol role="list" class="mt-2 divide-y divide-slate-100 text-sm" data-section="history">
                        @foreach ($history as $entry)
                            <li class="flex flex-wrap justify-between gap-2 py-2">
                                <span class="text-slate-900">{{ ucfirst($entry->action) }}{{ $entry->user ? ' by '.$entry->user->name : '' }}{{ isset($entry->data['from']) && $entry->action === 'duplicated' ? ' from “'.$entry->data['from'].'”' : '' }}</span>
                                <time class="text-slate-500" datetime="{{ $entry->created_at->toIso8601String() }}">{{ $organization->localTime($entry->created_at)->format('M j, Y g:i A') }}</time>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        <section aria-labelledby="details-heading" class="{{ $card }} h-fit">
            <h2 id="details-heading" class="{{ $heading }}">Details</h2>
            <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="text-slate-500">Created</dt><dd class="text-slate-900">{{ $organization->localTime($automation->created_at)->format('M j, Y') }}{{ $automation->creator ? ' by '.$automation->creator->name : '' }}</dd>
                <dt class="text-slate-500">Updated</dt><dd class="text-slate-900">{{ $organization->localTime($automation->updated_at)->format('M j, Y g:i A') }}</dd>
            </dl>
            @if (! $archived && $stats['executions'] === 0)
                <div class="mt-4 border-t border-slate-100 pt-3 text-sm">
                    @if ($confirmDelete)
                        <p class="text-slate-700">Delete this automation? This can’t be undone.</p>
                        <div class="mt-2 flex gap-2"><button type="button" wire:click="delete" class="rounded-lg bg-red-600 px-3 py-1.5 font-semibold text-white hover:bg-red-500">Delete</button><button type="button" wire:click="$set('confirmDelete', false)" class="px-2 font-medium text-slate-700 hover:underline">Cancel</button></div>
                    @else
                        @can('delete', $automation)<button type="button" wire:click="$set('confirmDelete', true)" class="font-medium text-red-700 hover:underline">Delete automation</button>@endcan
                    @endif
                </div>
            @endif
        </section>
    </div>
</div>
