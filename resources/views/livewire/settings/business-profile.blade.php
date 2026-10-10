@php($field = 'mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
@php($card = 'rounded-lg border border-gray-200 bg-white p-5 shadow-sm')
@php($label = 'block text-sm font-medium text-gray-700')
<x-settings.shell title="Business Profile" description="How your business appears to customers on estimates and emails.">
    @include('livewire.settings.partials.status')

    <form wire:submit="save" x-data="unsavedChanges" class="{{ $card }} space-y-5" aria-labelledby="profile-heading">
        <h2 id="profile-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Business details</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="business-name" class="{{ $label }}">Business name</label><input id="business-name" type="text" wire:model="name" maxlength="100" required class="{{ $field }}">@error('name') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div><label for="legal-name" class="{{ $label }}">Legal name <span class="font-normal text-gray-500">(optional)</span></label><input id="legal-name" type="text" wire:model="legalName" maxlength="150" class="{{ $field }}">@error('legalName') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div><label for="business-email" class="{{ $label }}">Business email <span class="font-normal text-gray-500">(optional)</span></label><input id="business-email" type="email" wire:model="email" maxlength="255" placeholder="hello@dallashvac.com" class="{{ $field }}">@error('email') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div><label for="business-phone" class="{{ $label }}">Phone <span class="font-normal text-gray-500">(optional)</span></label><input id="business-phone" type="tel" wire:model="phone" maxlength="32" placeholder="(214) 555-1234" class="{{ $field }}">@error('phone') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div class="sm:col-span-2"><label for="business-website" class="{{ $label }}">Website <span class="font-normal text-gray-500">(optional)</span></label><input id="business-website" type="url" wire:model="website" maxlength="255" placeholder="https://dallashvac.com" class="{{ $field }}">@error('website') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
        </div>

        <fieldset class="space-y-4">
            <legend class="text-sm font-semibold text-gray-900">Address <span class="font-normal text-gray-500">(optional)</span></legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><label for="address-line1" class="{{ $label }}">Address line 1</label><input id="address-line1" type="text" wire:model="addressLine1" maxlength="150" autocomplete="address-line1" class="{{ $field }}">@error('addressLine1') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div class="sm:col-span-2"><label for="address-line2" class="{{ $label }}">Address line 2</label><input id="address-line2" type="text" wire:model="addressLine2" maxlength="150" autocomplete="address-line2" class="{{ $field }}">@error('addressLine2') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div><label for="city" class="{{ $label }}">City</label><input id="city" type="text" wire:model="city" maxlength="100" autocomplete="address-level2" class="{{ $field }}">@error('city') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div><label for="state" class="{{ $label }}">State / province</label><input id="state" type="text" wire:model="state" maxlength="50" autocomplete="address-level1" class="{{ $field }}">@error('state') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div><label for="postal-code" class="{{ $label }}">ZIP / postal code</label><input id="postal-code" type="text" wire:model="postalCode" maxlength="20" autocomplete="postal-code" class="{{ $field }}">@error('postalCode') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div><label for="country" class="{{ $label }}">Country</label>
                    <select id="country" wire:model="country" class="{{ $field }}">@foreach ($countries as $code => $countryName)<option value="{{ $code }}">{{ $countryName }}</option>@endforeach</select>
                    @error('country') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            </div>
        </fieldset>

        <div class="flex justify-end">
            <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-violet-600 px-4 py-2 text-sm font-semibold text-white hover:bg-violet-500 disabled:opacity-50">Save</button>
        </div>
    </form>

    <section aria-labelledby="logo-heading" class="{{ $card }} space-y-4">
        <h2 id="logo-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Logo</h2>
        @if ($organization->logoUrl())
            <div class="flex flex-wrap items-center gap-4">
                <img src="{{ $organization->logoUrl() }}" alt="{{ $organization->name }} logo" class="h-16 max-w-48 rounded border border-gray-200 bg-white object-contain p-1">
                <button type="button" wire:click="removeLogo" wire:confirm="Remove the logo?" class="text-sm font-medium text-red-700 hover:underline">Remove logo</button>
            </div>
        @else
            <p class="text-sm text-gray-600">Upload your business logo.</p>
        @endif
        <form wire:submit="uploadLogo" class="flex flex-wrap items-end gap-3">
            <div>
                <label for="logo" class="{{ $label }}">{{ $organization->logoUrl() ? 'Replace logo' : 'Logo' }}</label>
                <input id="logo" type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="mt-1 block text-sm text-gray-700">
                <p class="mt-1 text-xs text-gray-500">PNG, JPEG or WebP, up to 2 MB.</p>
            </div>
            <button type="submit" wire:loading.attr="disabled" wire:target="logo,uploadLogo" class="rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50 disabled:opacity-50">Upload</button>
        </form>
        @error('logo') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
    </section>

    <section aria-labelledby="sender-heading" class="{{ $card }} space-y-3">
        <h2 id="sender-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Business email sender</h2>
        @if ($sender)
            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                <div><dt class="text-gray-500">From</dt><dd class="font-medium text-gray-900">{{ $sender->sender_email }}</dd></div>
                <div><dt class="text-gray-500">Status</dt><dd class="font-medium {{ $sender->verification_status === \App\Enums\EmailVerificationStatus::Verified ? 'text-green-700' : 'text-amber-700' }}">{{ $sender->verification_status->label() }}</dd></div>
            </dl>
        @else
            <p class="text-sm text-gray-600">No sender email yet. Customers can't be emailed until one is verified.</p>
        @endif
        @can('manage-email')
            <a href="{{ route('settings.email') }}" wire:navigate class="inline-flex rounded-md bg-white px-3 py-1.5 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50">Manage Email</a>
        @endcan
    </section>

    @include('livewire.settings.partials.history')
</x-settings.shell>
