<?php

namespace App\Policies;

use App\Enums\Team\Permission;
use App\Models\Subscription;
use App\Models\User;

/**
 * Billing is the owner's: only they see or change the subscription, and only their own organization's.
 */
class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ManageBilling);
    }

    public function view(User $user, Subscription $subscription): bool
    {
        return $this->manages($user, $subscription);
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return $this->manages($user, $subscription);
    }

    private function manages(User $user, Subscription $subscription): bool
    {
        return $user->hasPermission(Permission::ManageBilling) && $subscription->organization_id === $user->organization_id;
    }
}
