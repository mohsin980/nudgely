@php($field = 'mt-1 block w-full rounded-xl border-0 px-3.5 py-2.5 text-[15px] text-slate-900 ring-1 ring-slate-300 ring-inset focus:ring-2 focus:ring-violet-600 focus:ring-inset')
@php($card = 'rounded-lg border border-slate-200 bg-white p-5 shadow-sm')
@php($button = 'inline-flex items-center rounded-lg bg-white px-2.5 py-1 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50')
@php($statusClass = fn ($status) => match ($status) { \App\Enums\Team\MemberStatus::Active => 'bg-green-50 text-green-700 ring-green-600/20', \App\Enums\Team\MemberStatus::Suspended => 'bg-amber-50 text-amber-800 ring-amber-600/20', \App\Enums\Team\MemberStatus::Invited => 'bg-blue-50 text-blue-700 ring-blue-600/20', default => 'bg-slate-100 text-slate-500 ring-gray-500/20' })
<x-settings.shell title="Team Members" description="Invite people, choose what they can do, and suspend or remove access. History is always kept.">
    @include('livewire.settings.partials.status')
    @error('team') <div role="alert" class="rounded-lg bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div> @enderror

    @if ($this->inviteLink)
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900" data-invite-link>
            <p class="font-medium">Invitation link (works once, expires in {{ config('team.invitation_days') }} days):</p>
            <input type="text" readonly value="{{ $this->inviteLink }}" aria-label="Invitation link" class="mt-1 w-full rounded border-0 bg-white px-2 py-1 font-mono text-xs ring-1 ring-blue-200" x-on:focus="$el.select()">
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" wire:model.live="showRemoved" class="rounded border-slate-300"> Show removed members</label>
        @unless ($showInviteForm)
            <button type="button" wire:click="openInviteForm" class="rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-700">Invite Team Member</button>
        @endunless
    </div>

    @if ($showInviteForm)
        <form wire:submit="invite" x-data="unsavedChanges" class="{{ $card }} space-y-4" aria-labelledby="invite-heading">
            <h2 id="invite-heading" class="text-sm font-semibold text-slate-500">Invite a team member</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <div><label for="invite-name" class="block text-sm font-medium text-slate-700">Name</label><input id="invite-name" type="text" wire:model="inviteName" maxlength="100" class="{{ $field }}"></div>
                <div><label for="invite-email" class="block text-sm font-medium text-slate-700">Email</label><input id="invite-email" type="email" wire:model="inviteEmail" maxlength="255" class="{{ $field }}"></div>
                <div><label for="invite-role" class="block text-sm font-medium text-slate-700">Role</label>
                    <select id="invite-role" wire:model="inviteRole" class="{{ $field }}">@foreach ($roles as $role)<option value="{{ $role->value }}">{{ $role->label() }}</option>@endforeach</select></div>
            </div>
            @foreach (['inviteName', 'inviteEmail', 'inviteRole'] as $key) @error($key) <p role="alert" class="text-sm text-red-700">{{ $message }}</p> @enderror @endforeach
            <div class="flex gap-2">
                <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50">Send Invitation</button>
                <button type="button" wire:click="$set('showInviteForm', false)" class="px-3 py-2 text-sm font-medium text-slate-700 hover:underline">Cancel</button>
            </div>
        </form>
    @endif

    <section aria-labelledby="members-heading" class="{{ $card }}">
        <h2 id="members-heading" class="text-sm font-semibold text-slate-500">Members</h2>
        <ul role="list" class="mt-2 divide-y divide-slate-100" data-section="members">
            @foreach ($this->members as $member)
                @php($isOwner = $member->role === \App\Enums\OrganizationRole::Owner)
                @php($isMe = $member->id === auth()->id())
                <li class="flex flex-col gap-3 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="member-{{ $member->id }}" data-member="{{ $member->id }}">
                    <div class="min-w-0">
                        <p class="font-medium text-slate-900">{{ $member->name }}@if ($isMe) <span class="text-sm font-normal text-slate-500">(you)</span>@endif</p>
                        <p class="truncate text-sm text-slate-500">{{ $member->email }}</p>
                        <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                            <span class="font-medium text-slate-800">{{ $member->role->label() }}</span>
                            <span class="inline-flex rounded-lg px-1.5 py-0.5 font-medium ring-1 ring-inset {{ $statusClass($member->status) }}">{{ $member->status->label() }}</span>
                            <span>Joined {{ $organization->formatDate($member->created_at) }}</span>
                            @if ($member->last_active_at)<span>· Last active {{ $member->last_active_at->diffForHumans() }}</span>@endif
                        </p>
                    </div>
                    @unless ($isOwner || $isMe || $member->status === \App\Enums\Team\MemberStatus::Removed)
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($member->status === \App\Enums\Team\MemberStatus::Active)
                                <label for="role-{{ $member->id }}" class="sr-only">Role for {{ $member->name }}</label>
                                <select id="role-{{ $member->id }}" wire:change="changeRole({{ $member->id }}, $event.target.value)" class="rounded-lg border-slate-300 py-1 text-sm shadow-sm">
                                    @foreach ($roles as $role)<option value="{{ $role->value }}" @selected($member->role === $role)>{{ $role->label() }}</option>@endforeach
                                </select>
                                <button type="button" wire:click="suspend({{ $member->id }})" wire:confirm="Suspend {{ $member->name }}? They are signed out at once and can't sign in until reactivated." class="{{ $button }}">Suspend</button>
                            @else
                                <button type="button" wire:click="reactivate({{ $member->id }})" class="{{ $button }}">Reactivate</button>
                            @endif
                            <button type="button" wire:click="startRemoval({{ $member->id }})" class="rounded-lg px-2.5 py-1 text-sm font-medium text-red-700 hover:underline">Remove</button>
                        </div>
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
                <button type="button" wire:click="openInviteForm" class="mt-3 rounded-lg bg-violet-600 px-3 py-2 text-sm font-semibold text-white hover:bg-violet-700">Invite Team Member</button>
            </div>
        @endif
    </section>

    @if ($this->invitations->isNotEmpty())
        <section aria-labelledby="invitations-heading" class="{{ $card }}">
            <h2 id="invitations-heading" class="text-sm font-semibold text-slate-500">Invitations</h2>
            <ul role="list" class="mt-2 divide-y divide-slate-100" data-section="invitations">
                @foreach ($this->invitations as $invitation)
                    <li class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="invitation-{{ $invitation->id }}">
                        <div class="min-w-0">
                            <p class="font-medium text-slate-900">{{ $invitation->name }}</p>
                            <p class="truncate text-sm text-slate-500">{{ $invitation->email }}</p>
                            <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <span class="font-medium text-slate-800">{{ $invitation->role->label() }}</span>
                                @if ($invitation->isExpired())
                                    <span class="inline-flex rounded-lg bg-red-50 px-1.5 py-0.5 font-medium text-red-700 ring-1 ring-red-600/20 ring-inset">Expired</span>
                                @else
                                    <span class="inline-flex rounded-lg px-1.5 py-0.5 font-medium ring-1 ring-inset {{ $statusClass(\App\Enums\Team\MemberStatus::Invited) }}">Invited</span>
                                    <span>Expires {{ $organization->formatDate($invitation->expires_at) }}</span>
                                @endif
                                @if ($invitation->inviter)<span>· by {{ $invitation->inviter->name }}</span>@endif
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <button type="button" wire:click="resend({{ $invitation->id }})" class="{{ $button }}">Resend Invitation</button>
                            <button type="button" wire:click="revoke({{ $invitation->id }})" wire:confirm="Revoke this invitation? Its link stops working." class="rounded-lg px-2.5 py-1 text-sm font-medium text-red-700 hover:underline">Revoke</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @include('livewire.settings.partials.history')
</x-settings.shell>
