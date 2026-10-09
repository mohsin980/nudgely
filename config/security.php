<?php

/*
|--------------------------------------------------------------------------
| Security headers
|--------------------------------------------------------------------------
|
| Sent on every web response by App\Http\Middleware\SecurityHeaders.
|
| csp: "report-only" (default: browsers report violations in their console but block nothing),
|      "enforce" (block), or "off". The policy has to allow what the interface needs
|      (Livewire and Alpine use inline scripts and expression evaluation), so it ships in
|      report-only mode until it has been checked in a real browser with SECURITY_CSP=enforce.
| hsts: Strict-Transport-Security, only ever sent over HTTPS in production.
|
*/

return [

    'csp' => env('SECURITY_CSP', 'report-only'),

    // Extra sources while developing with the Vite dev server.
    'csp_dev_sources' => env('SECURITY_CSP_DEV_SOURCES', 'http://localhost:5173 ws://localhost:5173 http://127.0.0.1:5173 ws://127.0.0.1:5173'),

    'hsts' => (bool) env('SECURITY_HSTS', true),

    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

];
