<?php

use App\Http\Middleware\EnsureActiveMember;
use App\Http\Middleware\RedirectToOnboarding;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Fall back to the home page until the application has a login route.
        $middleware->redirectGuestsTo(fn () => Route::has('login') ? route('login') : '/');

        $middleware->alias(['onboarding' => RedirectToOnboarding::class]);

        // Suspended and removed members are signed out on their next request.
        $middleware->web(append: [EnsureActiveMember::class]);

        // Provider webhooks authenticate themselves and carry no CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
