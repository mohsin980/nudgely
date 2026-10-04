<?php

namespace App\Services\Team;

use App\Enums\OrganizationRole;
use App\Models\Automation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who in an organization can be assigned work, notified, or stand in for an automation's owner.
 * Only active members ever qualify; suspended and removed people are never picked.
 */
class TeamDirectory
{
    /**
     * @return Collection<int, User>
     */
    public function activeMembers(int $organizationId): Collection
    {
        return User::query()->activeIn($organizationId)->orderBy('name')->get();
    }

    /**
     * Name by ID, for assignee pickers.
     *
     * @return Collection<int, string>
     */
    public function assignableOptions(int $organizationId): Collection
    {
        return User::query()->activeIn($organizationId)->orderBy('name')->pluck('name', 'id');
    }

    /**
     * An active member of the organization, or null (wrong organization, suspended, removed, unknown).
     */
    public function activeMember(int $organizationId, int|string|null $userId): ?User
    {
        if ($userId === null || $userId === '' || ! ctype_digit((string) $userId)) {
            return null;
        }

        return User::query()->activeIn($organizationId)->find((int) $userId);
    }

    public function owner(int $organizationId): ?User
    {
        return User::query()->activeIn($organizationId)->where('role', OrganizationRole::Owner)->first();
    }

    /**
     * The owner and managers: who hears about things nobody in particular is responsible for.
     *
     * @return Collection<int, User>
     */
    public function leaders(int $organizationId): Collection
    {
        return User::query()->activeIn($organizationId)
            ->whereIn('role', [OrganizationRole::Owner, OrganizationRole::Manager])
            ->orderBy('id')->get();
    }

    /**
     * Who an automation acts for: its creator while active, else the business's default
     * automation owner, else the owner.
     */
    public function automationOwner(Automation $automation): ?User
    {
        return $this->activeMember($automation->organization_id, $automation->created_by)
            ?? $this->activeMember($automation->organization_id, Organization::find($automation->organization_id)?->businessSettings()->automationOwnerId())
            ?? $this->owner($automation->organization_id);
    }

    /**
     * Default assignee for automation tasks that don't name one (null = unassigned).
     */
    public function defaultTaskAssignee(Organization $organization): ?User
    {
        return $this->activeMember($organization->id, $organization->businessSettings()->taskAssigneeId());
    }

    /**
     * Who "Notify the business" reaches: the chosen person, else the owner and managers.
     *
     * @return Collection<int, User>
     */
    public function businessRecipients(Organization $organization): Collection
    {
        $chosen = $this->activeMember($organization->id, $organization->businessSettings()->notifyUserId());

        return $chosen !== null ? collect([$chosen]) : $this->leaders($organization->id);
    }
}
