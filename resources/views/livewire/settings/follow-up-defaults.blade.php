@php($field = 'mt-1 block w-full rounded-xl border-0 px-3.5 py-2.5 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
<x-settings.shell title="Reminder defaults" description="When new follow-ups are due unless you pick another time.">
    @include('livewire.settings.partials.status')

    <form wire:submit="save" x-data="unsavedChanges" class="space-y-4 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-[0_1px_2px_rgba(16,24,40,0.04),0_8px_24px_-12px_rgba(76,29,149,0.12)]">
        <div class="grid gap-4 sm:grid-cols-2">
            <div><label for="delay-days" class="block text-sm font-medium text-slate-700">Default delay (days)</label>
                <input id="delay-days" type="number" min="1" max="60" wire:model="delayDays" class="{{ $field }}">
                <p class="mt-1 text-xs text-slate-500">A new follow-up is due this many days from today.</p>
                @error('delayDays') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div><label for="follow-up-time" class="block text-sm font-medium text-slate-700">Default time</label>
                <input id="follow-up-time" type="time" wire:model="time" class="{{ $field }}">
                <p class="mt-1 text-xs text-slate-500">In {{ $organization->timezone() }}.</p>
                @error('time') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
        </div>
        <div class="border-t border-slate-100 pt-4">
            <label for="follow-up-attempts" class="block text-sm font-medium text-slate-700">Follow-up emails if the customer doesn't reply</label>
            <select id="follow-up-attempts" wire:model="attempts" class="{{ $field }} sm:max-w-xs">
                @foreach (range(1, \App\Support\Settings\OrganizationSettings::MAX_FOLLOW_UP_ATTEMPTS) as $n)
                    <option value="{{ $n }}">{{ $n === 1 ? '1 email (no repeats)' : $n.' emails' }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500">After an automatic follow-up email, if there's no reply the next one goes out after the default delay, until this many have been sent. It stops as soon as the customer replies, the estimate is accepted, declined or expires, or they unsubscribe.</p>
            @error('attempts') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>
        <div class="flex justify-end"><button type="submit" class="rounded-xl bg-violet-600 bg-linear-to-r from-violet-600 to-violet-500 px-4 py-2 text-sm font-semibold text-white hover:brightness-110 shadow-[0_8px_20px_-8px_rgba(109,40,217,0.6)]">Save</button></div>
    </form>

    @include('livewire.settings.partials.history')
</x-settings.shell>
