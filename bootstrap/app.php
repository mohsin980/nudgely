<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnsureActiveMember;
use App\Http\Middleware\RedirectToOnboarding;
use App\Http\Middleware\SecurityHeaders;
use App\Support\CorrelationId;
use App\Support\Logging\ExceptionReporting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every request gets a correlation ID first, so the rest of the stack logs under it.
        $middleware->prepend(AssignCorrelationId::class);

        // Fall back to the home page until the application has a login route.
        $middleware->redirectGuestsTo(fn () => Route::has('login') ? route('login') : '/');

        $middleware->alias(['onboarding' => RedirectToOnboarding::class]);

        // Suspended and removed members are signed out on their next request.
        $middleware->web(append: [EnsureActiveMember::class, SecurityHeaders::class]);

        // Provider webhooks authenticate themselves and carry no CSRF token.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Routine outcomes a user causes (bad input, no permission, wrong link, too many tries) are not incidents.
        $exceptions->dontReport([
            AuthenticationException::class,
            AuthorizationException::class,
            ValidationException::class,
            ModelNotFoundException::class,
            NotFoundHttpException::class,
            MethodNotAllowedHttpException::class,
            TokenMismatchException::class,
            TooManyRequestsHttpException::class,
        ]);

        // One sanitized line per unexpected exception, instead of the default full report. The exception's message
        // and trace can contain request data, so only the class, a scrubbed message and the origin are written.
        $exceptions->reportable(function (Throwable $exception) {
            return ExceptionReporting::report($exception);
        });

        // Production error responses: no trace, no internal message, and the reference to quote to support.
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (config('app.debug') || $exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                return null;
            }

            $reference = Context::get(CorrelationId::ATTRIBUTE);

            if (! $request->expectsJson() && ! $request->is('api/*')) {
                return response()->view('errors.500', ['reference' => $reference], 500);
            }

            return response()->json([
                'message' => 'Something went wrong. Quote the reference below if you contact support.',
                'reference' => $reference,
            ], 500);
        });
    })->create();
