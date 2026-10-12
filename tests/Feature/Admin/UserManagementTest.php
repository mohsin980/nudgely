<?php

use App\Enums\Platform\PlatformRole;
use App\Enums\Team\MemberStatus;
use App\Exceptions\Platform\UserAccountException;
use App\Exceptions\Team\TeamActionException;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\Platform\UserAccountService;
use App\Services\Team\TeamService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| SA-06: User and access management
|--------------------------------------------------------------------------
*/

function superAdmin(): User
{
    return platformAdmin(PlatformRole::SuperAdmin, ['organization_id' => null]);
}

function supportAdmin(): User
{
    return platformAdmin(PlatformRole::SupportAdmin, ['organization_id' => null]);
}

function member(array $state = []): User
{
    return User::factory()->staff()->create($state);
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

// Listing

test('an authorized admin sees users with organization, status and verification', function () {
    $this->actingAs(supportAdmin());
    $organization = Organization::factory()->create(['name' => 'Acme Plumbing']);
    $user = member(['organization_id' => $organization->id, 'name' => 'Jane Roe', 'email' => 'jane@acme.test']);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$user])
        ->assertSee('Jane Roe')->assertSee('jane@acme.test')->assertSee('Acme Plumbing')->assertSee('Active');
});

test('search finds users by name or email', function () {
    $this->actingAs(supportAdmin());
    $jane = member(['name' => 'Jane Roe', 'email' => 'jane@acme.test']);
    $bob = member(['name' => 'Bob Stone', 'email' => 'bob@zenith.test']);

    Livewire::test(ListUsers::class)
        ->searchTable('jane')->assertCanSeeTableRecords([$jane])->assertCanNotSeeTableRecords([$bob])
        ->searchTable('zenith')->assertCanSeeTableRecords([$bob])->assertCanNotSeeTableRecords([$jane]);
});

test('filters narrow by organization, status, role and email verification', function () {
    $this->actingAs(supportAdmin());
    $acme = Organization::factory()->create();
    $inAcme = member(['organization_id' => $acme->id, 'email_verified_at' => now()]);
    $other = member(['email_verified_at' => null]);
    $suspended = member(['status' => MemberStatus::Suspended, 'suspended_at' => now()]);

    Livewire::test(ListUsers::class)
        ->filterTable('organization_id', $acme->id)->assertCanSeeTableRecords([$inAcme])->assertCanNotSeeTableRecords([$other])
        ->resetTableFilters()
        ->filterTable('status', 'suspended')->assertCanSeeTableRecords([$suspended])->assertCanNotSeeTableRecords([$inAcme])
        ->resetTableFilters()
        ->filterTable('verified', true)->assertCanSeeTableRecords([$inAcme])->assertCanNotSeeTableRecords([$other])
        ->resetTableFilters()
        ->filterTable('verified', false)->assertCanSeeTableRecords([$other])->assertCanNotSeeTableRecords([$inAcme])
        ->resetTableFilters()
        ->filterTable('role', 'staff')->assertCanSeeTableRecords([$inAcme, $other]);
});

test('the list is paginated and its queries do not grow with the data', function () {
    $this->actingAs(supportAdmin());
    User::factory()->count(30)->create();

    $count = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $list = Livewire::test(ListUsers::class);
        $queries = count(DB::getQueryLog());

        return [$queries, $list];
    };

    $count();
    [$small, $list] = $count();
    expect($list->instance()->getTableRecords())->toHaveCount(25);

    User::factory()->count(20)->create();
    [$large] = $count();
    expect($large)->toBe($small);
});

// Details and secrets

test('the user list never loads password hashes or tokens', function () {
    $this->actingAs(supportAdmin());
    member();

    $record = UserResource::getEloquentQuery()->first();

    expect($record->getAttributes())->not->toHaveKeys(['password', 'remember_token']);
});

test('details show membership and platform access but no secrets', function () {
    $this->actingAs(supportAdmin());
    $organization = Organization::factory()->create(['name' => 'Acme Plumbing']);
    $user = member(['organization_id' => $organization->id]);
    $hash = DB::table('users')->where('id', $user->id)->value('password');
    DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'reset-token-abc123', 'created_at' => now()]);

    Livewire::test(ViewUser::class, ['record' => $user->id])
        ->assertSee($user->email)->assertSee('Acme Plumbing')->assertSee('Staff')
        ->assertDontSee($hash)->assertDontSee('reset-token-abc123');
});

