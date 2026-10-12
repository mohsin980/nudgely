<?php

use App\Enums\Platform\PlatformRole;
use App\Exceptions\Platform\OrganizationStateException;
use App\Filament\Resources\Organizations\OrganizationResource;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Resources\Organizations\RelationManagers\UsersRelationManager;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\PlatformAuditLog;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Platform\OrganizationSuspension;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| SA-05: Organization management
|--------------------------------------------------------------------------
*/

function orgWithPlan(string $name, ?string $plan = null, string $status = 'active'): Organization
{
    static $n = 0;
    $organization = Organization::factory()->create(['name' => $name]);

    if ($plan !== null) {
        Subscription::query()->forceCreate([
            'organization_id' => $organization->id, 'provider' => 'stripe', 'provider_subscription_id' => 'sub_org_'.++$n,
            'plan' => $plan, 'status' => $status,
        ]);
    }

    return $organization;
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// Listing

test('an authorized admin sees a paginated organization list with the key fields', function () {
    $this->actingAs(platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]));
    $acme = orgWithPlan('Acme Plumbing', 'pro');
    User::factory()->count(2)->create(['organization_id' => $acme->id]);

    Livewire::test(ListOrganizations::class)
        ->assertCanSeeTableRecords([$acme])
        ->assertSee('Acme Plumbing')
        ->assertSee('Pro')
        ->assertSee('Active');
});

test('the list is paginated', function () {
    $this->actingAs(platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]));
    Organization::factory()->count(30)->create();
    Organization::query()->where('id', auth()->user()->organization_id)->delete();

    $list = Livewire::test(ListOrganizations::class);
    expect($list->instance()->getTableRecords())->toHaveCount(25);

    $list->call('gotoPage', 2);
    expect($list->instance()->getTableRecords())->toHaveCount(5);
});

test('search finds organizations by name or by exact ID', function () {
    $this->actingAs(platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]));
    $acme = orgWithPlan('Acme Plumbing');
    $other = orgWithPlan('Zenith Roofing');

    Livewire::test(ListOrganizations::class)
        ->searchTable('plumb')
        ->assertCanSeeTableRecords([$acme])->assertCanNotSeeTableRecords([$other])
        ->searchTable((string) $other->id)
        ->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$acme]);
});

test('filters narrow by plan, subscription status and suspension', function () {
    $admin = platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]);
    $this->actingAs($admin);
    $pro = orgWithPlan('Pro Co', 'pro');
    $trial = orgWithPlan('Trial Co', 'starter', 'trialing');
    $none = orgWithPlan('Free Co');
    $suspended = orgWithPlan('Paused Co');
    $suspended->forceFill(['suspended_at' => now()])->save();

    Livewire::test(ListOrganizations::class)
        ->filterTable('plan', 'pro')->assertCanSeeTableRecords([$pro])->assertCanNotSeeTableRecords([$trial, $none])
        ->resetTableFilters()
        ->filterTable('subscription_status', 'trialing')->assertCanSeeTableRecords([$trial])->assertCanNotSeeTableRecords([$pro])
        ->resetTableFilters()
        ->filterTable('status', 'suspended')->assertCanSeeTableRecords([$suspended])->assertCanNotSeeTableRecords([$pro, $none])
        ->resetTableFilters()
        ->filterTable('status', 'active')->assertCanNotSeeTableRecords([$suspended])->assertCanSeeTableRecords([$none]);
});

test('listing queries stay constant as organizations grow', function () {
    $this->actingAs(platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]));

    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::test(ListOrganizations::class);

        return count(DB::getQueryLog());
    };

    orgWithPlan('One', 'pro');
    $count(); // warm-up: one-off permission and config queries
    $small = $count();
    foreach (range(1, 12) as $i) {
        orgWithPlan("Org {$i}", 'starter');
    }

    expect($count())->toBe($small);
});

// Details

