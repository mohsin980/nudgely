@php($field = 'mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 focus:ring-inset')
<x-settings.shell title="Estimate Defaults" description="Pre-filled on every new estimate. Anything you change on an estimate is kept.">
    @include('livewire.settings.partials.status')

    <form wire:submit="save" x-data="unsavedChanges" class="space-y-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm">
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="valid-days" class="block text-sm font-medium text-gray-700">Estimate valid for (days)</label>
                <input id="valid-days" type="number" min="1" max="365" wire:model="validDays" class="{{ $field }}">
                @error('validDays') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div><label for="tax-rate" class="block text-sm font-medium text-gray-700">Default tax rate (%) <span class="font-normal text-gray-500">(optional)</span></label>
                <input id="tax-rate" type="text" inputmode="decimal" wire:model="taxRate" placeholder="8.25" class="{{ $field }}">
                @error('taxRate') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div class="sm:col-span-2"><label for="default-notes" class="block text-sm font-medium text-gray-700">Default notes <span class="font-normal text-gray-500">(optional)</span></label>
                <textarea id="default-notes" rows="4" maxlength="2000" wire:model="notes" placeholder="Thank you for considering our services." class="{{ $field }}"></textarea>
                @error('notes') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
        </div>
        <p class="text-sm text-gray-600">Currency: <span class="font-medium text-gray-900">{{ $organization->currencyCode() }}</span>@can('manage-business-profile') · <a href="{{ route('settings.preferences') }}" wire:navigate class="text-indigo-700 hover:underline">Change in Business Preferences</a>@endcan</p>
        <div class="flex justify-end"><button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Save</button></div>
    </form>

    @include('livewire.settings.partials.history')
</x-settings.shell>
