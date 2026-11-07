@php($field = 'mt-1.5 block w-full rounded-xl border-0 bg-white px-3.5 py-2.5 text-[15px] text-slate-900 shadow-xs ring-1 ring-slate-200 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
<x-settings.shell title="Automation defaults" description="Who automations work for when an automation doesn't say. Email safety stays under Automations and is the owner's call.">
    @include('livewire.settings.partials.status')

    <form wire:submit="save" x-data="unsavedChanges" class="space-y-5 rounded-2xl bg-white p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70 sm:p-6">
        @foreach ([
            'automationOwnerId' => ['Default automation owner', 'Stands in when the person who created an automation is suspended or no longer on the team (e.g. “Email me” and “Assign to me”).', 'The business owner'],
            'taskAssigneeId' => ['Default task assignee', 'Automation tasks that don\'t name anyone are assigned to this person.', 'Nobody (unassigned)'],
            'notifyUserId' => ['Default notification recipient', '“Notify the business” automation actions notify this person.', 'The owner and managers'],
        ] as $property => [$fieldLabel, $help, $none])
            <div>
                <label for="{{ $property }}" class="block text-sm font-semibold text-slate-800">{{ $fieldLabel }}</label>
                <select id="{{ $property }}" wire:model="{{ $property }}" class="{{ $field }}">
                    <option value="">{{ $none }}</option>
                    @foreach ($members as $userId => $userName)<option value="{{ $userId }}">{{ $userName }}</option>@endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">{{ $help }}</p>
                @error($property) <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        @endforeach
        <div class="flex justify-end"><button type="submit" class="inline-flex items-center gap-2 rounded-xl bg-linear-to-r from-violet-600 to-violet-500 px-5 py-2.5 text-sm font-semibold text-white shadow-[0_10px_22px_-8px_rgba(109,40,217,0.65)] hover:from-violet-700 hover:to-violet-600"><x-ui-icon name="check" class="size-4 stroke-2" />Save Changes</button></div>
    </form>

    @include('livewire.settings.partials.history')
</x-settings.shell>
