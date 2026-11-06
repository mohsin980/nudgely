@php($field = 'mt-1.5 block w-full rounded-xl border-0 bg-white px-3.5 py-3 text-[15px] text-slate-900 shadow-xs ring-1 ring-slate-200 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-600 focus:ring-inset')
@php($card = 'rounded-2xl bg-white p-5 sm:p-6 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70')
@php($badge = 'grid size-12 shrink-0 place-items-center rounded-2xl bg-violet-100 text-violet-600')
@php($label = 'block text-[15px] font-semibold text-slate-800')
@php($eye = 'absolute top-1/2 right-3 grid size-8 -translate-y-1/2 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-violet-500')
<x-settings.shell title="Account & Security" description="Manage your account details, change your password, and review active sessions." art="shield" bare>
    @include('livewire.settings.partials.status')

    <section aria-labelledby="account-heading" class="{{ $card }}">
        <div class="flex items-center gap-4 border-b border-slate-100 pb-4">
            <span class="{{ $badge }}"><x-ui-icon name="user" class="size-6 stroke-2" /></span>
            <div>
                <h2 id="account-heading" class="text-lg font-bold text-slate-900">Account Details</h2>
                <p class="text-sm text-slate-500">Your sign-in details and organization information.</p>
            </div>
        </div>
        <div class="mt-5 grid gap-6 md:grid-cols-2 md:divide-x md:divide-slate-100">
            <dl class="space-y-4 text-sm">
                <div><dt class="font-medium text-slate-600">Account Name</dt><dd class="mt-0.5 text-base font-bold text-slate-900">{{ $user->name }}</dd></div>
                <div><dt class="font-medium text-slate-600">Organization</dt><dd class="mt-0.5 font-medium text-slate-900" data-membership>{{ $organization->name }} · {{ $user->role->label() }} · {{ $user->status->label() }} since {{ $organization->formatDate($user->created_at) }}</dd></div>
            </dl>
            <div class="flex items-start gap-3.5 md:pl-6">
                <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-white text-violet-600 shadow-xs ring-1 ring-slate-200"><x-ui-icon name="envelope" class="size-6" /></span>
                <dl class="min-w-0 text-sm">
                    <dt class="font-medium text-slate-600">Email Address</dt>
                    <dd class="mt-0.5 flex flex-wrap items-center gap-2">
                        <span class="truncate text-base font-bold text-slate-900">{{ $user->email }}</span>
                        @if ($user->email_verified_at)
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-600/20 ring-inset"><x-ui-icon name="check-circle" class="size-4 stroke-2" />Verified</span>
                        @endif
                    </dd>
                </dl>
            </div>
        </div>
    </section>

    <form wire:submit="changePassword" x-data="unsavedChanges" class="{{ $card }}" aria-labelledby="password-heading">
        <div class="flex items-center gap-4">
            <span class="{{ $badge }}"><x-ui-icon name="lock" class="size-6 stroke-2" /></span>
            <div>
                <h2 id="password-heading" class="text-lg font-bold text-slate-900">Change Password</h2>
                <p class="text-sm text-slate-500">Keep your account secure with a strong password.</p>
            </div>
        </div>
        <div x-data="{ show: false }" class="mt-5 grid gap-4 md:grid-cols-3 md:items-end xl:grid-cols-[repeat(3,minmax(0,1fr))_auto]">
            @foreach ([
                ['current-password', 'currentPassword', 'Current Password', 'current-password', 'Enter your current password'],
                ['new-password', 'newPassword', 'New Password', 'new-password', 'Enter your new password'],
                ['new-password-confirmation', 'newPasswordConfirmation', 'Confirm New Password', 'new-password', 'Confirm your new password'],
            ] as [$inputId, $property, $inputLabel, $autocomplete, $placeholder])
                <div>
                    <label for="{{ $inputId }}" class="{{ $label }}">{{ $inputLabel }}</label>
                    <div class="relative">
                        <input id="{{ $inputId }}" :type="show ? 'text' : 'password'" type="password" wire:model="{{ $property }}" autocomplete="{{ $autocomplete }}" placeholder="{{ $placeholder }}" class="{{ $field }} pr-12">
                        <button type="button" x-on:click="show = ! show" :aria-label="show ? 'Hide passwords' : 'Show passwords'" aria-label="Show passwords" class="{{ $eye }} mt-[3px]">
                            <x-ui-icon name="eye" class="size-5" x-show="! show" />
                            <x-ui-icon name="eye-slash" class="size-5" x-show="show" x-cloak />
                        </button>
                    </div>
                    @error($property) <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            @endforeach
            <button type="submit" class="inline-flex h-[50px] items-center justify-center gap-2 justify-self-end rounded-xl bg-linear-to-r md:col-span-3 xl:col-span-1 from-violet-600 to-violet-500 px-5 text-[15px] font-semibold whitespace-nowrap text-white shadow-[0_10px_22px_-8px_rgba(109,40,217,0.65)] transition hover:from-violet-700 hover:to-violet-600">
                <x-ui-icon name="lock" class="size-5" /> Change Password <x-ui-icon name="arrow-right" class="size-4 stroke-2" />
            </button>
        </div>
    </form>

    <section aria-labelledby="sessions-heading" class="{{ $card }}" x-data="{ confirming: {{ $errors->has('sessionsPassword') ? 'true' : 'false' }} }">
        <div class="flex items-center gap-4 border-b border-slate-100 pb-4">
            <span class="{{ $badge }}"><x-ui-icon name="computer" class="size-6 stroke-2" /></span>
            <div>
                <h2 id="sessions-heading" class="text-lg font-bold text-slate-900">Active Sessions</h2>
                <p class="text-sm text-slate-500">Manage your active sessions across devices.</p>
            </div>
        </div>
        <ul role="list" class="divide-y divide-slate-100" data-section="sessions">
            @forelse ($sessions as $session)
                <li class="grid gap-3 py-4 sm:grid-cols-[minmax(0,1fr)_13rem_14rem] sm:items-center">
                    <div class="flex items-center gap-3.5">
                        @include('livewire.settings.partials.browser-logo', ['browser' => $session->device['browser']])
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-900" title="{{ $session->agent ?: 'Unknown browser' }}">{{ $session->device['label'] }}</p>
                            <p class="text-sm text-slate-500">{{ $session->ip ?? 'Unknown IP' }}{{ $session->current ? ' · Current session' : '' }}</p>
                        </div>
                        @if ($session->current)<span class="ml-2 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">Active<span class="sr-only"> (this browser)</span></span>@endif
                    </div>
                    <div class="text-sm">
                        <p class="font-semibold text-slate-800">Last Active</p>
                        <p class="text-slate-500">{{ $organization->formatDateTime($session->last_active) }}</p>
                    </div>
                    <div class="sm:text-right">
                        @if ($loop->first)
                            <button type="button" x-show="! confirming" x-on:click="confirming = true; $nextTick(() => $refs.sessionsPassword.focus())" class="inline-flex items-center gap-2 rounded-xl bg-white px-3.5 py-2 text-[13px] font-semibold text-slate-700 shadow-xs ring-1 ring-slate-200 ring-inset hover:bg-slate-50">
                                <x-ui-icon name="logout" class="size-[18px]" /> Log out other sessions
                            </button>
                        @endif
                    </div>
                </li>
            @empty
                <li class="py-4 text-sm text-slate-500">No other sessions.</li>
            @endforelse
        </ul>
        <form wire:submit="logoutOtherSessions" x-show="confirming" x-cloak class="flex flex-wrap items-end justify-end gap-3 border-t border-slate-100 pt-4">
            <div><label for="sessions-password" class="{{ $label }}">Confirm with your password</label><input id="sessions-password" x-ref="sessionsPassword" type="password" wire:model="sessionsPassword" autocomplete="current-password" class="{{ $field }}"></div>
            <button type="submit" class="inline-flex h-[50px] items-center gap-2 rounded-xl bg-white px-4 text-sm font-semibold text-slate-700 shadow-xs ring-1 ring-slate-200 ring-inset hover:bg-slate-50"><x-ui-icon name="logout" class="size-5" /> Log out other sessions</button>
            <button type="button" x-on:click="confirming = false" class="h-[50px] rounded-xl px-3 text-sm font-medium text-slate-600 hover:bg-slate-100">Cancel</button>
        </form>
        @error('sessionsPassword') <p role="alert" class="mt-2 text-right text-sm text-red-700">{{ $message }}</p> @enderror
    </section>

    @can('transfer-ownership')
        <section aria-labelledby="danger-heading" class="rounded-2xl border border-red-200 bg-white p-5 shadow-xs space-y-4">
            <div>
                <h2 id="danger-heading" class="text-base font-semibold text-red-700">Danger zone</h2>
                <p class="mt-1 text-sm text-slate-700">Transfer ownership of {{ $organization->name }}. The new owner controls the team, email and business settings, and only they can give ownership back.</p>
            </div>
            @if ($candidates->isEmpty())
                <p class="text-sm text-slate-500">Invite a team member first: ownership can only go to an active member.</p>
            @else
                <form wire:submit="transferOwnership" class="space-y-3">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label for="transfer-to" class="block text-sm font-medium text-slate-700">New owner</label>
                            <select id="transfer-to" wire:model="transferTo" class="{{ $field }}"><option value="">Choose a team member…</option>@foreach ($candidates as $userId => $userName)<option value="{{ $userId }}">{{ $userName }}</option>@endforeach</select>
                            @error('transferTo') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                        <div><label for="my-new-role" class="block text-sm font-medium text-slate-700">Your role afterwards</label>
                            <select id="my-new-role" wire:model="myNewRole" class="{{ $field }}">@foreach ($roles as $role)<option value="{{ $role->value }}">{{ $role->label() }}</option>@endforeach</select></div>
                        <div><label for="transfer-password" class="block text-sm font-medium text-slate-700">Your current password</label>
                            <input id="transfer-password" type="password" wire:model="transferPassword" autocomplete="current-password" class="{{ $field }}">
                            @error('transferPassword') <p role="alert" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror</div>
                    </div>
                    <label class="flex items-start gap-2 text-sm text-slate-800"><input type="checkbox" wire:model="transferConfirmed" class="mt-0.5 rounded border-slate-300"> I understand I will no longer be the owner of {{ $organization->name }}.</label>
                    @error('transferConfirmed') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
                    <button type="submit" class="rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs transition hover:bg-red-500">Transfer ownership</button>
                </form>
            @endif
        </section>
    @endcan
</x-settings.shell>
