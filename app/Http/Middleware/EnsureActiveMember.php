<?php

namespace App\Http\Middleware;

use App\Enums\Team\MemberStatus;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suspended or removed people are signed out on their next request (their status is read
 * fresh from the database each request, so a suspension or role change applies at once).
 * The same applies to everyone in an organization the platform has suspended.
 * Also records when active members were last seen, at most every five minutes.
 */
class EnsureActiveMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user instanceof User && $user->organization_id !== null && $user->status !== MemberStatus::Active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = $user->status === MemberStatus::Suspended
                ? 'Your access to this business has been suspended. Contact the business owner.'
                : 'You are no longer a member of this business.';

            abort_if($request->expectsJson() || $request->hasHeader('X-Livewire'), 403, $message);

            return redirect()->route('login')->with('status', $message);
        }

        if ($user instanceof User && $user->organization_id !== null && $user->organization?->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'This business account is suspended. Please contact support.';

            abort_if($request->expectsJson() || $request->hasHeader('X-Livewire'), 403, $message);

            return redirect()->route('login')->with('status', $message);
        }

        if ($user instanceof User && ($user->last_active_at === null || $user->last_active_at->lt(now()->subMinutes(5)))) {
            User::query()->whereKey($user->id)->update(['last_active_at' => now()]);
        }

        return $next($request);
    }
}
