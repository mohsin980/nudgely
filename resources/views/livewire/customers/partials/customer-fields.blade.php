{{-- Customer form fields. Expects the component to have the customer properties, save(), and optionally $duplicateCustomerId. --}}
@php($field = 'block w-full rounded-xl border-0 bg-white px-3.5 py-2.5 text-[15px] text-slate-900 shadow-none ring-1 ring-slate-200 ring-inset transition placeholder:text-slate-400 hover:ring-slate-300 focus:ring-2 focus:ring-violet-500 focus:ring-inset')
@php($fieldIcons = ['first_name' => 'user', 'last_name' => 'user', 'email' => 'envelope', 'phone' => 'phone', 'company' => 'building'])
@php($isEdit = ($customerId ?? null) !== null)
<form wire:submit="save" class="space-y-6 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_8px_24px_-12px_rgba(76,29,149,0.12)] sm:p-7" aria-label="{{ $isEdit ? 'Edit customer' : 'Add customer' }}" novalidate>
    <div class="grid gap-x-5 gap-y-5 sm:grid-cols-2">
        @foreach ([
            ['first_name', 'First name', 'text', 'given-name', true, 'e.g. Jane'],
            ['last_name', 'Last name', 'text', 'family-name', true, 'e.g. Smith'],
            ['email', 'Email', 'email', 'off', true, 'e.g. jane@example.com'],
            ['phone', 'Phone', 'tel', 'off', false, 'e.g. +1 555 123 4567'],
            ['company', 'Company', 'text', 'off', false, 'e.g. Acme Inc.'],
        ] as [$name, $label, $type, $auto, $required, $placeholder])
            @if ($name === 'first_name')
                <div class="flex items-start gap-3 sm:col-span-2">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-600 ring-1 ring-violet-100 ring-inset"><x-ui-icon name="user" class="size-5" /></span>
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">Contact</h2>
                        <p class="mt-0.5 text-sm text-slate-500">Name and email are needed. Everything else is optional.</p>
                    </div>
                </div>
            @elseif ($name === 'company')
                <div class="flex items-start gap-3 border-t border-slate-100 pt-5 sm:col-span-2">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-600 ring-1 ring-violet-100 ring-inset"><x-ui-icon name="briefcase" class="size-5" /></span>
                    <div>
                        <h2 class="text-base font-semibold text-slate-900">Details</h2>
                        <p class="mt-0.5 text-sm text-slate-500">Shown on the customer's page.</p>
                    </div>
                </div>
            @endif
            <div @class(['sm:col-span-2' => $name === 'company'])>
                <label for="customer-{{ $name }}" class="block text-sm font-semibold text-slate-800">{{ $label }} @if ($required)<span class="text-red-600" aria-hidden="true">*</span>@else<span class="font-normal text-slate-500">(optional)</span>@endif</label>
                <div class="relative mt-1.5">
                    <x-ui-icon :name="$fieldIcons[$name]" class="pointer-events-none absolute top-1/2 left-3.5 size-5 -translate-y-1/2 text-slate-400" />
                    <input id="customer-{{ $name }}" type="{{ $type }}" wire:model="{{ $name }}" autocomplete="{{ $auto }}" placeholder="{{ $placeholder }}" @if ($required) required aria-required="true" @endif
                       @error($name) aria-invalid="true" aria-describedby="customer-{{ $name }}-error" @enderror class="{{ $field }} pl-11">
                </div>
                @error($name)
                    <p id="customer-{{ $name }}-error" class="mt-1 text-sm text-red-600">{{ $message }}
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
            <label for="customer-notes" class="block text-sm font-semibold text-slate-800">Notes <span class="font-normal text-slate-500">(optional)</span></label>
            <textarea id="customer-notes" wire:model="notes" rows="3" maxlength="2000" placeholder="Add any notes about this customer…" class="{{ $field }} mt-1.5"></textarea>
            @error('notes') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        @if (auth()->user()->organization?->businessSettings()->usesChannel(\App\Enums\MessageChannel::WhatsApp))
            <div class="sm:col-span-2 grid gap-4 rounded-2xl bg-emerald-50/50 p-4 ring-1 ring-emerald-100 sm:grid-cols-2" data-section="customer-channel">
                <div>
                    <label for="customer-channel" class="block text-sm font-semibold text-slate-800">Follow up by</label>
                    <select id="customer-channel" wire:model="preferred_channel" class="{{ $field }} mt-1.5">
                        <option value="">Business default</option>
                        <option value="email">Email</option>
                        <option value="whatsapp">WhatsApp</option>
                    </select>
                    @error('preferred_channel') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-start gap-3 sm:pt-7">
                    <input type="checkbox" wire:model="whatsapp_consent" class="mt-0.5 size-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500" data-whatsapp-consent>
                    <span class="text-sm">
                        <span class="block font-semibold text-slate-800">Customer agreed to WhatsApp messages</span>
                        <span class="block text-slate-500">Required before QuoteFlow messages them on WhatsApp. Add the country code to the phone number if they live outside your country.</span>
                    </span>
                </label>
            </div>
        @endif
        @if ($withStatus ?? false)
            <div>
                <label for="customer-status" class="block text-sm font-semibold text-slate-800">Status</label>
                <select id="customer-status" wire:model="status" class="{{ $field }} mt-1.5">
                    @foreach (\App\Enums\CustomerStatus::cases() as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
                @error('status') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        @endif
    </div>
    <div class="flex flex-col-reverse gap-3 border-t border-slate-100 pt-5 sm:flex-row sm:justify-end">
        <a href="{{ $isEdit ? route('customers.show', $customerId) : route('customers.index') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-slate-200 ring-inset transition hover:bg-slate-50">Cancel</a>
        <button type="submit" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-1.5 rounded-xl px-5 py-2.5 text-sm font-semibold text-white shadow-[0_10px_24px_-10px_rgba(109,40,217,0.7)] transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 disabled:opacity-50" style="background: linear-gradient(90deg, #6a2cf2, #8b5cf6);">
            <span wire:loading.remove wire:target="save" class="inline-flex items-center gap-1.5">@unless ($isEdit)<x-ui-icon name="plus" class="size-4" />@endunless{{ $isEdit ? 'Save changes' : 'Add Customer' }}</span>
            <span wire:loading wire:target="save">Saving…</span>
        </button>
    </div>
</form>
