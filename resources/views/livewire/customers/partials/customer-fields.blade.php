{{-- Customer form fields. Expects the component to have the customer properties, save(), and optionally $duplicateCustomerId. --}}
@php($field = 'mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
<form wire:submit="save" class="space-y-4 rounded-lg border border-gray-200 bg-white p-5 shadow-sm" aria-label="{{ ($customerId ?? null) ? 'Edit customer' : 'Add customer' }}" novalidate>
    <div class="grid gap-4 sm:grid-cols-2">
        @foreach ([['first_name', 'First name', 'text', 'given-name', true], ['last_name', 'Last name', 'text', 'family-name', true], ['email', 'Email', 'email', 'off', true], ['phone', 'Phone', 'tel', 'off', false], ['company', 'Company', 'text', 'off', false]] as [$name, $label, $type, $auto, $required])
            <div @class(['sm:col-span-2' => $name === 'company'])>
                <label for="customer-{{ $name }}" class="block text-sm font-medium text-gray-700">{{ $label }} @unless ($required)<span class="font-normal text-gray-500">(optional)</span>@endunless</label>
                <input id="customer-{{ $name }}" type="{{ $type }}" wire:model="{{ $name }}" autocomplete="{{ $auto }}" @if ($required) required aria-required="true" @endif
                       @error($name) aria-invalid="true" aria-describedby="customer-{{ $name }}-error" @enderror class="{{ $field }}">
                @error($name)
                    <p id="customer-{{ $name }}-error" class="mt-1 text-sm text-red-700">{{ $message }}
                        @if ($name === 'email' && ($duplicateCustomerId ?? null))
                            <a href="{{ route('customers.show', $duplicateCustomerId) }}" wire:navigate class="ml-1 font-medium text-violet-700 underline">View Customer</a>
                        @endif
                        @if ($name === 'email' && ($limitReached ?? false))
                            <x-billing.upgrade-link class="ml-1" />
                        @endif
                    </p>
                @enderror
            </div>
        @endforeach
        <div class="sm:col-span-2">
            <label for="customer-notes" class="block text-sm font-medium text-gray-700">Notes <span class="font-normal text-gray-500">(optional)</span></label>
            <textarea id="customer-notes" wire:model="notes" rows="3" maxlength="2000" class="{{ $field }}"></textarea>
            @error('notes') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        @if ($withStatus ?? false)
            <div>
                <label for="customer-status" class="block text-sm font-medium text-gray-700">Status</label>
                <select id="customer-status" wire:model="status" class="{{ $field }}">
                    @foreach (\App\Enums\CustomerStatus::cases() as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
                @error('status') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        @endif
    </div>
    <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 disabled:opacity-50">
        <span wire:loading.remove wire:target="save">{{ ($customerId ?? null) ? 'Save changes' : 'Add Customer' }}</span>
        <span wire:loading wire:target="save">Saving…</span>
    </button>
</form>
