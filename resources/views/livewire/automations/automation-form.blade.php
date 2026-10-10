<div class="space-y-6 pb-24 sm:pb-0">
    @php($card = 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5')
    @php($heading = 'text-sm font-semibold tracking-wide text-gray-500 uppercase')
    @php($fieldClass = 'mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
    @php($secondary = 'inline-flex items-center justify-center rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 whitespace-nowrap')
    @php($primary = 'inline-flex items-center justify-center rounded-md bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 disabled:opacity-50 whitespace-nowrap')

    <div class="space-y-1">
        <a href="{{ $automationId ? route('automations.show', $automationId) : route('automations.index') }}" wire:navigate class="text-sm font-medium text-violet-700 hover:underline">&larr; {{ $automationId ? 'Back to automation' : 'Automations' }}</a>
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $automationId ? 'Edit automation' : 'New automation' }}</h1>
    </div>

    {{-- Steps --}}
    <nav aria-label="Steps">
        <ol role="list" class="flex flex-wrap gap-2">
            @foreach ($steps as $number => $label)
                <li>
                    <button type="button" wire:click="goToStep({{ $number }})" @disabled($number > $furthestStep) @if ($number === $step) aria-current="step" @endif
                            @class(['rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset', 'bg-violet-600 text-white ring-violet-600' => $number === $step, 'bg-white text-gray-700 ring-gray-300 hover:bg-gray-50' => $number !== $step && $number <= $furthestStep, 'bg-gray-50 text-gray-400 ring-gray-200' => $number > $furthestStep])>
                        {{ $number }}. {{ $label }}
                    </button>
                </li>
            @endforeach
        </ol>
    </nav>

    @if ($notice)
        <div role="status" class="rounded-md bg-blue-50 p-3 text-sm text-blue-800">{{ $notice }}</div>
    @endif

    {{-- 1. Name --}}
    @if ($step === 1)
        <section aria-labelledby="step-name" class="{{ $card }} space-y-3">
            <h2 id="step-name" class="{{ $heading }}">Name your automation</h2>
            <div>
                <label for="automation-name" class="block text-sm font-medium text-gray-700">Name</label>
                <input id="automation-name" type="text" wire:model="name" maxlength="100" placeholder="Follow up after estimate" class="{{ $fieldClass }}">
                @error('name') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="automation-description" class="block text-sm font-medium text-gray-700">Description <span class="font-normal text-gray-500">(optional)</span></label>
                <textarea id="automation-description" wire:model="description" rows="2" maxlength="500" class="{{ $fieldClass }}"></textarea>
                @error('description') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </section>
    @endif

    {{-- 2. When --}}
    @if ($step === 2)
        <section aria-labelledby="step-when" class="{{ $card }} space-y-3">
            <h2 id="step-when" class="{{ $heading }}">When should it run?</h2>
            @error('triggerType') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
            <fieldset>
                <legend class="sr-only">Trigger</legend>
                <div class="grid gap-2 sm:grid-cols-2" data-section="triggers">
                    @foreach ($triggers as $definition)
                        <label wire:key="trigger-{{ $definition->key() }}" @class(['flex cursor-pointer gap-3 rounded-md p-3 ring-1 ring-inset', 'bg-violet-50 ring-violet-300' => $triggerType === $definition->key(), 'ring-gray-200 hover:bg-gray-50' => $triggerType !== $definition->key()])>
                            <input type="radio" name="trigger" value="{{ $definition->key() }}" wire:model.live="triggerType" class="mt-1">
                            <span>
                                <span class="block text-sm font-medium text-gray-900">{{ $definition->label }}</span>
                                <span class="block text-xs text-gray-600">{{ $definition->description }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
        </section>
    @endif

    {{-- 3. Wait & If --}}
    @if ($step === 3)
        <section aria-labelledby="step-wait" class="{{ $card }} space-y-3">
            <h2 id="step-wait" class="{{ $heading }}">Wait</h2>
            <div class="sm:max-w-xs">
                <label for="automation-wait" class="block text-sm font-medium text-gray-700">Before checking conditions, wait</label>
                <select id="automation-wait" wire:model.live="wait" class="{{ $fieldClass }}">
                    @foreach ($waits as $minutes => $label)
                        <option value="{{ $minutes }}">{{ $label }}</option>
                    @endforeach
                    @if ($wait !== '' && ! array_key_exists($wait, $waits))
                        <option value="{{ $wait }}">{{ \App\Models\Automation::describeWait((int) $wait) }}</option>
                    @endif
                </select>
                @error('wait') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-gray-500">Conditions are checked after the wait, with the latest information (e.g. whether the customer replied meanwhile).</p>
            </div>
        </section>

        <section aria-labelledby="step-if" class="{{ $card }} space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="step-if" class="{{ $heading }}">If</h2>
                @if (count($conditions) > 1)
                    <fieldset class="flex items-center gap-3 text-sm">
                        <legend class="sr-only">How conditions combine</legend>
                        <label class="flex items-center gap-1"><input type="radio" value="all" wire:model.live="conditionMatch"> <span>ALL must match</span></label>
                        <label class="flex items-center gap-1"><input type="radio" value="any" wire:model.live="conditionMatch"> <span>ANY can match</span></label>
                    </fieldset>
                @endif
            </div>
            @error('conditions') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
            @if ($conditions === [])
                <p class="text-sm text-gray-600">No conditions: the automation runs every time “{{ $trigger?->label }}” happens.</p>
            @endif
            <ol role="list" class="space-y-3" data-section="conditions">
                @foreach ($conditions as $i => $condition)
                    @php($field = $fields[$condition['type']] ?? null)
                    <li wire:key="condition-{{ $i }}" class="rounded-md border border-gray-200 p-3">
                        @if ($i > 0)<p class="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">{{ $conditionMatch === 'any' ? 'or' : 'and' }}</p>@endif
                        <div class="grid gap-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
                            <div>
                                <label for="condition-{{ $i }}-type" class="block text-xs font-medium text-gray-600">Field</label>
                                <select id="condition-{{ $i }}-type" wire:model.live="conditions.{{ $i }}.type" class="{{ $fieldClass }}">
                                    @foreach ($conditionFields as $group => $groupFields)
                                        <optgroup label="{{ $group }}">
                                            @foreach ($groupFields as $option)
                                                <option value="{{ $option->key() }}">{{ $option->label }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label for="condition-{{ $i }}-operator" class="block text-xs font-medium text-gray-600">Is</label>
                                <select id="condition-{{ $i }}-operator" wire:model.live="conditions.{{ $i }}.operator" class="{{ $fieldClass }}">
                                    @foreach ($field?->operators() ?? [] as $operator)
                                        <option value="{{ $operator->value }}">{{ $operator->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                @if ($field && $this->needsValue($condition['operator']))
                                    <label for="condition-{{ $i }}-value" class="block text-xs font-medium text-gray-600">Value</label>
                                    @switch($field->dataType)
                                        @case('enum')
                                            <select id="condition-{{ $i }}-value" wire:model.live="conditions.{{ $i }}.value" class="{{ $fieldClass }}">
                                                <option value="">Choose…</option>
                                                @foreach ($field->options as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            @break
                                        @case('percent')
                                            <div class="flex items-center gap-1"><input id="condition-{{ $i }}-value" type="number" min="0" max="100" wire:model.blur="conditions.{{ $i }}.value" class="{{ $fieldClass }}"><span class="mt-1 text-sm text-gray-600">%</span></div>
                                            @break
                                        @case('money')
                                            <div class="flex items-center gap-1"><span class="mt-1 text-sm text-gray-600">$</span><input id="condition-{{ $i }}-value" type="text" inputmode="decimal" wire:model.blur="conditions.{{ $i }}.value" class="{{ $fieldClass }}"></div>
                                            @break
                                        @case('number')
                                            <input id="condition-{{ $i }}-value" type="number" min="0" max="9999" wire:model.blur="conditions.{{ $i }}.value" class="{{ $fieldClass }}">
                                            @break
                                        @case('date')
                                            <input id="condition-{{ $i }}-value" type="text" placeholder="2026-12-31 or today+3" wire:model.blur="conditions.{{ $i }}.value" class="{{ $fieldClass }}">
                                            @break
                                        @default
                                            <input id="condition-{{ $i }}-value" type="text" maxlength="255" wire:model.blur="conditions.{{ $i }}.value" class="{{ $fieldClass }}">
                                    @endswitch
                                @endif
                            </div>
                            <div class="text-right"><button type="button" wire:click="removeCondition({{ $i }})" class="text-sm font-medium text-red-700 hover:underline">Remove<span class="sr-only"> condition {{ $i + 1 }}</span></button></div>
                        </div>
                        @if ($field?->help)<p class="mt-1 text-xs text-gray-500">{{ $field->help }}</p>@endif
                        @error("conditions.$i") <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </li>
                @endforeach
            </ol>
            <button type="button" wire:click="addCondition" class="{{ $secondary }}">+ Add Condition</button>
        </section>
    @endif

    {{-- 4. Then --}}
    @if ($step === 4)
        <section aria-labelledby="step-then" class="space-y-3">
            <h2 id="step-then" class="{{ $heading }}">Then</h2>
            @error('actions') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
            <ol role="list" class="space-y-3" data-section="actions">
                @foreach ($actions as $i => $action)
                    @php($definition = \App\Services\Automation\Registry\ActionRegistry::find($action['type']))
                    <li wire:key="action-{{ $i }}" class="{{ $card }} space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-semibold tracking-wide text-gray-900 uppercase">{{ $i + 1 }}. {{ $definition?->label ?? 'Action' }}</p>
                            <div class="flex items-center gap-3 text-sm">
                                @if ($i > 0)<button type="button" wire:click="moveAction({{ $i }}, -1)" class="font-medium text-gray-700 hover:underline">↑ Up<span class="sr-only"> action {{ $i + 1 }}</span></button>@endif
                                @if ($i < count($actions) - 1)<button type="button" wire:click="moveAction({{ $i }}, 1)" class="font-medium text-gray-700 hover:underline">↓ Down<span class="sr-only"> action {{ $i + 1 }}</span></button>@endif
                                <button type="button" wire:click="removeAction({{ $i }})" class="font-medium text-red-700 hover:underline">Delete<span class="sr-only"> action {{ $i + 1 }}</span></button>
                            </div>
                        </div>
                        @error("actions.$i") <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label for="action-{{ $i }}-type" class="block text-sm font-medium text-gray-700">Action</label>
                                <select id="action-{{ $i }}-type" wire:model.live="actions.{{ $i }}.type" class="{{ $fieldClass }}">
                                    @foreach ($actionDefinitions as $option)
                                        <option value="{{ $option->key() }}">{{ $option->label }}</option>
                                    @endforeach
                                </select>
                                @if ($definition)<p class="mt-1 text-xs text-gray-500">{{ $definition->description }}</p>@endif
                            </div>
                            @foreach ($definition?->fields ?? [] as $field)
                                @if ($field->isShown($action['configuration'] ?? []))
                                    @include('livewire.automations.partials.action-field', ['field' => $field, 'i' => $i])
                                @endif
                            @endforeach
                            @if ($action['type'] === 'send_email' && ($action['configuration']['recipient'] ?? 'customer') !== 'owner')
                                <label class="flex items-center gap-2 text-sm text-gray-700 sm:col-span-2"><input type="checkbox" wire:model="actions.{{ $i }}.requires_approval" class="rounded border-gray-300"> Always hold this email for approval</label>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
            <button type="button" wire:click="addAction" class="{{ $secondary }}">+ Add Action</button>
            @if ($variables !== [])
                <div class="rounded-md bg-gray-50 p-3 text-xs text-gray-600">
                    <p class="font-medium text-gray-700">Variables you can use with “{{ $trigger?->label }}”:</p>
                    <p class="mt-1 flex flex-wrap gap-1">@foreach ($variables as $variable)<code class="rounded bg-white px-1 ring-1 ring-gray-200" title="{{ $variable->label }} (e.g. {{ $variable->example }})">{{ $variable->token() }}</code>@endforeach</p>
                </div>
            @endif
            <p class="text-xs text-gray-500">Customer emails are sent only when “Automatic emails” is on and approval is off (Automations → Email safety). Every send is checked first: valid address, verified sender, not opted out, not already sent.</p>
        </section>
    @endif

    {{-- 5. Review --}}
    @if ($step === 5)
        <section aria-labelledby="step-review" class="{{ $card }} space-y-4">
            <h2 id="step-review" class="{{ $heading }}">Review</h2>
            <p class="text-base font-medium text-gray-900">{{ $name ?: 'Untitled automation' }}</p>
            <dl class="space-y-3 text-sm" data-section="summary">
                <div><dt class="text-xs font-semibold tracking-wide text-gray-500 uppercase">When</dt><dd class="text-gray-900">{{ $summary['when'] ?? 'Not chosen' }}</dd></div>
                @if ($summary['wait'])<div><dt class="text-xs font-semibold tracking-wide text-gray-500 uppercase">Wait</dt><dd class="text-gray-900">{{ $summary['wait'] }}</dd></div>@endif
                <div><dt class="text-xs font-semibold tracking-wide text-gray-500 uppercase">If {{ count($summary['conditions']) > 1 ? ($summary['match'] === 'any' ? '(any)' : '(all)') : '' }}</dt>
                    <dd class="text-gray-900">@forelse ($summary['conditions'] as $line)<span class="block">{{ $line }}</span>@empty <span class="text-gray-600">No conditions</span>@endforelse</dd></div>
                <div><dt class="text-xs font-semibold tracking-wide text-gray-500 uppercase">Then</dt>
                    <dd class="text-gray-900"><ol class="list-inside list-decimal">@forelse ($summary['actions'] as $line)<li>{{ $line }}</li>@empty <li class="list-none text-gray-600">No actions yet</li>@endforelse</ol></dd></div>
            </dl>
            <p class="rounded-md bg-gray-50 p-3 text-sm text-gray-700" data-sentence>{{ $summary['sentence'] }}</p>

            @if ($problems !== [])
                <div role="alert" class="rounded-md bg-amber-50 p-3 text-sm text-amber-900" data-problems>
                    <p class="font-medium">Fix before activating:</p>
                    <ul class="mt-1 list-inside list-disc">@foreach ($problems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
                </div>
            @endif
            @error('status') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
        </section>

        {{-- Test --}}
        <section aria-labelledby="step-test" class="{{ $card }} space-y-3">
            <h2 id="step-test" class="{{ $heading }}">Test automation</h2>
            <p class="text-sm text-gray-600">Run it against a real record to see what would happen. Nothing is sent or created.</p>
            @if ($samples === [])
                <p class="text-sm text-gray-600">There is no record to test “{{ $trigger?->label }}” with yet.</p>
            @else
                <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                    <div class="min-w-0 flex-1">
                        <label for="test-sample" class="block text-sm font-medium text-gray-700">Test with</label>
                        <select id="test-sample" wire:model="testSample" class="{{ $fieldClass }}">
                            <option value="">Choose a record…</option>
                            @foreach ($samples as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <button type="button" wire:click="runTest" class="{{ $secondary }}">Test Automation</button>
                </div>
                @error('testSample') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
            @endif
            @if ($testReport)
                <div class="space-y-2 rounded-md border border-gray-200 p-3 text-sm" data-section="test-report">
                    <p><span class="font-medium">Trigger:</span> {{ $testReport['trigger'] }} — {{ $testReport['record'] }}</p>
                    @if ($testReport['wait'])<p><span class="font-medium">Wait:</span> {{ $testReport['wait'] }}</p>@endif
                    @if ($testReport['conditions'] !== [])
                        <p class="font-medium">Conditions ({{ $testReport['match'] }}):</p>
                        <ul class="space-y-0.5">@foreach ($testReport['conditions'] as $result)<li><span aria-hidden="true">{{ $result['passed'] ? '✓' : '✗' }}</span> <span class="sr-only">{{ $result['passed'] ? 'Passed' : 'Failed' }}:</span> {{ $result['label'] }} <span class="text-gray-500">(actual: {{ $result['actual'] }})</span></li>@endforeach</ul>
                    @endif
                    @if ($testReport['actions'] !== [])
                        <p class="font-medium">Actions:</p>
                        <ul class="space-y-0.5">@foreach ($testReport['actions'] as $result)<li><span aria-hidden="true">{{ $result['ok'] ? '✓' : '⚠' }}</span> <span class="font-medium">{{ $result['label'] }}:</span> {{ $result['detail'] }}</li>@endforeach</ul>
                    @endif
                    <p class="rounded bg-gray-50 p-2 text-gray-700">{{ $testReport['summary'] }}</p>
                </div>
            @endif
        </section>
    @endif

    {{-- Navigation: stays on screen on phones --}}
    <div class="fixed inset-x-0 bottom-0 z-10 flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 bg-white/95 px-4 py-3 backdrop-blur sm:static sm:border-0 sm:bg-transparent sm:p-0">
        <div>
            @if ($step > 1)<button type="button" wire:click="back" class="{{ $secondary }}">Back</button>@endif
        </div>
        <div class="flex gap-2">
            <button type="button" wire:click="saveDraft" wire:loading.attr="disabled" class="{{ $secondary }}">Save Draft</button>
            @if ($step < 5)
                <button type="button" wire:click="next" class="{{ $primary }}">Next</button>
            @else
                <button type="button" wire:click="activate" wire:loading.attr="disabled" class="{{ $primary }}">Activate</button>
            @endif
        </div>
    </div>
</div>