// Access control

test('customers, guests and admins without view_users cannot open the pages', function () {
    $user = member();

    $this->get('/admin/users')->assertRedirect('/admin/login');

    $this->actingAs(User::factory()->owner()->create());
    $this->get('/admin/users')->assertForbidden();
    $this->get("/admin/users/{$user->id}")->assertForbidden();
    expect(UserResource::canAccess())->toBeFalse();

    $this->actingAs(platformAdmin(PlatformRole::BillingAdmin, ['organization_id' => null]));
    expect(UserResource::canAccess())->toBeFalse();
    $this->get('/admin/users')->assertForbidden();
});

test('support can view users but not deactivate them', function () {
    $support = supportAdmin();
    $user = member();
    $this->actingAs($support);

    Livewire::test(ListUsers::class)->assertActionHidden(TestAction::make('deactivate')->table($user));

    expect(fn () => app(UserAccountService::class)->deactivate($user, $support, 'Support should not be able to do this'))
        ->toThrow(AuthorizationException::class);
    expect($user->fresh()->status)->toBe(MemberStatus::Active);
});

test('a customer cannot deactivate anyone through the platform service', function () {
    $owner = User::factory()->owner()->create();
    $other = member();

    expect(fn () => app(UserAccountService::class)->deactivate($other, $owner, 'Trying to lock out a rival'))
        ->toThrow(AuthorizationException::class);
});

// Privilege escalation

test('no administrator can edit, delete or create accounts or grant platform privileges through the policy', function () {
    $target = member();

    foreach ([superAdmin(), supportAdmin()] as $admin) {
        foreach (['update', 'delete'] as $ability) {
            expect($admin->can($ability, $target))->toBeFalse($ability);
        }
        expect($admin->can('create', User::class))->toBeFalse();
    }

    $user = member();
    expect($user->isPlatformAdmin())->toBeFalse();
});

test('an administrator cannot act on their own account', function () {
    $admin = superAdmin();

    expect($admin->can('deactivate', $admin))->toBeFalse()
        ->and(fn () => app(UserAccountService::class)->deactivate($admin, $admin, 'Locking myself out of the panel'))
        ->toThrow(AuthorizationException::class);
});

test('an administrator with only manage_users cannot touch platform administrators', function () {
    $support = supportAdmin();
    $boss = superAdmin();
    $customer = member();

    // Created after the admins: building a platform admin re-runs the seeder, which resets role permissions.
    Role::findByName('support_admin', 'web')->givePermissionTo('manage_users');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $support = User::query()->find($support->id);

    expect($support->can('deactivate', $boss))->toBeFalse()
        ->and($support->can('deactivate', $customer))->toBeTrue();

    expect(fn () => app(UserAccountService::class)->deactivate($boss, $support, 'Trying to remove the super admin'))
        ->toThrow(AuthorizationException::class);
    expect($boss->fresh()->status)->toBe(MemberStatus::Active);
});

test('a super admin may deactivate another platform administrator', function () {
    $boss = superAdmin();
    $other = supportAdmin();

    app(UserAccountService::class)->deactivate($other, $boss, 'Left the company, access removed');

    $this->actingAs(User::query()->find($other->id))->get('/admin')->assertForbidden();
});

// Account state changes