test('details show business, subscription, usage and users but no credentials or content', function () {
    $admin = platformAdmin(PlatformRole::SuperAdmin, ['organization_id' => null]);
    $this->actingAs($admin);
    $org = orgWithPlan('Acme Plumbing', 'pro');
    $member = User::factory()->create(['organization_id' => $org->id, 'email' => 'owner@acme.test']);
    Customer::factory()->create(['organization_id' => $org->id]);

    Livewire::test(ViewOrganization::class, ['record' => $org->id])
        ->assertSee('Acme Plumbing')
        ->assertSee('Pro')
        ->assertSee('Customers')
        ->assertDontSee($member->password)
        ->assertDontSee('remember_token');

    Livewire::test(UsersRelationManager::class, ['ownerRecord' => $org, 'pageClass' => ViewOrganization::class])
        ->assertCanSeeTableRecords([$member])
        ->assertSee('owner@acme.test');
});

test('detail sections follow the permissions of the administrator', function () {
    $org = orgWithPlan('Acme Plumbing', 'pro');

    $this->actingAs(platformAdmin(PlatformRole::BillingAdmin, ['organization_id' => null]));
    Livewire::test(ViewOrganization::class, ['record' => $org->id])
        ->assertSee('Subscription')->assertSee('Usage')
        ->assertDontSee('Platform history');
    expect(UsersRelationManager::canViewForRecord($org, ViewOrganization::class))->toBeFalse();

    $this->actingAs(platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]));
    Livewire::test(ViewOrganization::class, ['record' => $org->id])->assertSee('Platform history');
    expect(UsersRelationManager::canViewForRecord($org, ViewOrganization::class))->toBeTrue();
});

// Access control

test('customers and guests cannot reach the organization pages', function () {
    $org = orgWithPlan('Acme Plumbing');

    $this->get('/admin/organizations')->assertRedirect('/admin/login');

    $this->actingAs(User::factory()->owner()->create());
    $this->get('/admin/organizations')->assertForbidden();
    $this->get("/admin/organizations/{$org->id}")->assertForbidden();
    expect(OrganizationResource::canAccess())->toBeFalse();
    Livewire::test(ListOrganizations::class)->assertForbidden();
});

test('an administrator without view_organizations is refused', function () {
    seedPlatformRoles();
    $this->actingAs(platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]));
    expect(OrganizationResource::canAccess())->toBeTrue();

    Role::findByName('support_admin', 'web')->revokePermissionTo('view_organizations');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs(User::query()->find(auth()->id()));
    expect(OrganizationResource::canAccess())->toBeFalse();
});

test('nobody can create, edit or delete an organization through the policy', function () {
    $org = orgWithPlan('Acme');
    $admin = platformAdmin(PlatformRole::SuperAdmin, ['organization_id' => null]);

    foreach (['create', 'update', 'delete'] as $ability) {
        expect($admin->can($ability, $org))->toBeFalse($ability);
    }
    expect($admin->can('create', Organization::class))->toBeFalse();
});

// Suspension

test('only administrators with suspend_organizations may suspend', function () {
    $org = orgWithPlan('Acme');

    foreach ([PlatformRole::SupportAdmin, PlatformRole::BillingAdmin] as $role) {
        $admin = platformAdmin($role, ['organization_id' => null]);
        expect(fn () => app(OrganizationSuspension::class)->suspend($org, $admin, 'Chargeback fraud investigation'))
            ->toThrow(AuthorizationException::class);
    }

    expect($org->fresh()->isSuspended())->toBeFalse()->and(PlatformAuditLog::count())->toBe(0);
});

test('a customer cannot suspend an organization even through the service', function () {
    $org = orgWithPlan('Acme');
    $owner = User::factory()->owner()->create();

    expect(fn () => app(OrganizationSuspension::class)->suspend($org, $owner, 'Trying to lock out a rival'))
        ->toThrow(AuthorizationException::class);
});

test('suspending needs a reason, records who and why, and is reversible', function () {
    $admin = platformAdmin(PlatformRole::SuperAdmin, ['organization_id' => null]);
    $org = orgWithPlan('Acme');
    $service = app(OrganizationSuspension::class);

    expect(fn () => $service->suspend($org, $admin, 'short'))->toThrow(OrganizationStateException::class);
    expect($org->fresh()->isSuspended())->toBeFalse();

    $service->suspend($org, $admin, '  Repeated   abuse reports  ');

    $log = PlatformAuditLog::sole();
    expect($org->fresh()->isSuspended())->toBeTrue()
        ->and($org->fresh()->suspended_by)->toBe($admin->id)
        ->and($log->action)->toBe('organization_suspended')
        ->and($log->actor_id)->toBe($admin->id)
        ->and($log->organization_id)->toBe($org->id)
        ->and($log->reason)->toBe('Repeated abuse reports');

    expect(fn () => $service->suspend($org, $admin, 'Suspending a second time'))->toThrow(OrganizationStateException::class);

    $service->reactivate($org, $admin, 'Issue resolved with the owner');
    expect($org->fresh()->isSuspended())->toBeFalse()->and(PlatformAuditLog::count())->toBe(2);
});

