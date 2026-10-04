@php($card = 'rounded-lg border border-gray-200 bg-white p-5 shadow-sm')
<x-settings.shell title="Notifications" description="Choose what you hear about, in QuoteFollow and by email.">
    @include('livewire.settings.partials.status')

    @unless ($canSendEmail)
        <p class="rounded-md bg-amber-50 p-3 text-sm text-amber-900">Email notifications start once the business has a verified sender email. In-app notifications work now.</p>
    @endunless

    <form wire:submit="save" x-data="unsavedChanges" class="{{ $card }} space-y-4" aria-labelledby="mine-heading">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 id="mine-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">My notifications</h2>
                @if ($usingDefaults)
                    <p class="mt-1 text-sm text-gray-600">You're using the default notification settings.</p>
                @else
                    <p class="mt-1 text-sm text-gray-600">Your own choices. Anything you haven't changed follows the business defaults.</p>
                @endif
            </div>
            @unless ($usingDefaults)
                <button type="button" wire:click="useDefaults" class="text-sm font-medium text-indigo-700 hover:underline">Use business defaults</button>
            @endunless
        </div>
        @include('livewire.settings.partials.notification-matrix', ['model' => 'mine'])
        <div class="flex justify-end"><button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Save</button></div>
    </form>

    @can('manage-business-defaults')
        <form wire:submit="saveDefaults" x-data="unsavedChanges" class="{{ $card }} space-y-4" aria-labelledby="defaults-heading">
            <div>
                <h2 id="defaults-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Business defaults</h2>
                <p class="mt-1 text-sm text-gray-600">For everyone on the team who hasn't chosen otherwise.</p>
            </div>
            @include('livewire.settings.partials.notification-matrix', ['model' => 'defaults'])
            <div class="flex justify-end"><button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Save defaults</button></div>
        </form>

        @include('livewire.settings.partials.history')
    @endcan
</x-settings.shell>
