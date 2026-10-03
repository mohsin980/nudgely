<div class="space-y-6">
    <div>
        <a href="{{ route('settings.automations.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">&larr; Automations</a>
        <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-900">{{ $automationId ? 'Edit automation' : 'New automation' }}</h1>
    </div>

    @if ($errors->any())
        <div role="alert" class="rounded-md bg-red-50 p-4 text-sm text-red-800">
            <p class="font-medium">Please fix the following:</p>
            <ul class="mt-1 list-disc pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php($input = 'block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 focus:ring-inset')
    @php($inline = 'rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 focus:ring-inset')
    @php($label = 'block text-sm font-medium text-gray-700')

    <form wire:submit="save" class="space-y-6">
        <section class="space-y-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <div>
                <label for="name" class="{{ $label }}">Name</label>
                <input id="name" type="text" wire:model="name" maxlength="100" class="mt-1 {{ $input }}" placeholder="Customer ready to book">
            </div>
            <div>
                <label for="description" class="{{ $label }}">Description <span class="font-normal text-gray-500">(optional)</span></label>
                <input id="description" type="text" wire:model="description" maxlength="500" class="mt-1 {{ $input }}">
            </div>
        </section>

        {{-- WHEN --}}
        <section aria-labelledby="when-heading" class="space-y-3 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h2 id="when-heading" class="text-sm font-semibold tracking-wide text-indigo-700 uppercase">When</h2>
            <label for="trigger" class="sr-only">Trigger</label>
            <select id="trigger" wire:model="triggerType" class="{{ $input }} sm:max-w-sm">
                @foreach ($triggers as $trigger)
                    <option value="{{ $trigger->value }}">{{ $trigger->label() }}</option>
                @endforeach
            </select>
        </section>

        {{-- IF --}}
        <section aria-labelledby="if-heading" class="space-y-3 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h2 id="if-heading" class="text-sm font-semibold tracking-wide text-indigo-700 uppercase">If</h2>
            @if ($conditions === [])
                <p class="text-sm text-gray-600">No conditions: the automation runs for every event.</p>
            @endif

            @foreach ($conditions as $i => $condition)
                @php($conditionType = \App\Enums\Automation\AutomationConditionType::tryFrom($condition['type']))
                <div wire:key="condition-{{ $i }}" class="flex flex-wrap items-center gap-2" data-condition="{{ $i }}">
                    <span class="w-10 text-xs font-semibold text-gray-500 uppercase">{{ $i === 0 ? 'If' : 'And' }}</span>
                    <label class="sr-only" for="condition-{{ $i }}-type">Condition</label>
                    <select id="condition-{{ $i }}-type" wire:model.live="conditions.{{ $i }}.type" class="{{ $inline }}">
                        @foreach ($conditionTypes as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    <label class="sr-only" for="condition-{{ $i }}-operator">Operator</label>
                    <select id="condition-{{ $i }}-operator" wire:model="conditions.{{ $i }}.operator" class="{{ $inline }}">
                        @foreach ($conditionType?->allowedOperators() ?? [] as $operator)
                            <option value="{{ $operator->value }}">{{ $operator->label() }}</option>
                        @endforeach
                    </select>
                    <label class="sr-only" for="condition-{{ $i }}-value">Value</label>
                    @switch($condition['type'])
                        @case('intent_equals')
                            <select id="condition-{{ $i }}-value" wire:model="conditions.{{ $i }}.value" class="{{ $inline }}">
                                <option value="">Choose intent…</option>
                                @foreach ($intents as $intent)
                                    <option value="{{ $intent->value }}">{{ $intent->label() }}</option>
                                @endforeach
                            </select>
                            @break
                        @case('conversation_status_equals')
                            <select id="condition-{{ $i }}-value" wire:model="conditions.{{ $i }}.value" class="{{ $inline }}">
                                <option value="">Choose status…</option>
                                @foreach ($conversationStatuses as $status)
                                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                @endforeach
                            </select>
                            @break
                        @case('confidence_greater_than')
                            <input id="condition-{{ $i }}-value" type="number" min="0" max="100" step="1" wire:model="conditions.{{ $i }}.value" class="{{ $inline }} w-24"> <span class="text-sm text-gray-600">%</span>
                            @break
                        @default
                            <input id="condition-{{ $i }}-value" type="number" min="0" max="9999" step="1" wire:model="conditions.{{ $i }}.value" class="{{ $inline }} w-24"> <span class="text-sm text-gray-600">days</span>
                    @endswitch
                    <button type="button" wire:click="removeCondition({{ $i }})" class="text-sm font-medium text-red-700 hover:underline">Remove</button>
                </div>
            @endforeach

            <button type="button" wire:click="addCondition" class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50">Add condition</button>
        </section>

        {{-- THEN --}}
        <section aria-labelledby="then-heading" class="space-y-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
            <h2 id="then-heading" class="text-sm font-semibold tracking-wide text-indigo-700 uppercase">Then</h2>
            @if ($actions === [])
                <p class="text-sm text-gray-600">Add at least one action before activating.</p>
            @endif

            @foreach ($actions as $i => $action)
                @php($config = "actions.{$i}.configuration")
                <div wire:key="action-{{ $i }}-{{ $action['type'] }}" class="space-y-3 rounded-md border border-gray-200 p-4" data-action="{{ $i }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold text-gray-500">{{ $i + 1 }}.</span>
                            <label class="sr-only" for="action-{{ $i }}-type">Action</label>
                            <select id="action-{{ $i }}-type" wire:model.live="actions.{{ $i }}.type" class="{{ $inline }}">
                                @foreach ($actionTypes as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="button" wire:click="removeAction({{ $i }})" class="text-sm font-medium text-red-700 hover:underline">Remove</button>
                    </div>

                    @switch($action['type'])
                        @case('create_task')
                            <div class="grid gap-3 sm:grid-cols-[1fr_auto_auto]">
                                <div><label class="{{ $label }}" for="action-{{ $i }}-title">Task title</label><input id="action-{{ $i }}-title" type="text" wire:model="{{ $config }}.title" maxlength="255" class="mt-1 {{ $input }}" placeholder="Call {customer_name}"></div>
                                <div><label class="{{ $label }}" for="action-{{ $i }}-priority">Priority</label>
                                    <select id="action-{{ $i }}-priority" wire:model="{{ $config }}.priority" class="mt-1 {{ $input }}">
                                        <option value="">Medium (default)</option>
                                        @foreach ($priorities as $priority)
                                            <option value="{{ $priority->value }}">{{ ucfirst($priority->value) }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div><label class="{{ $label }}" for="action-{{ $i }}-due">Due in (hours)</label><input id="action-{{ $i }}-due" type="number" min="0" max="8760" wire:model="{{ $config }}.due_in_hours" class="mt-1 {{ $input }} w-28"></div>
                            </div>
                            <p class="text-xs text-gray-500">Placeholders: {customer_name}, {customer_first_name}, {intent}, {conversation_subject}</p>
                            @break
                        @case('add_customer_tag')
                            <div><label class="{{ $label }}" for="action-{{ $i }}-tag">Tag</label><input id="action-{{ $i }}-tag" type="text" wire:model="{{ $config }}.tag" maxlength="50" class="mt-1 {{ $input }} sm:max-w-xs" placeholder="Ready to book"></div>
                            @break
                        @case('update_conversation_status')
                            <div><label class="{{ $label }}" for="action-{{ $i }}-status">New status</label>
                                <select id="action-{{ $i }}-status" wire:model="{{ $config }}.status" class="mt-1 {{ $input }} sm:max-w-xs">
                                    <option value="">Choose status…</option>
                                    @foreach ($conversationStatuses as $status)
                                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @break
                        @case('notify_user')
                            <div class="grid gap-3 sm:grid-cols-[1fr_auto]">
                                <div><label class="{{ $label }}" for="action-{{ $i }}-message">Notification</label><input id="action-{{ $i }}-message" type="text" wire:model="{{ $config }}.message" maxlength="500" class="mt-1 {{ $input }}" placeholder="{customer_name} is ready to book"></div>
                                <div><label class="{{ $label }}" for="action-{{ $i }}-recipients">Notify</label>
                                    <select id="action-{{ $i }}-recipients" wire:model="{{ $config }}.recipients" class="mt-1 {{ $input }}">
                                        <option value="admins">Owners and admins</option>
                                        <option value="members">Everyone in the team</option>
                                    </select>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500">In-app notification. Placeholders: {customer_name}, {customer_first_name}, {intent}, {conversation_subject}</p>
                            @break
                        @case('schedule_follow_up')
                        @case('send_email')
                            @if ($action['type'] === 'schedule_follow_up')
                                <div><label class="{{ $label }}" for="action-{{ $i }}-delay">Follow up after (days)</label><input id="action-{{ $i }}-delay" type="number" min="1" max="60" wire:model="{{ $config }}.delay_days" class="mt-1 {{ $input }} w-28"></div>
                            @endif
                            <div><label class="{{ $label }}" for="action-{{ $i }}-subject">Email subject</label><input id="action-{{ $i }}-subject" type="text" wire:model="{{ $config }}.subject" maxlength="200" class="mt-1 {{ $input }}"></div>
                            <div><label class="{{ $label }}" for="action-{{ $i }}-body">Email body</label><textarea id="action-{{ $i }}-body" rows="5" wire:model="{{ $config }}.body" maxlength="5000" class="mt-1 {{ $input }}"></textarea></div>
                            <p class="text-xs text-gray-500">Variables: @foreach ($variables as $token => $variableLabel)<code class="rounded bg-gray-100 px-1" title="{{ $variableLabel }}">{{ $token }}</code>@if (! $loop->last), @endif @endforeach</p>
                            @if ($action['type'] === 'send_email')
                                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" wire:model="actions.{{ $i }}.requires_approval" class="rounded border-gray-300 text-indigo-600"> Require approval before sending</label>
                                <p class="text-xs text-gray-500">Sent from your verified business email only when Automatic emails is on and approval is off in Automation settings.</p>
                            @else
                                <p class="text-xs text-gray-500">Skipped if the customer replies first. Emailed only when Automatic emails is on and approval is off; otherwise you get a reminder task.</p>
                            @endif
                            @break
                    @endswitch
                </div>
            @endforeach

            <button type="button" wire:click="addAction" class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50">Add action</button>
        </section>

        <div class="flex flex-wrap justify-end gap-3">
            <a href="{{ route('settings.automations.index') }}" wire:navigate class="rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:underline">Cancel</a>
            <button type="submit" class="rounded-md bg-white px-3 py-2 text-sm font-semibold text-gray-900 ring-1 ring-gray-300 ring-inset hover:bg-gray-50">Save</button>
            <button type="button" wire:click="saveAndActivate" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">Save and activate</button>
        </div>
    </form>
</div>
