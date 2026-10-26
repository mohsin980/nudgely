<?php

use App\Billing\PlanCatalog;
use App\Enums\Billing\LimitKey;
use App\Enums\Platform\PlatformPermission;
use App\Enums\Platform\PlatformRole;
use App\Enums\Team\MemberStatus;
use App\Filament\Pages\Plans;
use App\Filament\Pages\UsageDetails;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Models\Organization;
use App\Models\PlanSetting;
use App\Models\PlatformAdmin;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Platform\OrganizationSuspension;
use App\Services\Platform\PlanSettingsService;
use App\Services\Platform\SubscriptionRefresh;
use App\Services\Platform\UserAccountService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| SA-11: Authorization matrix for the Super Admin panel
|--------------------------------------------------------------------------
| Actors: super admin, support admin, billing admin (platform), business owner and ordinary organization user (customer),
| and a guest. Every admin route, resource action, widget and state-changing service is checked with direct requests, not
| through the UI, and the lists are guarded so new admin surface cannot appear without a matrix entry.
*/

/** @return array<string, User|null> */
function secActors(): array
{
    return [
        'guest' => null, // first: later actors would leave a session user behind
        'super' => platformAdmin(PlatformRole::SuperAdmin, ['organization_id' => null]),
        'support' => platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]),
        'billing' => platformAdmin(PlatformRole::BillingAdmin, ['organization_id' => null]),
        'owner' => User::factory()->owner()->create(),
        'staff' => User::factory()->staff()->create(),
    ];
}

