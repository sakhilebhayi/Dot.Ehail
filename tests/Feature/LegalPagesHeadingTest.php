<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The legal pages are Markdown without a title line: the page itself carries the one h1 (QA route sweep, D-1612).
 */
class LegalPagesHeadingTest extends TestCase
{
    public function test_each_legal_page_has_exactly_one_h1(): void
    {
        foreach (['/terms-of-service' => 'Terms of Service', '/privacy-policy' => 'Privacy Policy', '/cookies' => 'Cookie Policy'] as $url => $title) {
            $html = $this->get($url)->assertOk()->getContent();
            $this->assertSame(1, preg_match_all('/<h1\b/i', $html), "{$url} has one h1");
            $this->assertStringContainsString("<h1>{$title}</h1>", $html);
        }
    }
}
