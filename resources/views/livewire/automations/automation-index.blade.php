<div class="space-y-8">
    @php($button = 'inline-flex items-center justify-center rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500')
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Automations</h1>
            <p class="mt-1 text-sm text-gray-600">WHEN something happens, WAIT if you like, IF it still makes sense, THEN take action.</p>
        </div>
        @can('create', \App\Models\Automation::class)
        <a href="{{ route('automations.create') }}" wire:navigate class="inline-flex items-center rounded-md bg-violet-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-violet-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2">Create Automation</a>
        @endcan
    </div>

    @include('livewire.automations.partials.flash')

    @if ($this->recentFailures > 0)
        <div role="alert" class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-red-200 bg-red-50 p-4 text-sm text-red-800" data-failures>
            <p><span class="font-semibold">Automation issue:</span> {{ trans_choice(':count execution failed|:count executions failed', $this->recentFailures) }} in the last 7 days. Open an automation's logs to see why.</p>
        </div>
    @endif

    {{-- Automations --}}
    <section aria-labelledby="automations-heading" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="automations-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Your automations</h2>
            @if ($this->archivedCount > 0)
                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model.live="showArchived" class="rounded border-gray-300"> Show archived ({{ $this->archivedCount }})</label>
            @endif
        </div>

        @if ($this->automations->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-10 text-center">
                <p class="text-base font-semibold text-gray-900">Automate repetitive follow-ups and customer tasks.</p>
                <p class="mt-1 text-sm text-gray-600">Start from a template below, or build your own.</p>
                @can('create', \App\Models\Automation::class)
                <a href="{{ route('automations.create') }}" wire:navigate class="mt-4 inline-flex rounded-md bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-500">Create Automation</a>
                @endcan
            </div>
        @else
            <ul role="list" class="space-y-3" data-section="automations">
                @foreach ($this->automations as $automation)
                    <li wire:key="automation-{{ $automation->id }}" class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2">
                                    <a href="{{ route('automations.show', $automation->id) }}" wire:navigate class="font-semibold text-gray-900 hover:underline">{{ $automation->name }}</a>
                                    <x-automation-status-badge :status="$automation->status" />
                                </p>
                                <dl class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-gray-600">
                                    <div><dt class="inline">When:</dt> <dd class="inline font-medium text-gray-800">{{ $automation->trigger_type->label() }}</dd></div>
                                    @if ($automation->waitLabel())<div><dt class="inline">Wait:</dt> <dd class="inline">{{ $automation->waitLabel() }}</dd></div>@endif
                                    <div><dt class="inline">Actions:</dt> <dd class="inline" data-actions-count>{{ $automation->actions_count }}</dd></div>
                                    <div><dt class="inline">Runs:</dt> <dd class="inline" data-executions>{{ $automation->executions_count }}</dd></div>
                                    <div><dt class="inline">Last run:</dt> <dd class="inline">{{ $automation->last_run_at ? \Illuminate\Support\Carbon::parse($automation->last_run_at)->diffForHumans() : 'Never' }}</dd></div>
                                    <div><dt class="inline">Created:</dt> <dd class="inline">{{ $organization->localTime($automation->created_at)->format('M j, Y') }}</dd></div>
                                </dl>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if ($automation->status !== \App\Enums\Automation\AutomationStatus::Archived && auth()->user()->can('update', $automation))
                                    <a href="{{ route('automations.edit', $automation->id) }}" wire:navigate class="{{ $button }}">Edit</a>
                                @endif
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
        <h2 id="templates-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Starter templates</h2>
        <p class="text-sm text-gray-600">Each template is added as a draft: nothing runs until you review and activate it.</p>
        <ul role="list" class="grid gap-4 sm:grid-cols-2">
            @foreach (collect($this->templates)->sortBy(fn ($t, $key) => in_array($key, $starters, true) ? array_search($key, $starters, true) : 100) as $key => $template)
                <li wire:key="template-{{ $key }}" class="flex flex-col justify-between gap-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm" data-template="{{ $key }}">
                    <div>
                        <p class="font-semibold text-gray-900">{{ $template['name'] }}</p>
                        <p class="mt-1 text-sm text-gray-600">{{ $template['description'] }}</p>
                        <p class="mt-1 text-xs text-gray-500">When: {{ \App\Services\Automation\Registry\TriggerRegistry::get($template['trigger_type'])->label }}</p>
                    </div>
                    <div>
                        <button type="button" wire:click="installTemplate('{{ $key }}')" class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-violet-700 ring-1 ring-violet-200 ring-inset hover:bg-violet-50">Use template</button>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    @endcan

    {{-- Email safety settings (the owner decides; everyone else sees them) --}}
    <section aria-labelledby="settings-heading" class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <h2 id="settings-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Email safety</h2>
        <ul role="list" class="mt-3 divide-y divide-gray-100">
            @foreach ([
                'automations_enabled' => ['Automations', 'Run active automations.'],
                'automatic_email_enabled' => ['Automatic emails', 'Allow automations to email customers from your verified business email.'],
                'require_approval_for_email' => ['Require approval for emails', 'Never send automated emails to customers without approval. Turn off only if you trust your email automations.'],
            ] as $setting => [$label, $help])
                @php($on = (bool) $organization->{$setting})
                <li class="flex items-center justify-between gap-4 py-3" wire:key="setting-{{ $setting }}">
                    <div>
                        <p class="text-sm font-medium text-gray-900" id="setting-{{ $setting }}">{{ $label }}</p>
                        <p class="text-sm text-gray-600">{{ $help }}</p>
                    </div>
                    <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-labelledby="setting-{{ $setting }}"
                            @can('manage-email') wire:click="toggleSetting('{{ $setting }}')" @else disabled title="Only the owner can change this." @endcan data-setting="{{ $setting }}"
                            @class([
                                'relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2',
                                'bg-violet-600' => $on,
                                'bg-gray-200' => ! $on,
                            ])>
                        <span class="sr-only">{{ $on ? 'On' : 'Off' }}</span>
                        <span aria-hidden="true" @class(['inline-block h-5 w-5 transform rounded-full bg-white shadow transition', 'translate-x-5' => $on, 'translate-x-0' => ! $on])></span>
                    </button>
                </li>
            @endforeach
        </ul>
    </section>
</div>
