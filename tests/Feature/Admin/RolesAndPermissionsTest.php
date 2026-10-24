<?php

use App\Enums\Platform\PlatformPermission;
use App\Enums\Platform\PlatformRole;
use App\Models\Customer;
use App\Models\PlatformAdmin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| SA-02: platform roles and permissions
|--------------------------------------------------------------------------
*/

// The seeder

test('the seeder creates every platform permission and role', function () {
    seedPlatformRoles();

    expect(Permission::query()->pluck('name')->sort()->values()->all())->toBe(collect(PlatformPermission::values())->sort()->values()->all())
        ->and(Role::query()->pluck('name')->sort()->values()->all())->toBe(collect(PlatformRole::values())->sort()->values()->all())
        ->and(Permission::query()->where('guard_name', '!=', 'web')->count())->toBe(0)
        ->and(count(PlatformPermission::cases()))->toBe(17);
});

test('the seeder is safe to run more than once', function () {
    seedPlatformRoles();
    $permissions = Permission::query()->count();
    $roles = Role::query()->count();
    $links = DB::table('role_has_permissions')->count();

    seedPlatformRoles();
    seedPlatformRoles();

    expect(Permission::query()->count())->toBe($permissions)
        ->and(Role::query()->count())->toBe($roles)
        ->and(DB::table('role_has_permissions')->count())->toBe($links);
});

test('each role holds exactly the permissions defined for it', function () {
    seedPlatformRoles();

    foreach (PlatformRole::cases() as $role) {
        $held = Role::findByName($role->value, 'web')->permissions->pluck('name')->sort()->values()->all();
        $defined = collect($role->permissions())->map->value->sort()->values()->all();

        expect($held)->toBe($defined, "role {$role->value}");
    }
});

test('only super_admin holds every permission', function () {
    $all = PlatformPermission::values();

    expect(collect(PlatformRole::SuperAdmin->permissions())->map->value->all())->toEqualCanonicalizing($all);

    foreach ([PlatformRole::SupportAdmin, PlatformRole::BillingAdmin, PlatformRole::BusinessOwner] as $role) {
        expect(count($role->permissions()))->toBeLessThan(count($all));
    }
});

test('support and billing administrators hold no manage or suspend permission', function () {
    foreach ([PlatformRole::SupportAdmin, PlatformRole::BillingAdmin] as $role) {
        foreach ($role->permissions() as $permission) {
            expect($permission->value)->not->toStartWith('manage_')->not->toStartWith('suspend_');
        }
    }
});

test('support administrators cannot see payments and billing administrators cannot see users', function () {
    expect(PlatformRole::SupportAdmin->permissions())->not->toContain(PlatformPermission::ViewPayments)
        ->and(PlatformRole::BillingAdmin->permissions())->not->toContain(PlatformPermission::ViewUsers)
        ->and(PlatformRole::BillingAdmin->permissions())->not->toContain(PlatformPermission::ViewAuditLogs);
});

test('the business_owner role holds no platform permission', function () {
    seedPlatformRoles();

    expect(Role::findByName('business_owner', 'web')->permissions)->toHaveCount(0)
        ->and(PlatformRole::BusinessOwner->isPlatformStaff())->toBeFalse();
});

test('seeding never assigns a role to anyone or creates a user', function () {
    User::factory()->count(3)->create();
    $users = User::query()->count();

    seedPlatformRoles();

    expect(DB::table('model_has_roles')->count())->toBe(0)
        ->and(DB::table('model_has_permissions')->count())->toBe(0)
        ->and(PlatformAdmin::query()->count())->toBe(0)
        ->and(User::query()->count())->toBe($users);
});

test('seeding restores a role to its defined permissions', function () {
    seedPlatformRoles();
    Role::findByName('billing_admin', 'web')->givePermissionTo('manage_plans');

    seedPlatformRoles();

    expect(Role::findByName('billing_admin', 'web')->hasPermissionTo('manage_plans'))->toBeFalse();
});

