@php($field = 'mt-1 block w-full rounded-md border-0 px-3 py-1.5 text-sm text-gray-900 ring-1 ring-gray-300 ring-inset focus:ring-2 focus:ring-indigo-600 focus:ring-inset')
@php($card = 'rounded-lg border border-gray-200 bg-white p-5 shadow-sm')
<x-settings.shell title="Account & Security" description="Your sign-in details and where you're signed in.">
    @include('livewire.settings.partials.status')

    <section aria-labelledby="account-heading" class="{{ $card }}">
        <h2 id="account-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Account</h2>
        <dl class="mt-2 grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-gray-500">Name</dt><dd class="font-medium text-gray-900">{{ $user->name }}</dd></div>
            <div><dt class="text-gray-500">Email</dt><dd class="font-medium text-gray-900">{{ $user->email }}</dd></div>
        </dl>
        <h3 class="mt-4 text-sm font-medium text-gray-900">Organization membership</h3>
        <p class="mt-1 text-sm text-gray-700" data-membership>{{ $organization->name }} · {{ $user->role->label() }} · {{ $user->status->label() }} since {{ $organization->formatDate($user->created_at) }}</p>
    </section>

    <form wire:submit="changePassword" x-data="unsavedChanges" class="{{ $card }} space-y-4" aria-labelledby="password-heading">
        <h2 id="password-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Change password</h2>
        <div class="grid gap-4 sm:grid-cols-3">
            <div><label for="current-password" class="block text-sm font-medium text-gray-700">Current password</label><input id="current-password" type="password" wire:model="currentPassword" autocomplete="current-password" class="{{ $field }}">@error('currentPassword') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div><label for="new-password" class="block text-sm font-medium text-gray-700">New password</label><input id="new-password" type="password" wire:model="newPassword" autocomplete="new-password" class="{{ $field }}">@error('newPassword') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
            <div><label for="new-password-confirmation" class="block text-sm font-medium text-gray-700">Confirm new password</label><input id="new-password-confirmation" type="password" wire:model="newPasswordConfirmation" autocomplete="new-password" class="{{ $field }}"></div>
        </div>
        <div class="flex justify-end"><button type="submit" class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500">Change password</button></div>
    </form>

    <section aria-labelledby="sessions-heading" class="{{ $card }} space-y-3">
        <h2 id="sessions-heading" class="text-sm font-semibold tracking-wide text-gray-500 uppercase">Active sessions</h2>
        <ul role="list" class="divide-y divide-gray-100 text-sm" data-section="sessions">
            @forelse ($sessions as $session)
                <li class="py-2">
                    <p class="text-gray-900">{{ $session->agent ?: 'Unknown browser' }} @if ($session->current)<span class="ml-1 rounded bg-green-50 px-1.5 py-0.5 text-xs font-medium text-green-700">This browser</span>@endif</p>
                    <p class="text-xs text-gray-500">{{ $session->ip ?? 'Unknown IP' }} · last active {{ $organization->formatDateTime($session->last_active) }}</p>
                </li>
            @empty
                <li class="py-2 text-gray-600">No other sessions.</li>
            @endforelse
        </ul>
        <form wire:submit="logoutOtherSessions" class="flex flex-wrap items-end gap-3">
            <div><label for="sessions-password" class="block text-sm font-medium text-gray-700">Password</label><input id="sessions-password" type="password" wire:model="sessionsPassword" autocomplete="current-password" class="{{ $field }}"></div>
            <button type="submit" class="rounded-md bg-white px-3 py-2 text-sm font-medium text-gray-700 ring-1 ring-gray-300 ring-inset hover:bg-gray-50">Log out other sessions</button>
        </form>
        @error('sessionsPassword') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
    </section>

    @can('transfer-ownership')
        <section aria-labelledby="danger-heading" class="rounded-lg border border-red-200 bg-white p-5 shadow-sm space-y-4">
            <div>
                <h2 id="danger-heading" class="text-sm font-semibold tracking-wide text-red-700 uppercase">Danger zone</h2>
                <p class="mt-1 text-sm text-gray-700">Transfer ownership of {{ $organization->name }}. The new owner controls the team, email and business settings, and only they can give ownership back.</p>
            </div>
            @if ($candidates->isEmpty())
                <p class="text-sm text-gray-600">Invite a team member first: ownership can only go to an active member.</p>
            @else
                <form wire:submit="transferOwnership" class="space-y-3">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label for="transfer-to" class="block text-sm font-medium text-gray-700">New owner</label>
                            <select id="transfer-to" wire:model="transferTo" class="{{ $field }}"><option value="">Choose a team member…</option>@foreach ($candidates as $userId => $userName)<option value="{{ $userId }}">{{ $userName }}</option>@endforeach</select>
                            @error('transferTo') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                        <div><label for="my-new-role" class="block text-sm font-medium text-gray-700">Your role afterwards</label>
                            <select id="my-new-role" wire:model="myNewRole" class="{{ $field }}">@foreach ($roles as $role)<option value="{{ $role->value }}">{{ $role->label() }}</option>@endforeach</select></div>
                        <div><label for="transfer-password" class="block text-sm font-medium text-gray-700">Your current password</label>
                            <input id="transfer-password" type="password" wire:model="transferPassword" autocomplete="current-password" class="{{ $field }}">
                            @error('transferPassword') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                    </div>
                    <label class="flex items-start gap-2 text-sm text-gray-800"><input type="checkbox" wire:model="transferConfirmed" class="mt-0.5 rounded border-gray-300"> I understand I will no longer be the owner of {{ $organization->name }}.</label>
                    @error('transferConfirmed') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
                    <button type="submit" class="rounded-md bg-red-600 px-3 py-2 text-sm font-semibold text-white hover:bg-red-500">Transfer ownership</button>
                </form>
            @endif
        </section>
    @endcan
</x-settings.shell>
