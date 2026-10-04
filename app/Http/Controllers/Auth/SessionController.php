<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Team\MemberStatus;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Plain Laravel session sign-in and sign-out. Suspended and removed members can't sign in.
 */
class SessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'string', 'email'], 'password' => ['required', 'string']]);
        $key = 'login|'.Str::lower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many sign-in attempts. Please try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        $signedIn = Auth::attempt([
            'email' => Str::lower($credentials['email']),
            'password' => $credentials['password'],
            fn (Builder $query) => $query->where('status', MemberStatus::Active),
        ], $request->boolean('remember'));

        if (! $signedIn) {
            RateLimiter::hit($key, 60);

            // Same message whether the email is unknown, the password is wrong or access was removed.
            throw ValidationException::withMessages(['email' => 'These credentials do not match an active account.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
