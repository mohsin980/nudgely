<?php

use App\Enums\Platform\PlatformPermission;
use App\Enums\Platform\PlatformRole;
use App\Filament\Concerns\RequiresPlatformPermission;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Livewire\Livewire;
use Tests\Support\Admin\PaymentsFixturePage;
use Tests\Support\Admin\RolesFixturePage;

/*
|--------------------------------------------------------------------------
| SA-03: Secure Super Admin access
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    view()->addNamespace('admin-test', base_path('tests/Support/Admin/views'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    PaymentsFixturePage::$ran = false;
});

// The seven scenarios from the task

test('a guest visiting /admin is sent to sign in', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

test('a signed-in customer visiting /admin is forbidden', function () {
    $this->actingAs(User::factory()->owner()->create())->get('/admin')->assertForbidden();
});

test('a business owner cannot open a platform-wide page', function () {
    $owner = User::factory()->owner()->create();
    $this->actingAs($owner);

    expect(PaymentsFixturePage::canAccess())->toBeFalse()
        ->and(RolesFixturePage::canAccess())->toBeFalse();
    Livewire::test(PaymentsFixturePage::class)->assertForbidden();
});

test('support cannot use a billing-only page or action', function () {
    $this->actingAs(platformAdmin(PlatformRole::SupportAdmin));

    Livewire::test(PaymentsFixturePage::class)->assertForbidden();
    expect(PaymentsFixturePage::$ran)->toBeFalse();
});

test('a billing admin can open the page but a refund needs a stronger permission', function () {
    $this->actingAs(platformAdmin(PlatformRole::BillingAdmin));

    Livewire::test(PaymentsFixturePage::class)->assertOk()->assertActionHidden('refund');
    expect(PaymentsFixturePage::$ran)->toBeFalse();
});

test('a billing admin cannot manage roles', function () {
    $this->actingAs(platformAdmin(PlatformRole::BillingAdmin));

    Livewire::test(RolesFixturePage::class)->assertForbidden();
});

test('a super admin opens the dashboard and the sensitive pages', function () {
    $this->actingAs(platformAdmin());

    $this->get('/admin')->assertOk()->assertSee('Platform overview');
    Livewire::test(RolesFixturePage::class)->assertOk();
    Livewire::test(PaymentsFixturePage::class)->assertOk()->callAction('refund');
    expect(PaymentsFixturePage::$ran)->toBeTrue();
});

test('an administrator who loses access is refused on the next request', function () {
    $admin = platformAdmin();
    $this->actingAs($admin)->get('/admin')->assertOk();

    $admin->platformAdmin()->delete();

    $this->actingAs(User::query()->find($admin->id))->get('/admin')->assertForbidden();
});

test('removing the role or suspending the account also stops access', function () {
    $noRole = platformAdmin(PlatformRole::SupportAdmin);
    $noRole->removeRole(PlatformRole::SupportAdmin->value);
    $this->actingAs(User::query()->find($noRole->id))->get('/admin')->assertForbidden();

    $suspended = platformAdmin(PlatformRole::BillingAdmin);
    $suspended->forceFill(['status' => 'suspended', 'suspended_at' => now()])->save();
    $this->actingAs(User::query()->find($suspended->id))->get('/admin')->assertForbidden();
});

// Centralized rule

test('AdminAccess denies guests and customers and allows only permission holders', function () {
    expect(AdminAccess::allows(null, PlatformPermission::ViewDashboard))->toBeFalse()
        ->and(AdminAccess::allows(User::factory()->owner()->create(), PlatformPermission::ViewDashboard))->toBeFalse()
        ->and(AdminAccess::allows(platformAdmin(PlatformRole::SupportAdmin), PlatformPermission::ViewDashboard))->toBeTrue()
        ->and(AdminAccess::allows(platformAdmin(PlatformRole::SupportAdmin), PlatformPermission::ManageRoles))->toBeFalse();
});

test('a role with a permission but no platform_admins record still has no access', function () {
    seedPlatformRoles();
    $user = User::factory()->create();
    $user->assignRole(PlatformRole::SuperAdmin->value);

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

test('every page and widget in the admin panel declares a required permission', function () {
    $panel = Filament::getPanel('admin');
    $exempt = [AccountWidget::class]; // shows only the signed-in admin's own name; the panel gate already protects it

    $classes = [...$panel->getPages(), ...$panel->getResources(), ...$panel->getWidgets()];
    $unprotected = collect($classes)
        ->reject(fn (string $class) => in_array($class, $exempt, true))
        ->reject(fn (string $class) => in_array(RequiresPlatformPermission::class, class_uses_recursive($class), true))
        ->values()->all();

    expect($unprotected)->toBe([]);
});

test('refused attempts are logged without personal data', function () {
    config(['logging.channels.admin_audit_test' => ['driver' => 'single', 'path' => storage_path('logs/admin-audit-test.log')]]);
    config(['admin.audit_channel' => 'admin_audit_test']);
    @unlink(storage_path('logs/admin-audit-test.log'));
    $owner = User::factory()->owner()->create(['email' => 'private-owner@example.com']);

    $this->actingAs($owner)->get('/admin')->assertForbidden();

    $log = file_get_contents(storage_path('logs/admin-audit-test.log'));
    expect($log)->toContain('Admin access denied')->toContain('"user_id":'.$owner->id)->not->toContain('private-owner@example.com');
    unlink(storage_path('logs/admin-audit-test.log'));
});

// Sign-in protection

test('failed admin sign-ins give the same generic message for every account type', function () {
    $admin = platformAdmin();
    $customer = User::factory()->owner()->create();

    $messages = collect([
        [$admin->email, 'wrong-password'],
        [$customer->email, 'wrong-password'],
        ['nobody@example.com', 'wrong-password'],
    ])->map(function ($credentials) {
        $component = Livewire::test(Login::class)
            ->set('data.email', $credentials[0])
            ->set('data.password', $credentials[1])
            ->call('authenticate');

        return $component->errors()->get('data.email')[0] ?? null;
    });

    expect($messages->filter()->unique())->toHaveCount(1);
});

test('admin sign-in is rate limited', function () {
    $component = Livewire::test(Login::class);

    foreach (range(1, 6) as $i) {
        $component->set('data.email', 'nobody@example.com')->set('data.password', 'wrong')->call('authenticate');
    }

    $component->assertNotified();
});

test('a customer who signs in on the admin form gets no admin session', function () {
    $customer = User::factory()->owner()->create(['password' => 'secret-password-1']);

    Livewire::test(Login::class)
        ->set('data.email', $customer->email)
        ->set('data.password', 'secret-password-1')
        ->call('authenticate')
        ->assertHasErrors(['data.email']);

    $this->assertGuest();
});

test('registration cannot be used to become an administrator', function () {
    $this->post('/register', [
        'name' => 'Mallory',
        'email' => 'mallory@example.com',
        'password' => 'a-long-password-123',
        'password_confirmation' => 'a-long-password-123',
        'role' => 'super_admin',
        'is_platform_admin' => true,
        'platform_role' => 'super_admin',
    ]);

    $user = User::query()->where('email', 'mallory@example.com')->first();
    expect($user?->isPlatformAdmin() ?? false)->toBeFalse();
});

test('customer pages keep working for customers', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get('/customers')->assertSuccessful();
});
