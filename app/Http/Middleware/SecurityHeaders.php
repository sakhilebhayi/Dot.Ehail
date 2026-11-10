<?php

namespace App\Http\Middleware;

use App\Domain\Geo\Http\Middleware\AllowGeolocation;
use App\Domain\Geo\Support\GeoCsp;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Browser protections on every response (R-46 security S-29, docs/R46_SECURITY.md section 4). A header the route
 * already set is left alone (private files send their own sandboxing policy).
 *
 * Content-Security-Policy, ENFORCED, nonce-based:
 *  - script-src 'self' 'nonce-…': every executable inline script carries the per-request nonce (Vite tags and
 *    Livewire's tags take it from Vite::cspNonce()); no inline event handlers exist (resources/js/eh/inline-actions.js).
 *    'unsafe-eval' is added only to pages that load Livewire, whose bundled Alpine evaluates expressions; no other
 *    page gets it. 'strict-dynamic' is not used: Vite's module preloads are same-origin and listed by 'self'.
 *  - style-src 'self' 'unsafe-inline': the views use style attributes; style injection cannot run script.
 *  - img-src adds the configured map tile host (GeoCsp::imgSources()) and nothing else; avatars are local SVGs.
 *  - connect-src 'self' (+ the Reverb socket when configured): geocoding and routing go through the server proxy.
 *  - form-action 'self' plus Paystack's hosted checkout and InfoDot sign-in, which forms redirect to.
 *  - frame-ancestors 'self', object-src 'none', base-uri 'self', worker-src/manifest-src 'self'.
 * SECURITY_CSP_REPORT_ONLY=true turns it back into a report-only policy without a deploy (an escape hatch).
 *
 * Permissions-Policy: geolocation=(self) only on the passenger and driver pages that use the position (their routes
 * carry the AllowGeolocation middleware); everything else denies it. camera=() everywhere: photo uploads use
 * <input type=file capture>, which opens the device camera app and needs no camera permission for the page.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce();

        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'Permissions-Policy' => $this->permissions($request, $response),
            (config('security.csp_report_only', false) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy') => $this->policy($response),
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

    private function permissions(Request $request, Response $response): string
    {
        $geolocation = $response->headers->get(AllowGeolocation::MARKER) === 'self';
        $response->headers->remove(AllowGeolocation::MARKER);

        return implode(', ', [
            'camera=()',
            'microphone=()',
            'geolocation='.($geolocation ? '(self)' : '()'),
            'payment=()',
            'usb=()',
        ]);
    }

    private function policy(Response $response): string
    {
        $nonce = Vite::cspNonce();
        $content = $this->isHtml($response) ? (string) $response->getContent() : '';
        $livewire = $content !== '' && str_contains($content, '/livewire/livewire');

        $connect = ["'self'"];
        $reverbHost = (string) config('reverb.apps.apps.0.options.host', '');
        if (config('broadcasting.default') === 'reverb' && $reverbHost !== '') {
            $connect[] = 'wss://'.$reverbHost;
            if (app()->isLocal()) {
                $connect[] = 'ws://'.$reverbHost.':'.config('reverb.apps.apps.0.options.port', 8080);
            }
        }

        $formTargets = ["'self'", 'https://checkout.paystack.com'];
        $infodot = parse_url((string) config('ecosystem.platforms.infodot.url', ''));
        if (isset($infodot['scheme'], $infodot['host'])) {
            $formTargets[] = $infodot['scheme'].'://'.$infodot['host'].(isset($infodot['port']) ? ':'.$infodot['port'] : '');
        }

        return implode('; ', array_filter([
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'".($livewire ? " 'unsafe-eval'" : ''),
            "style-src 'self' 'unsafe-inline'",
            "font-src 'self' data:",
            "img-src 'self' data: blob: ".implode(' ', GeoCsp::imgSources()),
            'connect-src '.implode(' ', $connect),
            "worker-src 'self'",
            "manifest-src 'self'",
            "media-src 'self'",
            "frame-src 'none'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            'form-action '.implode(' ', array_unique($formTargets)),
            'report-uri /csp-report',
        ]));
    }

    private function isHtml(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html')
            && ! $response instanceof StreamedResponse
            && ! $response instanceof BinaryFileResponse;
    }
}
