{{-- Customer form fields. Expects the component to have the customer properties, save(), and optionally $duplicateCustomerId. --}}
@php($field = 'mt-1.5 block w-full rounded-xl border-0 px-3.5 py-2.5 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset focus:ring-2 focus:ring-emerald-600 focus:ring-inset')
<form wire:submit="save" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs sm:p-6" aria-label="{{ ($customerId ?? null) ? 'Edit customer' : 'Add customer' }}" novalidate>
    <div>
        <h2 class="text-base font-semibold text-slate-900">Basic information</h2>
        <p class="mt-1 text-sm text-slate-500">Name and email are needed. Everything else is optional.</p>
    </div>
    <div class="grid gap-x-5 gap-y-5 sm:grid-cols-2">
        @foreach ([['first_name', 'First name', 'text', 'given-name', true], ['last_name', 'Last name', 'text', 'family-name', true], ['email', 'Email', 'email', 'off', true], ['phone', 'Phone', 'tel', 'off', false], ['company', 'Company', 'text', 'off', false]] as [$name, $label, $type, $auto, $required])
            <div @class(['sm:col-span-2' => $name === 'company'])>
                <label for="customer-{{ $name }}" class="block text-sm font-medium text-slate-700">{{ $label }} @unless ($required)<span class="font-normal text-slate-500">(optional)</span>@endunless</label>
                <input id="customer-{{ $name }}" type="{{ $type }}" wire:model="{{ $name }}" autocomplete="{{ $auto }}" @if ($required) required aria-required="true" @endif
                       @error($name) aria-invalid="true" aria-describedby="customer-{{ $name }}-error" @enderror class="{{ $field }}">
                @error($name)
                    <p id="customer-{{ $name }}-error" class="mt-1 text-sm text-red-600">{{ $message }}
                        @if ($name === 'email' && ($duplicateCustomerId ?? null))
                            <a href="{{ route('customers.show', $duplicateCustomerId) }}" wire:navigate class="ml-1 font-medium text-emerald-700 underline">View Customer</a>
                        @endif
                        @if ($name === 'email' && ($limitReached ?? false))
                            <x-billing.upgrade-link class="ml-1" />
                        @endif
                    </p>
                @enderror
            </div>
        @endforeach
        <div class="sm:col-span-2">
            <label for="customer-notes" class="block text-sm font-medium text-slate-700">Notes <span class="font-normal text-slate-500">(optional)</span></label>
            <textarea id="customer-notes" wire:model="notes" rows="3" maxlength="2000" class="{{ $field }}"></textarea>
            @error('notes') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        @if ($withStatus ?? false)
            <div>
                <label for="customer-status" class="block text-sm font-medium text-slate-700">Status</label>
                <select id="customer-status" wire:model="status" class="{{ $field }}">
                    @foreach (\App\Enums\CustomerStatus::cases() as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
                @error('status') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        @endif
    </div>
    <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
        <a href="{{ ($customerId ?? null) ? route('customers.show', $customerId) : route('customers.index') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl bg-white px-4 py-2.5 text-sm font-medium text-slate-700 shadow-xs ring-1 ring-slate-300 ring-inset transition hover:bg-slate-50">Cancel</a>
        <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:opacity-50">
            <span wire:loading.remove wire:target="save">{{ ($customerId ?? null) ? 'Save changes' : 'Add Customer' }}</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </div>
</form>
