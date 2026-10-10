<?php

namespace App\Console\Commands;

use App\Enums\Platform\PlatformRole;
use App\Enums\Team\MemberStatus;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

/**
 * The only way to become a platform administrator, and the only way to give a platform role. It runs on the server,
 * for an account that already exists: there is no web form, registration option or API for it.
 *
 * The roles named with --role are set exactly (they replace the person's previous platform roles), so running it
 * again with a different role changes what that person can do.
 */
class PlatformAdminGrantCommand extends Command
{
    protected $signature = 'platform-admin:grant
        {email : Email of an existing, active account}
        {--role=* : Platform role to give: super_admin, support_admin or billing_admin (required)}
        {--force : Skip the confirmation when giving super_admin}';

    protected $description = 'Make an existing user a platform administrator with the given role(s) for the Super Admin panel (/admin)';

    public function handle(): int
    {
        $roles = $this->requestedRoles();

        if ($roles === null) {
            return self::FAILURE;
        }

        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->error('No account has that email. Register the account first, then run this command.');

            return self::FAILURE;
        }

        if ($user->status !== MemberStatus::Active) {
            $this->error('That account is not active, so it cannot be made a platform administrator.');

            return self::FAILURE;
        }

        if (Role::query()->whereIn('name', $roles)->where('guard_name', PlatformRole::GUARD)->count() !== count($roles)) {
            $this->error('The platform roles are not in the database yet. Run: php artisan db:seed --class=PlatformRolesAndPermissionsSeeder --force');

            return self::FAILURE;
        }

        if (in_array(PlatformRole::SuperAdmin->value, $roles, true) && ! $this->option('force')
            && ! $this->confirm("Give {$user->email} the super_admin role? It allows everything on the platform.")) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        if (! $user->isPlatformAdmin()) {
            // forceCreate: the model refuses mass assignment, so a grant can only come from deliberate code like this.
            PlatformAdmin::query()->forceCreate(['user_id' => $user->id]);
        }

        $user->syncRoles($roles);

        $this->info("Granted: {$user->email} is a platform administrator with role(s): ".implode(', ', $roles).'. They can sign in at /admin.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null The validated role names, or null after printing why they are not valid.
     */
    private function requestedRoles(): ?array
    {
        $roles = array_values(array_unique(array_map('trim', (array) $this->option('role'))));
        $allowed = array_map(fn (PlatformRole $role) => $role->value, PlatformRole::staffRoles());

        if ($roles === []) {
            $this->error('Choose at least one role with --role. Nobody is made a super admin by default. Roles: '.implode(', ', $allowed).'.');

            return null;
        }

        if (array_diff($roles, $allowed) !== []) {
            $this->error('Unknown or not allowed role. Choose from: '.implode(', ', $allowed).'.');

            return null;
        }

        return $roles;
    }
}