test('deactivation needs a reason, ends sessions and tokens, and is audited without exposing the reason to the business', function () {
    $admin = superAdmin();
    $user = member(['remember_token' => 'old-remember-token']);
    DB::table('sessions')->insert(['id' => 'sess-1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $service = app(UserAccountService::class);

    expect(fn () => $service->deactivate($user, $admin, 'short'))->toThrow(UserAccountException::class);
    expect($user->fresh()->status)->toBe(MemberStatus::Active);

    $service->deactivate($user, $admin, '  Compromised   credentials reported ');

    $fresh = $user->fresh();
    $log = PlatformAuditLog::sole();
    expect($fresh->status)->toBe(MemberStatus::Suspended)
        ->and($fresh->suspended_at)->not->toBeNull()
        ->and($fresh->remember_token)->not->toBe('old-remember-token')
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and($log->action)->toBe('user_deactivated')
        ->and($log->actor_id)->toBe($admin->id)
        ->and($log->subject_user_id)->toBe($user->id)
        ->and($log->reason)->toBe('Compromised credentials reported');

    $activity = OrganizationActivity::query()->where('organization_id', $user->organization_id)->where('action', 'member_suspended')->sole();
    expect($activity->user_id)->toBeNull()->and($activity->subject_user_id)->toBe($user->id)->and($activity->data)->toBeNull();
});

test('a deactivated user is refused on the next request and cannot sign in', function () {
    $admin = superAdmin();
    $user = User::factory()->staff()->create(['password' => 'a-long-password-1']);
    $this->actingAs($user)->get('/customers')->assertOk();

    app(UserAccountService::class)->deactivate($user, $admin, 'Policy violation under review');

    $this->actingAs(User::query()->find($user->id))->get('/customers')->assertRedirect('/login');
    $this->post('/login', ['email' => $user->email, 'password' => 'a-long-password-1']);
    $this->assertGuest();
});

test('reactivation restores sign-in with the existing password and is audited', function () {
    $admin = superAdmin();
    $user = User::factory()->staff()->create(['password' => 'a-long-password-1']);
    $hashBefore = DB::table('users')->where('id', $user->id)->value('password');
    $service = app(UserAccountService::class);

    $service->deactivate($user, $admin, 'Temporary hold during review');
    $service->reactivate($user, $admin, 'Review finished, all clear');

    expect($user->fresh()->status)->toBe(MemberStatus::Active)
        ->and(DB::table('users')->where('id', $user->id)->value('password'))->toBe($hashBefore)
        ->and(PlatformAuditLog::pluck('action')->all())->toBe(['user_deactivated', 'user_reactivated']);

    $this->post('/login', ['email' => $user->email, 'password' => 'a-long-password-1'])->assertRedirect();
    $this->assertAuthenticatedAs($user);
});

test('only active accounts can be deactivated and only deactivated ones reactivated', function () {
    $admin = superAdmin();
    $service = app(UserAccountService::class);
    $active = member();
    $suspended = member(['status' => MemberStatus::Suspended, 'suspended_at' => now()]);
    $removed = member(['status' => MemberStatus::Removed]);

    expect(fn () => $service->reactivate($active, $admin, 'Not actually deactivated'))->toThrow(UserAccountException::class)
        ->and(fn () => $service->deactivate($suspended, $admin, 'Already deactivated'))->toThrow(UserAccountException::class)
        ->and(fn () => $service->deactivate($removed, $admin, 'Removed accounts stay removed'))->toThrow(UserAccountException::class);
});

test('a business owner cannot be deactivated individually', function () {
    $admin = superAdmin();
    $owner = User::factory()->owner()->create();

    expect(fn () => app(UserAccountService::class)->deactivate($owner, $admin, 'Owner account under review'))
        ->toThrow(UserAccountException::class);
    expect($owner->fresh()->status)->toBe(MemberStatus::Active);
});

test('the deactivate action in the panel confirms, requires a reason and audits', function () {
    $this->actingAs(superAdmin());
    $user = member();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('deactivate')->table($user), ['reason' => ''])
        ->assertHasActionErrors(['reason' => 'required']);
    expect($user->fresh()->status)->toBe(MemberStatus::Active);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('deactivate')->table($user), ['reason' => 'Reported abuse from this account'])
        ->assertHasNoActionErrors();

    expect($user->fresh()->status)->toBe(MemberStatus::Suspended)
        ->and(PlatformAuditLog::sole()->reason)->toBe('Reported abuse from this account');
});

// Organization boundaries

test('a customer administrator cannot modify a user of another organization', function () {
    $owner = User::factory()->owner()->create();
    $foreign = User::factory()->staff()->create();

    expect(fn () => app(TeamService::class)->suspend($owner, $foreign))->toThrow(TeamActionException::class);
    expect($foreign->fresh()->status)->toBe(MemberStatus::Active);
});

test('deactivating one user does not affect another organization', function () {
    $admin = superAdmin();
    $target = member();
    $bystander = member();

    app(UserAccountService::class)->deactivate($target, $admin, 'Only this account is affected');

    expect($bystander->fresh()->status)->toBe(MemberStatus::Active);
    $this->actingAs($bystander)->get('/customers')->assertOk();
});

test('existing sign-in still works for active customers', function () {
    $user = User::factory()->owner()->create(['password' => 'a-long-password-1']);

    $this->post('/login', ['email' => $user->email, 'password' => 'a-long-password-1'])->assertRedirect();
    $this->assertAuthenticatedAs($user);
});
