<div class="space-y-6">
    @php($card = 'rounded-lg border border-gray-200 bg-white p-4 shadow-sm sm:p-5')
    @php($heading = 'text-sm font-semibold tracking-wide text-gray-500 uppercase')
    @php($button = 'inline-flex items-center justify-center rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500')
    @php($field = 'mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 focus:ring-inset')

    {{-- Header --}}
    <div class="space-y-3">
        <a href="{{ route('customers.index') }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">&larr; Customers</a>
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">{{ $this->customer->name }}</h1>
                <p class="break-words text-sm text-gray-600">
                    <a href="mailto:{{ $this->customer->email }}" class="hover:underline">{{ $this->customer->email }}</a>
                    @if ($this->customer->phone) · {{ $this->customer->phone }} @endif
                    @if ($this->customer->company) · {{ $this->customer->company }} @endif
                </p>
                <p class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                    <span class="text-gray-600">Status:</span>
                    <span @class(['rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', 'bg-green-50 text-green-700 ring-green-600/20' => $this->customer->isActive(), 'bg-gray-100 text-gray-600 ring-gray-500/20' => ! $this->customer->isActive()])>{{ $this->customer->status->label() }}</span>
                    @if ($this->customer->hasOptedOutOfEmail())
                        <span class="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-600/20 ring-inset">Opted out of email</span>
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="openEmailForm" class="{{ $button }} bg-indigo-600 text-white ring-indigo-600 hover:bg-indigo-500">Send Email</button>
                <a href="{{ route('estimates.create', ['customer' => $this->customer->id]) }}" wire:navigate class="{{ $button }}">Create Estimate</a>
                <button type="button" wire:click="openScheduleForm" class="{{ $button }}">Schedule Follow-Up</button>
                <a href="{{ route('customers.edit', $this->customer->id) }}" wire:navigate class="{{ $button }}">Edit</a>
            </div>
        </div>
        @if ($this->customer->notes)
            <p class="rounded-md bg-gray-50 p-3 text-sm whitespace-pre-line text-gray-700"><span class="font-medium">Notes:</span> {{ $this->customer->notes }}</p>
        @endif
    </div>

    @if ($statusMessage)
        <div role="status" class="rounded-md bg-green-50 p-3 text-sm text-green-800">{{ $statusMessage }}</div>
    @endif
    @include('follow-ups.flash')

    @if ($showEmailForm)
        <form wire:submit="sendEmail" class="{{ $card }} space-y-3" aria-label="New email">
            <h2 class="{{ $heading }}">New email to {{ $this->customer->email }}</h2>
            @error('email') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
            <div><label for="new-email-subject" class="block text-sm font-medium text-gray-700">Subject</label><input id="new-email-subject" type="text" wire:model="emailSubject" maxlength="200" class="{{ $field }}">@error('emailSubject') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div><label for="new-email-body" class="block text-sm font-medium text-gray-700">Message</label><textarea id="new-email-body" wire:model="emailBody" rows="5" class="{{ $field }}"></textarea>@error('emailBody') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div class="flex gap-2">
                <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"><span wire:loading.remove wire:target="sendEmail">Send</span><span wire:loading wire:target="sendEmail">Sending…</span></button>
                <button type="button" wire:click="$set('showEmailForm', false)" class="rounded-md px-3 py-2 text-sm font-medium text-gray-700 hover:underline">Cancel</button>
            </div>
        </form>
    @endif

    @if ($showScheduleForm)
        @include('follow-ups.schedule-form')
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="min-w-0 space-y-6 lg:col-span-2">
            {{-- Conversation --}}
            <section aria-labelledby="conversation-heading" class="{{ $card }}">
                <h2 id="conversation-heading" class="{{ $heading }}">Conversation</h2>
                @if ($this->conversations->isEmpty())
                    <p class="mt-3 text-sm text-gray-600">No conversation yet.</p>
                @else
                    <ul role="list" class="mt-2 divide-y divide-gray-100">
                        @foreach ($this->conversations as $conversation)
                            <li wire:key="conversation-{{ $conversation->id }}">
                                <a href="{{ route('inbox.show', $conversation->id) }}" wire:navigate class="-mx-2 block rounded-md px-2 py-2.5 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                    <p class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium text-gray-900">{{ $conversation->subject ?? '(no subject)' }}</span>
                                        <x-conversation-status-badge :status="$conversation->status" />
                                        @if ($conversation->latest_intent) <x-intent-badge :intent="$conversation->latest_intent" /> @endif
                                        @if ($conversation->unread_count) <span class="text-xs font-semibold text-indigo-700">● {{ $conversation->unread_count }} unread</span> @endif
                                    </p>
                                    @if ($conversation->latestMessage?->excerpt)
                                        <p class="truncate text-sm text-gray-600">{{ $conversation->latestMessage->direction->value === 'inbound' ? 'Customer' : 'You' }}: {{ \Illuminate\Support\Str::limit(trim(strtok($conversation->latestMessage->excerpt, "\n")), 120) }}</p>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Estimates --}}
            <section aria-labelledby="estimates-heading" class="{{ $card }}">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="estimates-heading" class="{{ $heading }}">Estimates</h2>
                    <a href="{{ route('estimates.create', ['customer' => $this->customer->id]) }}" wire:navigate class="text-sm font-medium text-indigo-700 hover:underline">Create Estimate</a>
                </div>
                @if ($this->estimates->isEmpty())
                    <p class="mt-3 text-sm text-gray-600">{{ "This customer doesn't have any estimates yet." }}</p>
                @else
                    <ul role="list" class="mt-2 divide-y divide-gray-100" data-section="estimates">
                        @foreach ($this->estimates as $estimate)
                            <li wire:key="estimate-{{ $estimate->id }}">
                                <a href="{{ route('estimates.show', $estimate->id) }}" wire:navigate class="-mx-2 flex items-center justify-between gap-3 rounded-md px-2 py-2.5 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                    <span class="min-w-0">
                                        <span class="block font-medium text-gray-900">{{ $estimate->displayNumber() }}</span>
                                        <span class="block truncate text-sm text-gray-600">{{ $estimate->title }}</span>
                                    </span>
                                    <span class="flex shrink-0 flex-col items-end gap-1">
                                        <span class="text-sm font-semibold text-gray-900">{{ $estimate->money('total') }}</span>
                                        <x-estimate-status-badge :status="$estimate->status" />
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Activity --}}
            <section aria-labelledby="activity-heading" class="{{ $card }}">
                <h2 id="activity-heading" class="{{ $heading }}">Activity</h2>
                <ol role="list" class="mt-2 divide-y divide-gray-100" data-section="timeline">
                    @foreach ($this->timeline as $entry)
                        @include('livewire.partials.timeline-entry')
                    @endforeach
                </ol>
                @if ($this->timeline->count() >= $timelineLimit)
                    <button type="button" wire:click="loadMoreActivity" class="mt-2 text-sm font-medium text-indigo-700 hover:underline">Show earlier activity</button>
                @endif
            </section>
        </div>

        <div class="min-w-0 space-y-6">
            {{-- Follow-ups --}}
            @php($upcoming = $this->followUps->filter(fn ($f) => $f->status->isOpen()))
            @php($history = $this->followUps->reject(fn ($f) => $f->status->isOpen()))
            <section aria-labelledby="upcoming-heading" class="{{ $card }}">
                <h2 id="upcoming-heading" class="{{ $heading }}">Upcoming follow-ups</h2>
                @if ($upcoming->isEmpty())
                    <p class="mt-3 text-sm text-gray-600">No follow-ups scheduled.</p>
                @else
                    <ul role="list" class="-mx-4 mt-1 divide-y divide-gray-100 sm:-mx-5">
                        @foreach ($upcoming as $followUp)
                            @include('follow-ups.item', ['showCustomer' => false, 'compact' => true])
                        @endforeach
                    </ul>
                @endif
            </section>

            <section aria-labelledby="history-heading" class="{{ $card }}">
                <h2 id="history-heading" class="{{ $heading }}">Follow-up history</h2>
                @if ($history->isEmpty())
                    <p class="mt-3 text-sm text-gray-600">No past follow-ups.</p>
                @else
                    <ol role="list" class="mt-2 divide-y divide-gray-100 text-sm">
                        @foreach ($history as $followUp)
                            <li wire:key="history-{{ $followUp->id }}" class="flex items-start justify-between gap-2 py-2">
                                <div class="min-w-0">
                                    <p class="text-gray-900">“{{ $followUp->reason() }}”</p>
                                    <p class="text-gray-600"><x-follow-up-due :at="$followUp->created_at" :organization="$organization" /> · {{ $followUp->type->label() }}</p>
                                    <p class="text-gray-600">{{ $followUp->skip_reason?->label() ?? ($followUp->cancelled_reason ? 'Cancelled: '.$followUp->cancelled_reason->label() : ($followUp->outcome ?? $followUp->completion_notes)) }}</p>
                                </div>
                                <x-automation-status-badge :status="$followUp->status" />
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            {{-- Tasks --}}
            <section aria-labelledby="tasks-heading" class="{{ $card }}">
                <h2 id="tasks-heading" class="{{ $heading }}">Tasks</h2>
                @if ($this->tasks->isEmpty())
                    <p class="mt-3 text-sm text-gray-600">No tasks for this customer.</p>
                @else
                    <ul role="list" class="mt-2 divide-y divide-gray-100 text-sm" data-section="tasks">
                        @foreach ($this->tasks as $task)
                            @include('livewire.partials.task-row')
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</div>
