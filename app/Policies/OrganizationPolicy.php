<?php

namespace App\Policies;

use App\Enums\Platform\PlatformPermission;
use App\Models\Organization;
use App\Models\User;
use App\Support\Admin\AdminAccess;

/**
 * Platform-side rules for organizations (the Super Admin panel). Customers never match: every method needs a
 * platform permission. There is deliberately no create, edit or delete: organizations are created by sign-up and
 * only suspended or reactivated here; permanent deletion needs a separate retention workflow.
 */
class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return AdminAccess::allows($user, PlatformPermission::ViewOrganizations, audit: false);
    }

    public function view(User $user, Organization $organization): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Organization $organization): bool
    {
        return false;
    }

    public function delete(User $user, Organization $organization): bool
    {
        return false;
    }

    public function suspend(User $user, Organization $organization): bool
    {
        return AdminAccess::allows($user, PlatformPermission::SuspendOrganizations, audit: false);
    }

    public function reactivate(User $user, Organization $organization): bool
    {
        return $this->suspend($user, $organization);
    }
}
