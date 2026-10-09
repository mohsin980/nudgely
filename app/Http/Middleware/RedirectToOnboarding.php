<?php

namespace App\Http\Middleware;

use App\Services\Onboarding\OnboardingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a business owner who hasn't finished (or left) onboarding to it. Only the dashboard uses
 * this, so every other page (billing, account security, sign out, email settings, onboarding itself)
 * stays reachable and there is nothing to loop through. Invited members and finished businesses are
 * never redirected.
 */
class RedirectToOnboarding
{
    public function __construct(private readonly OnboardingService $onboarding) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $request->expectsJson() && ! $request->hasHeader('X-Livewire') && $this->onboarding->needs($user)) {
            return redirect()->route('onboarding.show');
        }

        return $next($request);
    }
}
