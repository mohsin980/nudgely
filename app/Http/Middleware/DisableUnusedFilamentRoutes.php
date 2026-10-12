<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filament registers download routes for its export and import features. The application has neither (no export or
 * import actions, no tables for them), so the routes are closed instead of left as unauthenticated entry points.
 * If exports are ever added, remove this and give those routes the same authorization matrix as the admin pages.
 */
class DisableUnusedFilamentRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        abort(404);
    }
}
