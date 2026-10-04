<?php

namespace App\Livewire\Settings;

use App\Enums\OrganizationRole;
use App\Enums\Team\MemberStatus;
use App\Enums\Team\Permission;
use App\Exceptions\Team\TeamActionException;
use App\Livewire\Settings\Concerns\SettingsPage;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Team\InvitationService;
use App\Services\Team\TeamDirectory;
use App\Services\Team\TeamService;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The team (owner): members, invitations, roles, suspension and removal. Every change goes
 * through TeamService / InvitationService, which re-check permission and organization.
 */
#[Layout('components.layouts.app')]
#[Title('Team Members')]
class TeamMembers extends Component
{
    use SettingsPage;

    public bool $showInviteForm = false;

    public string $inviteName = '';

    public string $inviteEmail = '';

    public string $inviteRole = 'staff';

    public bool $showRemoved = false;

    /** Shown once after inviting when the business can't email yet. */
    public ?string $inviteLink = null;

    #[Locked]
    public ?int $removingId = null;

    public string $reassignTo = '';

    public function mount(): void
    {
        $this->authorize(Permission::ManageTeam->value);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return User::query()->where('organization_id', $this->organization()->id)
            ->when(! $this->showRemoved, fn ($q) => $q->where('status', '!=', MemberStatus::Removed))
            ->orderByRaw("case role when 'owner' then 0 when 'manager' then 1 else 2 end")->orderBy('name')
            ->get(['id', 'organization_id', 'name', 'email', 'role', 'status', 'created_at', 'last_active_at', 'suspended_at', 'removed_at']);
    }

    /**
     * @return Collection<int, TeamInvitation>
     */
    #[Computed]
    public function invitations(): Collection
    {
        return TeamInvitation::query()->where('organization_id', $this->organization()->id)->open()
            ->with('inviter:id,name')->latest()->get();
    }

    public function openInviteForm(): void
    {
        $this->authorize(Permission::ManageTeam->value);
        $this->resetErrorBag();
        $this->showInviteForm = true;
        $this->inviteLink = null;
    }

    public function invite(InvitationService $invitations): void
    {
        $this->authorize(Permission::ManageTeam->value);
        $role = OrganizationRole::tryFrom($this->inviteRole);

        if ($role === null || ! in_array($role, OrganizationRole::assignable(), true)) {
            $this->addError('inviteRole', 'Choose manager or staff.');

            return;
        }

        try {
            $result = $invitations->invite($this->user(), $this->inviteName, $this->inviteEmail, $role);
        } catch (TeamActionException $e) {
            $this->addError('inviteEmail', $e->getMessage());

            return;
        }

        $this->reset(['showInviteForm', 'inviteName', 'inviteEmail', 'inviteRole']);
        $this->afterInvitation($result, "Invitation sent to {$result['invitation']->email}.");
    }

    public function resend(int $invitationId, InvitationService $invitations): void
    {
        $this->authorize(Permission::ManageTeam->value);

        try {
            $result = $invitations->resend($this->user(), $invitationId);
        } catch (TeamActionException $e) {
            $this->failed($e->getMessage());

            return;
        }

        $this->afterInvitation($result, "A new invitation was sent to {$result['invitation']->email}. The previous link no longer works.");
    }

    public function revoke(int $invitationId, InvitationService $invitations): void
    {
        $this->authorize(Permission::ManageTeam->value);

        try {
            $invitations->revoke($this->user(), $invitationId);
        } catch (TeamActionException $e) {
            $this->failed($e->getMessage());

            return;
        }

        $this->inviteLink = null;
        unset($this->invitations);
        $this->saved('Invitation revoked. Its link no longer works.');
    }

    public function changeRole(int $userId, string $role, TeamService $team): void
    {
        $this->authorize(Permission::ManageTeam->value);
        $newRole = OrganizationRole::tryFrom($role) ?? abort(422);

        $this->runTeamAction(fn (User $member) => $team->changeRole($this->user(), $member, $newRole), $userId, fn (User $m) => "{$m->name} is now {$newRole->label()}.");
    }

    public function suspend(int $userId, TeamService $team): void
    {
        $this->authorize(Permission::ManageTeam->value);
        $this->runTeamAction(fn (User $member) => $team->suspend($this->user(), $member), $userId, fn (User $m) => "{$m->name} is suspended and was signed out.");
    }

    public function reactivate(int $userId, TeamService $team): void
    {
        $this->authorize(Permission::ManageTeam->value);
        $this->runTeamAction(fn (User $member) => $team->reactivate($this->user(), $member), $userId, fn (User $m) => "{$m->name} can sign in again.");
    }

    public function startRemoval(int $userId): void
    {
        $this->authorize(Permission::ManageTeam->value);
        $member = $this->findMember($userId);
        $this->resetErrorBag();
        $this->removingId = $member->id;
        $this->reassignTo = '';
    }

    public function cancelRemoval(): void
    {
        $this->removingId = null;
        $this->reassignTo = '';
    }

    public function remove(TeamService $team, TeamDirectory $directory): void
    {
        $this->authorize(Permission::ManageTeam->value);
        $target = null;

        if ($this->reassignTo !== '' && ($target = $directory->activeMember($this->organization()->id, $this->reassignTo)) === null) {
            $this->addError('reassignTo', 'Choose an active team member.');

            return;
        }

        $removed = $this->runTeamAction(fn (User $member) => $team->remove($this->user(), $member, $target), $this->removingId ?? abort(404),
            fn (User $m) => "{$m->name} was removed from the team.".($target ? " Their open work now belongs to {$target->name}." : ''), 'reassignTo');

        if ($removed) {
            $this->cancelRemoval();
        }
    }

    public function render(TeamService $team, TeamDirectory $directory)
    {
        $removing = $this->removingId === null ? null : $this->members->firstWhere('id', $this->removingId);

        return view('livewire.settings.team-members', [
            'organization' => $this->organization(),
            'roles' => OrganizationRole::assignable(),
            'removing' => $removing,
            'responsibilities' => $removing ? $team->responsibilities($removing) : null,
            'takeovers' => $directory->assignableOptions($this->organization()->id)->except([$this->removingId]),
            'history' => $this->history(['member_invited', 'invitation_resent', 'invitation_revoked', 'invitation_accepted', 'role_changed', 'member_suspended', 'member_reactivated', 'member_removed', 'ownership_transferred']),
        ]);
    }

    /**
     * @param  array{invitation: TeamInvitation, link: string, emailed: bool}  $result
     */
    private function afterInvitation(array $result, string $message): void
    {
        unset($this->invitations);
        $this->inviteLink = $result['emailed'] ? null : $result['link'];
        $this->saved($result['emailed'] ? $message : 'Invitation created. Your business email isn\'t verified yet, so share this link with them yourself.');
    }

    private function findMember(int $userId): User
    {
        return User::query()->where('organization_id', $this->organization()->id)->find($userId) ?? abort(404);
    }

    /**
     * @param  \Closure(User): User  $action
     * @param  \Closure(User): string  $message
     */
    private function runTeamAction(\Closure $action, int $userId, \Closure $message, string $errorKey = 'team'): bool
    {
        try {
            $member = $action($this->findMember($userId));
        } catch (TeamActionException $e) {
            $this->addError($errorKey, $e->getMessage());
            $this->statusMessage = null;

            return false;
        }

        unset($this->members);
        $this->saved($message($member));

        return true;
    }
}
