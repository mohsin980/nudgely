<div class="space-y-6" wire:poll.{{ (int) config('dashboard.refresh_seconds') }}s.visible>
    @php($card = 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5')
    @php($heading = 'text-sm font-semibold tracking-wide text-gray-500 uppercase')
    @php($button = 'inline-flex items-center rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500')

    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $greeting }}, {{ $firstName }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ "Here's what needs your attention today." }}</p>
        </div>
        <p class="text-sm text-gray-600">
            <time datetime="{{ $d->localNow->toDateString() }}">{{ $d->localNow->format('l, F j, Y') }}</time>
            <span class="sr-only">({{ $organization->timezone() }})</span>
            <span wire:loading.delay class="ml-2 text-xs text-gray-500" role="status">Updating…</span>
        </p>
    </div>

    @if ($checklist)
        {{-- Getting started: disappears by itself once everything is done. --}}
        <section aria-labelledby="checklist-heading" class="{{ $card }}" data-section="getting-started">
            <h2 id="checklist-heading" class="{{ $heading }}">Getting started</h2>
            <ul role="list" class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                @foreach ($checklist as $item)
                    <li class="flex items-center gap-2">
                        <span aria-hidden="true" @class(['inline-flex h-5 w-5 items-center justify-center rounded-full text-xs', 'bg-green-100 text-green-800' => $item['done'], 'ring-1 ring-gray-300 text-gray-400' => ! $item['done']])>{{ $item['done'] ? '✓' : '○' }}</span>
                        @if ($item['done'])
                            <span class="text-gray-600"><span class="sr-only">Done: </span>{{ $item['label'] }}</span>
                        @else
                            <a href="{{ $item['url'] }}" wire:navigate class="font-medium text-indigo-700 hover:underline">{{ $item['label'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if (! $d->hasCustomers)
        {{-- New business: one clear next step instead of a wall of zeros. --}}
        <section aria-labelledby="welcome-heading" class="{{ $card }} max-w-xl">
            <h2 id="welcome-heading" class="text-lg font-semibold text-gray-900">No customers yet.</h2>
            <p class="mt-1 text-sm text-gray-600">Add your first customer to get started. Let's get your first customer workflow running.</p>
            <div class="mt-3 flex flex-wrap gap-2" data-empty-actions>
                <a href="{{ route('customers.create') }}" wire:navigate class="{{ $button }}">Add Customer</a>
                <a href="{{ route('estimates.create') }}" wire:navigate class="{{ $button }}">Create Estimate</a>
                <a href="{{ route('automations.index') }}" wire:navigate class="{{ $button }}">Create Automation</a>
            </div>
            <div class="mt-4">
                <livewire:customers.create-customer-form />
            </div>
        </section>
    @else
        {{-- Summary cards --}}
        <section aria-label="Summary">
            <ul role="list" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @foreach ([
                    ['overdue', 'Overdue Follow-Ups', route('follow-ups.index', ['filter' => 'overdue']), $d->summary['overdue'] > 0 ? 'border-red-300 bg-red-50' : '', '⚠'],
                    ['due_today', 'Due Today', route('follow-ups.index', ['filter' => 'today']), '', '◷'],
                    ['waiting', 'Waiting for You', route('inbox.index', ['filter' => 'waiting']), $d->summary['waiting'] > 0 ? 'border-amber-300 bg-amber-50' : '', '●'],
                    ['new_replies', 'New Customer Replies', route('inbox.index'), '', '✉'],
                ] as [$key, $label, $url, $tone, $icon])
                    <li>
                        <a href="{{ $url }}" wire:navigate data-card="{{ $key }}"
                           class="block h-full rounded-lg border border-gray-200 bg-white p-4 shadow-sm hover:border-indigo-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 {{ $tone }}">
                            <p class="flex items-center gap-1.5 text-sm font-medium text-gray-700"><span aria-hidden="true">{{ $icon }}</span>{{ $label }}</p>
                            <p class="mt-1 text-3xl font-semibold text-gray-900" data-count="{{ $key }}">{{ $d->summary[$key] }}</p>
                            @if ($key === 'new_replies')
                                <p class="text-xs text-gray-500">Last {{ (int) config('dashboard.new_replies_hours') }} hours</p>
                            @endif
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
                        <ol role="list" class="mt-3 divide-y divide-gray-100" data-section="attention">
                            @foreach ($d->attention as $item)
                                <li wire:key="attention-{{ $item->kind }}-{{ $loop->index }}" class="flex flex-col gap-2 py-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0 space-y-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="{{ route('customers.show', $item->customerId) }}" wire:navigate class="font-semibold text-gray-900 hover:underline">{{ $item->customerName }}</a>
                                            <x-priority-badge :priority="$item->priority" />
                                        </div>
                                        @if ($item->excerpt)
                                            <p class="truncate text-sm text-gray-700">“{{ $item->excerpt }}”</p>
                                        @endif
                                        <p class="flex flex-wrap items-center gap-x-2 text-sm text-gray-600">
                                            @if ($item->intent)
                                                <x-intent-badge :intent="$item->intent" />
                                            @else
                                                <span class="font-medium {{ $item->overdue ? 'text-red-700' : 'text-gray-800' }}">{{ $item->reason }}</span>
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
                        <div class="mt-3 text-sm text-gray-600" role="status">
                            <p class="font-medium text-gray-800">No tasks to do today.</p>
                            @if ($d->taskCounts['later'])
                                <p>{{ trans_choice(':count task is scheduled for later.|:count tasks are scheduled for later.', $d->taskCounts['later']) }}</p>
                            @endif
                        </div>
                    @else
                        <ul role="list" class="mt-1 divide-y divide-gray-100 text-sm" data-section="tasks">
                            @foreach ($d->tasks as $task)
                                @include('livewire.partials.task-row', ['showCustomer' => true])
                            @endforeach
                        </ul>
                        @if ($d->taskCounts['due'] > $d->tasks->count() || $d->taskCounts['later'])
                            <p class="mt-2 text-xs text-gray-500">
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
                        <a href="{{ route('follow-ups.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">View All Follow-Ups</a>
                    </div>

                    @if ($d->overdueFollowUps->isNotEmpty())
                        <h3 class="mt-3 text-xs font-semibold tracking-wide text-red-700 uppercase">⚠ Overdue ({{ $d->summary['overdue'] }})</h3>
                        <ul role="list" class="mt-1 divide-y divide-gray-100" data-section="overdue">
                            @foreach ($d->overdueFollowUps as $followUp)
                                @include('livewire.partials.dashboard-follow-up', ['overdue' => true])
                            @endforeach
                        </ul>
                    @endif

                    @if ($d->todaysFollowUps->isEmpty() && $d->overdueFollowUps->isEmpty())
                        <div class="mt-3 text-sm text-gray-600" role="status">
                            <p class="font-medium text-gray-800">{{ "You're all caught up." }}</p>
                            <p>{{ $d->hasOpenFollowUps ? 'Nothing is due today.' : 'No follow-ups are currently scheduled.' }}</p>
                        </div>
                    @elseif ($d->todaysFollowUps->isNotEmpty())
                        <h3 class="mt-3 text-xs font-semibold tracking-wide text-gray-500 uppercase">Today ({{ $d->summary['due_today'] }})</h3>
                        <ul role="list" class="mt-1 divide-y divide-gray-100" data-section="today">
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
                        <p class="mt-3 text-sm text-gray-600">No customer replies yet.</p>
                    @else
                        <ul role="list" class="mt-2 divide-y divide-gray-100" data-section="replies">
                            @foreach ($d->recentReplies as $reply)
                                <li wire:key="reply-{{ $reply->id }}">
                                    <a href="{{ route('inbox.show', $reply->conversation_id) }}" wire:navigate class="-mx-2 block rounded-md px-2 py-2.5 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                        <div class="flex flex-wrap items-baseline justify-between gap-x-3">
                                            <p class="font-medium text-gray-900">{{ $reply->conversation?->customer?->name }}</p>
                                            <time class="text-xs text-gray-500" datetime="{{ $reply->received_at->toIso8601String() }}">{{ $reply->received_at->diffForHumans() }}</time>
                                        </div>
                                        <p class="truncate text-sm text-gray-700">“{{ \Illuminate\Support\Str::limit(trim(strtok((string) $reply->excerpt, "\n")), 120) }}”</p>
                                        @if ($reply->latestClassification?->intent)
                                            <p class="mt-1 flex items-center gap-2 text-sm text-gray-600"><x-intent-badge :intent="$reply->latestClassification->intent" /> {{ (int) round($reply->latestClassification->confidence * 100) }}%</p>
                                        @endif
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>

            <div class="min-w-0 space-y-6">
                {{-- Quick actions --}}
                <section aria-labelledby="quick-heading" class="{{ $card }}">
                    <h2 id="quick-heading" class="{{ $heading }}">Quick actions</h2>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <button type="button" wire:click="$toggle('showAddCustomer')" aria-expanded="{{ $showAddCustomer ? 'true' : 'false' }}" aria-controls="add-customer-panel" class="{{ $button }} justify-center">Add Customer</button>
                        <a href="{{ route('follow-ups.index', ['schedule' => 1]) }}" wire:navigate class="{{ $button }} justify-center">Schedule Follow-Up</a>
                        <a href="{{ route('inbox.index') }}" wire:navigate class="{{ $button }} justify-center">View Conversations</a>
                        @can('viewAny', \App\Models\Automation::class)
                            <a href="{{ route('automations.index') }}" wire:navigate class="{{ $button }} justify-center">View Automations</a>
                        @endcan
                    </div>
                    @if ($showAddCustomer)
                        <div id="add-customer-panel" class="mt-4 border-t border-gray-100 pt-4">
                            <livewire:customers.create-customer-form />
                        </div>
                    @endif
                </section>

                {{-- Notifications --}}
                <section aria-labelledby="notifications-heading" class="{{ $card }}">
                    <div class="flex items-center justify-between gap-2">
                        <h2 id="notifications-heading" class="{{ $heading }}">Notifications @if ($d->unreadNotifications)<span class="font-normal">({{ $d->unreadNotifications }} unread)</span>@endif</h2>
                        @if ($d->unreadNotifications > 1)
                            <button type="button" wire:click="markAllNotificationsRead" class="text-sm font-medium text-indigo-700 hover:underline">Mark all read</button>
                        @endif
                    </div>
                    @if ($d->notifications->isEmpty())
                        <p class="mt-3 text-sm text-gray-600">No new notifications.</p>
                    @else
                        <ul role="list" class="mt-2 divide-y divide-gray-100" data-section="notifications">
                            @foreach ($d->notifications as $notification)
                                <li wire:key="notification-{{ $notification->id }}" class="flex items-start justify-between gap-2 py-2 text-sm">
                                    <div>
                                        @if (! empty($notification->data['url']))
                                            <a href="{{ $notification->data['url'] }}" wire:navigate class="text-gray-900 hover:underline">{{ $notification->data['message'] ?? 'Notification' }}</a>
                                        @else
                                            <p class="text-gray-900">{{ $notification->data['message'] ?? 'Notification' }}</p>
                                        @endif
                                        @if (! empty($notification->data['action']) && ! empty($notification->data['url']))
                                            <a href="{{ $notification->data['url'] }}" wire:navigate class="block text-xs font-semibold text-indigo-700 hover:underline">{{ $notification->data['action'] }}</a>
                                        @endif
                                        <time class="text-xs text-gray-500" datetime="{{ $notification->created_at->toIso8601String() }}">{{ $notification->created_at->diffForHumans() }}</time>
                                    </div>
                                    <button type="button" wire:click="markNotificationRead('{{ $notification->id }}')" class="shrink-0 rounded px-1 text-xs font-medium text-gray-600 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                        Dismiss<span class="sr-only"> notification</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- Today --}}
                <section aria-labelledby="summary-heading" class="{{ $card }}">
                    <h2 id="summary-heading" class="{{ $heading }}">Today</h2>
                    <dl class="mt-3 grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm" data-section="today-summary">
                        @foreach ([
                            'new_customers' => 'New customers',
                            'customer_replies' => 'Customer replies',
                            'follow_ups_completed' => 'Follow-ups completed',
                            'follow_ups_due' => 'Follow-ups due',
                            'emails_sent' => 'Emails sent',
                        ] as $key => $label)
                            <dt class="text-gray-600">{{ $label }}</dt>
                            <dd class="text-right font-semibold text-gray-900" data-stat="{{ $key }}">{{ $d->today[$key] }}</dd>
                        @endforeach
                    </dl>
                </section>

                {{-- Estimates --}}
                <section aria-labelledby="estimates-heading" class="{{ $card }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="estimates-heading" class="{{ $heading }}">Estimates</h2>
                        <a href="{{ route('estimates.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">View Estimates</a>
                    </div>
                    <dl class="mt-3 grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm" data-section="estimates">
                        <dt class="text-gray-600">Sent today</dt>
                        <dd class="text-right font-semibold text-gray-900" data-estimates="sent_today">{{ $d->estimates['sent_today'] }}</dd>
                        <dt class="text-gray-600"><a href="{{ route('estimates.index', ['status' => 'awaiting']) }}" wire:navigate class="hover:underline">Awaiting customer</a></dt>
                        <dd class="text-right font-semibold text-gray-900" data-estimates="awaiting">{{ $d->estimates['awaiting'] }}</dd>
                        <dt class="text-gray-600">Accepted today</dt>
                        <dd class="text-right font-semibold text-gray-900" data-estimates="accepted_today">{{ $d->estimates['accepted_today'] }}</dd>
                    </dl>
                </section>

                {{-- Conversations --}}
                <section aria-labelledby="conversations-heading" class="{{ $card }}">
                    <h2 id="conversations-heading" class="{{ $heading }}">Conversations</h2>
                    <dl class="mt-3 grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm" data-section="conversation-statuses">
                        @foreach (\App\Enums\ConversationStatus::cases() as $status)
                            <dt class="text-gray-600">{{ $status === \App\Enums\ConversationStatus::Open ? 'Open' : $status->label() }}</dt>
                            <dd class="text-right font-semibold text-gray-900" data-status="{{ $status->value }}">{{ $d->conversationStatuses[$status->value] ?? 0 }}</dd>
                        @endforeach
                    </dl>
                </section>

                {{-- Automation activity --}}
                <section aria-labelledby="automation-heading" class="{{ $card }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 id="automation-heading" class="{{ $heading }}">Automation activity</h2>
                        @can('viewAny', \App\Models\Automation::class)
                            <a href="{{ route('automations.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">View Automation Activity</a>
                        @endcan
                    </div>
                    @if ($d->automationFailures > 0)
                        <p class="mt-3 rounded-md bg-red-50 p-2 text-sm text-red-800" role="alert" data-automation-failures>
                            <span class="font-semibold">Automation issue:</span> {{ trans_choice(':count execution failed|:count executions failed', $d->automationFailures) }} in the last 7 days.
                            @can('viewAny', \App\Models\Automation::class)<a href="{{ route('automations.index') }}" wire:navigate class="font-medium underline">View</a>@endcan
                        </p>
                    @endif
                    @if ($d->automationRuns->isEmpty())
                        <p class="mt-3 text-sm text-gray-600">No automations have run yet.</p>
                    @else
                        <ol role="list" class="mt-2 space-y-3" data-section="automation">
                            @foreach ($d->automationRuns as $run)
                                <li wire:key="run-{{ $run->id }}" class="text-sm">
                                    <p class="flex flex-wrap items-center gap-2">
                                        <time class="text-xs text-gray-500" datetime="{{ $run->created_at->toIso8601String() }}">{{ $organization->localTime($run->created_at)->format('g:i A') }}</time>
                                        <span class="font-medium text-gray-900">{{ $run->automation?->name ?? 'Deleted automation' }}</span>
                                    </p>
                                    @if ($run->customer)
                                        <p class="text-gray-700">{{ $run->customer->name }}</p>
                                    @endif
                                    <ul role="list" class="mt-0.5 text-gray-600">
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
