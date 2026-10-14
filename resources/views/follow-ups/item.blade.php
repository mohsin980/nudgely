{{-- One follow-up with its actions. Expects $followUp, $organization and optionally $showCustomer. --}}
@php($open = $followUp->status->isOpen())
@php($ready = $open && $followUp->status === \App\Enums\FollowUpStatus::Due && $followUp->isAutomated() && $followUp->hasEmail() && $followUp->due_notified_at)
@php($field = 'block w-full rounded-xl border-0 px-3.5 py-2.5 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
<li wire:key="follow-up-{{ $followUp->id }}" id="follow-up-{{ $followUp->id }}" data-follow-up="{{ $followUp->id }}" class="space-y-3 p-4">
    {{-- $compact: narrow side panels stack the actions under the details. --}}
    <div @class(['flex flex-col gap-3', 'sm:flex-row sm:items-start sm:justify-between' => ! ($compact ?? false)])>
        <div class="min-w-0 space-y-1">
            <div class="flex flex-wrap items-center gap-2">
                @if ($showCustomer ?? true)
                    <a href="{{ route('customers.show', $followUp->customer_id) }}" wire:navigate class="font-semibold text-slate-900 hover:underline">{{ $followUp->customer?->name }}</a>
                @endif
                @if ($ready)
                    <span class="inline-flex items-center rounded-full bg-violet-50 px-2.5 py-0.5 text-xs font-medium text-violet-700 ring-1 ring-violet-600/20 ring-inset">Follow-up ready</span>
                @else
                    <x-automation-status-badge :status="$followUp->status" />
                @endif
                <span class="text-xs text-slate-500">{{ $followUp->type->label() }}</span>
            </div>
            <p class="text-sm text-slate-700">“{{ $followUp->reason() }}”</p>
            <p class="text-sm text-slate-500">
                @if ($open)
                    Due: <x-follow-up-due :at="$followUp->due_at" :organization="$organization" class="font-medium text-slate-900" />
                @elseif ($followUp->completed_at)
                    Completed <x-follow-up-due :at="$followUp->completed_at" :organization="$organization" />
                @else
                    Was due <x-follow-up-due :at="$followUp->due_at" :organization="$organization" />
                @endif
                @if ($followUp->assignee)
                    · Assigned to {{ $followUp->assignee->name }}
                @endif
                @if ($followUp->conversation_id && ($showCustomer ?? true))
                    · <a href="{{ route('inbox.show', $followUp->conversation_id) }}" wire:navigate class="text-violet-700 hover:underline">Conversation</a>
                @endif
            </p>
            @if ($followUp->status === \App\Enums\FollowUpStatus::Skipped && $followUp->skip_reason)
                <p class="text-sm text-amber-800">Reason: {{ $followUp->skip_reason->label() }}</p>
            @elseif ($followUp->status === \App\Enums\FollowUpStatus::Cancelled && $followUp->cancelled_reason)
                <p class="text-sm text-slate-500">Reason: {{ $followUp->cancelled_reason->label() }}{{ $followUp->outcome ? ' — '.$followUp->outcome : '' }}</p>
            @elseif ($followUp->outcome && ! $open || $ready)
                <p class="text-sm text-slate-500">{{ $followUp->outcome }}</p>
            @endif
            @if ($followUp->completion_notes)
                <p class="text-sm text-slate-500">Notes: {{ $followUp->completion_notes }}</p>
            @endif
        </div>

        @if ($open)
            <div @class(['flex flex-wrap gap-2', 'sm:justify-end' => ! ($compact ?? false)])>
                @if ($ready)
                    <button type="button" wire:click="sendFollowUp({{ $followUp->id }})" wire:loading.attr="disabled" class="rounded-2xl bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50">Send Follow-Up</button>
                @endif
                <button type="button" wire:click="openFollowUpForm({{ $followUp->id }}, 'complete')" class="rounded-2xl bg-white px-3 py-1.5 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset transition hover:bg-slate-50">Complete</button>
                <button type="button" wire:click="openFollowUpForm({{ $followUp->id }}, 'reschedule')" class="rounded-2xl bg-white px-3 py-1.5 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset transition hover:bg-slate-50">Reschedule</button>
                <button type="button" wire:click="openFollowUpForm({{ $followUp->id }}, 'assign')" class="rounded-2xl bg-white px-3 py-1.5 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset transition hover:bg-slate-50">Assign</button>
                <button type="button" wire:click="openFollowUpForm({{ $followUp->id }}, 'cancel')" class="rounded-2xl px-3 py-1.5 text-sm font-medium text-red-600 hover:underline">Cancel</button>
            </div>
        @endif
    </div>

    @if ($activeFollowUpId === $followUp->id)
        <form wire:submit="{{ ['complete' => 'completeFollowUp', 'reschedule' => 'rescheduleFollowUp', 'cancel' => 'cancelFollowUp', 'assign' => 'assignFollowUp'][$followUpForm] }}"
              class="space-y-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
            @error('followUp') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror

            @if ($followUpForm === 'complete')
                <label for="completion-notes-{{ $followUp->id }}" class="block text-sm font-medium text-slate-700">Notes <span class="font-normal text-slate-500">(optional)</span></label>
                <input id="completion-notes-{{ $followUp->id }}" type="text" wire:model="completionNotes" maxlength="1000" class="{{ $field }}" placeholder="Customer approved estimate.">
            @elseif ($followUpForm === 'reschedule')
                <div class="flex flex-wrap items-end gap-3">
                    <div><label for="reschedule-date-{{ $followUp->id }}" class="block text-sm font-medium text-slate-700">New date</label><input id="reschedule-date-{{ $followUp->id }}" type="date" wire:model="rescheduleDate" class="mt-1 {{ $field }}"></div>
                    <div><label for="reschedule-time-{{ $followUp->id }}" class="block text-sm font-medium text-slate-700">Time</label><input id="reschedule-time-{{ $followUp->id }}" type="time" wire:model="rescheduleTime" class="mt-1 {{ $field }}"></div>
                    <p class="pb-2 text-xs text-slate-500">{{ $organization->timezone() }}</p>
                </div>
            @elseif ($followUpForm === 'assign')
                <label for="assign-to-{{ $followUp->id }}" class="block text-sm font-medium text-slate-700">Assign to</label>
                <select id="assign-to-{{ $followUp->id }}" wire:model="assignTo" class="{{ $field }}">
                    <option value="">Unassigned</option>
                    @foreach ($this->assignableUsers() as $userId => $userName)
                        <option value="{{ $userId }}">{{ $userName }}</option>
                    @endforeach
                </select>
            @else
                <div class="flex flex-wrap items-end gap-3">
                    <div><label for="cancel-reason-{{ $followUp->id }}" class="block text-sm font-medium text-slate-700">Reason</label>
                        <select id="cancel-reason-{{ $followUp->id }}" wire:model.live="cancelReason" class="mt-1 {{ $field }}">
                            @foreach (\App\Enums\FollowUpCancelReason::forPeople() as $reason)
                                <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="min-w-48 flex-1"><label for="cancel-note-{{ $followUp->id }}" class="block text-sm font-medium text-slate-700">Note {{ $cancelReason === 'other' ? '' : '(optional)' }}</label><input id="cancel-note-{{ $followUp->id }}" type="text" wire:model="cancelNote" maxlength="1000" class="mt-1 {{ $field }}"></div>
                </div>
                @error('cancelNote') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
            @endif

            <div class="flex gap-2">
                <button type="submit" class="rounded-2xl bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700">
                    {{ ['complete' => 'Mark completed', 'reschedule' => 'Save new time', 'cancel' => 'Cancel follow-up', 'assign' => 'Save assignee'][$followUpForm] }}
                </button>
                <button type="button" wire:click="closeFollowUpForm" class="rounded-2xl px-3 py-1.5 text-sm font-medium text-slate-700 hover:underline">Close</button>
            </div>
        </form>
    @endif
</li>
