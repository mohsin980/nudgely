<div class="space-y-6">
    @php($card = 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5')
    @php($heading = 'text-xs font-semibold tracking-wide text-gray-500 uppercase')
    @php($button = 'inline-flex items-center justify-center rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500')
    @php($field = 'mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
    @php($conversation = $this->conversation)
    @php($closed = $conversation->status === \App\Enums\ConversationStatus::Closed)

    {{-- Customer header --}}
    <div class="space-y-3">
        <a href="{{ route('inbox.index') }}" wire:navigate class="text-sm font-medium text-violet-700 hover:underline">&larr; Conversations</a>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900"><a href="{{ route('customers.show', $conversation->customer_id) }}" wire:navigate class="hover:underline">{{ $conversation->customer->name }}</a></h1>
                <p class="break-words text-sm text-gray-600">{{ $conversation->subject ?? '(no subject)' }} · {{ $conversation->customer->email }}</p>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                    <x-conversation-status-badge :status="$conversation->status" />
                    @if ($closed && $conversation->closed_reason)
                        <span class="text-gray-600">({{ $conversation->closed_reason->label() }})</span>
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('customers.show', $conversation->customer_id) }}" wire:navigate class="{{ $button }}">Customer Profile</a>
                @if ($closed)
                    <button type="button" wire:click="reopenConversation" class="{{ $button }}">Reopen Conversation</button>
                @else
                    <label for="conversation-status" class="sr-only">Conversation status</label>
                    <select id="conversation-status" wire:change="setStatus($event.target.value)" class="rounded-md border-0 py-2 pr-8 pl-3 text-sm text-gray-700 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-violet-600">
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected($status === $conversation->status)>{{ $status === \App\Enums\ConversationStatus::Closed ? 'Close…' : $status->label() }}</option>
                        @endforeach
                    </select>
                    <button type="button" wire:click="$set('showCloseForm', true)" class="{{ $button }}">Close Conversation</button>
                @endif
            </div>
        </div>
    </div>

    @if ($statusMessage)
        <div role="status" @class(['flex items-start justify-between gap-4 rounded-md p-4 text-sm', 'bg-green-50 text-green-800' => $statusMessageType === 'success', 'bg-blue-50 text-blue-800' => $statusMessageType !== 'success'])>
            <p>{{ $statusMessage }}</p>
            <button type="button" wire:click="$set('statusMessage', null)" class="shrink-0 font-medium hover:underline">Dismiss</button>
        </div>
    @endif

    @if ($showCloseForm && ! $closed)
        <form wire:submit="closeConversation" class="{{ $card }} space-y-3" aria-label="Close conversation">
            <p class="text-sm text-gray-700">Closing keeps every message. Open automated follow-ups for this conversation will be skipped, and it leaves "Needs your attention".</p>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label for="close-reason" class="block text-sm font-medium text-gray-700">Reason</label>
                    <select id="close-reason" wire:model="closeReason" class="{{ $field }}">@foreach ($closeReasons as $reason)<option value="{{ $reason->value }}">{{ $reason->label() }}</option>@endforeach</select></div>
                <div><label for="close-note" class="block text-sm font-medium text-gray-700">Note <span class="font-normal text-gray-500">(optional)</span></label><input id="close-note" type="text" wire:model="closeNote" maxlength="255" class="{{ $field }}"></div>
            </div>
            <div class="flex gap-2">
                <button type="submit" class="rounded-md bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-500">Close conversation</button>
                <button type="button" wire:click="$set('showCloseForm', false)" class="rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:underline">Cancel</button>
            </div>
        </form>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Timeline + composer --}}
        <div class="min-w-0 space-y-4 lg:col-span-2">
            <h2 class="sr-only">Messages and activity</h2>
            @if ($this->hasEarlierMessages)
                <button type="button" wire:click="loadEarlierMessages" class="text-sm font-medium text-violet-700 hover:underline">Load earlier messages</button>
            @endif

            @if ($this->feed->isEmpty())
                <p class="rounded-lg border border-dashed border-gray-300 bg-white px-6 py-10 text-center text-sm text-gray-600">No messages yet.</p>
            @else
                <ol role="list" class="space-y-4" aria-label="Messages">
                    @foreach ($this->feed as $row)
                        @if ($row['type'] === 'message')
                            @include('livewire.inbox.partials.message', ['message' => $row['item']])
                        @else
                            @php($entry = $row['item'])
                            <li wire:key="event-{{ $loop->index }}-{{ $entry->at->getTimestamp() }}" data-kind="{{ $entry->kind }}"
                                @class(['rounded-md border px-3 py-2 text-sm', 'border-violet-200 bg-violet-50/60' => $entry->kind === 'automation', 'border-gray-200 bg-gray-50' => $entry->kind !== 'automation'])>
                                <p class="flex flex-wrap items-center gap-x-2">
                                    <span class="rounded px-1.5 py-0.5 text-[11px] font-semibold tracking-wide uppercase ring-1 ring-inset {{ $entry->kind === 'automation' ? 'bg-violet-100 text-violet-800 ring-violet-200' : 'bg-white text-gray-600 ring-gray-200' }}">{{ ['automation' => 'Automation', 'business' => 'Business', 'system' => 'System', 'customer' => 'Customer'][$entry->kind] }}</span>
                                    <span class="font-medium text-gray-900">{{ $entry->title }}</span>
                                    <time class="text-xs text-gray-500" datetime="{{ $organization->localTime($entry->at)->toIso8601String() }}">{{ $organization->localTime($entry->at)->format('M j, g:i A') }}</time>
                                </p>
                                @if ($entry->body)<p class="mt-0.5 text-gray-700">{{ $entry->body }}</p>@endif
                                @if ($entry->details)
                                    <ul role="list" class="mt-1 text-gray-700">
                                        @foreach ($entry->details as $detail)
                                            <li><span aria-hidden="true">{{ $detail['ok'] ? '✓' : '–' }}</span> <span class="sr-only">{{ $detail['status'] }}:</span> {{ $detail['text'] }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </li>
                        @endif
                    @endforeach
                </ol>
            @endif

            {{-- Composer: sticky at the bottom of the screen on small devices. --}}
            <div class="sticky bottom-0 z-10 -mx-4 border-t border-gray-200 bg-gray-50/95 px-4 py-3 backdrop-blur sm:static sm:mx-0 sm:border-0 sm:bg-transparent sm:p-0" id="composer">
                @if (! $composerOpen)
                    <button type="button" wire:click="$set('composerOpen', true)" class="w-full rounded-md bg-violet-600 px-3 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-violet-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 sm:w-auto">Reply</button>
                @else
                    <form wire:submit="sendReply" class="{{ $card }} space-y-3" aria-label="Reply by email">
                        <p class="text-sm text-gray-600">To: <span class="font-medium text-gray-900">{{ $conversation->customer->email }}</span></p>
                        @error('reply') <p role="alert" class="rounded-md bg-red-50 p-2 text-sm text-red-800">{{ $message }}</p> @enderror
                        <div><label for="reply-subject" class="block text-sm font-medium text-gray-700">Subject</label><input id="reply-subject" type="text" wire:model="replySubject" maxlength="200" class="{{ $field }}">@error('replySubject') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                        <div><label for="reply-body" class="block text-sm font-medium text-gray-700">Message</label><textarea id="reply-body" wire:model="replyBody" rows="4" class="{{ $field }}" autofocus></textarea>@error('replyBody') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                        <div class="flex gap-2">
                            <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500 disabled:opacity-50"><span wire:loading.remove wire:target="sendReply">Send</span><span wire:loading wire:target="sendReply">Sending…</span></button>
                            <button type="button" wire:click="$set('composerOpen', false)" class="rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:underline">Cancel</button>
                        </div>
                    </form>
                @endif
            </div>
        </div>

        {{-- Side panel --}}
        <div class="min-w-0 space-y-6">
            {{-- AI classification --}}
            @php($current = $this->currentClassification)
            <section aria-labelledby="classification-heading" class="{{ $card }}" data-section="classification">
                <h2 id="classification-heading" class="{{ $heading }}">AI classification</h2>
                @if ($current)
                    <p class="mt-2 text-lg font-semibold tracking-wide text-gray-900 uppercase">{{ $current->intent?->label() }}</p>
                    @if ($current->isManual())
                        <p class="text-sm text-gray-600">Corrected by {{ $current->overrider?->name ?? 'a team member' }} (AI said {{ $current->previous_intent?->label() }})</p>
                        @if ($current->override_reason)<p class="text-sm text-gray-600">Reason: {{ $current->override_reason }}</p>@endif
                    @else
                        <p class="text-sm text-gray-900">{{ (int) round($current->confidence * 100) }}% confidence</p>
                    @endif
                    <p class="text-xs text-gray-500">Classified {{ $organization->localTime($current->classified_at ?? $current->created_at)->format('M j, g:i A') }}</p>
                    @if (! $current->isManual() && $current->summary)
                        <p class="mt-2 text-sm text-gray-700">{{ $current->summary }}</p>
                    @endif

                    @if ($showOverrideForm)
                        <form wire:submit="overrideClassification" class="mt-3 space-y-2" aria-label="Correct the classification">
                            <div><label for="override-intent" class="block text-sm font-medium text-gray-700">Correct intent</label>
                                <select id="override-intent" wire:model="overrideIntent" class="{{ $field }}"><option value="">Choose…</option>@foreach ($intents as $intent)<option value="{{ $intent->value }}">{{ $intent->label() }}</option>@endforeach</select>
                                @error('overrideIntent') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                            <div><label for="override-reason" class="block text-sm font-medium text-gray-700">Reason <span class="font-normal text-gray-500">(optional)</span></label><input id="override-reason" type="text" wire:model="overrideReason" maxlength="255" class="{{ $field }}"></div>
                            <div class="flex gap-2">
                                <button type="submit" class="rounded-md bg-violet-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-violet-500">Save</button>
                                <button type="button" wire:click="$set('showOverrideForm', false)" class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-700 hover:underline">Cancel</button>
                            </div>
                        </form>
                    @else
                        <button type="button" wire:click="$set('showOverrideForm', true)" class="mt-3 text-sm font-medium text-violet-700 hover:underline">Change classification</button>
                    @endif
                @else
                    <p class="mt-2 text-sm text-gray-600">No customer reply has been classified yet.</p>
                @endif
            </section>

            {{-- Estimates --}}
            <section aria-labelledby="estimates-heading" class="{{ $card }}" data-section="estimates">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="estimates-heading" class="{{ $heading }}">Estimate</h2>
                    <a href="{{ route('estimates.create', ['conversation' => $conversation->id]) }}" wire:navigate class="text-sm font-medium text-violet-700 hover:underline">Create Estimate</a>
                </div>
                @if ($this->estimates->isEmpty())
                    <p class="mt-2 text-sm text-gray-500">No estimate for this conversation.</p>
                @else
                    <ul role="list" class="mt-2 divide-y divide-gray-100">
                        @foreach ($this->estimates as $estimate)
                            <li wire:key="estimate-{{ $estimate->id }}" class="flex items-start justify-between gap-2 py-2 text-sm">
                                <div class="min-w-0">
                                    <p class="font-semibold text-gray-900">{{ $estimate->displayNumber() }} <span class="font-normal text-gray-900">{{ $estimate->money('total') }}</span></p>
                                    <p class="truncate text-gray-600">{{ $estimate->title }}</p>
                                    <x-estimate-status-badge :status="$estimate->status" class="mt-1" />
                                </div>
                                <a href="{{ route('estimates.show', $estimate->id) }}" wire:navigate class="shrink-0 rounded-md bg-white px-2 py-1 text-xs font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50">View Estimate</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Follow-up --}}
            <section aria-labelledby="follow-up-heading" class="{{ $card }} space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    @php($latest = $this->conversationFollowUps->first())
                    <h2 id="follow-up-heading" class="{{ $heading }}">
                        {{ $latest && $latest->status === \App\Enums\FollowUpStatus::Skipped && $this->conversationFollowUps->count() === 1 ? 'Follow-up skipped' : 'Follow-up' }}
                    </h2>
                    @unless ($showScheduleForm)
                        <button type="button" wire:click="openScheduleForm" class="text-sm font-medium text-violet-700 hover:underline">Schedule Follow-Up</button>
                    @endunless
                </div>
                @include('follow-ups.flash')
                @if ($showScheduleForm)
                    @include('follow-ups.schedule-form')
                @endif
                @if ($this->conversationFollowUps->isEmpty())
                    <p class="text-sm text-gray-500">No follow-up scheduled.</p>
                @else
                    <ul role="list" class="-mx-4 divide-y divide-gray-100 sm:-mx-5">
                        @foreach ($this->conversationFollowUps as $followUp)
                            @include('follow-ups.item', ['showCustomer' => false, 'compact' => true])
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Tasks --}}
            <section aria-labelledby="tasks-heading" class="{{ $card }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="tasks-heading" class="{{ $heading }}">Tasks</h2>
                    @unless ($showTaskForm)
                        <button type="button" wire:click="$set('showTaskForm', true)" class="text-sm font-medium text-violet-700 hover:underline">Create Task</button>
                    @endunless
                </div>
                @if ($showTaskForm)
                    <form wire:submit="createTask" class="mt-3 space-y-2" aria-label="Create task">
                        <div><label for="task-title" class="block text-sm font-medium text-gray-700">Task</label><input id="task-title" type="text" wire:model="taskTitle" maxlength="255" placeholder="Call John about the install date" class="{{ $field }}">@error('taskTitle') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                        <div><label for="task-priority" class="block text-sm font-medium text-gray-700">Priority</label>
                            <select id="task-priority" wire:model="taskPriority" class="{{ $field }}">@foreach (\App\Enums\TaskPriority::cases() as $p)<option value="{{ $p->value }}">{{ ucfirst($p->value) }}</option>@endforeach</select></div>
                        <div><label for="task-assignee" class="block text-sm font-medium text-gray-700">Assign to</label>
                            <select id="task-assignee" wire:model="taskAssignee" class="{{ $field }}">@foreach ($this->assignableUsers() as $userId => $userName)<option value="{{ $userId }}">{{ $userName }}{{ $userId === auth()->id() ? ' (me)' : '' }}</option>@endforeach</select>
                            @error('taskAssignee') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                        <div class="flex gap-2">
                            <button type="submit" class="rounded-md bg-violet-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-violet-500">Add task</button>
                            <button type="button" wire:click="$set('showTaskForm', false)" class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-700 hover:underline">Cancel</button>
                        </div>
                    </form>
                @endif
                @if ($this->tasks->isEmpty())
                    <p class="mt-3 text-sm text-gray-600">No tasks for this conversation.</p>
                @else
                    <ul role="list" class="mt-2 divide-y divide-gray-100 text-sm" data-section="tasks">
                        @foreach ($this->tasks as $task)
                            @include('livewire.partials.task-row', ['assignable' => $this->assignableUsers()])
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</div>
