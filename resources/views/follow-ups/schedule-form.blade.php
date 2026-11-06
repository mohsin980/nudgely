{{-- New manual follow-up. Expects $organization, optionally $customers (pick one) — otherwise the page's customer — and $withTips for the side panel. --}}
@php($field = 'block w-full rounded-xl border-0 bg-white py-3 pr-3.5 pl-11 text-[15px] text-slate-900 shadow-xs ring-1 ring-slate-200 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-600 focus:ring-inset')
@php($fieldIcon = 'pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400')
@php($label = 'block text-[15px] font-semibold text-slate-800')
<form wire:submit="scheduleFollowUp" aria-label="Schedule follow-up"
      class="rounded-2xl bg-white p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.2)] ring-1 ring-slate-200/70 sm:p-6">
    <div class="flex items-center gap-4">
        <span class="grid size-14 shrink-0 place-items-center rounded-full bg-violet-100 text-violet-600"><x-ui-icon name="calendar" class="size-7" /></span>
        <div>
            <h2 class="text-xl font-bold text-slate-900">Schedule Follow-up</h2>
            <p class="text-[15px] text-slate-500">Set up a follow-up for your customer.</p>
        </div>
    </div>

    <div @class(['mt-5 grid gap-6', 'lg:grid-cols-[minmax(0,1fr)_19rem]' => $withTips ?? false])>
        <div class="space-y-4">
            @error('schedule') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
            @isset($customers)
                <div>
                    <label for="schedule-customer" class="{{ $label }}">Customer</label>
                    <div class="relative mt-1.5">
                        <x-ui-icon name="user" class="{{ $fieldIcon }}" />
                        <select id="schedule-customer" wire:model="scheduleCustomerId" class="{{ $field }}">
                            <option value="">Choose customer…</option>
                            @foreach ($customers as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            @endisset
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="schedule-date" class="{{ $label }}">Date</label>
                    <div class="relative mt-1.5">
                        <x-ui-icon name="calendar" class="{{ $fieldIcon }}" />
                        <input id="schedule-date" type="date" wire:model="scheduleDate" class="{{ $field }}">
                    </div>
                </div>
                <div>
                    <label for="schedule-time" class="{{ $label }}">Time <span class="font-normal text-slate-500">({{ $organization->timezone() }})</span></label>
                    <div class="relative mt-1.5">
                        <x-ui-icon name="clock" class="{{ $fieldIcon }}" />
                        <input id="schedule-time" type="time" wire:model="scheduleTime" class="{{ $field }}">
                    </div>
                </div>
            </div>
            <div>
                <label for="schedule-notes" class="{{ $label }}">Notes</label>
                <textarea id="schedule-notes" wire:model="scheduleNotes" maxlength="1000" rows="2" placeholder="Ask if customer is ready to proceed."
                          class="mt-1.5 block w-full rounded-xl border-0 px-3.5 py-3 text-[15px] text-slate-900 shadow-xs ring-1 ring-slate-200 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-600 focus:ring-inset"></textarea>
            </div>
            <div class="sm:max-w-md">
                <label for="schedule-assignee" class="{{ $label }}">Assigned to</label>
                <div class="relative mt-1.5">
                    <x-ui-icon name="user" class="{{ $fieldIcon }}" />
                    <select id="schedule-assignee" wire:model="scheduleAssignee" class="{{ $field }}">
                        <option value="">Owners and admins</option>
                        @foreach ($this->assignableUsers() as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex items-center gap-3 pt-1">
                <button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-linear-to-r from-violet-600 to-violet-500 px-5 py-2.5 text-[15px] font-semibold text-white shadow-[0_10px_22px_-8px_rgba(109,40,217,0.65)] transition hover:from-violet-700 hover:to-violet-600">
                    <x-ui-icon name="calendar" class="size-5" /> Schedule
                </button>
                <button type="button" wire:click="$set('showScheduleForm', false)" class="rounded-xl px-4 py-2.5 text-[15px] font-medium text-slate-700 hover:bg-slate-100">Cancel</button>
            </div>
        </div>

        @if ($withTips ?? false)
            <aside class="relative hidden overflow-hidden rounded-2xl bg-linear-to-br from-violet-50 via-white to-violet-50 p-6 ring-1 ring-violet-100 lg:block">
                <x-illustration name="plane" class="ml-auto size-28" />
                <p class="mt-2 text-xl font-bold text-slate-900">Stay on track</p>
                <p class="mt-1 text-[15px] text-slate-600">Schedule follow-ups so you never miss a potential customer.</p>
                <ul role="list" class="mt-4 space-y-2.5 text-[15px] text-slate-700">
                    @foreach (['Get a reminder when it’s due', 'Assign it to anyone on your team', 'See every follow-up in one place'] as $tip)
                        <li class="flex items-center gap-2.5"><x-ui-icon name="check-circle" class="size-5 shrink-0 text-emerald-500 stroke-2" /> {{ $tip }}</li>
                    @endforeach
                </ul>
            </aside>
        @endif
    </div>
</form>
