<div class="space-y-8">
    @php($button = 'inline-flex items-center justify-center rounded-xl bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-xs ring-1 ring-slate-300 ring-inset transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500')
    @php($templateIcons = ['follow_up_after_estimate' => 'mail', 'ready_to_book' => 'calendar', 'price_objection' => 'tag', 'wants_callback' => 'phone', 'estimate_accepted' => 'check', 'estimate_declined' => 'filter', 'interested' => 'bolt'])
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">Automations</h1>
            <p class="mt-1 text-[15px] text-slate-500">Do things automatically, like following up with customers at the right time.</p>
        </div>
        @can('create', \App\Models\Automation::class)
        <a href="{{ route('automations.create') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2"><x-automation-icon name="plus" class="size-4" />Create Automation</a>
        @endcan
    </div>

    @include('livewire.automations.partials.flash')

    @if ($this->recentFailures > 0)
        <div role="alert" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" data-failures>
            <p><span class="font-semibold">Automation issue:</span> {{ trans_choice(':count execution failed|:count executions failed', $this->recentFailures) }} in the last 7 days. Open an automation's logs to see why.</p>
        </div>
    @endif

    {{-- Automations --}}
    <section aria-labelledby="automations-heading" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="automations-heading" class="text-base font-semibold text-slate-900">Your automations</h2>
            @if ($this->archivedCount > 0)
                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model.live="showArchived" class="rounded border-slate-300"> Show archived ({{ $this->archivedCount }})</label>
            @endif
        </div>

        @if ($this->automations->isEmpty())
            <div class="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center">
                <span class="mx-auto grid size-12 place-items-center rounded-xl bg-violet-50 text-violet-600"><x-automation-icon name="bolt" class="size-6" /></span>
                <p class="mt-4 text-base font-semibold text-slate-900">Automate repetitive follow-ups and customer tasks.</p>
                <p class="mt-1 text-sm text-slate-500">Start from a template below, or build your own.</p>
                @can('create', \App\Models\Automation::class)
                <a href="{{ route('automations.create') }}" wire:navigate class="mt-4 inline-flex rounded-xl bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-violet-700">Create Automation</a>
                @endcan
            </div>
        @else
            <ul role="list" class="grid gap-4 sm:grid-cols-2" data-section="automations">
                @foreach ($this->automations as $automation)
                    <li wire:key="automation-{{ $automation->id }}" class="flex flex-col justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-600"><x-automation-icon name="bolt" /></span>
                                <div class="min-w-0">
                                    <a href="{{ route('automations.show', $automation->id) }}" wire:navigate class="font-semibold text-slate-900 hover:underline">{{ $automation->name }}</a>
                                    @if ($automation->description)<p class="mt-0.5 line-clamp-2 text-sm text-slate-500">{{ $automation->description }}</p>@endif
                                </div>
                            </div>
                            <x-automation-status-badge :status="$automation->status" />
                        </div>
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <div class="col-span-2"><dt class="inline text-slate-500">When:</dt> <dd class="inline font-medium text-slate-800">{{ $automation->trigger_type->label() }}</dd></div>
                            @if ($automation->waitLabel())<div><dt class="inline text-slate-500">Wait:</dt> <dd class="inline text-slate-800">{{ $automation->waitLabel() }}</dd></div>@endif
                            <div><dt class="inline text-slate-500">Actions:</dt> <dd class="inline text-slate-800" data-actions-count>{{ $automation->actions_count }}</dd></div>
                            <div><dt class="inline text-slate-500">Runs:</dt> <dd class="inline text-slate-800" data-executions>{{ $automation->executions_count }}</dd></div>
                            <div><dt class="inline text-slate-500">Last run:</dt> <dd class="inline text-slate-800">{{ $automation->last_run_at ? \Illuminate\Support\Carbon::parse($automation->last_run_at)->diffForHumans() : 'Never' }}</dd></div>
                        </dl>
                        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-4">
                            <span class="text-xs text-slate-500">Created {{ $organization->localTime($automation->created_at)->format('M j, Y') }}</span>
                            <div class="flex flex-wrap gap-2">
                                @if ($automation->status !== \App\Enums\Automation\AutomationStatus::Archived && auth()->user()->can('update', $automation))
                                    <a href="{{ route('automations.edit', $automation->id) }}" wire:navigate class="{{ $button }}">Edit</a>
                                @endif
                                <a href="{{ route('automations.show', $automation->id) }}" wire:navigate class="{{ $button }}">View details</a>
                                <a href="{{ route('automations.logs', $automation->id) }}" wire:navigate class="{{ $button }}">View Logs</a>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Starter templates (people who build automations) --}}
    @can('create', \App\Models\Automation::class)
    <section aria-labelledby="templates-heading" class="space-y-3">
        <div>
            <h2 id="templates-heading" class="text-base font-semibold text-slate-900">Starter templates</h2>
            <p class="mt-1 text-sm text-slate-500">Each template is added as a draft: nothing runs until you review and activate it.</p>
        </div>
        <ul role="list" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach (collect($this->templates)->sortBy(fn ($t, $key) => in_array($key, $starters, true) ? array_search($key, $starters, true) : 100) as $key => $template)
                <li wire:key="template-{{ $key }}" class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs" data-template="{{ $key }}">
                    <div class="flex items-start gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-600"><x-automation-icon :name="$templateIcons[$key] ?? 'bolt'" /></span>
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-900">{{ $template['name'] }}</p>
                            <p class="mt-1 text-sm text-slate-500">{{ $template['description'] }}</p>
                        </div>
                    </div>
                    <div class="mt-auto flex items-center justify-between gap-3">
                        <p class="text-xs text-slate-500">When: {{ \App\Services\Automation\Registry\TriggerRegistry::get($template['trigger_type'])->label }}</p>
                        <button type="button" wire:click="installTemplate('{{ $key }}')" class="shrink-0 whitespace-nowrap rounded-xl bg-white px-3.5 py-2 text-sm font-medium text-violet-700 shadow-xs ring-1 ring-violet-200 ring-inset transition hover:bg-violet-50">Use template</button>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    @endcan

    {{-- Email safety settings (the owner decides; everyone else sees them) --}}
    <section aria-labelledby="settings-heading" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs sm:p-6">
        <h2 id="settings-heading" class="text-base font-semibold text-slate-900">Email safety</h2>
        <ul role="list" class="mt-3 divide-y divide-slate-100">
            @foreach ([
                'automations_enabled' => ['Turn on automations', 'Let QuoteFlow run the automations you have turned on.'],
                'automatic_email_enabled' => ['Send emails automatically', 'Automations can email customers from your business email address.'],
                'require_approval_for_email' => ['Ask me before sending any email', 'Recommended. You see every email before it goes to a customer.'],
            ] as $setting => [$label, $help])
                @php($on = (bool) $organization->{$setting})
                <li class="flex items-center justify-between gap-4 py-3" wire:key="setting-{{ $setting }}">
                    <div>
                        <p class="text-[15px] font-medium text-slate-900" id="setting-{{ $setting }}">{{ $label }}</p>
                        <p class="text-sm text-slate-500">{{ $help }}</p>
                    </div>
                    <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-labelledby="setting-{{ $setting }}"
                            @can('manage-email') wire:click="toggleSetting('{{ $setting }}')" @else disabled title="Only the owner can change this." @endcan data-setting="{{ $setting }}"
                            @class([
                                'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2',
                                'bg-violet-600' => $on,
                                'bg-slate-300' => ! $on,
                            ])>
                        <span class="sr-only">{{ $on ? 'On' : 'Off' }}</span>
                        <span aria-hidden="true" @class(['inline-block h-5 w-5 transform rounded-full bg-white shadow transition', 'translate-x-5' => $on, 'translate-x-0' => ! $on])></span>
                    </button>
                </li>
            @endforeach
        </ul>
    </section>
</div>
