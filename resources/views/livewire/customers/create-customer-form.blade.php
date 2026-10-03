<form wire:submit="save" class="space-y-3" aria-label="Add customer">
    <div>
        <label for="new-customer-name" class="block text-sm font-medium text-gray-700">Name</label>
        <input id="new-customer-name" type="text" wire:model="name" maxlength="255" autocomplete="off" required
               @error('name') aria-invalid="true" aria-describedby="new-customer-name-error" @enderror
               class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600">
        @error('name') <p id="new-customer-name-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
    </div>
    <div>
        <label for="new-customer-email" class="block text-sm font-medium text-gray-700">Email</label>
        <input id="new-customer-email" type="email" wire:model="email" maxlength="254" autocomplete="off" required
               @error('email') aria-invalid="true" aria-describedby="new-customer-email-error" @enderror
               class="mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600">
        @error('email') <p id="new-customer-email-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
    </div>
    <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 disabled:opacity-50">
        <span wire:loading.remove wire:target="save">Add Customer</span>
        <span wire:loading wire:target="save">Adding…</span>
    </button>
</form>
