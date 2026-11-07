<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Someone signed in with an unconfirmed email address can only reach the "check your email" page
 * (and sign out) until they click the link we sent. This stops sign-ups with addresses people don't own.
 */
class EnsureEmailVerified
{
    /** Routes an unverified person may still use. */
    private const ALLOWED = ['verification.notice', 'verification.verify', 'verification.send', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if (! $user instanceof User || $user->hasVerifiedEmail() || $request->routeIs(...self::ALLOWED) || $request->is('admin', 'admin/*')) {
            return $next($request);
        }

        abort_if($request->expectsJson() || $request->hasHeader('X-Livewire'), 403, 'Confirm your email address first.');

        return redirect()->route('verification.notice');
    }
}