// Permission checks

test('a super admin may do everything', function () {
    $admin = platformAdmin(PlatformRole::SuperAdmin);

    foreach (PlatformPermission::cases() as $permission) {
        expect($admin->can($permission->value))->toBeTrue($permission->value)
            ->and($admin->hasPlatformPermission($permission))->toBeTrue();
    }
});

test('a support admin may look but not change or see payments', function () {
    $admin = platformAdmin(PlatformRole::SupportAdmin);

    expect($admin->can('view_organizations'))->toBeTrue()
        ->and($admin->can('view_users'))->toBeTrue()
        ->and($admin->can('view_audit_logs'))->toBeTrue()
        ->and($admin->can('manage_users'))->toBeFalse()
        ->and($admin->can('suspend_organizations'))->toBeFalse()
        ->and($admin->can('view_payments'))->toBeFalse()
        ->and($admin->can('manage_roles'))->toBeFalse()
        ->and($admin->can('manage_system_settings'))->toBeFalse();
});

test('a billing admin sees billing but changes nothing and cannot see users', function () {
    $admin = platformAdmin(PlatformRole::BillingAdmin);

    expect($admin->can('view_subscriptions'))->toBeTrue()
        ->and($admin->can('view_payments'))->toBeTrue()
        ->and($admin->can('view_plans'))->toBeTrue()
        ->and($admin->can('manage_subscriptions'))->toBeFalse()
        ->and($admin->can('manage_payments'))->toBeFalse()
        ->and($admin->can('manage_plans'))->toBeFalse()
        ->and($admin->can('view_users'))->toBeFalse()
        ->and($admin->can('manage_roles'))->toBeFalse();
});

test('a role on a user who is not a platform administrator grants nothing', function () {
    seedPlatformRoles();
    $customer = User::factory()->owner()->create();
    $customer->assignRole('super_admin');

    expect($customer->can('manage_plans'))->toBeFalse()
        ->and($customer->hasPlatformPermission(PlatformPermission::ViewDashboard))->toBeFalse();
    $this->actingAs($customer)->get('/admin')->assertForbidden();
});

test('being recorded as a platform administrator without a role grants nothing', function () {
    seedPlatformRoles();
    $user = User::factory()->create();
    PlatformAdmin::query()->forceCreate(['user_id' => $user->id]);

    expect($user->can('view_dashboard'))->toBeFalse();
    $this->actingAs($user)->get('/admin')->assertForbidden();
});

test('a suspended platform administrator may do nothing', function () {
    $admin = platformAdmin(PlatformRole::SuperAdmin, ['status' => 'suspended', 'suspended_at' => now()]);

    expect($admin->can('view_dashboard'))->toBeFalse()->and($admin->can('manage_roles'))->toBeFalse();
});

test('business roles never carry platform permissions', function () {
    seedPlatformRoles();

    foreach (['owner', 'manager', 'staff'] as $role) {
        $user = User::factory()->{$role}()->create();

        foreach (PlatformPermission::cases() as $permission) {
            expect($user->can($permission->value))->toBeFalse("{$role} {$permission->value}");
        }
    }
});

test('a platform role gives no business permission and a business role gives no platform permission', function () {
    $admin = platformAdmin(PlatformRole::SuperAdmin, ['role' => 'staff']);
    $owner = User::factory()->owner()->create();

    expect($admin->can('manage-team'))->toBeFalse()
        ->and($owner->can('manage-team'))->toBeTrue()
        ->and($owner->can('manage_roles'))->toBeFalse();
});

