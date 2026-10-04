<div class="space-y-6">
    @php($card = 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5')
    @php($automation = $this->automation)
    <div>
        <a href="{{ route('automations.show', $automation->id) }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">&larr; {{ $automation->name }}</a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Execution logs</h1>
            <x-automation-status-badge :status="$automation->status" />
        </div>
        <p class="text-sm text-gray-600">{{ $automation->name }} · When: {{ $automation->trigger_type->label() }}</p>
    </div>

    @if ($this->selectedRun)
        @php($run = $this->selectedRun)
        <section aria-labelledby="run-heading" class="{{ $card }} space-y-4" data-section="execution">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="run-heading" class="text-base font-semibold text-gray-900">Execution #{{ $run->id }}</h2>
                <a href="{{ route('automations.logs', $automation->id) }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">Close</a>
            </div>

            <div>
                <h3 class="text-xs font-semibold tracking-wide text-gray-500 uppercase">Trigger</h3>
                <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    <dt class="text-gray-500">Event</dt><dd class="text-gray-900">{{ $run->event_type->label() }}</dd>
                    <dt class="text-gray-500">Status</dt><dd><x-automation-status-badge :status="$run->status" /></dd>
                    @if ($run->customer)
                        <dt class="text-gray-500">Customer</dt><dd><a href="{{ route('customers.show', $run->customer_id) }}" wire:navigate class="text-indigo-700 hover:underline">{{ $run->customer->name }}</a></dd>
                    @endif
                    @if ($this->selectedEstimate)
                        <dt class="text-gray-500">Estimate</dt><dd><a href="{{ route('estimates.show', $this->selectedEstimate->id) }}" wire:navigate class="text-indigo-700 hover:underline">{{ $this->selectedEstimate->displayNumber() }}</a> · {{ $this->selectedEstimate->money('total') }}</dd>
                    @endif
                    @if (! empty($run->context['intent']))
                        <dt class="text-gray-500">AI intent</dt>
                        <dd><x-intent-badge :intent="\App\Enums\CustomerReplyIntent::from($run->context['intent'])" />@isset($run->context['confidence']) <span class="text-gray-600">{{ (int) round($run->context['confidence'] * 100) }}%</span>@endisset</dd>
                    @endif
                    @if ($run->conversation_id)
                        <dt class="text-gray-500">Conversation</dt><dd><a href="{{ route('inbox.show', $run->conversation_id) }}" wire:navigate class="text-indigo-700 hover:underline">Open conversation</a></dd>
                    @endif
                    <dt class="text-gray-500">Started</dt><dd class="text-gray-900">{{ $run->started_at ? $organization->localTime($run->started_at)->format('M j, Y g:i:s A') : '—' }}</dd>
                    @if ($run->status === \App\Enums\Automation\AutomationRunStatus::Waiting && $run->resume_at)
                        <dt class="text-gray-500">Continues</dt><dd class="text-gray-900">{{ $organization->localTime($run->resume_at)->format('M j, Y g:i A') }} (after the wait)</dd>
                    @endif
                    @if ($run->completed_at || $run->failed_at)
                        <dt class="text-gray-500">Finished</dt><dd class="text-gray-900">{{ $organization->localTime($run->completed_at ?? $run->failed_at)->format('M j, Y g:i:s A') }}@if ($run->durationSeconds() !== null) ({{ $run->durationSeconds() }}s)@endif</dd>
                    @endif
                    @if ($run->failure_reason)
                        <dt class="text-gray-500">Reason</dt><dd class="text-gray-900" data-reason>{{ $run->failure_reason }}</dd>
                    @endif
                </dl>
            </div>

            <div>
                <h3 class="text-xs font-semibold tracking-wide text-gray-500 uppercase">Conditions{{ ! empty($run->condition_results['results']) ? ' ('.($run->condition_results['match'] ?? 'all').')' : '' }}</h3>
                @if (empty($run->condition_results['results']))
                    <p class="mt-1 text-sm text-gray-600">{{ $run->status === \App\Enums\Automation\AutomationRunStatus::Waiting ? 'Checked after the wait.' : 'No conditions.' }}</p>
                @else
                    <ul role="list" class="mt-1 space-y-1 text-sm" data-section="conditions">
                        @foreach ($run->condition_results['results'] as $result)
                            <li><span aria-hidden="true" @class(['text-green-700' => $result['passed'], 'text-red-700' => ! $result['passed']])>{{ $result['passed'] ? '✓' : '✗' }}</span> <span class="sr-only">{{ $result['passed'] ? 'Passed' : 'Not met' }}:</span> {{ $result['label'] }} <span class="text-gray-500">— actual: {{ $result['actual'] }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div>
                <h3 class="text-xs font-semibold tracking-wide text-gray-500 uppercase">Actions</h3>
                @if ($run->actionRuns->isEmpty())
                    <p class="mt-1 text-sm text-gray-600">No actions ran.</p>
                @else
                    <ol role="list" class="mt-1 divide-y divide-gray-100 rounded-md border border-gray-200" data-section="actions">
                        @foreach ($run->actionRuns as $actionRun)
                            <li wire:key="action-run-{{ $actionRun->id }}" class="flex flex-wrap items-start justify-between gap-2 p-3 text-sm">
                                <div class="min-w-0">
                                    <p class="font-medium text-gray-900">{{ $actionRun->action_type->label() }}</p>
                                    <p class="break-words text-gray-700">{{ $actionRun->result['message'] ?? ($actionRun->status->value === 'pending' ? 'Waiting to run.' : '') }}</p>
                                    <p class="text-xs text-gray-500">
                                        @if ($actionRun->executed_at){{ $organization->localTime($actionRun->executed_at)->format('M j, Y g:i:s A') }}@endif
                                        @if ($actionRun->attempts > 1) · {{ $actionRun->attempts }} attempts @endif
                                        @if (! empty($actionRun->result['data']['reason'])) · {{ str_replace('_', ' ', $actionRun->result['data']['reason']) }} @endif
                                    </p>
                                </div>
                                <x-automation-status-badge :status="$actionRun->status" />
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
        </section>
    @endif

    <section aria-labelledby="runs-heading" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 id="runs-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Executions</h2>
            <label class="flex items-center gap-2 text-sm text-gray-700">Status
                <select wire:model.live="status" class="rounded-md border-0 py-1 pr-8 pl-2 text-sm ring-1 ring-gray-300 ring-inset">
                    <option value="">All</option>
                    @foreach ($filters as $filter)<option value="{{ $filter }}">{{ ucfirst($filter) }}</option>@endforeach
                </select>
            </label>
        </div>
        @if ($this->runs->isEmpty())
            <p class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-10 text-center text-sm text-gray-600">{{ $status === '' ? "This automation hasn't run yet." : 'No executions with this status.' }}</p>
        @else
            <ul role="list" class="divide-y divide-gray-100 overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm" data-section="runs">
                @foreach ($this->runs as $listed)
                    <li wire:key="run-{{ $listed->id }}" @class(['bg-indigo-50' => $listed->id === $runId])>
                        <a href="{{ route('automations.logs.show', [$automation->id, $listed->id]) }}" wire:navigate class="grid gap-1 px-4 py-3 hover:bg-gray-50 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto] sm:items-center sm:gap-4">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2"><span class="font-medium text-gray-900">{{ $listed->customer?->name ?? 'No customer' }}</span><span class="text-xs text-gray-500">#{{ $listed->id }} · {{ $listed->event_type->label() }}</span></p>
                                @if ($listed->failure_reason)<p class="truncate text-sm text-gray-600">{{ $listed->failure_reason }}</p>@endif
                            </div>
                            <p class="text-xs text-gray-600">
                                {{ $listed->started_at ? $organization->localTime($listed->started_at)->format('M j, g:i A') : '' }}
                                @if ($listed->durationSeconds() !== null) · {{ $listed->durationSeconds() }}s @endif
                                @if ($listed->status === \App\Enums\Automation\AutomationRunStatus::Waiting && $listed->resume_at) · continues {{ $organization->localTime($listed->resume_at)->format('M j') }} @endif
                            </p>
                            <x-automation-status-badge :status="$listed->status" />
                        </a>
                    </li>
                @endforeach
            </ul>
            {{ $this->runs->links() }}
        @endif
    </section>
</div>
