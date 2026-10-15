@php($field = 'mt-1 block w-full rounded-xl border-0 px-3.5 py-2.5 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset focus:ring-2 focus:ring-emerald-600 focus:ring-inset')
<div>
    @switch($state)
        @case('expired')
            <h1 class="text-lg font-semibold text-slate-900">This invitation has expired.</h1>
            <p class="mt-2 text-sm text-slate-500">Ask the person who invited you to send a new invitation.</p>
            @break
        @case('used')
            <h1 class="text-lg font-semibold text-slate-900">This invitation was already used.</h1>
            <p class="mt-2 text-sm text-slate-500">If that was you, <a href="{{ route('login') }}" class="font-medium text-emerald-700 hover:underline">sign in</a>.</p>
            @break
        @case('invalid')
            <h1 class="text-lg font-semibold text-slate-900">This invitation is no longer valid.</h1>
            <p class="mt-2 text-sm text-slate-500">It may have been replaced by a newer invitation or revoked. Ask the business for a new one.</p>
            @break
        @default
            <h1 class="text-lg font-semibold text-slate-900">Join {{ $invitation->organization->name }}</h1>
            <p class="mt-1 text-sm text-slate-500">You've been invited as <span class="font-medium text-slate-900">{{ $invitation->role->label() }}</span> with {{ $invitation->email }}.</p>

            @if ($signedInAs)
                <p class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">You're signed in as {{ $signedInAs->email }}. Accepting signs you in with the new account instead.</p>
            @endif

            @error('invitation') <div role="alert" class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div> @enderror

            <form wire:submit="accept" class="mt-4 space-y-4">
                <div><label for="name" class="block text-sm font-medium text-slate-700">Your name</label><input id="name" type="text" wire:model="name" maxlength="100" autocomplete="name" class="{{ $field }}">@error('name') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div><label for="password" class="block text-sm font-medium text-slate-700">Choose a password</label><input id="password" type="password" wire:model="password" autocomplete="new-password" class="{{ $field }}">@error('password') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                <div><label for="password-confirmation" class="block text-sm font-medium text-slate-700">Confirm password</label><input id="password-confirmation" type="password" wire:model="passwordConfirmation" autocomplete="new-password" class="{{ $field }}"></div>
                <button type="submit" wire:loading.attr="disabled" class="w-full rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">Accept Invitation</button>
            </form>
    @endswitch
</div>
