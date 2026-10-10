{{-- New manual follow-up. Expects $organization, optionally $customers (pick one) — otherwise the page's customer. --}}
@php($field = 'block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
<form wire:submit="scheduleFollowUp" class="space-y-3 rounded-lg border border-gray-200 bg-white p-4 shadow-sm" aria-label="Schedule follow-up">
    @error('schedule') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
    <div class="grid gap-3 sm:grid-cols-2">
        @isset($customers)
            <div class="sm:col-span-2"><label for="schedule-customer" class="block text-sm font-medium text-gray-700">Customer</label>
                <select id="schedule-customer" wire:model="scheduleCustomerId" class="mt-1 {{ $field }}">
                    <option value="">Choose customer…</option>
                    @foreach ($customers as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endisset
        <div><label for="schedule-date" class="block text-sm font-medium text-gray-700">Date</label><input id="schedule-date" type="date" wire:model="scheduleDate" class="mt-1 {{ $field }}"></div>
        <div><label for="schedule-time" class="block text-sm font-medium text-gray-700">Time <span class="font-normal text-gray-500">({{ $organization->timezone() }})</span></label><input id="schedule-time" type="time" wire:model="scheduleTime" class="mt-1 {{ $field }}"></div>
        <div class="sm:col-span-2"><label for="schedule-notes" class="block text-sm font-medium text-gray-700">Notes</label><input id="schedule-notes" type="text" wire:model="scheduleNotes" maxlength="1000" class="mt-1 {{ $field }}" placeholder="Ask if customer is ready to proceed."></div>
        <div><label for="schedule-assignee" class="block text-sm font-medium text-gray-700">Assigned to</label>
            <select id="schedule-assignee" wire:model="scheduleAssignee" class="mt-1 {{ $field }}">
                <option value="">Owners and admins</option>
                @foreach ($this->assignableUsers() as $id => $name)
                    <option value="{{ $id }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="flex gap-2">
        <button type="submit" class="rounded-md bg-violet-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-violet-500">Schedule</button>
        <button type="button" wire:click="$set('showScheduleForm', false)" class="rounded-md px-3 py-1.5 text-sm font-medium text-gray-700 hover:underline">Cancel</button>
    </div>
</form>
