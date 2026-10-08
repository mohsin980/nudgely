<?php

namespace App\Services\Team;

use App\Enums\Automation\AutomationStatus;
use App\Enums\Billing\LimitKey;
use App\Enums\FollowUpStatus;
use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Enums\Team\MemberStatus;
use App\Enums\Team\Permission;
use App\Exceptions\Billing\PlanLimitException;
use App\Exceptions\Team\TeamActionException;
use App\Models\Automation;
use App\Models\FollowUp;
use App\Models\OrganizationActivity;
use App\Models\Task;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Role changes, suspension, removal and ownership transfer. Every method checks the actor's
 * permission and organization itself (callers can't skip it), runs in a transaction with the
 * affected rows locked, and records an OrganizationActivity entry.
 *
 * Rules: an organization always has exactly one owner; nobody changes, suspends or removes
 * themselves; the owner can only change hands through transferOwnership().
 */
class TeamService
{
    public function __construct(private readonly TeamDirectory $team) {}

    /**
     * @throws AuthorizationException|TeamActionException
     */
    public function changeRole(User $actor, User $member, OrganizationRole $role): User
    {
        return DB::transaction(function () use ($actor, $member, $role) {
            $member = $this->lockMember($actor, $member);

            if ($member->role === OrganizationRole::Owner || $role === OrganizationRole::Owner) {
                throw new TeamActionException($member->role === OrganizationRole::Owner
                    ? TeamActionException::LAST_OWNER.' Transfer ownership to someone else first.'
                    : 'Ownership can only be given with “Transfer ownership”.');
            }

            if ($member->role === $role) {
                return $member;
            }

            $from = $member->role;
            $member->forceFill(['role' => $role])->save();
            OrganizationActivity::record($member->organization_id, 'role_changed', $actor, ['from' => $from->value, 'to' => $role->value], $member);
            Log::info('Team member role changed.', ['organization_id' => $member->organization_id, 'user_id' => $member->id, 'actor_id' => $actor->id, 'role' => $role->value]);

            return $member;
        });
    }

    /**
     * Suspended: keeps their history, can't sign in or act; signed out everywhere at once.
     *
     * @throws AuthorizationException|TeamActionException
     */
    public function suspend(User $actor, User $member): User
    {
        return DB::transaction(function () use ($actor, $member) {
            $member = $this->lockMember($actor, $member);

            if ($member->role === OrganizationRole::Owner) {
                throw TeamActionException::lastOwner();
            }

            if ($member->status !== MemberStatus::Active) {
                throw new TeamActionException('Only active members can be suspended.');
            }

            $member->forceFill(['status' => MemberStatus::Suspended, 'suspended_at' => now()])->save();
            $this->endSessions($member);
            OrganizationActivity::record($member->organization_id, 'member_suspended', $actor, [], $member);

            return $member;
        });
    }

    /**
     * @throws AuthorizationException|TeamActionException
     */
    public function reactivate(User $actor, User $member): User
    {
        return DB::transaction(function () use ($actor, $member) {
            $member = $this->lockMember($actor, $member);

            if ($member->status !== MemberStatus::Suspended) {
                throw new TeamActionException('Only suspended members can be reactivated.');
            }

            // Coming back takes a seat again, and the plan may have changed since the suspension.
            try {
                app(EntitlementService::class)->guard($member->organization, LimitKey::TeamMembers, function () use ($member, $actor) {
                    $member->forceFill(['status' => MemberStatus::Active, 'suspended_at' => null])->save();
                    OrganizationActivity::record($member->organization_id, 'member_reactivated', $actor, [], $member);
                }, fn () => TeamInvitation::query()->where('organization_id', $member->organization_id)->open()->count());
            } catch (PlanLimitException $e) {
                throw new TeamActionException($e->getMessage());
            }

            return $member;
        });
    }

    /**
     * What the member is responsible for and would need a new owner/assignee before removal.
     *
     * @return array{automations: int, tasks: int, follow_ups: int}
     */
    public function responsibilities(User $member): array
    {
        return [
            'automations' => Automation::query()->where('organization_id', $member->organization_id)->where('created_by', $member->id)
                ->whereIn('status', [AutomationStatus::Active, AutomationStatus::Paused, AutomationStatus::Draft])->count(),
            'tasks' => Task::query()->where('organization_id', $member->organization_id)->where('assigned_to', $member->id)->where('status', TaskStatus::Pending)->count(),
            'follow_ups' => FollowUp::query()->where('organization_id', $member->organization_id)->where('assigned_to', $member->id)
                ->whereIn('status', [FollowUpStatus::Pending, FollowUpStatus::Due])->count(),
        ];
    }

    /**
     * Remove a member from the business. Their open work moves to $reassignTo (an active member)
     * or, for tasks and follow-ups only, becomes unassigned; nothing is deleted. History keeps their name.
     *
     * @throws AuthorizationException|TeamActionException
     */
    public function remove(User $actor, User $member, ?User $reassignTo): User
    {
        return DB::transaction(function () use ($actor, $member, $reassignTo) {
            $member = $this->lockMember($actor, $member);

            if ($member->role === OrganizationRole::Owner) {
                throw TeamActionException::lastOwner();
            }

            if ($member->status === MemberStatus::Removed) {
                throw new TeamActionException('This person was already removed.');
            }

            if ($reassignTo !== null && ($reassignTo->id === $member->id || $this->team->activeMember($member->organization_id, $reassignTo->id) === null)) {
                throw new TeamActionException('Choose an active team member to take over.');
            }

            $open = $this->responsibilities($member);

            if ($open['automations'] > 0 && $reassignTo === null) {
                throw new TeamActionException('Choose who takes over '.$open['automations'].' '.Str::plural('automation', $open['automations']).' this person created.');
            }

            $organizationId = $member->organization_id;
            Automation::query()->where('organization_id', $organizationId)->where('created_by', $member->id)
                ->whereIn('status', [AutomationStatus::Active, AutomationStatus::Paused, AutomationStatus::Draft])
                ->update(['created_by' => $reassignTo?->id, 'updated_at' => now()]);
            Task::query()->where('organization_id', $organizationId)->where('assigned_to', $member->id)->where('status', TaskStatus::Pending)
                ->update(['assigned_to' => $reassignTo?->id, 'updated_at' => now()]);
            FollowUp::query()->where('organization_id', $organizationId)->where('assigned_to', $member->id)
                ->whereIn('status', [FollowUpStatus::Pending, FollowUpStatus::Due])
                ->update(['assigned_to' => $reassignTo?->id, 'updated_at' => now()]);

            $member->forceFill(['status' => MemberStatus::Removed, 'removed_at' => now(), 'suspended_at' => null])->save();
            $this->endSessions($member);

            OrganizationActivity::record($organizationId, 'member_removed', $actor, $open + ['reassigned_to' => $reassignTo?->name], $member);
            Log::info('Team member removed.', ['organization_id' => $organizationId, 'user_id' => $member->id, 'actor_id' => $actor->id]);

            return $member;
        });
    }

    /**
     * The owner hands the business to another active member, confirming with their password.
     * The previous owner becomes a manager (or staff). There is always exactly one owner.
     *
     * @throws AuthorizationException|TeamActionException
     */
    public function transferOwnership(User $actor, User $newOwner, string $currentPassword, OrganizationRole $previousOwnerRole = OrganizationRole::Manager): void
    {
        if (! $actor->hasPermission(Permission::TransferOwnership)) {
            throw new AuthorizationException('Only the owner can transfer ownership.');
        }

        if (! Hash::check($currentPassword, $actor->password)) {
            throw new TeamActionException('Your current password is incorrect.');
        }

        if (! in_array($previousOwnerRole, OrganizationRole::assignable(), true)) {
            throw new TeamActionException('Choose manager or staff for yourself.');
        }

        DB::transaction(function () use ($actor, $newOwner, $previousOwnerRole) {
            // Lock both rows in a fixed order so two transfers can't interleave.
            $rows = User::query()->where('organization_id', $actor->organization_id)->whereIn('id', [$actor->id, $newOwner->id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $owner = $rows->get($actor->id);
            $target = $rows->get($newOwner->id);

            if ($owner === null || $owner->role !== OrganizationRole::Owner || $owner->status !== MemberStatus::Active) {
                throw new AuthorizationException('Only the owner can transfer ownership.');
            }

            if ($target === null || $target->id === $owner->id || $target->status !== MemberStatus::Active) {
                throw new TeamActionException('Choose an active team member other than yourself.');
            }

            // Demote first: the unique index allows one owner per organization.
            $owner->forceFill(['role' => $previousOwnerRole])->save();
            $target->forceFill(['role' => OrganizationRole::Owner])->save();

            OrganizationActivity::record($owner->organization_id, 'ownership_transferred', $owner, ['from' => $owner->name, 'to' => $target->name, 'previous_owner_role' => $previousOwnerRole->value], $target);
            Log::info('Ownership transferred.', ['organization_id' => $owner->organization_id, 'from_user_id' => $owner->id, 'to_user_id' => $target->id]);
        });

        $actor->refresh();
    }

    /**
     * Sign the person out of every session (database sessions and "remember me").
     */
    public function endSessions(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    /**
     * The member, locked, after checking the actor may manage the team and isn't acting on themselves.
     *
     * @throws AuthorizationException|TeamActionException
     */
    private function lockMember(User $actor, User $member): User
    {
        if (! $actor->hasPermission(Permission::ManageTeam)) {
            throw new AuthorizationException('You can’t manage the team.');
        }

        $locked = User::query()->where('organization_id', $actor->organization_id)->whereKey($member->id)->lockForUpdate()->first();

        if ($locked === null) {
            throw TeamActionException::notFound();
        }

        if ($locked->id === $actor->id) {
            throw new TeamActionException('You can’t change your own membership here.');
        }

        return $locked;
    }
}
