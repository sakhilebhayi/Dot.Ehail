<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

/**
 * Owner rule: the original logo is never redrawn, recoloured, restyled, replaced or generated. Only size, placement,
 * clear space and the light/dark file choice may change.
 */
class OriginalLogoTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    public function test_the_original_logo_files_are_byte_for_byte_unchanged(): void
    {
        // Git blob ids of the files as first committed; also equal to docs/logos/dot.logos2.png in InfoDot (logo.png).
        $this->assertSame('9801575f1656ecb973ef85a6328cdae5a7560af5', $this->gitBlobId(public_path('images/logo.png')));
        $this->assertSame('b43e4f3986723675ec5b1db56a42cd5edd2afb20', $this->gitBlobId(public_path('images/logo-light.png')));
    }

    public function test_no_view_draws_the_brand_mark_any_more(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            $text = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString('eh-logo__disc', $text, $file->getPathname());
            $this->assertStringNotContainsString('eh-logo__chevron', $text, $file->getPathname());
            $this->assertStringNotContainsString('eh-logo__car', $text, $file->getPathname());
            $this->assertStringNotContainsString('lp-foot__disc', $text, $file->getPathname());
            $this->assertStringNotContainsString('lp-foot__chev', $text, $file->getPathname());
        }
    }

    public function test_every_layout_shows_the_real_logo_image_with_alt_text_and_size(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $pages = [
            'landing' => $this->get('/'),
            'sign in' => $this->get('/login'),
            'not found' => $this->get('/no-such-page-xyz'),
            'rider' => $this->actingAs($passenger)->get(route('ride.home')),
            'dashboard' => $this->actingAs($passenger)->get(route('dashboard')),
            'driver' => $this->actingAs($this->makeApprovedDriver())->get(route('drive.home')),
            'operator' => $this->actingAs($this->makeOperator())->get(route('ops.live')),
        ];

        foreach ($pages as $name => $response) {
            $html = $response->getContent();
            $this->assertStringNotContainsString('class="eh-logo__mark"', $html, $name);
            $this->assertMatchesRegularExpression('~<img[^>]+src="[^"]*/images/logo(-light)?\.png"[^>]+alt="Dot\.Ehail"[^>]+width="\d+"[^>]+height="\d+"~', $html, "$name shows the original logo image");
        }
    }

    public function test_the_refusal_page_shows_the_real_logo_image(): void
    {
        $html = response()->view('entitlement.refused', [
            'title' => 'Dot.Ehail has ended for Acme Ltd', 'body' => 'Ask your admin.', 'platformsUrl' => 'https://infodot.test/platforms/ehail',
            'other' => null, 'otherCompanies' => collect(),
        ], 403)->getContent();

        $this->assertMatchesRegularExpression('~<img[^>]+src="[^"]*/images/logo\.png"[^>]+alt="Dot\.Ehail"~', $html);
        $this->assertStringContainsString('images/logo-light.png', $html);
        $this->assertStringNotContainsString('<svg class="eh-logo__mark"', $html);
    }

    public function test_the_mail_header_uses_the_original_logo(): void
    {
        foreach (['message', 'header'] as $part) {
            $source = file_get_contents(resource_path("views/vendor/mail/html/$part.blade.php"));
            $this->assertStringContainsString('images/logo-light.png', $source, $part);
            $this->assertStringNotContainsString('icons/icon-192.png', $source, $part);
        }
    }

    private function gitBlobId(string $path): string
    {
        $bytes = file_get_contents($path);

        return sha1('blob '.strlen($bytes)."\0".$bytes);
    }
}
