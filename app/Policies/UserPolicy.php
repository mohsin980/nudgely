<?php

namespace App\Policies;

use App\Enums\Platform\PlatformPermission;
use App\Models\User;
use App\Support\Admin\AdminAccess;

/**
 * Platform-side rules for user accounts (the Super Admin panel). Customers never match.
 *
 * There is deliberately no create, edit or delete, and no ability to grant platform roles: platform privileges
 * are given only on the server with `platform-admin:grant`. Deactivating needs manage_users, nobody can act on
 * their own account, and changing another platform administrator's account additionally needs manage_roles
 * (super admin), so support or billing staff can never touch the people who hold more privileges than they do.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return AdminAccess::allows($user, PlatformPermission::ViewUsers, audit: false);
    }

    public function view(User $user, User $target): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, User $target): bool
    {
        return false;
    }

    public function delete(User $user, User $target): bool
    {
        return false;
    }

    public function deactivate(User $user, User $target): bool
    {
        return $this->manage($user, $target);
    }

    public function reactivate(User $user, User $target): bool
    {
        return $this->manage($user, $target);
    }

    private function manage(User $actor, User $target): bool
    {
        if ($actor->is($target) || ! AdminAccess::allows($actor, PlatformPermission::ManageUsers, audit: false)) {
            return false;
        }

        return ! $target->isPlatformAdmin() || AdminAccess::allows($actor, PlatformPermission::ManageRoles, audit: false);
    }
}
