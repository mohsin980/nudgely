@php($field = 'mt-1 block w-full rounded-xl border-0 px-3.5 py-2.5 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
<x-settings.shell title="Automation defaults" description="Who automations work for when an automation doesn't say. Email safety stays under Automations and is the owner's call.">
    @include('livewire.settings.partials.status')

    <form wire:submit="save" x-data="unsavedChanges" class="space-y-4 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_8px_24px_-12px_rgba(76,29,149,0.12)]">
        @foreach ([
            'automationOwnerId' => ['Default automation owner', 'Stands in when the person who created an automation is suspended or no longer on the team (e.g. “Email me” and “Assign to me”).', 'The business owner'],
            'taskAssigneeId' => ['Default task assignee', 'Automation tasks that don\'t name anyone are assigned to this person.', 'Nobody (unassigned)'],
            'notifyUserId' => ['Default notification recipient', '“Notify the business” automation actions notify this person.', 'The owner and managers'],
        ] as $property => [$fieldLabel, $help, $none])
            <div>
                <label for="{{ $property }}" class="block text-sm font-medium text-slate-700">{{ $fieldLabel }}</label>
                <select id="{{ $property }}" wire:model="{{ $property }}" class="{{ $field }}">
                    <option value="">{{ $none }}</option>
                    @foreach ($members as $userId => $userName)<option value="{{ $userId }}">{{ $userName }}</option>@endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">{{ $help }}</p>
                @error($property) <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        @endforeach
        <div class="flex justify-end"><button type="submit" class="rounded-xl bg-violet-600 bg-linear-to-r from-violet-600 to-violet-500 px-4 py-2 text-sm font-semibold text-white hover:brightness-110 shadow-[0_8px_20px_-8px_rgba(109,40,217,0.6)]">Save</button></div>
    </form>

    @include('livewire.settings.partials.history')
</x-settings.shell>
