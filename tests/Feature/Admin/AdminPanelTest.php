<?php

use App\Filament\AvatarProviders\InitialsAvatarProvider;
use App\Models\PlatformAdmin;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| SA-01: Super Admin panel (/admin)
|--------------------------------------------------------------------------
*/

// Access

test('guests are sent to the admin sign-in page', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

test('the admin sign-in page loads', function () {
    $this->get('/admin/login')->assertOk()->assertSee('Sign in');
});

test('there is no way to register an administrator account', function () {
    $this->get('/admin/register')->assertNotFound();
    $this->post('/admin/register')->assertNotFound();
});

test('a business owner cannot enter the admin panel', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get('/admin')->assertForbidden();
    expect($owner->isPlatformAdmin())->toBeFalse();
});

test('business roles never grant admin access', function () {
    foreach (['owner', 'manager', 'staff'] as $role) {
        $user = User::factory()->{$role}()->create();

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }
});

test('a platform administrator sees the dashboard', function () {
    $this->actingAs(platformAdmin())->get('/admin')
        ->assertOk()
        ->assertSee('Platform overview')
        ->assertSee('QuoteFlow AI');
});

test('a suspended platform administrator is refused', function () {
    $this->actingAs(platformAdmin(state: ['status' => 'suspended', 'suspended_at' => now()]))->get('/admin')->assertForbidden();
});

test('an administrator can sign in through the admin form', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = platformAdmin();

    Livewire::test(Login::class)
        ->fillForm(['email' => $admin->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    $this->assertAuthenticatedAs($admin);
});

test('a business owner with a valid password is still refused by the admin form', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $owner = User::factory()->owner()->create();

    Livewire::test(Login::class)
        ->fillForm(['email' => $owner->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

test('a wrong password does not sign an administrator in', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $admin = platformAdmin();

    Livewire::test(Login::class)
        ->fillForm(['email' => $admin->email, 'password' => 'not-the-password'])
        ->call('authenticate');

    $this->assertGuest();
});

test('a platform administrator keeps normal access to their own business pages', function () {
    $this->actingAs(platformAdmin())->get('/customers')->assertSuccessful();
});

// Granting and revoking

test('the grant command makes an existing account an administrator', function () {
    $user = User::factory()->create(['email' => 'founder@example.com']);

    seedPlatformRoles();

    $this->artisan('platform-admin:grant', ['email' => 'Founder@Example.com', '--role' => ['support_admin']])->assertSuccessful();

    expect($user->fresh()->isPlatformAdmin())->toBeTrue();
    $this->actingAs($user->fresh())->get('/admin')->assertOk();
});

test('the grant command is safe to run twice', function () {
    $user = User::factory()->create();

    seedPlatformRoles();

    $this->artisan('platform-admin:grant', ['email' => $user->email, '--role' => ['support_admin']])->assertSuccessful();
    $this->artisan('platform-admin:grant', ['email' => $user->email, '--role' => ['support_admin']])->assertSuccessful();

    expect(PlatformAdmin::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('the grant command never creates an account', function () {
    $before = User::query()->count();

    seedPlatformRoles();

    $this->artisan('platform-admin:grant', ['email' => 'nobody@example.com', '--role' => ['support_admin']])->assertFailed();

    expect(User::query()->count())->toBe($before)->and(PlatformAdmin::query()->count())->toBe(0);
});

test('the grant command refuses an account that is not active', function () {
    $user = User::factory()->suspended()->create();

    seedPlatformRoles();

    $this->artisan('platform-admin:grant', ['email' => $user->email, '--role' => ['support_admin']])->assertFailed();

    expect($user->isPlatformAdmin())->toBeFalse();
});

test('the revoke command removes admin access', function () {
    $admin = platformAdmin();

    $this->artisan('platform-admin:revoke', ['email' => $admin->email])->assertSuccessful();

    $this->actingAs($admin->fresh())->get('/admin')->assertForbidden();
});

test('the revoke command fails for someone who is not an administrator', function () {
    $user = User::factory()->create();

    $this->artisan('platform-admin:revoke', ['email' => $user->email])->assertFailed();
});

test('deleting a user removes their admin grant', function () {
    $admin = platformAdmin();

    $admin->delete();

    expect(PlatformAdmin::query()->count())->toBe(0);
});

// The customer-facing application is unchanged

test('existing public pages still work', function () {
    $this->get('/login')->assertOk();
    $this->get('/register')->assertOk();
    $this->get('/up')->assertOk();
    $this->get('/health/ready')->assertOk();
    $this->get('/')->assertRedirect(route('login'));
    $this->get('/dashboard')->assertRedirect(route('login'));
});

test('a business owner still reaches their own dashboard', function () {
    $owner = User::factory()->owner()->create();

    $response = $this->actingAs($owner)->get('/dashboard');

    expect($response->status())->toBeIn([200, 302]);
    expect((string) $response->headers->get('Location'))->not->toContain('/admin');
});

test('admin avatars are drawn locally and never load an outside image', function () {
    $avatar = (new InitialsAvatarProvider)->get(User::factory()->make(['name' => 'Pat Owner']));

    expect($avatar)->toStartWith('data:image/svg+xml;base64,')->not->toContain('http');
    expect(base64_decode(substr($avatar, strlen('data:image/svg+xml;base64,'))))->toContain('PO');
});
