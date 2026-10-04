<?php

namespace App\Policies;

use App\Enums\Team\Permission;
use App\Models\Automation;
use App\Models\User;

/**
 * Everyone in the organization can see its automations and their logs; owners and managers
 * build and run them. Never across organizations.
 */
class AutomationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ViewAutomations);
    }

    public function view(User $user, Automation $automation): bool
    {
        return $user->hasPermission(Permission::ViewAutomations) && $automation->organization_id === $user->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ManageAutomations);
    }

    public function update(User $user, Automation $automation): bool
    {
        return $this->manages($user, $automation);
    }

    public function delete(User $user, Automation $automation): bool
    {
        return $this->manages($user, $automation);
    }

    private function manages(User $user, Automation $automation): bool
    {
        return $user->hasPermission(Permission::ManageAutomations) && $automation->organization_id === $user->organization_id;
    }
}