/** One organization with a member, a subscription and an audit event, so every record URL exists. */
function secFixtures(): array
{
    $organization = Organization::factory()->create(['name' => 'Fixture Co', 'timezone' => 'UTC']);
    $member = User::factory()->staff()->create(['organization_id' => $organization->id]);
    $subscription = Subscription::query()->forceCreate(['organization_id' => $organization->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_sec_1', 'plan' => 'pro', 'status' => 'active']);
    $audit = PlatformAuditLog::record('plan_updated', null, $organization->id, null, null, null, 'plan', 'pro');

    return compact('organization', 'member', 'subscription', 'audit');
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// Permission matrix

test('every platform permission is held by exactly the roles the matrix says, and by no customer', function () {
    $actors = secActors();
    $roles = ['super' => PlatformRole::SuperAdmin, 'support' => PlatformRole::SupportAdmin, 'billing' => PlatformRole::BillingAdmin];

    foreach (PlatformPermission::cases() as $permission) {
        foreach ($roles as $key => $role) {
            expect(Gate::forUser($actors[$key])->allows($permission->value))->toBe(in_array($permission, $role->permissions(), true), "{$key} / {$permission->value}");
        }

        foreach (['owner', 'staff'] as $customer) {
            expect(Gate::forUser($actors[$customer])->allows($permission->value))->toBeFalse("{$customer} / {$permission->value}");
        }
    }
});

// Every admin page, by direct request

dataset('admin pages', [
    // route name => [parameters, roles allowed to open it]
    'dashboard' => ['filament.admin.pages.dashboard', [], ['super', 'support', 'billing']],
    'organizations' => ['filament.admin.resources.organizations.index', [], ['super', 'support', 'billing']],
    'organization' => ['filament.admin.resources.organizations.view', ['organization'], ['super', 'support', 'billing']],
    'users' => ['filament.admin.resources.users.index', [], ['super', 'support']],
    'user' => ['filament.admin.resources.users.view', ['member'], ['super', 'support']],
    'plans' => ['filament.admin.pages.plans', [], ['super', 'support', 'billing']],
    'subscriptions' => ['filament.admin.resources.subscriptions.index', [], ['super', 'support', 'billing']],
    'subscription' => ['filament.admin.resources.subscriptions.view', ['subscription'], ['super', 'support', 'billing']],
    'usage' => ['filament.admin.pages.usage', [], ['super', 'support', 'billing']],
    'usage details' => ['filament.admin.pages.usage.{organizationId}', ['organization'], ['super', 'support', 'billing']],
    'audit logs' => ['filament.admin.resources.audit-logs.index', [], ['super', 'support']],
    'audit log' => ['filament.admin.resources.audit-logs.view', ['audit'], ['super', 'support']],
]);

test('each admin page is open to exactly the roles the matrix allows', function (string $route, array $needs, array $allowed) {
    $fixtures = secFixtures();
    $url = route($route, collect($needs)->map(fn ($key) => $fixtures[$key]->getKey())->all());

    foreach (secActors() as $key => $actor) {
        $response = $actor === null ? test()->get($url) : test()->actingAs($actor)->get($url);

        if (in_array($key, $allowed, true)) {
            $response->assertOk();
        } elseif ($actor === null) {
            $response->assertRedirect('/admin/login');
        } else {
            $response->assertForbidden();
        }
    }
})->with('admin pages');

test('the matrix covers every admin page that exists', function () {
    $covered = collect(dataset_routes())->all();
    $registered = collect(Route::getRoutes()->getRoutesByName())
        ->filter(fn ($route, $name) => str_starts_with($name, 'filament.admin.') && in_array('GET', $route->methods(), true))
        ->keys()
        ->reject(fn ($name) => $name === 'filament.admin.auth.login')
        ->values()->all();

    expect(array_values(array_diff($registered, $covered)))->toBe([], 'An admin route has no authorization matrix entry.');
});

function dataset_routes(): array
{
    return ['filament.admin.pages.dashboard', 'filament.admin.resources.organizations.index', 'filament.admin.resources.organizations.view',
        'filament.admin.resources.users.index', 'filament.admin.resources.users.view', 'filament.admin.pages.plans',
        'filament.admin.resources.subscriptions.index', 'filament.admin.resources.subscriptions.view', 'filament.admin.pages.usage',
        'filament.admin.pages.usage.{organizationId}', 'filament.admin.resources.audit-logs.index', 'filament.admin.resources.audit-logs.view'];
}

test('record URLs for missing records are 404 for admins and never reveal anything to others', function () {
    foreach (secActors() as $key => $actor) {
        if ($actor === null) {
            continue;
        }

        foreach (['organizations', 'users', 'subscriptions', 'audit-logs'] as $resource) {
            $status = test()->actingAs($actor)->get("/admin/{$resource}/999999")->status();
            expect($status)->toBeIn([403, 404], "{$key} {$resource}");
        }
    }
});

// Navigation is backed by the server

// One test per role: the menu is built once per application instance, as it is once per request in production.
test('navigation shows only pages the role may open, and every hidden page is refused on the server', function (string $key) {
    secFixtures();
    $panel = Filament::getPanel('admin');
    $classes = collect([...$panel->getPages(), ...$panel->getResources()])->filter(fn ($class) => $class::shouldRegisterNavigation());
    $actor = secActors()[$key];
    $platform = in_array($key, ['super', 'support', 'billing'], true);

    $menu = [];
    if ($platform) {
        $html = test()->actingAs($actor)->get('/admin')->assertOk()->getContent();
        preg_match_all('/href="(http[^"]*\/admin[^"]*)"/', $html, $matches);
        $menu = array_unique($matches[1]);
    }

    foreach ($classes as $class) {
        $url = $class::getUrl();
        test()->actingAs($actor ?? new User);
        $allowed = $actor !== null && $class::canAccess();

        // A link is shown only when the server lets the person in...
        if ($platform) {
            expect(in_array($url, $menu, true))->toBe($allowed, "{$key}: menu entry for {$class}");
        }

        // ...and a page that is not allowed is refused when requested directly (not merely hidden).
        if (! $allowed && $actor !== null) {
            test()->actingAs($actor)->get($url)->assertForbidden();
        }
    }
})->with(['super', 'support', 'billing', 'owner', 'staff']);

// Actions: allowed and denied

test('sensitive actions are allowed to exactly the roles that hold their permission', function () {
    $fixtures = secFixtures();
    $organization = $fixtures['organization'];
    $customer = $fixtures['member'];
    $rows = [
        // ability => [roles allowed]
        'suspend organization' => [fn (User $u) => $u->can('suspend', $organization), ['super']],
        'reactivate organization' => [fn (User $u) => $u->can('reactivate', $organization), ['super']],
        'deactivate user' => [fn (User $u) => $u->can('deactivate', $customer), ['super']],
        'reactivate user' => [fn (User $u) => $u->can('reactivate', $customer), ['super']],
        'manage plans' => [fn (User $u) => $u->can('manage_plans'), ['super']],
        'refresh subscription' => [fn (User $u) => $u->can('manage_subscriptions'), ['super']],
        'view payments' => [fn (User $u) => $u->can('view_payments'), ['super', 'billing']],
        'view audit logs' => [fn (User $u) => $u->can('view_audit_logs'), ['super', 'support']],
        'manage roles' => [fn (User $u) => $u->can('manage_roles'), ['super']],
        'update role records' => [fn (User $u) => $u->can('update', Role::findByName('support_admin', 'web')), ['super']],
        'create roles' => [fn (User $u) => $u->can('create', Role::class), ['super']],
        'edit an organization' => [fn (User $u) => $u->can('update', $organization), []],
        'delete an organization' => [fn (User $u) => $u->can('delete', $organization), []],
        'edit a user' => [fn (User $u) => $u->can('update', $customer), []],
        'delete a user' => [fn (User $u) => $u->can('delete', $customer), []],
        'edit an audit event' => [fn (User $u) => $u->can('update', $fixtures['audit']), []],
        'delete an audit event' => [fn (User $u) => $u->can('delete', $fixtures['audit']), []],
    ];

    foreach (secActors() as $key => $actor) {
        if ($actor === null) {
            continue;
        }

        foreach ($rows as $label => [$check, $allowed]) {
            expect($check($actor))->toBe(in_array($key, $allowed, true), "{$key}: {$label}");
        }
    }
});

test('support cannot perform billing-only operations and billing cannot manage roles', function () {
    $fixtures = secFixtures();
    $support = secActors()['support'];
    $billing = secActors()['billing'];

    // Billing-only: payments and invoices (view_payments), refreshing from the provider, editing plans.
    expect(Gate::forUser($support)->allows('view_payments'))->toBeFalse()
        ->and(fn () => app(SubscriptionRefresh::class)->refresh($fixtures['subscription'], $support))->toThrow(AuthorizationException::class)
        ->and(fn () => app(PlanSettingsService::class)->reset('pro', $support, 'Support attempting a billing change'))->toThrow(AuthorizationException::class);

    // Role management is a super-admin power; billing (and support) have none of it.
    foreach ([$billing, $support] as $actor) {
        expect(Gate::forUser($actor)->allows('manage_roles'))->toBeFalse()
            ->and($actor->can('create', Role::class))->toBeFalse()
            ->and($actor->can('update', Role::findByName('super_admin', 'web')))->toBeFalse()
            ->and($actor->can('delete', Role::findByName('super_admin', 'web')))->toBeFalse();
    }
});

test('an unauthorized request cannot suspend an organization or modify a plan, through any door', function () {
    $fixtures = secFixtures();
    $organization = $fixtures['organization'];
    $limits = collect(LimitKey::cases())->mapWithKeys(fn ($k) => [$k->value => app(PlanCatalog::class)->get('pro')->limit($k)])->all();

    foreach (['support', 'billing', 'owner', 'staff'] as $key) {
        $actor = secActors()[$key];

        // Service layer
        expect(fn () => app(OrganizationSuspension::class)->suspend($organization, $actor, 'Unauthorized attempt to suspend'))->toThrow(AuthorizationException::class)
            ->and(fn () => app(PlanSettingsService::class)->update('pro', 'Pro', [...$limits, 'customers' => 9999], $actor, 'Unauthorized plan modification'))->toThrow(AuthorizationException::class);

        // Panel pages (only possible for platform admins; customers never get a page to call)
        if (in_array($key, ['support', 'billing'], true)) {
            test()->actingAs($actor);
            Livewire::test(ListOrganizations::class)->assertActionHidden(TestAction::make('suspend')->table($organization));
            Livewire::test(Plans::class)->assertActionHidden(TestAction::make('edit')->table('pro'));
        }
    }

    expect($organization->fresh()->isSuspended())->toBeFalse()
        ->and(PlanSetting::count())->toBe(0)
        ->and(PlatformAuditLog::where('outcome', 'success')->where('action', '!=', 'plan_updated')->count())->toBe(0);
});

test('a forced call of a hidden panel action still changes nothing', function () {
    $organization = secFixtures()['organization'];
    test()->actingAs(secActors()['support']);

    try {
        Livewire::test(ListOrganizations::class)->callAction(TestAction::make('suspend')->table($organization), ['reason' => 'Forcing a hidden action']);
    } catch (Throwable) {
        // Refusing loudly is fine; the state is what matters.
    }

    expect($organization->fresh()->isSuspended())->toBeFalse();
});

// Privilege escalation

test('nobody can assign themselves, or anyone, platform privileges from the application', function () {
    $actors = secActors();

    // Platform admin rows and roles cannot be mass assigned or created through any model-level door.
    expect(fn () => PlatformAdmin::query()->create(['user_id' => $actors['owner']->id]))->toThrow(MassAssignmentException::class);

    // Customer-controlled input cannot change a user's role, organization or status.
    $staff = $actors['staff'];
    $staff->fill(['role' => 'owner', 'organization_id' => $actors['owner']->organization_id, 'status' => 'active', 'is_platform_admin' => true]);
    $staff->save();
    $fresh = $staff->fresh();

    expect($fresh->role->value)->toBe('staff')->and($fresh->organization_id)->not->toBe($actors['owner']->organization_id)->and($fresh->isPlatformAdmin())->toBeFalse();

    // The panel offers no role assignment: its surface is exactly the reviewed set.
    $panel = Filament::getPanel('admin');
    $names = collect([...$panel->getResources(), ...$panel->getPages()])->map(fn ($class) => class_basename($class))->sort()->values()->all();
    expect($names)->toBe(['AuditLogResource', 'Dashboard', 'OrganizationResource', 'Plans', 'SubscriptionResource', 'UsageDetails', 'UsageMonitoring', 'UserResource'], 'The admin surface changed: add it to the authorization matrix.');
});

test('a support admin who is given a role-like permission still cannot touch more privileged accounts or themselves', function () {
    $actors = secActors();
    Role::findByName('support_admin', 'web')->givePermissionTo('manage_users');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $support = User::query()->find($actors['support']->id);

    expect($support->can('deactivate', $actors['super']))->toBeFalse()
        ->and($support->can('deactivate', $support))->toBeFalse()
        ->and(fn () => app(UserAccountService::class)->deactivate($actors['super'], $support, 'Trying to remove the super admin'))->toThrow(AuthorizationException::class);
});

test('business roles never carry platform power, and platform roles never carry business power', function () {
    $actors = secActors();

    expect($actors['owner']->can('access_admin_panel'))->toBeFalse()
        ->and($actors['owner']->can('manage_roles'))->toBeFalse()
        ->and($actors['super']->can('manage-team'))->toBeFalse() // platform admins have no organization, so no business permissions
        ->and($actors['super']->can('access-organization'))->toBeFalse();
});

// Revoked permissions take effect on the next request

test('revoking a role, the admin record or the account takes effect on the next request', function () {
    $fixtures = secFixtures();
    $url = route('filament.admin.resources.audit-logs.index');

    $support = secActors()['support'];
    test()->actingAs($support)->get($url)->assertOk();

    // 1. A role change removes the permission, but not the rest of the access.
    $support->syncRoles([PlatformRole::BillingAdmin->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->actingAs(User::query()->find($support->id))->get($url)->assertForbidden();
    test()->actingAs(User::query()->find($support->id))->get(route('filament.admin.resources.subscriptions.index'))->assertOk();

    // 2. Removing the admin record removes everything.
    PlatformAdmin::query()->where('user_id', $support->id)->delete();
    test()->actingAs(User::query()->find($support->id))->get(route('filament.admin.resources.subscriptions.index'))->assertForbidden();

    // 3. A deactivated account is refused too.
    $super = secActors()['super'];
    test()->actingAs($super)->get($url)->assertOk();
    $super->forceFill(['status' => MemberStatus::Suspended, 'suspended_at' => now()])->save();
    test()->actingAs(User::query()->find($super->id))->get($url)->assertForbidden();
});

test('permission revoked while a page is open: the next action on it is refused and audited', function () {
    $organization = secFixtures()['organization'];
    $admin = secActors()['super'];
    test()->actingAs($admin);

    $page = Livewire::test(ListOrganizations::class)->assertActionVisible(TestAction::make('suspend')->table($organization));

    $admin->syncRoles([PlatformRole::SupportAdmin->value]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    test()->actingAs(User::query()->find($admin->id));

    expect(fn () => app(OrganizationSuspension::class)->suspend($organization, User::query()->find($admin->id), 'Acting on a stale page after losing access'))->toThrow(AuthorizationException::class);
    expect($organization->fresh()->isSuspended())->toBeFalse()
        ->and(PlatformAuditLog::where('outcome', 'denied')->where('action', 'organization_suspended')->count())->toBe(1);
});

test('a customer who is demoted loses business pages on the next request', function () {
    ['owner' => $owner, 'manager' => $manager] = teamBusiness();

    test()->actingAs($manager)->get('/settings/business')->assertForbidden();
    test()->actingAs($owner)->get('/settings/business')->assertOk();

    $owner->forceFill(['role' => 'staff'])->save();
    test()->actingAs(User::query()->find($owner->id))->get('/settings/business')->assertForbidden();
});

// Exports and bulk actions

test('no admin table offers bulk actions, and no export exists', function () {
    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $listClass = $resource::getPages()['index']->getPage();
        test()->actingAs(secActors()['super']);
        $table = Livewire::test($listClass)->instance()->getTable();

        expect($table->getFlatBulkActions())->toBe([], "{$resource} has bulk actions: add them to the authorization matrix first");
    }

    // Filament's own export/import download routes exist in the router but are closed (DisableUnusedFilamentRoutes).
    // The business's own estimate import (CSV sample and problem report) is the one intended exception: it is tenant
    // data, and both routes must stay behind sign-in and the create-estimate permission.
    $intended = ['estimates.import.sample', 'estimates.import.problems'];
    $exports = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => preg_match('/export|download|csv|xlsx/i', $route->uri()) && ! str_starts_with($route->uri(), 'filament/') && ! in_array($route->getName(), $intended, true))->map->uri()->values()->all();
    expect($exports)->toBe([]);

    foreach ($intended as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())->toContain('auth', 'can:create,App\\Models\\Estimate');
    }

    foreach (['/filament/exports/1/download', '/filament/imports/1/failed-rows/download'] as $path) {
        foreach (['guest', 'owner', 'super'] as $key) {
            $actor = secActors()[$key];
            $response = $actor === null ? test()->get($path) : test()->actingAs($actor)->get($path);
            $response->assertNotFound();
        }
    }

    $code = collect(glob(app_path('Filament/**/*.php')) + glob(app_path('Filament/*/*/*.php')))->map(fn ($file) => file_get_contents($file))->implode("\n");
    expect($code)->not->toContain('ExportAction')->not->toContain('Exporter')->not->toContain('BulkAction');
});

test('unauthorized direct requests to export-like or livewire endpoints do not expose admin data', function () {
    secFixtures();

    foreach (['/admin/organizations?export=1', '/admin/users.csv', '/admin/audit-logs/export', '/admin/export'] as $path) {
        $status = test()->actingAs(secActors()['owner'])->get($path)->status();
        expect($status)->toBeIn([403, 404], $path);
    }
});

// Browser-controlled state on admin pages

test('the browser cannot forge what an admin page displays or which record it is about', function () {
    $fixtures = secFixtures();
    test()->actingAs(secActors()['billing']);

    $usage = Livewire::test(UsageDetails::class, ['organizationId' => $fixtures['organization']->id]);
    $subscription = Livewire::test(ViewSubscription::class, ['record' => $fixtures['subscription']->id]);

    foreach ([['organizationId', 999], ['row', ['name' => 'Forged']], ['activity', []]] as [$property, $value]) {
        expect(fn () => $usage->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
    }

    foreach ([['invoices', [['reference' => 'FAKE']]], ['invoicesError', 'x'], ['invoicesLoadedAt', 'x']] as [$property, $value]) {
        expect(fn () => $subscription->set($property, $value))->toThrow(CannotUpdateLockedPropertyException::class);
    }
});

test('an unknown plan key or record cannot be edited by tampering with the table action', function () {
    test()->actingAs(secActors()['super']);

    try {
        Livewire::test(Plans::class)->callAction(TestAction::make('edit')->table('platinum'), ['name' => 'Platinum', 'limit_customers' => 1, 'limit_automations' => 1, 'limit_team_members' => 1, 'limit_outbound_emails' => 1, 'limit_estimates' => 1, 'reason' => 'Tampering with the record key']);
    } catch (Throwable) {
        // Refusing loudly is fine.
    }

    expect(PlanSetting::count())->toBe(0)->and(array_keys(app(PlanCatalog::class)->all()))->toBe(['free', 'starter', 'pro']);
});
