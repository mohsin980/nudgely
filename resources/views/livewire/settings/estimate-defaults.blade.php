@php($field = 'mt-1 block w-full rounded-xl border-0 px-3.5 py-2.5 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
<x-settings.shell title="Quote defaults" description="Pre-filled on every new estimate. Anything you change on an estimate is kept.">
    @include('livewire.settings.partials.status')

    <form wire:submit="save" x-data="unsavedChanges" class="space-y-4 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_8px_24px_-12px_rgba(76,29,149,0.12)]">
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="valid-days" class="block text-sm font-medium text-slate-700">Estimate valid for (days)</label>
                <input id="valid-days" type="number" min="1" max="365" wire:model="validDays" class="{{ $field }}">
                @error('validDays') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div><label for="tax-rate" class="block text-sm font-medium text-slate-700">Default tax rate (%) <span class="font-normal text-slate-500">(optional)</span></label>
                <input id="tax-rate" type="text" inputmode="decimal" wire:model="taxRate" placeholder="8.25" class="{{ $field }}">
                @error('taxRate') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div class="sm:col-span-2"><label for="default-notes" class="block text-sm font-medium text-slate-700">Default notes <span class="font-normal text-slate-500">(optional)</span></label>
                <textarea id="default-notes" rows="4" maxlength="2000" wire:model="notes" placeholder="Thank you for considering our services." class="{{ $field }}"></textarea>
                @error('notes') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
        </div>
        <p class="text-sm text-slate-500">Currency: <span class="font-medium text-slate-900">{{ $organization->currencyCode() }}</span>@can('manage-business-profile') · <a href="{{ route('settings.preferences') }}" wire:navigate class="text-violet-700 hover:underline">Change in Business Preferences</a>@endcan</p>
        <div class="flex justify-end"><button type="submit" class="rounded-xl bg-violet-600 bg-linear-to-r from-violet-600 to-violet-500 px-4 py-2 text-sm font-semibold text-white hover:brightness-110 shadow-[0_8px_20px_-8px_rgba(109,40,217,0.6)]">Save</button></div>
    </form>

    @include('livewire.settings.partials.history')
</x-settings.shell>
