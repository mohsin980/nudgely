<?php

namespace App\Policies;

use App\Enums\Platform\PlatformPermission;
use App\Enums\Platform\PlatformRole;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Who may manage platform roles. Used by the role-management screens in a later task; defined now so the rule
 * exists before any screen does. Every action needs manage_roles, and the built-in roles can never be deleted.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPlatformPermission(PlatformPermission::ManageRoles);
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPlatformPermission(PlatformPermission::ManageRoles);
    }

    public function create(User $user): bool
    {
        return $user->hasPlatformPermission(PlatformPermission::ManageRoles);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPlatformPermission(PlatformPermission::ManageRoles);
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPlatformPermission(PlatformPermission::ManageRoles)
            && ! in_array($role->name, PlatformRole::values(), true);
    }
}
