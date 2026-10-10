@php($field = 'mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
<x-settings.shell title="Automation Defaults" description="Who automations work for when an automation doesn't say. Email safety stays under Automations and is the owner's call.">
    @include('livewire.settings.partials.status')

    <form wire:submit="save" x-data="unsavedChanges" class="space-y-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        @foreach ([
            'automationOwnerId' => ['Default automation owner', 'Stands in when the person who created an automation is suspended or no longer on the team (e.g. “Email me” and “Assign to me”).', 'The business owner'],
            'taskAssigneeId' => ['Default task assignee', 'Automation tasks that don\'t name anyone are assigned to this person.', 'Nobody (unassigned)'],
            'notifyUserId' => ['Default notification recipient', '“Notify the business” automation actions notify this person.', 'The owner and managers'],
        ] as $property => [$fieldLabel, $help, $none])
            <div>
                <label for="{{ $property }}" class="block text-sm font-medium text-gray-700">{{ $fieldLabel }}</label>
                <select id="{{ $property }}" wire:model="{{ $property }}" class="{{ $field }}">
                    <option value="">{{ $none }}</option>
                    @foreach ($members as $userId => $userName)<option value="{{ $userId }}">{{ $userName }}</option>@endforeach
                </select>
                <p class="mt-1 text-xs text-gray-500">{{ $help }}</p>
                @error($property) <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        @endforeach
        <div class="flex justify-end"><button type="submit" class="rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">Save</button></div>
    </form>

    @include('livewire.settings.partials.history')
</x-settings.shell>
