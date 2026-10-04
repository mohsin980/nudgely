<div class="space-y-6">
    @php($card = 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5')
    @php($heading = 'text-xs font-semibold tracking-wide text-gray-500 uppercase')
    @php($button = 'inline-flex items-center justify-center rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500')
    @php($automation = $this->automation)
    @php($stats = $this->stats)

    <div class="space-y-3">
        <a href="{{ route('automations.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">&larr; Automations</a>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $automation->name }}</h1>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-gray-600"><x-automation-status-badge :status="$automation->status" />@if ($automation->description)<span>{{ $automation->description }}</span>@endif</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @cannot('update', $automation)
                    <a href="{{ route('automations.logs', $automation->id) }}" wire:navigate class="{{ $button }}">View Logs</a>
                @else
                @unless ($archived)
                    <a href="{{ route('automations.edit', $automation->id) }}" wire:navigate class="{{ $button }}">Edit</a>
                    @if ($automation->isActive())
                        <button type="button" wire:click="pause" class="{{ $button }}">Pause</button>
                    @else
                        <button type="button" wire:click="activate" class="inline-flex items-center rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Activate</button>
                    @endif
                @endunless
                <button type="button" wire:click="duplicate" class="{{ $button }}">Duplicate</button>
                <a href="{{ route('automations.logs', $automation->id) }}" wire:navigate class="{{ $button }}">View Logs</a>
                @if ($archived)
                    <button type="button" wire:click="restore" class="{{ $button }}">Restore as Draft</button>
                @else
                    <button type="button" wire:click="$set('confirmArchive', true)" class="{{ $button }}">Archive</button>
                @endif
                @endcannot
            </div>
        </div>
    </div>

    @include('livewire.automations.partials.flash')

    @if ($confirmArchive)
        <div class="{{ $card }} space-y-3" role="alertdialog" aria-labelledby="archive-q">
            <p id="archive-q" class="text-sm text-gray-800">Archive “{{ $automation->name }}”? It stops running (including waiting runs). Its history and logs are kept, and you can restore it as a draft.</p>
            <div class="flex gap-2">
                <button type="button" wire:click="archive" class="rounded-md bg-gray-800 px-3 py-2 text-sm font-semibold text-white hover:bg-gray-700">Archive</button>
                <button type="button" wire:click="$set('confirmArchive', false)" class="rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:underline">Keep it</button>
            </div>
        </div>
    @endif

    @if (! $automation->isActive() && ! $archived && $this->activationErrors !== [])
        <div role="status" class="rounded-md bg-amber-50 p-4 text-sm text-amber-900" data-problems>
            <p class="font-medium">To activate, fix:</p>
            <ul class="mt-1 list-inside list-disc">@foreach ($this->activationErrors as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            <section aria-label="What it does" class="{{ $card }} space-y-4">
                <dl class="space-y-3 text-sm" data-section="summary">
                    <div><dt class="{{ $heading }}">When</dt><dd class="text-gray-900">{{ $summary['when'] }}</dd></div>
                    @if ($summary['wait'])<div><dt class="{{ $heading }}">Wait</dt><dd class="text-gray-900">{{ $summary['wait'] }}</dd></div>@endif
                    <div><dt class="{{ $heading }}">If {{ count($summary['conditions']) > 1 ? ($summary['match'] === 'any' ? '(any)' : '(all)') : '' }}</dt>
                        <dd class="text-gray-900">@forelse ($summary['conditions'] as $line)<span class="block">{{ $line }}</span>@empty <span class="text-gray-600">No conditions</span>@endforelse</dd></div>
                    <div><dt class="{{ $heading }}">Then</dt>
                        <dd class="text-gray-900"><ol class="list-inside list-decimal">@forelse ($summary['actions'] as $line)<li>{{ $line }}</li>@empty <li class="list-none text-gray-600">No actions</li>@endforelse</ol></dd></div>
                </dl>
                <p class="rounded-md bg-gray-50 p-3 text-sm text-gray-700">{{ $summary['sentence'] }}</p>
            </section>

            <section aria-labelledby="history-heading" class="{{ $card }}">
                <h2 id="history-heading" class="{{ $heading }}">History</h2>
                @if ($history->isEmpty())
                    <p class="mt-2 text-sm text-gray-600">No changes recorded.</p>
                @else
                    <ol role="list" class="mt-2 divide-y divide-gray-100 text-sm" data-section="history">
                        @foreach ($history as $entry)
                            <li class="flex flex-wrap justify-between gap-2 py-2">
                                <span class="text-gray-900">{{ ucfirst($entry->action) }}{{ $entry->user ? ' by '.$entry->user->name : '' }}{{ isset($entry->data['from']) && $entry->action === 'duplicated' ? ' from “'.$entry->data['from'].'”' : '' }}</span>
                                <time class="text-gray-500" datetime="{{ $entry->created_at->toIso8601String() }}">{{ $organization->localTime($entry->created_at)->format('M j, Y g:i A') }}</time>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        <section aria-labelledby="stats-heading" class="{{ $card }} h-fit">
            <h2 id="stats-heading" class="{{ $heading }}">Details</h2>
            <dl class="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm" data-section="stats">
                <dt class="text-gray-600">Runs</dt><dd class="text-gray-900" data-stat="executions">{{ $stats['executions'] }}</dd>
                <dt class="text-gray-600">Failed</dt><dd @class(['text-gray-900', 'font-semibold text-red-700' => $stats['failed'] > 0])>{{ $stats['failed'] }}</dd>
                <dt class="text-gray-600">Last run</dt><dd class="text-gray-900">{{ $stats['last'] ? \Illuminate\Support\Carbon::parse($stats['last'])->diffForHumans() : 'Never' }}</dd>
                <dt class="text-gray-600">Next run</dt><dd class="text-gray-900">{{ $stats['next'] ? $organization->localTime(\Illuminate\Support\Carbon::parse($stats['next']))->format('M j, g:i A').' ('.$waitingRuns.' waiting)' : '—' }}</dd>
                <dt class="text-gray-600">Created</dt><dd class="text-gray-900">{{ $organization->localTime($automation->created_at)->format('M j, Y') }}{{ $automation->creator ? ' by '.$automation->creator->name : '' }}</dd>
                <dt class="text-gray-600">Updated</dt><dd class="text-gray-900">{{ $organization->localTime($automation->updated_at)->format('M j, Y g:i A') }}</dd>
            </dl>
            @if (! $archived && $stats['executions'] === 0)
                <div class="mt-4 border-t border-gray-100 pt-3 text-sm">
                    @if ($confirmDelete)
                        <p class="text-gray-700">Delete this automation? This can’t be undone.</p>
                        <div class="mt-2 flex gap-2"><button type="button" wire:click="delete" class="rounded-md bg-red-600 px-3 py-1.5 font-semibold text-white hover:bg-red-500">Delete</button><button type="button" wire:click="$set('confirmDelete', false)" class="px-2 font-medium text-gray-700 hover:underline">Cancel</button></div>
                    @else
                        @can('delete', $automation)<button type="button" wire:click="$set('confirmDelete', true)" class="font-medium text-red-700 hover:underline">Delete automation</button>@endcan
                    @endif
                </div>
            @endif
        </section>
    </div>
</div>
