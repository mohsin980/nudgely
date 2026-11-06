<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

test('the sign-in page links to the forgot password page', function () {
    $this->get(route('login'))->assertOk()->assertSee('Forgot password?')->assertSee(route('password.request'));
    $this->get(route('password.request'))->assertOk()->assertSee('Send reset link');
});

test('a reset link is emailed to an existing account', function () {
    Notification::fake();
    $user = User::factory()->owner()->create(['email' => 'owner@example.com']);

    $this->post(route('password.email'), ['email' => 'Owner@Example.com '])
        ->assertRedirect()
        ->assertSessionHas('status', 'If an account exists for that email, we have sent a link to reset your password.');

    Notification::assertSentTo($user, ResetPassword::class);
});

test('an unknown email gets the same message and no email is sent', function () {
    Notification::fake();

    $this->post(route('password.email'), ['email' => 'nobody@example.com'])
        ->assertSessionHas('status', 'If an account exists for that email, we have sent a link to reset your password.');

    Notification::assertNothingSent();
});

test('a valid reset link sets a new password', function () {
    $user = User::factory()->owner()->create(['email' => 'owner@example.com']);
    $token = Password::createToken($user);

    $this->get(route('password.reset', ['token' => $token, 'email' => $user->email]))->assertOk()->assertSee('Set a new password');

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => 'owner@example.com',
        'password' => 'New-Secure-Pass-2026!',
        'password_confirmation' => 'New-Secure-Pass-2026!',
    ])->assertRedirect(route('login'))->assertSessionHas('status', 'Your password has been reset. You can sign in now.');

    expect(Hash::check('New-Secure-Pass-2026!', $user->refresh()->password))->toBeTrue();
});

test('an invalid reset token is rejected and the password is unchanged', function () {
    $user = User::factory()->owner()->create(['email' => 'owner@example.com']);
    $original = $user->password;

    $this->from(route('password.reset', ['token' => 'wrong']))->post(route('password.update'), [
        'token' => 'wrong',
        'email' => 'owner@example.com',
        'password' => 'New-Secure-Pass-2026!',
        'password_confirmation' => 'New-Secure-Pass-2026!',
    ])->assertSessionHasErrors('email');

    expect($user->refresh()->password)->toBe($original);
});
