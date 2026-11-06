<div class="space-y-8">
    @php($button = 'inline-flex items-center justify-center rounded-xl bg-white px-3.5 py-2 text-sm font-medium text-slate-700 shadow-xs ring-1 ring-slate-300 ring-inset transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500')
    {{-- Presentation for each starter template: its icon, the tab it appears under, and two short tags. --}}
    @php($templateMeta = [
        'follow_up_after_estimate' => ['icon' => 'mail', 'tone' => 'bg-violet-100 text-violet-600', 'tab' => 'Follow-ups', 'tags' => ['Follow-up', '3 days']],
        'ready_to_book' => ['icon' => 'bell', 'tone' => 'bg-sky-100 text-sky-600', 'tab' => 'Sales', 'tags' => ['Sales', 'High priority']],
        'estimate_accepted' => ['icon' => 'check', 'tone' => 'bg-emerald-100 text-emerald-600', 'tab' => 'Sales', 'tags' => ['Sales', 'Task']],
        'interested' => ['icon' => 'refresh', 'tone' => 'bg-orange-100 text-orange-600', 'tab' => 'Sales', 'tags' => ['Sales', '2 days']],
        'price_objection' => ['icon' => 'tag', 'tone' => 'bg-blue-100 text-blue-600', 'tab' => 'Sales', 'tags' => ['Sales', 'Priority']],
        'wants_callback' => ['icon' => 'phone', 'tone' => 'bg-teal-100 text-teal-600', 'tab' => 'Support', 'tags' => ['Support', 'Callback']],
        'estimate_follow_up' => ['icon' => 'calendar', 'tone' => 'bg-amber-100 text-amber-600', 'tab' => 'Reminders', 'tags' => ['Reminders', '3 days']],
        'new_lead_follow_up' => ['icon' => 'plus', 'tone' => 'bg-fuchsia-100 text-fuchsia-600', 'tab' => 'Follow-ups', 'tags' => ['Leads', '1 day']],
        'new_lead_sequence' => ['icon' => 'envelope', 'tone' => 'bg-pink-100 text-pink-600', 'tab' => 'Follow-ups', 'tags' => ['Leads', '3 emails']],
        'estimate_declined' => ['icon' => 'x', 'tone' => 'bg-rose-100 text-rose-600', 'tab' => 'Sales', 'tags' => ['Sales', 'High priority']],
    ])

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">Automations</h1>
            <p class="mt-1 text-[15px] text-slate-500">Do things automatically, like following up with customers at the right time.</p>
        </div>
        @can('create', \App\Models\Automation::class)
        <a href="{{ route('automations.create') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl px-4 py-2.5 text-sm font-semibold text-white shadow-[0_10px_24px_-10px_rgba(109,40,217,0.7)] transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2" style="background: linear-gradient(90deg, #6a2cf2, #8b5cf6);"><x-ui-icon name="plus" class="size-4 stroke-2" />Create Automation</a>
        @endcan
    </div>

    @include('livewire.automations.partials.flash')

    @cannot('create', \App\Models\Automation::class)
        <div class="flex items-start gap-3 rounded-2xl bg-violet-50/80 px-4 py-3.5 text-[15px] ring-1 ring-violet-100" data-view-only-note>
            <x-ui-icon name="info" class="mt-0.5 size-5 shrink-0 stroke-2 text-violet-600" />
            <p class="text-slate-700"><span class="font-semibold text-slate-900">You can view automations, but not add or change them.</span>
                Only the owner or a manager can.@if ($owner) Ask {{ $owner->name }} if you need one set up or changed.@endif</p>
        </div>
    @endcannot

    @if ($this->recentFailures > 0)
        <div role="alert" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800" data-failures>
            <p><span class="font-semibold">Automation issue:</span> {{ trans_choice(':count execution failed|:count executions failed', $this->recentFailures) }} in the last 7 days. Open an automation's logs to see why.</p>
        </div>
    @endif

    {{-- Headline numbers. Trend badges compare the last 7 days with the 7 days before, and only show when there is something to compare. --}}
    <section aria-label="Summary" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['Active automations', $this->stats['active'], 'users', 'bg-linear-to-b from-violet-100 to-violet-200/70 text-violet-600', 'from-violet-200/80', null],
            ['Total runs', $this->stats['runs'], 'envelope', 'bg-linear-to-b from-sky-100 to-sky-200/70 text-sky-600', 'from-sky-200/80', $this->stats['runs_trend']],
            ['Successful', $this->stats['successful'], 'check', 'bg-linear-to-b from-emerald-100 to-emerald-200/70 text-emerald-600', 'from-emerald-200/80', $this->stats['successful_trend']],
            ['Pending', $this->stats['pending'], 'clock', 'bg-linear-to-b from-orange-100 to-orange-200/70 text-orange-600', 'from-orange-200/80', null],
        ] as [$label, $value, $icon, $tone, $glow, $trend])
            <a href="#automations-heading" class="group relative flex items-center gap-3.5 overflow-hidden rounded-2xl bg-white p-4 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.2)] ring-1 ring-slate-200/70 transition hover:-translate-y-0.5 hover:shadow-[0_14px_34px_-12px_rgba(76,29,149,0.3)] focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 sm:p-5" data-stat="{{ \Illuminate\Support\Str::slug($label) }}">
                <span aria-hidden="true" class="pointer-events-none absolute -right-8 -bottom-14 h-28 w-48 rounded-[100%] bg-linear-to-tl {{ $glow }} to-transparent"></span>
                <span class="relative grid size-14 shrink-0 place-items-center rounded-2xl {{ $tone }} shadow-[inset_0_1px_0_rgba(255,255,255,0.8)]"><x-ui-icon :name="$icon" class="size-7 stroke-2" /></span>
                <div class="relative min-w-0 flex-1">
                    <p class="text-[28px] leading-tight font-extrabold tracking-tight text-slate-900">{{ number_format($value) }}</p>
                    <p class="text-[13px] leading-snug text-slate-500 sm:text-sm">{{ $label }}</p>
                </div>
                @if ($trend !== null)
                    <span class="absolute top-3 right-12 inline-flex items-center gap-0.5 rounded-full px-2 py-0.5 text-xs font-semibold {{ $trend >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700' }}" title="Last 7 days compared with the 7 days before">{{ $trend >= 0 ? '↑' : '↓' }} {{ abs($trend) }}%</span>
                @endif
                <span aria-hidden="true" class="relative grid size-8 shrink-0 place-items-center rounded-full text-slate-400 transition group-hover:bg-violet-50 group-hover:text-violet-700"><x-ui-icon name="chevron-right" class="size-5 stroke-2" /></span>
            </a>
        @endforeach
    </section>

    {{-- Automations --}}
    <section aria-labelledby="automations-heading" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="automations-heading" class="text-base font-bold text-slate-900">Your automations</h2>
            @if ($this->archivedCount > 0)
                <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model.live="showArchived" class="rounded border-slate-300"> Show archived ({{ $this->archivedCount }})</label>
            @endif
        </div>

        @if ($this->automations->isEmpty())
            <div class="rounded-2xl border-2 border-dashed border-violet-200 bg-white/70 px-6 py-12 text-center">
                <span class="mx-auto grid size-[72px] place-items-center rounded-full bg-linear-to-b from-violet-100 to-violet-200/60 text-violet-600 shadow-[inset_0_1px_0_rgba(255,255,255,0.9)]"><x-automation-icon name="bolt" class="size-9" /></span>
                <p class="mt-5 text-xl font-bold text-slate-900">Automate repetitive follow-ups and customer tasks.</p>
                @can('create', \App\Models\Automation::class)
                    <p class="mt-1.5 text-[17px] text-slate-500">Start from a template below, or build your own.</p>
                @else
                    <p class="mt-1.5 text-[17px] text-slate-500">The owner or a manager can set them up.</p>
                @endcan
                @can('create', \App\Models\Automation::class)
                <a href="#templates-heading" class="mt-6 inline-flex items-center gap-2 rounded-xl bg-linear-to-r from-violet-600 to-violet-500 px-6 py-3 text-[15px] font-semibold text-white shadow-[0_10px_24px_-8px_rgba(109,40,217,0.65)] transition hover:from-violet-700 hover:to-violet-600">Browse Templates <x-ui-icon name="arrow-right" class="size-5 stroke-2" /></a>
                @endcan
            </div>
        @else
            <ul role="list" class="grid gap-4 sm:grid-cols-2" data-section="automations">
                @foreach ($this->automations as $automation)
                    <li wire:key="automation-{{ $automation->id }}" class="flex flex-col justify-between gap-4 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_8px_24px_-12px_rgba(76,29,149,0.12)] transition hover:border-violet-200">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex min-w-0 items-start gap-3">
                                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-600"><x-automation-icon name="bolt" class="size-6" /></span>
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

    {{-- Email safety settings (the owner decides; everyone else sees them) --}}
    <section aria-labelledby="settings-heading" class="rounded-2xl bg-white p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70 sm:p-6">
        @php($columns = [
            [['automations_enabled', 'bolt', 'Turn on automations', 'Let QuoteFlow run the automations you have turned on.'],
             ['require_approval_for_email', 'envelope', 'Ask me before sending any email', 'Recommended. You see every email before it goes to a customer.']],
            [['automatic_email_enabled', 'envelope', 'Send emails automatically', 'Automations can email customers from your business email address.']],
        ])
        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.9fr)_minmax(0,1.3fr)] lg:items-center lg:divide-x lg:divide-slate-100">
            <div class="flex items-center gap-4">
                <span class="grid size-16 shrink-0 place-items-center rounded-2xl bg-linear-to-b from-emerald-100 to-emerald-200/70 text-emerald-600 shadow-[inset_0_1px_0_rgba(255,255,255,0.8)]"><x-ui-icon name="shield" class="size-8 stroke-[1.8]" /></span>
                <div>
                    <h2 id="settings-heading" class="text-lg font-bold text-slate-900">Email safety</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Control how QuoteFlow handles customer emails.</p>
                </div>
            </div>
            @foreach ($columns as $column)
                <ul role="list" class="space-y-5 lg:px-6">
                    @foreach ($column as [$setting, $settingIcon, $label, $help])
                        @php($item = ['setting' => $setting, 'settingIcon' => $settingIcon, 'label' => $label, 'help' => $help, 'on' => (bool) $organization->{$setting}])
                        <li class="flex items-start gap-3.5" wire:key="setting-{{ $item['setting'] }}">
                            <button type="button" role="switch" aria-checked="{{ $item['on'] ? 'true' : 'false' }}" aria-labelledby="setting-{{ $item['setting'] }}"
                                    @can('manage-email') wire:click="toggleSetting('{{ $item['setting'] }}')" @else disabled title="Only the owner can change this." @endcan data-setting="{{ $item['setting'] }}"
                                    @class([
                                        'relative mt-0.5 inline-flex h-8 w-14 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60',
                                        'bg-linear-to-r from-violet-600 to-violet-500 shadow-[0_6px_14px_-6px_rgba(109,40,217,0.8)]' => $item['on'],
                                        'bg-slate-200' => ! $item['on'],
                                    ])>
                                <span class="sr-only">{{ $item['on'] ? 'On' : 'Off' }}</span>
                                <span aria-hidden="true" @class(['inline-block size-7 transform rounded-full bg-white shadow transition', 'translate-x-6' => $item['on'], 'translate-x-0' => ! $item['on']])></span>
                            </button>
                            <x-ui-icon :name="$item['settingIcon']" class="mt-1 size-5 shrink-0 text-slate-500" />
                            <div class="min-w-0">
                                <p class="text-[15px] font-bold text-slate-900" id="setting-{{ $item['setting'] }}">{{ $item['label'] }}</p>
                                <p class="mt-0.5 text-sm text-slate-500">{{ $item['help'] }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endforeach
        </div>
    </section>

    {{-- Starter templates: tabs and search filter the cards in the browser; nothing is saved until "Use Template" --}}
    @can('create', \App\Models\Automation::class)
    <section aria-labelledby="templates-heading" class="space-y-4 rounded-2xl border border-slate-200/80 bg-white p-4 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_8px_24px_-12px_rgba(76,29,149,0.12)] sm:p-5" x-data="{ tab: 'All', query: '' }">
        <h2 id="templates-heading" class="sr-only">Starter templates</h2>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div role="tablist" aria-label="Template categories" class="flex flex-wrap gap-2">
                @foreach (['All' => 'All Templates', 'Follow-ups' => 'Follow-ups', 'Reminders' => 'Reminders', 'Sales' => 'Sales', 'Support' => 'Support', 'Custom' => 'Custom'] as $tabName => $tabLabel)
                    <button type="button" role="tab" @click="tab = '{{ $tabName }}'" :aria-selected="tab === '{{ $tabName }}'" :class="tab === '{{ $tabName }}' ? 'bg-violet-600 text-white shadow-[0_8px_18px_-8px_rgba(109,40,217,0.8)]' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'"
                            class="rounded-full px-4 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">{{ $tabLabel }}</button>
                @endforeach
            </div>
            <div class="relative w-full sm:w-64">
                <label for="template-search" class="sr-only">Search templates</label>
                <x-ui-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                <input id="template-search" type="search" x-model="query" placeholder="Search templates…" class="block h-10 w-full rounded-xl border-0 bg-white pr-3.5 pl-10 text-sm text-slate-900 shadow-none ring-1 ring-slate-200 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-500">
            </div>
        </div>

        <ul role="list" class="grid gap-4 lg:grid-cols-2" data-section="templates">
            @foreach (collect($this->templates)->sortBy(fn ($t, $key) => in_array($key, $starters, true) ? array_search($key, $starters, true) : 100) as $key => $template)
                @php($meta = $templateMeta[$key] ?? ['icon' => 'bolt', 'tone' => 'bg-violet-100 text-violet-600', 'tab' => 'Custom', 'tags' => []])
                <li wire:key="template-{{ $key }}" class="flex gap-4 rounded-2xl border border-slate-200/80 bg-white p-4 transition hover:border-violet-200 hover:shadow-[0_10px_28px_-14px_rgba(76,29,149,0.35)] sm:p-5"
                    data-template="{{ $key }}" data-tab="{{ $meta['tab'] }}" data-name="{{ strtolower($template['name']) }}"
                    x-show="(tab === 'All' || tab === '{{ $meta['tab'] }}') && $el.dataset.name.includes(query.trim().toLowerCase())">
                    <span class="grid size-14 shrink-0 place-items-center rounded-2xl {{ $meta['tone'] }}"><x-automation-icon :name="$meta['icon']" class="size-7" /></span>
                    <div class="flex min-w-0 flex-1 flex-col">
                        <p class="text-base font-bold text-slate-900">{{ $template['name'] }}</p>
                        <p class="mt-1 text-sm leading-relaxed text-slate-500">{{ $template['description'] }}</p>
                        <div class="mt-auto flex flex-wrap items-end justify-between gap-3 pt-3">
                            <div class="flex flex-wrap gap-2">
                                @foreach ($meta['tags'] as $tag)
                                    <span class="rounded-full bg-white px-3 py-1 text-xs font-medium text-slate-600 ring-1 ring-slate-200 ring-inset">{{ $tag }}</span>
                                @endforeach
                            </div>
                            <button type="button" wire:click="installTemplate('{{ $key }}')" title="When: {{ \App\Services\Automation\Registry\TriggerRegistry::get($template['trigger_type'])->label }}" class="inline-flex shrink-0 items-center gap-1.5 rounded-xl bg-white px-3.5 py-2 text-sm font-semibold whitespace-nowrap text-violet-700 ring-1 ring-violet-300 ring-inset transition hover:bg-violet-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">Use template <x-ui-icon name="arrow-right" class="size-4 stroke-2" /></button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        <p x-show="tab === 'Custom'" class="text-sm text-slate-500" x-cloak>Build your own automation from scratch with Create Automation.</p>
        <p class="text-xs text-slate-500">Each template is added as a draft: nothing runs until you review and activate it.</p>
    </section>
    @endcan

</div>
