<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Browser protections are on every response; the nonce CSP is enforced; a route's own header is kept. */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_the_csp_is_enforced_with_a_per_request_nonce_and_no_inline_allowance_for_scripts(): void
    {
        $r = $this->get('/');

        $policy = (string) $r->headers->get('Content-Security-Policy');
        $this->assertFalse($r->headers->has('Content-Security-Policy-Report-Only'), 'enforced, not report-only (R-46 security S-29)');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\\/=]{20,}'(;| )/", $policy);
        $this->assertStringNotContainsString("'unsafe-inline' https", $policy);
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-inline'/", $policy, 'no inline script allowance');
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-eval'/", $policy, 'no eval on a page without Livewire');
        foreach (["default-src 'self'", 'report-uri /csp-report', "frame-ancestors 'self'", "object-src 'none'", "base-uri 'self'", "connect-src 'self'", 'form-action', "worker-src 'self'"] as $part) {
            $this->assertStringContainsString($part, $policy);
        }
        foreach (['cdn.tailwindcss.com', 'fonts.googleapis.com', 'ui-avatars.com', 'unsplash'] as $gone) {
            $this->assertStringNotContainsString($gone, $policy, "no third-party $gone");
        }

        // Every executable inline script on the page carries this response's nonce.
        preg_match("/'nonce-([^']+)'/", $policy, $m);
        $html = $r->getContent();
        preg_match_all('/<script(?![^>]*\\bsrc=)(?![^>]*type="application\\/json")[^>]*>/', $html, $tags);
        foreach ($tags[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$m[1].'"', $tag);
        }
        $this->assertNotSame($m[1], $this->extractNonce($this->get('/')), 'a fresh nonce per response');

        // The escape hatch turns the same policy into report-only without a deploy.
        config(['security.csp_report_only' => true]);
        $this->assertTrue($this->get('/')->headers->has('Content-Security-Policy-Report-Only'));
    }

    public function test_unsafe_eval_is_given_only_to_pages_that_load_livewire(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $profile = $this->actingAs($user)->get('/user/profile')->assertOk();
        $this->assertStringContainsString('/livewire/livewire', $profile->getContent());
        $this->assertMatchesRegularExpression("/script-src[^;]*'unsafe-eval'/", (string) $profile->headers->get('Content-Security-Policy'));

        $ride = $this->actingAs($user)->get(route('ride.home'))->assertOk();
        $this->assertStringNotContainsString('/livewire/livewire', $ride->getContent());
        $this->assertDoesNotMatchRegularExpression("/script-src[^;]*'unsafe-eval'/", (string) $ride->headers->get('Content-Security-Policy'));
    }

    public function test_geolocation_is_allowed_only_on_the_passenger_and_driver_apps(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->assertStringContainsString('geolocation=()', (string) $this->get('/')->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('geolocation=()', (string) $this->actingAs($user)->get('/user/profile')->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('geolocation=(self)', (string) $this->actingAs($user)->get(route('ride.home'))->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('camera=()', (string) $this->actingAs($user)->get(route('ride.home'))->headers->get('Permissions-Policy'));
    }

    public function test_the_default_avatar_is_local(): void
    {
        $user = User::factory()->make(['name' => 'Thandi Mokoena']);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $user->profile_photo_url);
        $this->assertStringContainsString('TM', base64_decode(substr($user->profile_photo_url, 26)));
    }

    private function extractNonce($response): string
    {
        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $m);

        return $m[1] ?? '';
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
