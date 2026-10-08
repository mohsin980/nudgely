<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side defenses on every web response: no MIME sniffing, no framing (clickjacking), a
 * referrer policy that keeps private URLs out of other sites' logs, a restrictive permissions
 * policy, HSTS over HTTPS in production, and a Content-Security-Policy (report-only until enforced).
 * Headers a controller already set (e.g. the public estimate page's stricter Referrer-Policy) are kept.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if (config('security.hsts') && app()->isProduction() && $request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age='.(int) config('security.hsts_max_age').'; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        $mode = config('security.csp');

        if (in_array($mode, ['enforce', 'report-only'], true)) {
            $header = $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';

            if (! $response->headers->has($header)) {
                $response->headers->set($header, $this->policy());
            }
        }

        return $response;
    }

    /**
     * Same-origin by default. Inline scripts/styles and eval are allowed because Livewire and Alpine need
     * them; everything that matters for injection is still closed: no objects, no foreign frames, forms
     * and base URLs only to ourselves, and no remote scripts.
     */
    private function policy(): string
    {
        $dev = app()->environment('local') ? ' '.config('security.csp_dev_sources') : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'".$dev,
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net".$dev,
            "font-src 'self' https://fonts.bunny.net data:",
            "img-src 'self' data:",
            "connect-src 'self'".$dev,
            "object-src 'none'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self' https://checkout.stripe.com https://billing.stripe.com",
        ]);
    }
}
