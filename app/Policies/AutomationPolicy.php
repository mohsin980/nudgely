<?php

namespace App\Policies;

use App\Models\Automation;
use App\Models\User;

/**
 * Automations (and their conditions, actions and run logs) are managed by organization
 * admins, only within their own organization.
 */
class AutomationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOrganizationAdmin();
    }

    public function view(User $user, Automation $automation): bool
    {
        return $this->ownsAutomation($user, $automation);
    }

    public function create(User $user): bool
    {
        return $user->isOrganizationAdmin();
    }

    public function update(User $user, Automation $automation): bool
    {
        return $this->ownsAutomation($user, $automation);
    }

    public function delete(User $user, Automation $automation): bool
    {
        return $this->ownsAutomation($user, $automation);
    }

    private function ownsAutomation(User $user, Automation $automation): bool
    {
        return $user->isOrganizationAdmin() && $automation->organization_id === $user->organization_id;
    }
}
