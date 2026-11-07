<?php

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

test('signing in to the app does not sign anyone in to the admin panel', function () {
    $admin = platformAdmin();

    $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect();
    $this->assertAuthenticatedAs($admin, 'web');
    $this->assertGuest('admin');

    $this->get('/admin')->assertRedirect('/admin/login');
});

test('the authenticator app is required by default and can only be relaxed by configuration', function () {
    // Tests run with ADMIN_REQUIRE_MFA=false; production defaults to true (config/admin.php).
    config(['admin.require_mfa' => true]);
    expect(Filament::getPanel('admin')->isMultiFactorAuthenticationRequired())->toBeTrue();

    config(['admin.require_mfa' => false]);
    expect(Filament::getPanel('admin')->isMultiFactorAuthenticationRequired())->toBeFalse();
});

test('an administrator with an authenticator app must enter its code to sign in', function () {
    $admin = platformAdmin();
    $secret = app(AppAuthentication::class)->generateSecret();
    $admin->saveAppAuthenticationSecret($secret);

    $login = Livewire::test(Login::class)
        ->fillForm(['email' => $admin->email, 'password' => 'password'])
        ->call('authenticate');

    $this->assertGuest('admin');

    $login->set('data.multiFactor.app.code', '000000')->call('authenticate')->assertHasErrors();
    $this->assertGuest('admin');

    $login->set('data.multiFactor.app.code', app(Google2FA::class)->getCurrentOtp($secret))->call('authenticate');
    $this->assertAuthenticatedAs($admin->fresh(), 'admin');
});

test('the secret and recovery codes are stored encrypted and never serialized', function () {
    $admin = platformAdmin();
    $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
    $admin->saveAppAuthenticationRecoveryCodes(['aaaa-bbbb']);

    $raw = DB::table('users')->where('id', $admin->id)->first();

    expect($raw->app_authentication_secret)->not->toContain('JBSWY3DPEHPK3PXP')
        ->and($raw->app_authentication_recovery_codes)->not->toContain('aaaa-bbbb')
        ->and(User::find($admin->id)->getAppAuthenticationRecoveryCodes())->toBe(['aaaa-bbbb'])
        ->and(User::find($admin->id)->toArray())->not->toHaveKeys(['app_authentication_secret', 'app_authentication_recovery_codes']);
});
