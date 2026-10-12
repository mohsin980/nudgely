<?php

namespace App\Http\Middleware;

use App\Support\Admin\AdminAccess;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;

/**
 * Filament's sign-in check plus the central admin rule, with refused attempts logged.
 *
 * It replaces Filament's Authenticate (which Laravel would run first anyway) and is a persistent middleware, so
 * it also runs on every admin Livewire update: an admin whose access was removed is stopped on their next click.
 * Guests are sent to the admin sign-in page.
 */
class AuthenticatePlatformAdmin extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $user = Filament::auth()->user();

        if ($user !== null && ! AdminAccess::canEnterPanel($user)) {
            AdminAccess::logDenied($user, 'access_admin_panel');

            abort(403);
        }

        parent::authenticate($request, $guards);
    }
}