test('a role added after the permissions are cached takes effect', function () {
    $admin = platformAdmin(PlatformRole::SupportAdmin);
    expect($admin->can('view_payments'))->toBeFalse();

    $admin->syncRoles(['billing_admin']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($admin->fresh()->can('view_payments'))->toBeTrue()->and($admin->fresh()->can('view_users'))->toBeFalse();
});

// Ordinary registration

test('registering a business gives no role and no platform access', function () {
    seedPlatformRoles();

    $this->post('/register', [
        'business_name' => 'Dallas HVAC',
        'name' => 'Pat Owner',
        'email' => 'pat@example.com',
        'password' => 'a-long-password-1',
        'password_confirmation' => 'a-long-password-1',
    ]);

    $user = User::query()->where('email', 'pat@example.com')->firstOrFail();

    expect($user->roles)->toHaveCount(0)
        ->and($user->isPlatformAdmin())->toBeFalse()
        ->and($user->can('access_admin_panel'))->toBeFalse();
    $this->actingAs($user)->get('/admin')->assertForbidden();
});

// Separate from customer data

test('a platform administrator cannot open another business\'s records', function () {
    $admin = platformAdmin(PlatformRole::SuperAdmin);
    $foreignCustomer = Customer::factory()->create();

    expect($admin->organization_id)->not->toBe($foreignCustomer->organization_id);
    expect($this->actingAs($admin)->get(route('customers.show', $foreignCustomer->id))->status())->toBeIn([403, 404]);
});

test('a business user cannot reach the admin panel whatever their business role', function () {
    seedPlatformRoles();

    foreach (['owner', 'manager', 'staff'] as $role) {
        $this->actingAs(User::factory()->{$role}()->create())->get('/admin')->assertForbidden();
    }
});

// The panel

test('every platform staff role may enter the panel and see the dashboard', function () {
    foreach (PlatformRole::staffRoles() as $role) {
        $this->actingAs(platformAdmin($role))->get('/admin')->assertOk()->assertSee('Platform overview');
        auth()->logout();
    }
});

test('the business_owner role may not enter the panel even with an admin record', function () {
    $this->actingAs(platformAdmin(PlatformRole::BusinessOwner))->get('/admin')->assertForbidden();
});

test('the dashboard needs view_dashboard', function () {
    seedPlatformRoles();
    Role::findOrCreate('panel_only', 'web')->givePermissionTo('access_admin_panel');

    $this->actingAs(platformAdmin('panel_only'))->get('/admin')->assertForbidden();
});

// The role policy

test('only someone with manage_roles may manage roles', function () {
    $role = Role::findOrCreate('custom', 'web');

    $super = platformAdmin(PlatformRole::SuperAdmin);
    $support = platformAdmin(PlatformRole::SupportAdmin);
    $billing = platformAdmin(PlatformRole::BillingAdmin);

    expect($super->can('viewAny', Role::class))->toBeTrue()
        ->and($super->can('update', $role))->toBeTrue()
        ->and($support->can('viewAny', Role::class))->toBeFalse()
        ->and($support->can('update', $role))->toBeFalse()
        ->and($billing->can('create', Role::class))->toBeFalse()
        ->and(User::factory()->owner()->create()->can('viewAny', Role::class))->toBeFalse();
});

test('the built-in roles cannot be deleted, even by a super admin', function () {
    $super = platformAdmin(PlatformRole::SuperAdmin);

    foreach (PlatformRole::cases() as $built) {
        expect($super->can('delete', Role::findByName($built->value, 'web')))->toBeFalse($built->value);
    }

    expect($super->can('delete', Role::findOrCreate('custom', 'web')))->toBeTrue();
});

// The commands

test('the grant command needs an explicit role and never defaults to super_admin', function () {
    seedPlatformRoles();
    $user = User::factory()->create();

    $this->artisan('platform-admin:grant', ['email' => $user->email])->assertFailed();

    expect($user->fresh()->isPlatformAdmin())->toBeFalse()->and($user->fresh()->roles)->toHaveCount(0);
});

test('the grant command rejects unknown roles and the customer role', function () {
    seedPlatformRoles();
    $user = User::factory()->create();

    $this->artisan('platform-admin:grant', ['email' => $user->email, '--role' => ['owner_of_everything']])->assertFailed();
    $this->artisan('platform-admin:grant', ['email' => $user->email, '--role' => ['business_owner']])->assertFailed();

    expect($user->fresh()->isPlatformAdmin())->toBeFalse();
});

test('the grant command gives exactly the requested role', function () {
    seedPlatformRoles();
    $user = User::factory()->create();

    $this->artisan('platform-admin:grant', ['email' => $user->email, '--role' => ['billing_admin']])->assertSuccessful();

    expect($user->fresh()->getRoleNames()->all())->toBe(['billing_admin'])
        ->and($user->fresh()->can('view_payments'))->toBeTrue()
        ->and($user->fresh()->can('manage_roles'))->toBeFalse();
});

test('running the grant command again replaces the roles', function () {
    seedPlatformRoles();
    $user = User::factory()->create();

    $this->artisan('platform-admin:grant', ['email' => $user->email, '--role' => ['billing_admin']])->assertSuccessful();
    $this->artisan('platform-admin:grant', ['email' => $user->email, '--role' => ['support_admin']])->assertSuccessful();

    expect($user->fresh()->getRoleNames()->all())->toBe(['support_admin'])
        ->and(PlatformAdmin::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('giving super_admin asks for confirmation', function () {
    seedPlatformRoles();
    $user = User::factory()->create();

    $this->artisan('platform-admin:grant', ['email' => $user->email, '--role' => ['super_admin']])
        ->expectsConfirmation("Give {$user->email} the super_admin role? It allows everything on the platform.", 'no')
        ->assertFailed();
    expect($user->fresh()->isPlatformAdmin())->toBeFalse();

    $this->artisan('platform-admin:grant', ['email' => $user->email, '--role' => ['super_admin']])
        ->expectsConfirmation("Give {$user->email} the super_admin role? It allows everything on the platform.", 'yes')
        ->assertSuccessful();
    expect($user->fresh()->hasRole('super_admin'))->toBeTrue();
});

test('the force option skips the super_admin confirmation', function () {
    seedPlatformRoles();
    $user = User::factory()->create();

    $this->artisan('platform-admin:grant', ['email' => $user->email, '--role' => ['super_admin'], '--force' => true])->assertSuccessful();

    expect($user->fresh()->hasRole('super_admin'))->toBeTrue();
});

test('the grant command explains when the roles have not been seeded', function () {
    $user = User::factory()->create();

    $this->artisan('platform-admin:grant', ['email' => $user->email, '--role' => ['support_admin']])
        ->expectsOutputToContain('PlatformRolesAndPermissionsSeeder')
        ->assertFailed();

    expect($user->fresh()->isPlatformAdmin())->toBeFalse();
});

test('the revoke command removes the roles as well as the access', function () {
    $admin = platformAdmin(PlatformRole::SuperAdmin);

    $this->artisan('platform-admin:revoke', ['email' => $admin->email])->assertSuccessful();

    expect($admin->fresh()->roles)->toHaveCount(0)
        ->and($admin->fresh()->isPlatformAdmin())->toBeFalse()
        ->and($admin->fresh()->can('manage_roles'))->toBeFalse();
});

// Gates run for every row of a list, so they must not query per check

test('business permission checks never look up platform administrators', function () {
    $owner = User::factory()->owner()->create();

    DB::enableQueryLog();
    foreach (range(1, 25) as $ignored) {
        $owner->can('manage-team');
    }
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'platform_admins'));

    expect($queries)->toHaveCount(0);
});

test('repeated platform permission checks look the administrator up once', function () {
    $admin = platformAdmin(PlatformRole::SupportAdmin);
    $admin = User::query()->findOrFail($admin->id);

    DB::enableQueryLog();
    foreach (range(1, 25) as $ignored) {
        $admin->can('view_users');
    }
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'platform_admins'));

    expect($queries->count())->toBeLessThanOrEqual(1);
});
