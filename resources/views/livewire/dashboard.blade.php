<div class="space-y-6" wire:poll.{{ (int) config('dashboard.refresh_seconds') }}s.visible>
    @php($card = 'rounded-2xl border border-slate-200 bg-white p-5 shadow-xs sm:p-6')
    @php($heading = 'text-base font-semibold text-slate-900')
    @php($button = 'inline-flex items-center gap-1.5 rounded-xl bg-white px-4 py-2.5 text-sm font-medium text-slate-700 shadow-xs ring-1 ring-slate-300 ring-inset transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500')

    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">{{ $greeting }}, {{ $firstName }}! <span aria-hidden="true">👋</span></h1>
            <p class="mt-1 text-sm text-slate-500">{{ "Here's what's happening with your business today." }}</p>
        </div>
        <p class="rounded-full bg-white px-3.5 py-1.5 text-sm text-slate-600 shadow-xs ring-1 ring-slate-200">
            <time datetime="{{ $d->localNow->toDateString() }}">{{ $d->localNow->format('l, F j, Y') }}</time>
            <span class="sr-only">({{ $organization->timezone() }})</span>
            <span wire:loading.delay class="ml-2 text-xs text-slate-500" role="status">Updating…</span>
        </p>
    </div>

    {{-- What do you want to do? Four big, plain-language buttons. --}}
    <section aria-labelledby="quick-heading">
        <h2 id="quick-heading" class="sr-only">Quick actions</h2>
        <ul role="list" class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            @php($tile = 'group flex h-full w-full flex-col rounded-2xl border border-slate-200 bg-white p-4 text-left shadow-xs transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 sm:p-5')
            <li>
                <button type="button" wire:click="$toggle('showAddCustomer')" aria-expanded="{{ $showAddCustomer ? 'true' : 'false' }}" aria-controls="add-customer-panel" class="{{ $tile }}">
                    <span class="grid size-11 place-items-center rounded-xl bg-violet-100 text-violet-600"><x-ui-icon name="user-plus" class="size-6" /></span>
                    <span class="mt-4 text-base font-semibold text-slate-900">Add a customer</span>
                    <span class="mt-0.5 text-sm text-slate-500">Save someone's contact details.</span>
                    <x-ui-icon name="arrow-right" class="mt-3 size-4 self-end text-slate-300 transition group-hover:text-violet-500" />
                </button>
            </li>
            <li>
                <a href="{{ route('estimates.create') }}" wire:navigate class="{{ $tile }}">
                    <span class="grid size-11 place-items-center rounded-xl bg-sky-100 text-sky-600"><x-ui-icon name="document" class="size-6" /></span>
                    <span class="mt-4 text-base font-semibold text-slate-900">Create a quote</span>
                    <span class="mt-0.5 text-sm text-slate-500">Tell a customer what a job will cost.</span>
                    <x-ui-icon name="arrow-right" class="mt-3 size-4 self-end text-slate-300 transition group-hover:text-sky-500" />
                </a>
            </li>
            <li>
                <a href="{{ route('inbox.index') }}" wire:navigate class="{{ $tile }}">
                    <span class="grid size-11 place-items-center rounded-xl bg-emerald-100 text-emerald-600"><x-ui-icon name="chat" class="size-6" /></span>
                    <span class="mt-4 text-base font-semibold text-slate-900">Reply to a message</span>
                    <span class="mt-0.5 text-sm text-slate-500">Keep the conversation going.</span>
                    <x-ui-icon name="arrow-right" class="mt-3 size-4 self-end text-slate-300 transition group-hover:text-emerald-500" />
                </a>
            </li>
            <li>
                <a href="{{ route('follow-ups.index', ['schedule' => 1]) }}" wire:navigate class="{{ $tile }}">
                    <span class="grid size-11 place-items-center rounded-xl bg-amber-100 text-amber-600"><x-ui-icon name="bell" class="size-6" /></span>
                    <span class="mt-4 text-base font-semibold text-slate-900">Set a reminder</span>
                    <span class="mt-0.5 text-sm text-slate-500">Remember to contact someone later.</span>
                    <x-ui-icon name="arrow-right" class="mt-3 size-4 self-end text-slate-300 transition group-hover:text-amber-500" />
                </a>
            </li>
        </ul>

        @if ($showAddCustomer)
            <div id="add-customer-panel" class="mt-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs sm:p-6">
                <livewire:customers.create-customer-form />
            </div>
        @endif
    </section>

    @if ($checklist)
        {{-- Getting started: disappears by itself once everything is done. --}}
        <section aria-labelledby="checklist-heading" class="{{ $card }}" data-section="getting-started">
            <h2 id="checklist-heading" class="{{ $heading }}">Getting started</h2>
            <ul role="list" class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                @foreach ($checklist as $item)
                    <li class="flex items-center gap-2">
                        <span aria-hidden="true" @class(['inline-flex h-5 w-5 items-center justify-center rounded-full text-xs', 'bg-green-100 text-green-800' => $item['done'], 'ring-1 ring-slate-300 text-slate-400' => ! $item['done']])>{{ $item['done'] ? '✓' : '○' }}</span>
                        @if ($item['done'])
                            <span class="text-slate-500"><span class="sr-only">Done: </span>{{ $item['label'] }}</span>
                        @else
                            <a href="{{ $item['url'] }}" wire:navigate class="font-medium text-violet-700 hover:underline">{{ $item['label'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if (! $d->hasCustomers)
        {{-- New business: one clear next step instead of a wall of zeros. --}}
        <section aria-labelledby="welcome-heading" class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs">
            <div class="flex flex-col gap-5 bg-linear-to-br from-violet-50 via-white to-emerald-50 p-6 sm:p-8">
                <div class="flex items-start gap-4">
                    <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-white text-violet-600 shadow-xs ring-1 ring-slate-200"><x-ui-icon name="users" class="size-6" /></span>
                    <div>
                        <h2 id="welcome-heading" class="text-xl font-semibold text-slate-900">No customers yet.</h2>
                        <p class="mt-1 max-w-xl text-[15px] text-slate-600">Add your first customer to get started. Let's get your first customer workflow running.</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2" data-empty-actions>
                    <a href="{{ route('customers.create') }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-emerald-700"><x-ui-icon name="plus" class="size-4" />Add Customer</a>
                    <a href="{{ route('estimates.create') }}" wire:navigate class="{{ $button }}">Create Estimate</a>
                    <a href="{{ route('automations.index') }}" wire:navigate class="{{ $button }}">Create Automation</a>
                </div>
            </div>
            <div class="border-t border-slate-100 p-5 sm:p-8">
                <h3 class="text-sm font-semibold text-slate-900">Or add one right here</h3>
                <p class="mb-4 text-sm text-slate-500">Just a name and an email is enough.</p>
                <livewire:customers.create-customer-form />
            </div>
        </section>
    @else
        {{-- Today at a glance --}}
        <section aria-labelledby="glance-heading">
            <h2 id="glance-heading" class="text-lg font-semibold text-slate-900">Today</h2>
            <ul role="list" class="mt-3 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                @foreach ([
                    ['waiting', 'Messages to reply', route('inbox.index', ['filter' => 'waiting']), 'bg-violet-100 text-violet-600', 'chat'],
                    ['due_today', 'Reminders due today', route('follow-ups.index', ['filter' => 'today']), 'bg-emerald-100 text-emerald-600', 'clock'],
                    ['new_replies', 'New customer replies', route('inbox.index'), 'bg-sky-100 text-sky-600', 'envelope'],
                    ['overdue', 'Overdue reminders', route('follow-ups.index', ['filter' => 'overdue']), $d->summary['overdue'] > 0 ? 'bg-red-100 text-red-600' : 'bg-slate-100 text-slate-500', 'exclamation'],
                ] as [$key, $label, $url, $tone, $icon])
                    <li>
                        <a href="{{ $url }}" wire:navigate data-card="{{ $key }}"
                           class="flex h-full items-center gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-xs transition hover:border-slate-300 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
                            <span class="grid size-11 shrink-0 place-items-center rounded-xl {{ $tone }}"><x-ui-icon :name="$icon" class="size-6" /></span>
                            <span class="min-w-0">
                                <span class="block text-2xl leading-none font-semibold tracking-tight text-slate-900" data-count="{{ $key }}">{{ $d->summary[$key] }}</span>
                                <span class="mt-1 block text-sm text-slate-500">{{ $label }}@if ($key === 'new_replies') <span class="text-xs text-slate-400">(last {{ (int) config('dashboard.new_replies_hours') }}h)</span>@endif</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="min-w-0 space-y-6 lg:col-span-2">
                {{-- Needs your attention --}}
                <section aria-labelledby="attention-heading" class="{{ $card }}">
                    <h2 id="attention-heading" class="{{ $heading }}">Needs your attention</h2>
                    @if ($d->attention->isEmpty())
                        <div class="mt-3 rounded-md bg-green-50 p-4 text-sm text-green-800" role="status">
                            <p class="font-medium"><span aria-hidden="true">✓</span> {{ "You're all caught up." }}</p>
                            <p>No customer action is currently required.</p>
                        </div>
                    @else
                        <ol role="list" class="mt-3 divide-y divide-slate-100" data-section="attention">
                            @foreach ($d->attention as $item)
                                <li wire:key="attention-{{ $item->kind }}-{{ $loop->index }}" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0 space-y-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ route('customers.show', $item->customerId) }}" wire:navigate class="font-semibold text-slate-900 hover:underline">{{ $item->customerName }}</a>
                                            <x-priority-badge :priority="$item->priority" />
                                        </div>
                                        @if ($item->excerpt)
                                            <p class="truncate text-sm text-slate-700">“{{ $item->excerpt }}”</p>
                                        @endif
                                        <p class="flex flex-wrap items-center gap-x-2 text-sm text-slate-500">
                                            @if ($item->intent)
                                                <x-intent-badge :intent="$item->intent" />
                                            @else
                                                <span class="font-medium {{ $item->overdue ? 'text-red-700' : 'text-slate-800' }}">{{ $item->reason }}</span>
                                            @endif
                                            @if ($item->confidence !== null)
                                                <span>{{ (int) round($item->confidence * 100) }}% confidence</span>
                                            @endif
                                            <span aria-hidden="true">·</span>
                                            <time datetime="{{ \Carbon\CarbonImmutable::instance($item->at)->toIso8601String() }}">{{ \Carbon\CarbonImmutable::instance($item->at)->diffForHumans() }}</time>
                                        </p>
                                    </div>
                                    <a href="{{ $item->url }}" wire:navigate class="{{ $button }} shrink-0 self-start">{{ $item->actionLabel }}</a>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>

                {{-- Open tasks --}}
                <section aria-labelledby="tasks-heading" class="{{ $card }}">
                    <h2 id="tasks-heading" class="{{ $heading }}">Tasks{{ $d->taskCounts['due'] ? ' ('.$d->taskCounts['due'].')' : '' }}</h2>
                    @if ($d->tasks->isEmpty())
                        <div class="mt-3 text-sm text-slate-500" role="status">
                            <p class="font-medium text-slate-800">No tasks to do today.</p>
                            @if ($d->taskCounts['later'])
                                <p>{{ trans_choice(':count task is scheduled for later.|:count tasks are scheduled for later.', $d->taskCounts['later']) }}</p>
                            @endif
                        </div>
                    @else
                        <ul role="list" class="mt-1 divide-y divide-slate-100 text-sm" data-section="tasks">
                            @foreach ($d->tasks as $task)
                                @include('livewire.partials.task-row', ['showCustomer' => true])
                            @endforeach
                        </ul>
                        @if ($d->taskCounts['due'] > $d->tasks->count() || $d->taskCounts['later'])
                            <p class="mt-2 text-xs text-slate-500">
                                {{ collect([
                                    $d->taskCounts['due'] > $d->tasks->count() ? 'Showing '.$d->tasks->count().' of '.$d->taskCounts['due'].'.' : null,
                                    $d->taskCounts['later'] ? trans_choice(':count more task is scheduled for later.|:count more tasks are scheduled for later.', $d->taskCounts['later']) : null,
                                ])->filter()->implode(' ') }}
                                All tasks are on each customer's page.
                            </p>
                        @endif
                    @endif
                </section>

                {{-- Today's follow-ups --}}
                <section aria-labelledby="today-heading" class="{{ $card }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="today-heading" class="{{ $heading }}">{{ "Today's follow-ups" }}</h2>
                        <a href="{{ route('follow-ups.index') }}" wire:navigate class="text-sm font-medium text-violet-700 hover:underline">View All Follow-Ups</a>
                    </div>

                    @if ($d->overdueFollowUps->isNotEmpty())
                        <h3 class="mt-3 text-xs font-semibold text-red-700 font-semibold">⚠ Overdue ({{ $d->summary['overdue'] }})</h3>
                        <ul role="list" class="mt-1 divide-y divide-slate-100" data-section="overdue">
                            @foreach ($d->overdueFollowUps as $followUp)
                                @include('livewire.partials.dashboard-follow-up', ['overdue' => true])
                            @endforeach
                        </ul>
                    @endif

                    @if ($d->todaysFollowUps->isEmpty() && $d->overdueFollowUps->isEmpty())
                        <div class="mt-3 text-sm text-slate-500" role="status">
                            <p class="font-medium text-slate-800">{{ "You're all caught up." }}</p>
                            <p>{{ $d->hasOpenFollowUps ? 'Nothing is due today.' : 'No follow-ups are currently scheduled.' }}</p>
                        </div>
                    @elseif ($d->todaysFollowUps->isNotEmpty())
                        <h3 class="mt-3 text-xs font-semibold text-slate-500 font-semibold">Today ({{ $d->summary['due_today'] }})</h3>
                        <ul role="list" class="mt-1 divide-y divide-slate-100" data-section="today">
                            @foreach ($d->todaysFollowUps as $followUp)
                                @include('livewire.partials.dashboard-follow-up', ['overdue' => false])
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- Recent customer replies --}}
                <section aria-labelledby="replies-heading" class="{{ $card }}">
                    <h2 id="replies-heading" class="{{ $heading }}">Recent customer replies</h2>
                    @if ($d->recentReplies->isEmpty())
                        <p class="mt-3 text-sm text-slate-500">No customer replies yet.</p>
                    @else
                        <ul role="list" class="mt-2 divide-y divide-slate-100" data-section="replies">
                            @foreach ($d->recentReplies as $reply)
                                <li wire:key="reply-{{ $reply->id }}">
                                    <a href="{{ route('inbox.show', $reply->conversation_id) }}" wire:navigate class="-mx-2 block rounded-md px-2 py-2.5 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
                                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                            <p class="font-medium text-slate-900">{{ $reply->conversation?->customer?->name }}</p>
                                            <time class="text-xs text-slate-500" datetime="{{ $reply->received_at->toIso8601String() }}">{{ $reply->received_at->diffForHumans() }}</time>
                                        </div>
                                        <p class="truncate text-sm text-slate-700">“{{ \Illuminate\Support\Str::limit(trim(strtok((string) $reply->excerpt, "\n")), 120) }}”</p>
                                        @if ($reply->latestClassification?->intent)
                                            <p class="mt-1 flex items-center gap-2 text-sm text-slate-500"><x-intent-badge :intent="$reply->latestClassification->intent" /> {{ (int) round($reply->latestClassification->confidence * 100) }}%</p>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>

            <div class="min-w-0 space-y-6">
                {{-- Notifications --}}
                <section aria-labelledby="notifications-heading" class="{{ $card }}">
                    <div class="flex items-center justify-between gap-2">
                        <h2 id="notifications-heading" class="{{ $heading }}">Notifications @if ($d->unreadNotifications)<span class="font-normal">({{ $d->unreadNotifications }} unread)</span>@endif</h2>
                        @if ($d->unreadNotifications > 1)
                            <button type="button" wire:click="markAllNotificationsRead" class="text-sm font-medium text-violet-700 hover:underline">Mark all read</button>
                        @endif
                    </div>
                    @if ($d->notifications->isEmpty())
                        <p class="mt-3 text-sm text-slate-500">No new notifications.</p>
                    @else
                        <ul role="list" class="mt-2 divide-y divide-slate-100" data-section="notifications">
                            @foreach ($d->notifications as $notification)
                                <li wire:key="notification-{{ $notification->id }}" class="flex items-start justify-between gap-2 py-2 text-sm">
                                    <div>
                                        @if (! empty($notification->data['url']))
                                            <a href="{{ $notification->data['url'] }}" wire:navigate class="text-slate-900 hover:underline">{{ $notification->data['message'] ?? 'Notification' }}</a>
                                        @else
                                            <p class="text-slate-900">{{ $notification->data['message'] ?? 'Notification' }}</p>
                                        @endif
                                        @if (! empty($notification->data['action']) && ! empty($notification->data['url']))
                                            <a href="{{ $notification->data['url'] }}" wire:navigate class="block text-xs font-semibold text-violet-700 hover:underline">{{ $notification->data['action'] }}</a>
                                        @endif
                                        <time class="text-xs text-slate-500" datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
                                    </div>
                                    <button type="button" wire:click="markNotificationRead('{{ $notification->id }}')" class="shrink-0 rounded px-1 text-xs font-medium text-slate-500 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500">
                                        Dismiss<span class="sr-only"> notification</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- Today --}}
                <section aria-labelledby="summary-heading" class="{{ $card }}">
                    <h2 id="summary-heading" class="{{ $heading }}">Today's activity</h2>
                    <dl class="mt-3 grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm" data-section="today-summary">
                        @foreach ([
                            'new_customers' => 'New customers',
                            'customer_replies' => 'Customer replies',
                            'follow_ups_completed' => 'Follow-ups completed',
                            'follow_ups_due' => 'Follow-ups due',
                            'emails_sent' => 'Emails sent',
                        ] as $key => $label)
                            <dt class="text-slate-500">{{ $label }}</dt>
                            <dd class="text-right font-semibold text-slate-900" data-stat="{{ $key }}">{{ $d->today[$key] }}</dd>
                        @endforeach
                    </dl>
                </section>

                {{-- Estimates --}}
                <section aria-labelledby="estimates-heading" class="{{ $card }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="estimates-heading" class="{{ $heading }}">Estimates</h2>
                        <a href="{{ route('estimates.index') }}" wire:navigate class="text-sm font-medium text-violet-700 hover:underline">View Estimates</a>
                    </div>
                    <dl class="mt-3 grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm" data-section="estimates">
                        <dt class="text-slate-500">Sent today</dt>
                        <dd class="text-right font-semibold text-slate-900" data-estimates="sent_today">{{ $d->estimates['sent_today'] }}</dd>
                        <dt class="text-slate-500"><a href="{{ route('estimates.index', ['status' => 'awaiting']) }}" wire:navigate class="hover:underline">Awaiting customer</a></dt>
                        <dd class="text-right font-semibold text-slate-900" data-estimates="awaiting">{{ $d->estimates['awaiting'] }}</dd>
                        <dt class="text-slate-500">Accepted today</dt>
                        <dd class="text-right font-semibold text-slate-900" data-estimates="accepted_today">{{ $d->estimates['accepted_today'] }}</dd>
                    </dl>
                </section>

                {{-- Conversations --}}
                <section aria-labelledby="conversations-heading" class="{{ $card }}">
                    <h2 id="conversations-heading" class="{{ $heading }}">Conversations</h2>
                    <dl class="mt-3 grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm" data-section="conversation-statuses">
                        @foreach (\App\Enums\ConversationStatus::cases() as $status)
                            <dt class="text-slate-500">{{ $status === \App\Enums\ConversationStatus::Open ? 'Open' : $status->label() }}</dt>
                            <dd class="text-right font-semibold text-slate-900" data-status="{{ $status->value }}">{{ $d->conversationStatuses[$status->value] ?? 0 }}</dd>
                        @endforeach
                    </dl>
                </section>

                {{-- Automation activity --}}
                <section aria-labelledby="automation-heading" class="{{ $card }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="automation-heading" class="{{ $heading }}">Automation activity</h2>
                        @can('viewAny', \App\Models\Automation::class)
                            <a href="{{ route('automations.index') }}" wire:navigate class="text-sm font-medium text-violet-700 hover:underline">View Automation Activity</a>
                        @endcan
                    </div>
                    @if ($d->automationFailures > 0)
                        <p class="mt-3 rounded-md bg-red-50 p-2 text-sm text-red-800" role="alert" data-automation-failures>
                            <span class="font-semibold">Automation issue:</span> {{ trans_choice(':count execution failed|:count executions failed', $d->automationFailures) }} in the last 7 days.
                            @can('viewAny', \App\Models\Automation::class)<a href="{{ route('automations.index') }}" wire:navigate class="font-medium underline">View</a>@endcan
                        </p>
                    @endif
                    @if ($d->automationRuns->isEmpty())
                        <p class="mt-3 text-sm text-slate-500">No automations have run yet.</p>
                    @else
                        <ol role="list" class="mt-2 space-y-3" data-section="automation">
                            @foreach ($d->automationRuns as $run)
                                <li wire:key="run-{{ $run->id }}" class="text-sm">
                                    <p class="flex flex-wrap items-center gap-2">
                                        <time class="text-xs text-slate-500" datetime="{{ $run->created_at->toIso8601String() }}">{{ $organization->localTime($run->created_at)->format('g:i A') }}</time>
                                        <span class="font-medium text-slate-900">{{ $run->automation?->name ?? 'Deleted automation' }}</span>
                                    </p>
                                    @if ($run->customer)
                                        <p class="text-slate-700">{{ $run->customer->name }}</p>
                                    @endif
                                    <ul role="list" class="mt-0.5 text-slate-500">
                                        @forelse ($run->actionRuns as $actionRun)
                                            <li>
                                                <span aria-hidden="true">{{ match ($actionRun->status->value) { 'completed' => '✓', 'failed' => '✗', 'skipped' => '–', default => '…' } }}</span>
                                                <span class="sr-only">{{ $actionRun->status->label() }}:</span>
                                                {{ $actionRun->result['message'] ?? $actionRun->action_type->label() }}
                                            </li>
                                        @empty
                                            <li>{{ $run->failure_reason ?? ($run->status === \App\Enums\Automation\AutomationRunStatus::Waiting && $run->resume_at ? 'Waiting until '.$organization->localTime($run->resume_at)->format('M j, g:i A') : $run->status->label()) }}</li>
                                        @endforelse
                                    </ul>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </section>
            </div>
        </div>
    @endif
</div>
