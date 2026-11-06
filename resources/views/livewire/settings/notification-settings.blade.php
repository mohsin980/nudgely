@php($card = 'overflow-hidden rounded-2xl bg-white shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70')
@php($header = 'flex flex-wrap items-start justify-between gap-3 px-5 pt-6 pb-4 sm:px-7')
@php($body = 'px-2 pb-2 sm:px-3')
@php($footer = 'flex justify-end border-t border-slate-100 bg-slate-50/60 px-5 py-3 sm:px-6')
@php($save = 'rounded-xl bg-violet-600 bg-linear-to-r from-violet-600 to-violet-500 px-4 py-2 text-sm font-semibold text-white transition hover:brightness-110 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500 focus-visible:ring-offset-2 shadow-[0_8px_20px_-8px_rgba(109,40,217,0.6)]')
<x-settings.shell title="Notifications" description="Choose what you hear about, in QuoteFollow and by email.">
    @can('manage-business-defaults')
        <x-slot:actions>
            <a href="#defaults-heading" class="inline-flex items-center gap-2 rounded-2xl bg-white px-4 py-2.5 text-sm font-semibold text-violet-800 shadow-[0_8px_20px_-12px_rgba(76,29,149,0.4)] ring-1 ring-slate-200/70 transition hover:bg-violet-50"><x-ui-icon name="bell" class="size-5" /> Business defaults</a>
        </x-slot:actions>
    @endcan
    @include('livewire.settings.partials.status')

    @unless ($canSendEmail)
        <div x-data="{ shown: true }" x-show="shown" class="flex items-center gap-3 rounded-2xl bg-violet-100/60 px-4 py-3.5 text-[15px] ring-1 ring-violet-200/60">
            <span aria-hidden="true" class="grid size-7 shrink-0 place-items-center rounded-full bg-violet-600 text-sm font-bold text-white">i</span>
            <p class="flex-1"><span class="font-semibold text-violet-900">Email notifications start once the business has a verified sender email.</span> <span class="text-violet-700">In-app notifications work now.</span></p>
            <button type="button" x-on:click="shown = false" class="grid size-8 place-items-center rounded-lg text-violet-900 hover:bg-violet-200/60"><x-ui-icon name="close" class="size-5 stroke-2" /><span class="sr-only">Hide this message</span></button>
        </div>
    @endunless

    <form wire:submit="save" x-data="unsavedChanges" class="{{ $card }}" aria-labelledby="mine-heading">
        <div class="{{ $header }}">
            <div>
                <h2 id="mine-heading" class="text-xl font-bold text-slate-900">Notification Settings</h2>
                @if ($usingDefaults)
                    <p class="mt-0.5 text-[15px] text-slate-500">Manage your notification preferences. You're using the default notification settings.</p>
                @else
                    <p class="mt-0.5 text-[15px] text-slate-500">Manage your notification preferences. Anything you haven't changed follows the business defaults.</p>
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
                    <h2 id="defaults-heading" class="scroll-mt-6 text-xl font-bold text-slate-900">Business defaults</h2>
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
