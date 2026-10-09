<?php

namespace App\Policies;

use App\Models\Estimate;
use App\Models\User;

/**
 * Anyone in the organization works with its estimates; nobody sees another organization's.
 */
class EstimatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isActiveMember();
    }

    public function view(User $user, Estimate $estimate): bool
    {
        return $user->isActiveMember() && $estimate->organization_id === $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->isActiveMember();
    }

    public function update(User $user, Estimate $estimate): bool
    {
        return $this->view($user, $estimate);
    }
}
