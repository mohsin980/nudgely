<?php

namespace Database\Seeders;

use App\Enums\Platform\PlatformPermission;
use App\Enums\Platform\PlatformRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Writes the platform roles and permissions defined in PlatformRole / PlatformPermission to the database.
 *
 * Safe to run any number of times (deploys, tests): rows are found or created, never duplicated, and each built-in
 * role is set to exactly its defined permissions. It never assigns a role to a user, so it cannot make anyone an
 * administrator. Run it with:
 *
 *   php artisan db:seed --class=PlatformRolesAndPermissionsSeeder --force
 */
class PlatformRolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        foreach (PlatformPermission::cases() as $permission) {
            Permission::findOrCreate($permission->value, PlatformRole::GUARD);
        }

        foreach (PlatformRole::cases() as $role) {
            Role::findOrCreate($role->value, PlatformRole::GUARD)
                ->syncPermissions(array_map(fn (PlatformPermission $permission) => $permission->value, $role->permissions()));
        }

        $registrar->forgetCachedPermissions();
    }
}
