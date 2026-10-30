<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser protections on every response. A header the route already set is left alone.
 *
 * ENFORCED now: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy and (HTTPS requests
 * only) Strict-Transport-Security. HSTS covers this host alone: includeSubDomains and preload are left out on
 * purpose because many sibling platforms live on neighbouring subdomains and each sets its own.
 *
 * The Content-Security-Policy is REPORT-ONLY. The layouts use inline scripts and styles, Alpine/Livewire (which
 * needs 'unsafe-eval') and a small set of CDNs; the browser reports what the policy would have blocked to
 * /csp-report and blocks nothing, so the allow-list can be settled from real reports before anything is enforced.
 * Every origin below is one the views or the framework actually load:
 *
 * - https://cdn.tailwindcss.com (script-src): the Tailwind CDN script in layouts/app.blade.php.
 * - https://fonts.googleapis.com (style-src) and https://fonts.gstatic.com (font-src): Google Fonts and
 *   Material Symbols, linked from every layout and the error pages.
 * - https://images.unsplash.com (img-src): the welcome page and authentication-card backgrounds.
 * - https://ui-avatars.com (img-src): Jetstream's default avatar (profile photos are switched off), shown on the team pages.
 *
 * Paystack needs no allowance: checkout is a server-side redirect to Paystack's hosted payment page and back
 * (redirect()->away), so no js.paystack.co script, checkout.paystack.com frame or Paystack form is loaded here.
 *
 * SECURITY_CSP_REPORT_ONLY=false in .env turns the same policy into an enforced one without a deploy.
 */
class SecurityHeaders
{
    /** @var list<string> */
    private const SCRIPT_ORIGINS = ['https://cdn.tailwindcss.com'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            (config('security.csp_report_only', true) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy') => $this->policy(),
        ];

        if ($request->isSecure() && (int) config('security.hsts_seconds', 0) > 0) {
            $headers['Strict-Transport-Security'] = 'max-age='.(int) config('security.hsts_seconds');
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function policy(): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' ".implode(' ', self::SCRIPT_ORIGINS),
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob: https://images.unsplash.com https://ui-avatars.com",
            // Reverb websockets are wss: in production; local development talks plaintext ws:.
            "connect-src 'self' wss: https:".(app()->isLocal() ? ' ws:' : ''),
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            'report-uri /csp-report',
        ]);
    }
}
