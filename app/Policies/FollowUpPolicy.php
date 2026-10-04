<?php

namespace App\Policies;

use App\Models\FollowUp;
use App\Models\User;

/**
 * Anyone in the organization works its follow-ups; nobody touches another organization's.
 */
class FollowUpPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActiveMember();
    }

    public function create(User $user): bool
    {
        return $user->isActiveMember();
    }

    public function view(User $user, FollowUp $followUp): bool
    {
        return $this->sameOrganization($user, $followUp);
    }

    /**
     * Complete, reschedule, cancel or send.
     */
    public function update(User $user, FollowUp $followUp): bool
    {
        return $this->sameOrganization($user, $followUp);
    }

    private function sameOrganization(User $user, FollowUp $followUp): bool
    {
        return $user->isActiveMember() && $followUp->organization_id === $user->organization_id;
    }
}
