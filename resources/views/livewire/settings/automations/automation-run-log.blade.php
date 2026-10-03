<div class="space-y-6">
    <div>
        <a href="{{ route('settings.automations.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">&larr; Automations</a>
        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $this->automation->name }}</h1>
            <x-automation-status-badge :status="$this->automation->status" />
        </div>
        <p class="text-sm text-gray-600">Run history · {{ $this->automation->trigger_type->label() }}</p>
    </div>

    @if ($this->selectedRun)
        @php($run = $this->selectedRun)
        <section aria-labelledby="run-heading" class="space-y-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="run-heading" class="text-base font-semibold text-gray-900">Run #{{ $run->id }}</h2>
                <a href="{{ route('settings.automations.runs', $this->automation->id) }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">Close</a>
            </div>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <dt class="text-gray-500">Status</dt>
                <dd><x-automation-status-badge :status="$run->status" /></dd>
                <dt class="text-gray-500">Event</dt>
                <dd class="text-gray-900">{{ $run->event_type->label() }} <span class="text-gray-500">({{ $run->event_id }})</span></dd>
                @if (! empty($run->context['intent']))
                    <dt class="text-gray-500">Intent</dt>
                    <dd><x-intent-badge :intent="\App\Enums\CustomerReplyIntent::from($run->context['intent'])" />
                        @isset($run->context['confidence']) <span class="text-gray-600">{{ (int) round($run->context['confidence'] * 100) }}%</span> @endisset</dd>
                @endif
                @if ($run->conversation_id)
                    <dt class="text-gray-500">Conversation</dt>
                    <dd><a href="{{ route('inbox.show', $run->conversation_id) }}" wire:navigate class="text-indigo-700 hover:underline">Open conversation</a></dd>
                @endif
                <dt class="text-gray-500">Started</dt>
                <dd class="text-gray-900">{{ $run->started_at?->format('M j, Y g:i:s A') }}</dd>
                @if ($run->failure_reason)
                    <dt class="text-gray-500">Reason</dt>
                    <dd class="text-gray-900">{{ $run->failure_reason }}</dd>
                @endif
            </dl>

            <h3 class="text-sm font-semibold text-gray-700">Actions</h3>
            @if ($run->actionRuns->isEmpty())
                <p class="text-sm text-gray-600">No actions ran.</p>
            @else
                <ol role="list" class="divide-y divide-gray-100 rounded-md border border-gray-200">
                    @foreach ($run->actionRuns as $actionRun)
                        <li wire:key="action-run-{{ $actionRun->id }}" class="flex flex-wrap items-start justify-between gap-2 p-3 text-sm">
                            <div>
                                <p class="font-medium text-gray-900">{{ $actionRun->action_type->label() }}</p>
                                <p class="text-gray-700">{{ $actionRun->result['message'] ?? ($actionRun->status->value === 'pending' ? 'Waiting to run.' : '') }}</p>
                                @if ($actionRun->executed_at)
                                    <p class="text-xs text-gray-500">{{ $actionRun->executed_at->format('M j, Y g:i:s A') }}</p>
                                @endif
                            </div>
                            <x-automation-status-badge :status="$actionRun->status" />
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    @endif

    <section aria-labelledby="runs-heading" class="space-y-3">
        <h2 id="runs-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Runs</h2>
        @if ($this->runs->isEmpty())
            <p class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-10 text-center text-sm text-gray-600">This automation hasn’t run yet.</p>
        @else
            <div class="relative overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50 text-left text-xs font-semibold tracking-wide text-gray-500 uppercase">
                        <tr>
                            <th scope="col" class="px-4 py-3">Run</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                            <th scope="col" class="px-4 py-3">Event</th>
                            <th scope="col" class="px-4 py-3">Actions</th>
                            <th scope="col" class="px-4 py-3">When</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($this->runs as $listed)
                            <tr wire:key="run-{{ $listed->id }}" @class(['bg-indigo-50' => $listed->id === $runId])>
                                <td class="px-4 py-3"><a href="{{ route('settings.automations.runs.show', [$this->automation->id, $listed->id]) }}" wire:navigate class="font-medium text-indigo-700 hover:underline">#{{ $listed->id }}</a></td>
                                <td class="px-4 py-3"><x-automation-status-badge :status="$listed->status" />
                                    @if ($listed->failure_reason)<p class="mt-1 text-xs text-gray-600">{{ $listed->failure_reason }}</p>@endif</td>
                                <td class="px-4 py-3 text-gray-700">{{ $listed->event_id }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $listed->action_runs_count }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $listed->created_at->format('M j, Y g:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $this->runs->links() }}
        @endif
    </section>
</div>
