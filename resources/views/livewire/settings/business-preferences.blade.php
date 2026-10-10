@php($field = 'mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
@php($card = 'rounded-lg border border-gray-200 bg-white p-5 shadow-sm')
@php($label = 'block text-sm font-medium text-gray-700')
<x-settings.shell title="Business Preferences" description="Timezone, currency and formats used across QuoteFollow, and your opening hours.">
    @include('livewire.settings.partials.status')

    <form wire:submit="save" x-data="unsavedChanges" class="{{ $card }} space-y-4" aria-labelledby="prefs-heading">
        <h2 id="prefs-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Regional settings</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="timezone" class="{{ $label }}">Timezone</label>
                <select id="timezone" wire:model="timezone" class="{{ $field }}">
                    <optgroup label="United States">
                        @foreach ($commonTimezones as $zone => $zoneLabel)<option value="{{ $zone }}">{{ $zoneLabel }} ({{ $zone }})</option>@endforeach
                    </optgroup>
                    <optgroup label="All timezones">
                        @foreach ($allTimezones as $zone)@unless (isset($commonTimezones[$zone]))<option value="{{ $zone }}">{{ $zone }}</option>@endunless @endforeach
                    </optgroup>
                </select>
                <p class="mt-1 text-xs text-gray-500">Follow-ups, automations and business hours use this timezone.</p>
                @error('timezone') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div><label for="currency" class="{{ $label }}">Currency</label>
                <select id="currency" wire:model="currency" class="{{ $field }}">@foreach ($currencies as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</select>
                <p class="mt-1 text-xs text-gray-500">Used for new estimates. No currency conversion.</p>
                @error('currency') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div><label for="task-priority" class="{{ $label }}">Default task priority</label>
                <select id="task-priority" wire:model="taskPriority" class="{{ $field }}">@foreach ($priorities as $priority)<option value="{{ $priority->value }}">{{ ucfirst($priority->value) }}</option>@endforeach</select>
                @error('taskPriority') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div><label for="date-format" class="{{ $label }}">Date format</label>
                <select id="date-format" wire:model="dateFormat" class="{{ $field }}">@foreach ($dateFormats as $format => $name)<option value="{{ $format }}">{{ $name }}</option>@endforeach</select>
                @error('dateFormat') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div><label for="time-format" class="{{ $label }}">Time format</label>
                <select id="time-format" wire:model="timeFormat" class="{{ $field }}">@foreach ($timeFormats as $format => $name)<option value="{{ $format }}">{{ $name }}</option>@endforeach</select>
                @error('timeFormat') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
        </div>
        <div class="flex justify-end"><button type="submit" class="rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">Save</button></div>
    </form>

    <form wire:submit="saveHours" x-data="unsavedChanges" class="{{ $card }} space-y-4" aria-labelledby="hours-heading">
        <div>
            <h2 id="hours-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Business hours</h2>
            <p class="mt-1 text-sm text-gray-600">Your opening hours in {{ $organization->timezone() }}. Stored for scheduling; automations don't move sends into these hours yet.</p>
        </div>
        <ul role="list" class="divide-y divide-gray-100">
            @foreach ($days as $day => $dayName)
                <li class="flex flex-wrap items-center gap-3 py-2" wire:key="hours-{{ $day }}">
                    <label class="flex w-36 items-center gap-2 text-sm font-medium text-gray-900">
                        <input type="checkbox" wire:model.live="hours.{{ $day }}.open" class="rounded border-gray-300"> {{ $dayName }}
                    </label>
                    @if ($hours[$day]['open'] ?? false)
                        <label class="sr-only" for="hours-{{ $day }}-start">{{ $dayName }} opens</label>
                        <input id="hours-{{ $day }}-start" type="time" wire:model="hours.{{ $day }}.start" class="rounded-md border-0 px-2 py-1 text-sm ring-1 ring-gray-300 ring-inset">
                        <span class="text-sm text-gray-500">to</span>
                        <label class="sr-only" for="hours-{{ $day }}-end">{{ $dayName }} closes</label>
                        <input id="hours-{{ $day }}-end" type="time" wire:model="hours.{{ $day }}.end" class="rounded-md border-0 px-2 py-1 text-sm ring-1 ring-gray-300 ring-inset">
                    @else
                        <span class="text-sm text-gray-500">Closed</span>
                    @endif
                    @error("hours.{$day}") <p role="alert" class="w-full text-sm text-red-700">{{ $message }}</p> @enderror
                </li>
            @endforeach
        </ul>
        <div class="flex justify-end"><button type="submit" class="rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500">Save hours</button></div>
    </form>

    @include('livewire.settings.partials.history')
</x-settings.shell>
