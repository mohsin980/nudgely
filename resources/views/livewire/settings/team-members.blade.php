@php($field = 'mt-1 block w-full rounded-xl border-0 px-3.5 py-2.5 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
@php($card = 'rounded-2xl bg-white p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70 sm:p-6')
@php($button = 'inline-flex items-center rounded-xl bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 shadow-xs ring-1 ring-slate-200 ring-inset hover:bg-slate-50')
@php($primaryButton = 'inline-flex items-center justify-center gap-2 rounded-xl bg-linear-to-r from-violet-600 to-violet-500 px-4 py-2.5 text-sm font-semibold text-white shadow-[0_10px_22px_-8px_rgba(109,40,217,0.65)] transition hover:from-violet-700 hover:to-violet-600 disabled:opacity-50')
@php($rolePill = fn ($role) => match ($role) { \App\Enums\OrganizationRole::Owner => 'bg-violet-50 text-violet-700 ring-violet-600/20', \App\Enums\OrganizationRole::Manager => 'bg-amber-50 text-amber-800 ring-amber-600/20', default => 'bg-sky-50 text-sky-700 ring-sky-600/20' })
@php($avatarTones = ['bg-violet-100 text-violet-700', 'bg-sky-100 text-sky-700', 'bg-emerald-100 text-emerald-700', 'bg-amber-100 text-amber-800', 'bg-rose-100 text-rose-700'])
@php($menuItem = 'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-700 hover:bg-slate-50')
@php($statusClass = fn ($status) => match ($status) { \App\Enums\Team\MemberStatus::Active => 'bg-green-50 text-green-700 ring-green-600/20', \App\Enums\Team\MemberStatus::Suspended => 'bg-amber-50 text-amber-800 ring-amber-600/20', \App\Enums\Team\MemberStatus::Invited => 'bg-blue-50 text-blue-700 ring-blue-600/20', default => 'bg-slate-100 text-slate-500 ring-gray-500/20' })
<x-settings.shell title="Team Members" description="Invite people, choose what they can do, and suspend or remove access. History is always kept.">
    @unless ($showInviteForm)
        <x-slot:actions>
            <button type="button" wire:click="openInviteForm" class="{{ $primaryButton }}"><x-ui-icon name="user-plus" class="size-4" />Invite Team Member</button>
        </x-slot:actions>
    @endunless
    @include('livewire.settings.partials.status')
    @error('team') <div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div> @enderror

    @if ($this->inviteLink)
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900" data-invite-link>
            <p class="font-medium">Invitation link (works once, expires in {{ config('team.invitation_days') }} days):</p>
            <input type="text" readonly value="{{ $this->inviteLink }}" aria-label="Invitation link" class="mt-1 w-full rounded border-0 bg-white px-2 py-1 font-mono text-xs ring-1 ring-blue-200" x-on:focus="$el.select()">
        </div>
    @endif


    @if ($showInviteForm)
        <form wire:submit="invite" x-data="unsavedChanges" class="{{ $card }} space-y-4" aria-labelledby="invite-heading">
            <h2 id="invite-heading" class="text-lg font-bold text-slate-900">Invite a team member</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <div><label for="invite-name" class="block text-sm font-medium text-slate-700">Name</label><input id="invite-name" type="text" wire:model="inviteName" maxlength="100" class="{{ $field }}"></div>
                <div><label for="invite-email" class="block text-sm font-medium text-slate-700">Email</label><input id="invite-email" type="email" wire:model="inviteEmail" maxlength="255" class="{{ $field }}"></div>
                <div><label for="invite-role" class="block text-sm font-medium text-slate-700">Role</label>
                    <select id="invite-role" wire:model="inviteRole" class="{{ $field }}">@foreach ($roles as $role)<option value="{{ $role->value }}">{{ $role->label() }}</option>@endforeach</select></div>
            </div>
            @foreach (['inviteName', 'inviteEmail', 'inviteRole'] as $key) @error($key) <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror @endforeach
            <div class="flex gap-2">
                <button type="submit" wire:loading.attr="disabled" class="rounded-xl bg-violet-600 bg-linear-to-r from-violet-600 to-violet-500 px-3 py-2 text-sm font-semibold text-white hover:brightness-110 disabled:opacity-50 shadow-[0_8px_20px_-8px_rgba(109,40,217,0.6)]">Send Invitation</button>
                <button type="button" wire:click="$set('showInviteForm', false)" class="px-3 py-2 text-sm font-medium text-slate-700 hover:underline">Cancel</button>
            </div>
        </form>
    @endif

    <section aria-labelledby="members-heading" class="{{ $card }}">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="members-heading" class="text-lg font-bold text-slate-900">Members <span class="font-semibold text-slate-500">({{ $this->members->count() }})</span></h2>
            <label class="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" wire:model.live="showRemoved" class="size-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"> Show removed members</label>
        </div>
        <ul role="list" class="mt-3 divide-y divide-slate-100" data-section="members">
            @foreach ($this->members as $member)
                @php($isOwner = $member->role === \App\Enums\OrganizationRole::Owner)
                @php($isMe = $member->id === auth()->id())
                @php($initials = collect(preg_split('/\s+/', trim($member->name)))->filter()->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode(''))
                <li class="flex items-center gap-3 py-3.5" wire:key="member-{{ $member->id }}" data-member="{{ $member->id }}">
                    <span aria-hidden="true" class="grid size-11 shrink-0 place-items-center rounded-full text-sm font-bold {{ $avatarTones[crc32($member->email) % count($avatarTones)] }}">{{ $initials ?: '?' }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-slate-900">{{ $member->name }}@if ($isMe) <span class="text-sm font-normal text-slate-500">(you)</span>@endif</p>
                        <p class="truncate text-sm text-slate-500">{{ $member->email }}</p>
                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-slate-500">
                            @unless ($member->status === \App\Enums\Team\MemberStatus::Active)
                                <span class="inline-flex rounded-full px-2 py-0.5 font-semibold ring-1 ring-inset {{ $statusClass($member->status) }}">{{ $member->status->label() }}</span>
                            @endunless
                            <span><span class="font-semibold text-slate-700">{{ $member->role->label() }}</span> · Joined {{ $organization->formatDate($member->created_at) }}</span>
                            @if ($member->last_active_at)<span>· Last active {{ $member->last_active_at->diffForHumans() }}</span>@endif
                        </p>
                    </div>
                    <span class="inline-flex shrink-0 rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $rolePill($member->role) }}" data-role>{{ $member->role->label() }}</span>
                    @unless ($isOwner || $isMe || $member->status === \App\Enums\Team\MemberStatus::Removed)
                        <div class="relative shrink-0" x-data="{ open: false }" x-on:keydown.escape="open = false">
                            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="menu" class="grid size-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800" aria-label="Actions for {{ $member->name }}"><x-ui-icon name="ellipsis" class="size-5" /></button>
                            <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition role="menu" class="absolute right-0 z-20 mt-1 w-56 space-y-0.5 rounded-xl bg-white p-1.5 shadow-lg ring-1 ring-slate-200">
                                @if ($member->status === \App\Enums\Team\MemberStatus::Active)
                                    <div class="px-3 pt-1.5 pb-1">
                                        <label for="role-{{ $member->id }}" class="block text-xs font-semibold text-slate-500">Role</label>
                                        <select id="role-{{ $member->id }}" wire:change="changeRole({{ $member->id }}, $event.target.value)" class="mt-1 w-full rounded-lg border-0 py-1.5 text-sm ring-1 ring-slate-200 ring-inset focus:ring-2 focus:ring-violet-600">
                                            @foreach ($roles as $role)<option value="{{ $role->value }}" @selected($member->role === $role)>{{ $role->label() }}</option>@endforeach
                                        </select>
                                    </div>
                                    <button type="button" role="menuitem" wire:click="suspend({{ $member->id }})" wire:confirm="Suspend {{ $member->name }}? They are signed out at once and can't sign in until reactivated." class="{{ $menuItem }}"><x-ui-icon name="lock" class="size-4" />Suspend</button>
                                @else
                                    <button type="button" role="menuitem" wire:click="reactivate({{ $member->id }})" class="{{ $menuItem }}"><x-ui-icon name="arrow-path" class="size-4" />Reactivate</button>
                                @endif
                                <button type="button" role="menuitem" wire:click="startRemoval({{ $member->id }})" x-on:click="open = false" class="{{ $menuItem }} text-red-700 hover:bg-red-50"><x-ui-icon name="trash" class="size-4" />Remove</button>
                            </div>
                        </div>
                    @else
                        <span class="size-9 shrink-0" aria-hidden="true"></span>
                    @endunless
                </li>
                @if ($removing && $removing->id === $member->id)
                    <li class="pb-4" wire:key="remove-{{ $member->id }}">
                        <form wire:submit="remove" class="space-y-3 rounded-lg border border-red-200 bg-red-50 p-4" role="alertdialog" aria-labelledby="remove-q-{{ $member->id }}">
                            <p id="remove-q-{{ $member->id }}" class="text-sm font-medium text-red-900">Remove {{ $member->name }} from the team?</p>
                            <p class="text-sm text-red-900">They lose access at once. Their past activity stays in the history. Nothing is deleted.</p>
                            @if (array_sum($responsibilities) > 0)
                                <ul class="list-inside list-disc text-sm text-red-900" data-responsibilities>
                                    @if ($responsibilities['automations'])<li>{{ $responsibilities['automations'] }} {{ \Illuminate\Support\Str::plural('automation', $responsibilities['automations']) }} they created</li>@endif
                                    @if ($responsibilities['tasks'])<li>{{ $responsibilities['tasks'] }} open {{ \Illuminate\Support\Str::plural('task', $responsibilities['tasks']) }} assigned to them</li>@endif
                                    @if ($responsibilities['follow_ups'])<li>{{ $responsibilities['follow_ups'] }} open {{ \Illuminate\Support\Str::plural('follow-up', $responsibilities['follow_ups']) }} assigned to them</li>@endif
                                </ul>
                                <div class="max-w-sm"><label for="reassign-to" class="block text-sm font-medium text-red-900">Hand their work to</label>
                                    <select id="reassign-to" wire:model="reassignTo" class="{{ $field }}">
                                        <option value="">{{ $responsibilities['automations'] ? 'Choose someone…' : 'Nobody (leave tasks and follow-ups unassigned)' }}</option>
                                        @foreach ($takeovers as $userId => $userName)<option value="{{ $userId }}">{{ $userName }}</option>@endforeach
                                    </select></div>
                            @endif
                            @error('reassignTo') <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror
                            <div class="flex gap-2">
                                <button type="submit" class="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-500">Remove {{ $member->name }}</button>
                                <button type="button" wire:click="cancelRemoval" class="px-3 py-1.5 text-sm font-medium text-slate-700 hover:underline">Keep</button>
                            </div>
                        </form>
                    </li>
                @endif
            @endforeach
        </ul>
        @if ($this->members->count() <= 1 && $this->invitations->isEmpty())
            <div class="mt-3 rounded-lg border border-dashed border-slate-300 p-6 text-center">
                <p class="text-sm font-medium text-slate-900">You haven't invited anyone yet.</p>
                <button type="button" wire:click="openInviteForm" class="mt-3 {{ $primaryButton }}"><x-ui-icon name="user-plus" class="size-4" />Invite Team Member</button>
            </div>
        @endif
    </section>

    @if ($this->invitations->isNotEmpty())
        <section aria-labelledby="invitations-heading" class="{{ $card }}">
            <h2 id="invitations-heading" class="text-lg font-bold text-slate-900">Invitations <span class="font-semibold text-slate-500">({{ $this->invitations->count() }})</span></h2>
            <ul role="list" class="mt-3 divide-y divide-slate-100" data-section="invitations">
                @foreach ($this->invitations as $invitation)
                    <li class="flex flex-col gap-2 py-3.5 sm:flex-row sm:items-center sm:gap-4" wire:key="invitation-{{ $invitation->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-slate-900">{{ $invitation->name }}</p>
                            <p class="truncate text-sm text-slate-500">{{ $invitation->email }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                            <span class="inline-flex rounded-full px-3 py-1 font-semibold ring-1 ring-inset {{ $rolePill($invitation->role) }}">{{ $invitation->role->label() }}</span>
                            @if ($invitation->isExpired())
                                <span class="inline-flex rounded-full bg-red-50 px-2 py-0.5 font-semibold text-red-700 ring-1 ring-red-600/20 ring-inset">Expired</span>
                            @else
                                <span class="sr-only">Invited.</span><span>Expires {{ $organization->formatDate($invitation->expires_at) }}@if ($invitation->inviter) · by {{ $invitation->inviter->name }}@endif</span>
                            @endif
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="resend({{ $invitation->id }})" class="{{ $button }}">Resend<span class="sr-only"> invitation to {{ $invitation->email }}</span></button>
                            <button type="button" wire:click="revoke({{ $invitation->id }})" wire:confirm="Revoke this invitation? Its link stops working." class="inline-flex items-center rounded-xl bg-white px-3 py-1.5 text-sm font-semibold text-red-700 shadow-xs ring-1 ring-red-200 ring-inset hover:bg-red-50">Revoke</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @include('livewire.settings.partials.history')
</x-settings.shell>
