<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function signUpUnverified(): User
{
    test()->post('/register', [
        'business_name' => 'Dallas HVAC', 'name' => 'Mohsin', 'email' => 'mohsin@example.com',
        'password' => 'Sup3r-Secret-Pass!', 'password_confirmation' => 'Sup3r-Secret-Pass!',
    ])->assertRedirect(route('verification.notice'));

    return User::query()->where('email', 'mohsin@example.com')->sole();
}

test('signing up sends a confirmation link and keeps the person on the check-your-email page', function () {
    Notification::fake();
    $user = signUpUnverified();

    Notification::assertSentTo($user, VerifyEmail::class);
    expect($user->hasVerifiedEmail())->toBeFalse();

    $this->get(route('verification.notice'))->assertOk()->assertSee('Check your email')->assertSee('mohsin@example.com');
    $this->get('/dashboard')->assertRedirect(route('verification.notice'));
    $this->get('/onboarding')->assertRedirect(route('verification.notice'));
    $this->get('/settings/business')->assertRedirect(route('verification.notice'));
});

test('the link in the email confirms the address and opens the app', function () {
    Notification::fake();
    $user = signUpUnverified();

    $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
    $this->get($link)->assertRedirect('/');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    // A real browser makes a fresh request; the test client keeps the signed-in user from before, so reset it.
    app('auth')->forgetGuards();
    $this->get('/onboarding')->assertOk();
});

test('the link works in another browser where nobody is signed in, and the person can then sign in', function () {
    Notification::fake();
    $user = signUpUnverified();
    $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

    auth()->guard('web')->logout();
    session()->flush();

    $this->get($link)->assertRedirect(route('login'));
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();

    $this->post('/login', ['email' => 'mohsin@example.com', 'password' => 'Sup3r-Secret-Pass!'])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user->fresh());
});

test('an expired confirmation link is refused', function () {
    Notification::fake();
    $user = signUpUnverified();
    $link = URL::temporarySignedRoute('verification.verify', now()->subMinute(), ['id' => $user->id, 'hash' => sha1($user->email)]);

    $this->get($link)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('a tampered or unsigned link does not confirm the address', function () {
    Notification::fake();
    $user = signUpUnverified();

    $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('other@example.com')]))->assertForbidden();
    $this->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]))->assertForbidden();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('the link can be sent again, and unconfirmed people can still sign out', function () {
    Notification::fake();
    $user = signUpUnverified();

    $this->post(route('verification.send'))->assertRedirect();
    Notification::assertSentToTimes($user, VerifyEmail::class, 2);

    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

test('people who already confirmed are not asked again', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get(route('verification.notice'))->assertRedirect('/');
});
