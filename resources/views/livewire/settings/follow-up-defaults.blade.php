@php($field = 'mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 focus:ring-inset')
<x-settings.shell title="Follow-up Defaults" description="When new follow-ups are due unless you pick another time.">
    @include('livewire.settings.partials.status')

    <form wire:submit="save" x-data="unsavedChanges" class="space-y-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="delay-days" class="block text-sm font-medium text-gray-700">Default delay (days)</label>
                <input id="delay-days" type="number" min="1" max="60" wire:model="delayDays" class="{{ $field }}">
                <p class="mt-1 text-xs text-gray-500">A new follow-up is due this many days from today.</p>
                @error('delayDays') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div><label for="follow-up-time" class="block text-sm font-medium text-gray-700">Default time</label>
                <input id="follow-up-time" type="time" wire:model="time" class="{{ $field }}">
                <p class="mt-1 text-xs text-gray-500">In {{ $organization->timezone() }}.</p>
                @error('time') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
        </div>
        <div class="flex justify-end"><button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Save</button></div>
    </form>

    @include('livewire.settings.partials.history')
</x-settings.shell>
