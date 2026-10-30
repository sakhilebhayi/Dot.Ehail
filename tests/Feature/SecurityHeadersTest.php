<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Baseline browser protections are on every response; the CSP only reports; a route's own header is kept. */
class SecurityHeadersTest extends TestCase
{
    public function test_every_response_carries_the_enforced_baseline_headers(): void
    {
        $r = $this->get('/');

        $r->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $permissions = (string) $r->headers->get('Permissions-Policy');
        foreach (['camera', 'microphone', 'geolocation', 'payment', 'usb'] as $feature) {
            $this->assertStringContainsString($feature.'=()', $permissions, "$feature is denied");
        }

        $this->get('/up')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/does-not-exist')->assertNotFound()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    public function test_the_csp_is_report_only_and_reports_to_the_csp_report_endpoint(): void
    {
        $r = $this->get('/');

        $policy = (string) $r->headers->get('Content-Security-Policy-Report-Only');
        $this->assertFalse($r->headers->has('Content-Security-Policy'), 'nothing is enforced yet');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString('report-uri /csp-report', $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString('connect-src', $policy);
        $this->assertStringContainsString('https://cdn.tailwindcss.com', $policy, 'the Tailwind CDN the app layout loads');
        $this->assertStringContainsString('https://fonts.googleapis.com', $policy);
        $this->assertStringContainsString('https://fonts.gstatic.com', $policy);
        $this->assertStringNotContainsString('paystack', $policy, 'Paystack is a redirect to its hosted page, so no script or frame allowance is needed');
        // The switch turns the same policy into an enforced one, with no deploy.
        config(['security.csp_report_only' => false]);
        $enforced = $this->get('/');
        $this->assertStringContainsString("default-src 'self'", (string) $enforced->headers->get('Content-Security-Policy'));
        $this->assertFalse($enforced->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_hsts_is_sent_on_https_only_and_can_be_switched_off(): void
    {
        $this->assertFalse($this->get('/')->headers->has('Strict-Transport-Security'), 'plain http never gets HSTS');

        $hsts = (string) $this->get('https://localhost/')->headers->get('Strict-Transport-Security');
        $this->assertSame('max-age=15552000', $hsts, 'six months, and no includeSubDomains or preload');

        config(['security.hsts_seconds' => 0]);
        $this->assertFalse($this->get('https://localhost/')->headers->has('Strict-Transport-Security'));
    }

    public function test_a_header_the_route_already_set_is_not_overwritten(): void
    {
        Route::get('/__strict', fn () => response('x')->header('X-Frame-Options', 'DENY'));

        $this->get('/__strict')->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_violation_reports_are_accepted_without_a_session_and_keep_only_the_directive_and_host(): void
    {
        Cache::flush();
        Log::spy();
        $body = json_encode(['csp-report' => [
            'effective-directive' => 'script-src-elem',
            'blocked-uri' => 'https://evil.example/steal?token=SECRET123',
            'document-uri' => 'https://example.test/x?code=SECRET456',
        ]]);

        $first = $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body);
        $first->assertNoContent();
        $this->assertEmpty($first->headers->getCookies(), 'no session or cookie is started for a report');

        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $body)->assertNoContent();   // the same pair is logged once a day

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) => $message === 'csp.violation' && $context === ['directive' => 'script-src-elem', 'blocked_host' => 'evil.example'])
            ->once();

        // The Reporting API shape and garbage are both answered quietly.
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], json_encode([['type' => 'csp-violation', 'body' => ['effectiveDirective' => 'img-src', 'blockedURL' => 'inline']]]))->assertNoContent();
        $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/reports+json'], 'not json at all')->assertNoContent();
    }
}
