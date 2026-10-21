@php($card = 'overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xs')
@php($header = 'flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4 sm:px-6')
@php($body = 'px-3 py-1 sm:px-4')
@php($footer = 'flex justify-end border-t border-slate-100 bg-slate-50/60 px-5 py-3 sm:px-6')
@php($save = 'rounded-lg bg-violet-600 px-4 py-2 text-sm font-semibold text-white shadow-xs transition hover:bg-violet-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2')
<x-settings.shell title="Notifications" description="Choose what you hear about, in QuoteFollow and by email.">
    @include('livewire.settings.partials.status')

    @unless ($canSendEmail)
        <div class="flex gap-3 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900">
            <span aria-hidden="true" class="mt-0.5 size-2 shrink-0 rounded-full bg-amber-500"></span>
            <p>Email notifications start once the business has a verified sender email. In-app notifications work now.</p>
        </div>
    @endunless

    <form wire:submit="save" x-data="unsavedChanges" class="{{ $card }}" aria-labelledby="mine-heading">
        <div class="{{ $header }}">
            <div>
                <h2 id="mine-heading" class="text-base font-semibold text-slate-900">My notifications</h2>
                @if ($usingDefaults)
                    <p class="mt-0.5 text-sm text-slate-500">You're using the default notification settings.</p>
                @else
                    <p class="mt-0.5 text-sm text-slate-500">Your own choices. Anything you haven't changed follows the business defaults.</p>
                @endif
            </div>
            @unless ($usingDefaults)
                <button type="button" wire:click="useDefaults" class="text-sm font-medium text-violet-700 hover:text-violet-800 hover:underline">Use business defaults</button>
            @endunless
        </div>
        <div class="{{ $body }}">
            @include('livewire.settings.partials.notification-matrix', ['model' => 'mine'])
        </div>
        <div class="{{ $footer }}"><button type="submit" class="{{ $save }}">Save changes</button></div>
    </form>

    @can('manage-business-defaults')
        <form wire:submit="saveDefaults" x-data="unsavedChanges" class="{{ $card }}" aria-labelledby="defaults-heading">
            <div class="{{ $header }}">
                <div>
                    <h2 id="defaults-heading" class="text-base font-semibold text-slate-900">Business defaults</h2>
                    <p class="mt-0.5 text-sm text-slate-500">For everyone on the team who hasn't chosen otherwise.</p>
                </div>
            </div>
            <div class="{{ $body }}">
                @include('livewire.settings.partials.notification-matrix', ['model' => 'defaults'])
            </div>
            <div class="{{ $footer }}"><button type="submit" class="{{ $save }}">Save defaults</button></div>
        </form>

        @include('livewire.settings.partials.history')
    @endcan
</x-settings.shell>
