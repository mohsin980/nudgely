<?php

namespace App\Http\Middleware;

use App\Support\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every HTTP request one correlation ID: logged with every line the request writes, returned in the
 * X-Request-Id header, and carried into the jobs it dispatches (Laravel's Context travels with queued jobs).
 * Webhooks and Livewire requests pass through unchanged apart from the header.
 */
class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = CorrelationId::fromRequest($request);

        $request->attributes->set(CorrelationId::ATTRIBUTE, $id);
        Context::add(CorrelationId::ATTRIBUTE, $id);

        $response = $next($request);
        $response->headers->set(CorrelationId::HEADER, $id);

        return $response;
    }
}
