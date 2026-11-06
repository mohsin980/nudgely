@php($field = 'mt-1 block w-full rounded-xl border-0 px-3.5 py-2.5 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
@php($card = 'rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_8px_24px_-12px_rgba(76,29,149,0.12)]')
@php($label = 'block text-sm font-medium text-slate-700')
<x-settings.shell title="Business Preferences" description="Timezone, currency and formats used across QuoteFollow, and your opening hours.">
    @include('livewire.settings.partials.status')

    <form wire:submit="save" x-data="unsavedChanges" class="{{ $card }} space-y-4" aria-labelledby="prefs-heading">
        <h2 id="prefs-heading" class="text-base font-bold text-slate-900">Regional settings</h2>
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
                <p class="mt-1 text-xs text-slate-500">Follow-ups, automations and business hours use this timezone.</p>
                @error('timezone') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
            <div><label for="currency" class="{{ $label }}">Currency</label>
                <select id="currency" wire:model="currency" class="{{ $field }}">@foreach ($currencies as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</select>
                <p class="mt-1 text-xs text-slate-500">Used for new estimates. No currency conversion.</p>
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
        <div class="flex justify-end"><button type="submit" class="rounded-xl bg-violet-600 bg-linear-to-r from-violet-600 to-violet-500 px-4 py-2 text-sm font-semibold text-white hover:brightness-110 shadow-[0_8px_20px_-8px_rgba(109,40,217,0.6)]">Save</button></div>
    </form>

    <form wire:submit="saveHours" x-data="unsavedChanges" class="{{ $card }} space-y-4" aria-labelledby="hours-heading">
        <div>
            <h2 id="hours-heading" class="text-base font-bold text-slate-900">Business hours</h2>
            <p class="mt-1 text-sm text-slate-500">Your opening hours in {{ $organization->timezone() }}. Stored for scheduling; automations don't move sends into these hours yet.</p>
        </div>
        <ul role="list" class="divide-y divide-slate-100">
            @foreach ($days as $day => $dayName)
                <li class="flex flex-wrap items-center gap-3 py-2" wire:key="hours-{{ $day }}">
                    <label class="flex w-36 items-center gap-2 text-sm font-medium text-slate-900">
                        <input type="checkbox" wire:model.live="hours.{{ $day }}.open" class="rounded border-slate-300"> {{ $dayName }}
                    </label>
                    @if ($hours[$day]['open'] ?? false)
                        <label class="sr-only" for="hours-{{ $day }}-start">{{ $dayName }} opens</label>
                        <input id="hours-{{ $day }}-start" type="time" wire:model="hours.{{ $day }}.start" class="rounded-lg border-0 px-2 py-1 text-sm ring-1 ring-slate-300 ring-inset">
                        <span class="text-sm text-slate-500">to</span>
                        <label class="sr-only" for="hours-{{ $day }}-end">{{ $dayName }} closes</label>
                        <input id="hours-{{ $day }}-end" type="time" wire:model="hours.{{ $day }}.end" class="rounded-lg border-0 px-2 py-1 text-sm ring-1 ring-slate-300 ring-inset">
                    @else
                        <span class="text-sm text-slate-500">Closed</span>
                    @endif
                    @error("hours.{$day}") <p role="alert" class="w-full text-sm text-red-700">{{ $message }}</p> @enderror
                </li>
            @endforeach
        </ul>
        <div class="flex justify-end"><button type="submit" class="rounded-xl bg-violet-600 bg-linear-to-r from-violet-600 to-violet-500 px-4 py-2 text-sm font-semibold text-white hover:brightness-110 shadow-[0_8px_20px_-8px_rgba(109,40,217,0.6)]">Save hours</button></div>
    </form>

    @include('livewire.settings.partials.history')
</x-settings.shell>