test('the internal reason never reaches the business activity feed', function () {
    $admin = platformAdmin(PlatformRole::SuperAdmin, ['organization_id' => null]);
    $org = orgWithPlan('Acme');

    app(OrganizationSuspension::class)->suspend($org, $admin, 'Internal note: suspected fraud');

    expect(OrganizationActivity::query()->where('organization_id', $org->id)->where('action', 'like', '%suspend%')->count())->toBe(0);
});

test('an administrator cannot suspend their own organization', function () {
    $admin = platformAdmin(PlatformRole::SuperAdmin);

    expect(fn () => app(OrganizationSuspension::class)->suspend($admin->organization, $admin, 'Locking myself out'))
        ->toThrow(OrganizationStateException::class);
});

test('the suspend action in the panel confirms, requires a reason and audits', function () {
    $admin = platformAdmin(PlatformRole::SuperAdmin, ['organization_id' => null]);
    $this->actingAs($admin);
    $org = orgWithPlan('Acme');

    Livewire::test(ListOrganizations::class)
        ->callAction(TestAction::make('suspend')->table($org), ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);
    expect($org->fresh()->isSuspended())->toBeFalse();

    Livewire::test(ListOrganizations::class)
        ->callAction(TestAction::make('suspend')->table($org), ['reason' => 'Non-payment after final notice'])
        ->assertHasNoActionErrors();

    expect($org->fresh()->isSuspended())->toBeTrue()
        ->and(PlatformAuditLog::sole()->reason)->toBe('Non-payment after final notice');
});

test('the suspend action is hidden from administrators who may not use it', function () {
    $this->actingAs(platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]));
    $org = orgWithPlan('Acme');

    Livewire::test(ListOrganizations::class)->assertActionHidden(TestAction::make('suspend')->table($org));
});

// Effect on customers

test('members of a suspended organization are signed out and cannot use the app', function () {
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner)->get('/customers')->assertOk();

    $owner->organization->forceFill(['suspended_at' => now()])->save();

    $this->actingAs(User::query()->find($owner->id))->get('/customers')->assertRedirect('/login');
    $this->assertGuest();
});

test('suspension affects only that organization and is lifted by reactivation', function () {
    $suspendedOwner = User::factory()->owner()->create();
    $otherOwner = User::factory()->owner()->create();
    $suspendedOwner->organization->forceFill(['suspended_at' => now()])->save();

    $this->actingAs($otherOwner)->get('/customers')->assertOk();

    $suspendedOwner->organization->forceFill(['suspended_at' => null])->save();
    $this->actingAs(User::query()->find($suspendedOwner->id))->get('/customers')->assertOk();
});

test('a suspended member cannot sign back in', function () {
    $owner = User::factory()->owner()->create(['password' => 'a-long-password-1']);
    $owner->organization->forceFill(['suspended_at' => now()])->save();

    $this->post('/login', ['email' => $owner->email, 'password' => 'a-long-password-1']);
    $this->get('/dashboard')->assertRedirect('/login');
});

test('customer data stays in place while suspended', function () {
    $admin = platformAdmin(PlatformRole::SuperAdmin, ['organization_id' => null]);
    $org = orgWithPlan('Acme', 'pro');
    Customer::factory()->count(3)->create(['organization_id' => $org->id]);
    $users = User::factory()->count(2)->create(['organization_id' => $org->id]);

    app(OrganizationSuspension::class)->suspend($org, $admin, 'Policy violation under review');

    expect(Customer::where('organization_id', $org->id)->count())->toBe(3)
        ->and(User::where('organization_id', $org->id)->count())->toBe(2)
        ->and(Subscription::where('organization_id', $org->id)->count())->toBe(1);
});
