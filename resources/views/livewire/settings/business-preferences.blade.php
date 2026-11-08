@php($field = 'mt-1.5 block w-full rounded-xl border-0 bg-white px-3.5 py-2.5 text-[15px] text-slate-900 shadow-xs ring-1 ring-slate-200 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
@php($card = 'rounded-2xl bg-white p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70 sm:p-6')
@php($label = 'block text-sm font-semibold text-slate-800')
@php($help = 'mt-1 text-xs text-slate-500')
@php($primaryButton = 'inline-flex items-center justify-center gap-2 rounded-xl bg-linear-to-r from-violet-600 to-violet-500 px-5 py-2.5 text-sm font-semibold text-white shadow-[0_10px_22px_-8px_rgba(109,40,217,0.65)] transition hover:from-violet-700 hover:to-violet-600 disabled:opacity-50')
@php($time = 'w-28 rounded-lg border-0 bg-white px-2 py-1 text-sm text-slate-900 shadow-xs ring-1 ring-slate-200 ring-inset focus:ring-2 focus:ring-violet-600')
<x-settings.shell title="Business Preferences" description="Timezone, currency and formats used across QuoteFollow, and your opening hours.">
    @include('livewire.settings.partials.status')

    <form wire:submit="saveAll" x-data="unsavedChanges" class="space-y-5" aria-label="Business preferences">
        <div class="grid gap-5 2xl:grid-cols-2">
            <section class="{{ $card }} space-y-4" aria-labelledby="prefs-heading">
                <h2 id="prefs-heading" class="text-lg font-bold text-slate-900">Regional settings</h2>
                <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,11rem)]">
                    <div>
                        <label for="timezone" class="{{ $label }}">Timezone</label>
                        <select id="timezone" wire:model.live="timezone" class="{{ $field }}">
                            @foreach ($timezoneGroups as $groupLabel => $zones)
                                <optgroup label="{{ $groupLabel }}">
                                    @foreach ($zones as $zone => $zoneLabel)<option value="{{ $zone }}">{{ $zoneLabel }}</option>@endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs" x-data="{ device: (() => { try { return Intl.DateTimeFormat().resolvedOptions().timeZone } catch (e) { return '' } })() }">
                            @if ($localTime)<span class="text-slate-600" data-local-time>Local time now: <strong class="font-semibold text-slate-800">{{ $localTime->format(($timeFormat === '24h' ? 'H:i' : 'g:i A').', D') }}</strong></span>@endif
                            <button type="button" x-show="device && device !== $wire.timezone" x-cloak x-on:click="$wire.set('timezone', device)" class="font-semibold text-violet-700 hover:underline">Use this device's timezone (<span x-text="device"></span>)</button>
                        </div>
                        @if ($outsideCountry)<p class="mt-1 text-xs text-amber-700" data-timezone-warning>This timezone isn't in {{ $outsideCountry }}. That's fine if it's where you work; QuoteFlow will use it.</p>@endif
                        @error('timezone') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div><label for="task-priority" class="{{ $label }}">Default task priority</label>
                        <select id="task-priority" wire:model="taskPriority" class="{{ $field }}">@foreach ($priorities as $priority)<option value="{{ $priority->value }}">{{ ucfirst($priority->value) }}</option>@endforeach</select>
                        @error('taskPriority') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                </div>
                <p class="-mt-2 {{ $help }}">Follow-ups, reminders, automations, business hours and every date in QuoteFlow use this timezone. Pick any timezone; your country's are listed first.</p>
                <div><label for="currency" class="{{ $label }}">Currency</label>
                    <select id="currency" wire:model="currency" class="{{ $field }}">@foreach ($currencies as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</select>
                    <p class="{{ $help }}">Used for new estimates. No currency conversion.</p>
                    @error('currency') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div><label for="date-format" class="{{ $label }}">Default date format</label>
                    <select id="date-format" wire:model="dateFormat" class="{{ $field }}">@foreach ($dateFormats as $format => $name)<option value="{{ $format }}">{{ $name }}</option>@endforeach</select>
                    @error('dateFormat') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div><label for="time-format" class="{{ $label }}">Time format</label>
                    <select id="time-format" wire:model="timeFormat" class="{{ $field }}">@foreach ($timeFormats as $format => $name)<option value="{{ $format }}">{{ $name }}</option>@endforeach</select>
                    @error('timeFormat') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            </section>

            <section class="{{ $card }} space-y-3" aria-labelledby="hours-heading">
                <div>
                    <h2 id="hours-heading" class="text-lg font-bold text-slate-900">Business hours</h2>
                    <p class="mt-0.5 text-sm text-slate-500">Your opening hours in {{ $organization->timezone() }}.</p>
                </div>
                <ul role="list" class="divide-y divide-slate-100">
                    @foreach ($days as $day => $dayName)
                        @php($open = $hours[$day]['open'] ?? false)
                        <li class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2" wire:key="hours-{{ $day }}">
                            <label class="flex w-32 cursor-pointer items-center gap-2.5 text-sm font-semibold text-slate-900">
                                <input type="checkbox" wire:model.live="hours.{{ $day }}.open" class="peer sr-only">
                                <span @class(['grid size-5 place-items-center rounded-md ring-1 ring-inset peer-focus-visible:ring-2 peer-focus-visible:ring-violet-500', 'bg-emerald-50 text-emerald-600 ring-emerald-200' => $open, 'bg-white ring-slate-300' => ! $open]) aria-hidden="true">@if ($open)<x-ui-icon name="check" class="size-3.5 stroke-2" />@endif</span>
                                {{ $dayName }}
                            </label>
                            @if ($open)
                                <div class="ml-auto flex items-center gap-1.5">
                                    <label class="sr-only" for="hours-{{ $day }}-start">{{ $dayName }} opens</label>
                                    <input id="hours-{{ $day }}-start" type="time" wire:model="hours.{{ $day }}.start" class="{{ $time }}">
                                    <x-ui-icon name="arrow-right" class="size-3.5 text-slate-400" /><span class="sr-only">to</span>
                                    <label class="sr-only" for="hours-{{ $day }}-end">{{ $dayName }} closes</label>
                                    <input id="hours-{{ $day }}-end" type="time" wire:model="hours.{{ $day }}.end" class="{{ $time }}">
                                </div>
                            @else
                                <span class="ml-auto text-sm text-slate-500">Closed</span>
                            @endif
                            @error("hours.{$day}") <p role="alert" class="w-full text-sm text-red-700">{{ $message }}</p> @enderror
                        </li>
                    @endforeach
                </ul>
                <p class="text-xs text-slate-500">Stored for scheduling; automations don't move sends into these hours yet.</p>
            </section>
        </div>
        <div class="flex justify-end"><button type="submit" class="{{ $primaryButton }}"><x-ui-icon name="check" class="size-4 stroke-2" />Save Changes</button></div>
    </form>

    @include('livewire.settings.partials.history')
</x-settings.shell>
