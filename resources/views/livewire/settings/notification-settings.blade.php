@php($card = 'rounded-2xl border border-slate-200 bg-white p-5 shadow-xs sm:p-6')
<x-settings.shell title="Notifications" description="Choose what you hear about, in QuoteFollow and by email.">
    @include('livewire.settings.partials.status')

    @unless ($canSendEmail)
        <p class="rounded-lg bg-amber-50 p-4 text-sm text-amber-900">Email notifications start once the business has a verified sender email. In-app notifications work now.</p>
    @endunless

    <form wire:submit="save" x-data="unsavedChanges" class="{{ $card }} space-y-4" aria-labelledby="mine-heading">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 id="mine-heading" class="text-base font-semibold text-slate-900">My notifications</h2>
                @if ($usingDefaults)
                    <p class="mt-1 text-sm text-slate-500">You're using the default notification settings.</p>
                @else
                    <p class="mt-1 text-sm text-slate-500">Your own choices. Anything you haven't changed follows the business defaults.</p>
                @endif
            </div>
            @unless ($usingDefaults)
                <button type="button" wire:click="useDefaults" class="text-sm font-medium text-violet-700 hover:underline">Use business defaults</button>
            @endunless
        </div>
        @include('livewire.settings.partials.notification-matrix', ['model' => 'mine'])
        <div class="flex justify-end"><button type="submit" class="rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-violet-700">Save</button></div>
    </form>

    @can('manage-business-defaults')
        <form wire:submit="saveDefaults" x-data="unsavedChanges" class="{{ $card }} space-y-4" aria-labelledby="defaults-heading">
            <div>
                <h2 id="defaults-heading" class="text-base font-semibold text-slate-900">Business defaults</h2>
                <p class="mt-1 text-sm text-slate-500">For everyone on the team who hasn't chosen otherwise.</p>
            </div>
            @include('livewire.settings.partials.notification-matrix', ['model' => 'defaults'])
            <div class="flex justify-end"><button type="submit" class="rounded-xl bg-violet-600 px-5 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-violet-700">Save defaults</button></div>
        </form>

        @include('livewire.settings.partials.history')
    @endcan
</x-settings.shell>
