<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EcosystemErrorPagesTest extends TestCase
{
    #[DataProvider('errorCodes')]
    public function test_error_page_renders_branded_content(int $code, string $expectedHeading): void
    {
        $response = $this->get("/_test-only-error-render/{$code}");

        $response->assertStatus($code);
        $response->assertSee($expectedHeading);
        $response->assertSee('Dot.Ehail', false);
        // A way back, not a catalogue of every Dot product (R-46 polish, D-2210).
        $response->assertDontSee('the rest of the Dot Ecosystem', false);
        $response->assertDontSee('Dot.Analytics', false);
    }

    public function test_not_found_offers_at_most_three_destinations(): void
    {
        $html = $this->get('/_test-only-error-render/404')->getContent();
        preg_match('#<main.*</main>#s', $html, $main);
        preg_match('#<header class="eh-error__bar">.*?</header>#s', $html, $bar);

        $links = preg_match_all('#<a [^>]*href=#', ($main[0] ?? '').($bar[0] ?? ''));
        // The logo, plus at most three places to go.
        $this->assertLessThanOrEqual(4, $links);
        $this->assertStringContainsString('Go home', $html);
    }

    public static function errorCodes(): array
    {
        return [
            '404 not found' => [404, "We couldn't find that page"],
            '403 forbidden' => [403, "You don't have access to this"],
            '419 page expired' => [419, 'Your session timed out'],
            '429 too many requests' => [429, 'Slow down a little'],
            '500 server error' => [500, 'Something went wrong on our end'],
            '503 unavailable' => [503, "We'll be right back"],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app['router']->get('/_test-only-error-render/{code}', function (int $code) {
            return response()->view("errors.{$code}", [], $code);
        });
    }
}
