<?php

namespace Tests\Feature\Ehail\Ds;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

/**
 * ds (R-46): the landing page says what the product is and keeps its promises honest; no rendered page loads
 * anything from another host (fonts, scripts, styles, images, icons); the PWA files are present and safe.
 */
class PublicPagesTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /** Every resource the page itself loads: scripts, stylesheets, preloads, icons, manifest, images and srcsets. */
    private function assertNoExternalResources(TestResponse $response, string $page): void
    {
        $xpath = $this->xpath($response);
        $host = parse_url(config('app.url'), PHP_URL_HOST);
        $urls = [];
        foreach ($xpath->query('//script[@src]') as $n) {
            $urls[] = $n->getAttribute('src');
        }
        foreach ($xpath->query('//link[@href]') as $n) {
            $urls[] = $n->getAttribute('href');
        }
        foreach ($xpath->query('//img[@src] | //source[@src]') as $n) {
            $urls[] = $n->getAttribute('src');
        }
        foreach ($xpath->query('//*[@srcset] | //*[@imagesrcset]') as $n) {
            foreach (explode(',', $n->getAttribute('srcset').','.$n->getAttribute('imagesrcset')) as $candidate) {
                $urls[] = trim(explode(' ', trim($candidate))[0] ?? '');
            }
        }
        foreach (array_filter($urls) as $url) {
            $urlHost = parse_url($url, PHP_URL_HOST);
            $this->assertTrue($urlHost === null || $urlHost === $host || $urlHost === 'localhost' || $urlHost === '127.0.0.1', "{$page} loads {$url} from another host");
        }
        // No font, CSS or script CDN anywhere in the markup (Livewire snapshots may carry data URLs; those are never loaded).
        $this->assertDoesNotMatchRegularExpression('#fonts\.googleapis|fonts\.gstatic|cdn\.tailwindcss|unpkg\.com|jsdelivr|material-symbols#i', $response->getContent(), $page);
    }

    public function test_the_landing_page_states_the_offer_and_its_honest_limits(): void
    {
        $response = $this->get('/')->assertOk();
        $xpath = $this->xpath($response);

        $this->assertSame(1, $xpath->query('//h1')->length);
        $this->assertSame('See the fare before you ride', trim($xpath->query('//h1')->item(0)->textContent));
        foreach (['Your journey.', 'Your safety.', 'Your choice.'] as $promise) {
            $response->assertSee($promise);
        }
        foreach (['fares', 'safety', 'drivers', 'business', 'questions'] as $section) {
            $this->assertSame(1, $xpath->query('//section[@id="'.$section.'"][@aria-labelledby]')->length, $section);
        }
        foreach (['10111', '10177', '112'] as $number) {
            $this->assertGreaterThan(0, $xpath->query('//a[@href="tel:'.$number.'"]')->length, $number);
        }
        $response->assertSee('SOS does not dispatch police or an ambulance');
        $this->assertSame(8, $xpath->query('//*[@data-lp-stop]')->length, 'every fare term has a stop');
        $this->assertGreaterThanOrEqual(1, $xpath->query('//section[contains(@class, "lp-hero")]//a[@href="'.route('login').'"][contains(@class, "eh-btn")][contains(., "Sign in to ride")]')->length);

        $text = preg_replace('/\s+/', ' ', $xpath->query('//body')->item(0)->textContent);
        $this->assertStringNotContainsString("\u{2014}", $text, 'no em dashes');
        $this->assertStringNotContainsString("\u{2013}", $text, 'no en dashes');
        // Every photograph is credited, and no photograph is shown twice (jury item 13, D-2010).
        $photos = array_map(fn ($img) => $img->getAttribute('src'), iterator_to_array($xpath->query('//img[@alt!=""]')));
        $this->assertGreaterThanOrEqual(4, count($photos));
        $this->assertSame($photos, array_values(array_unique($photos)), 'a photograph appears twice');
        $this->assertSame(0, $xpath->query('//*[@data-lp-crossfade]')->length, 'the hero no longer cycles through photographs used further down');
        $response->assertDontSee('minibus');
        $response->assertSee('CC BY-SA 4.0')->assertSee('CC0');
    }

    public function test_signed_in_visitors_are_offered_the_app_instead_of_sign_in(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get('/')->assertOk()->assertSee('Open the app')->assertSee(route('home'));
    }

    public function test_no_page_loads_anything_from_another_host(): void
    {
        $this->assertNoExternalResources($this->get('/'), 'landing');
        $this->assertNoExternalResources($this->get('/login'), 'login');
        $this->assertNoExternalResources($this->get('/forgot-password'), 'forgot password');
        $this->assertNoExternalResources($this->get(route('terms.show')), 'terms');
        $this->assertNoExternalResources($this->get('/this-page-does-not-exist'), '404');

        $passenger = User::factory()->withPersonalTeam()->create();
        foreach ([route('ride.home'), route('drive.home'), route('business.home'), route('fleet.home'), route('dashboard'), route('rides.index'), route('profile.show'), route('teams.show', $passenger->currentTeam)] as $url) {
            $this->assertNoExternalResources($this->actingAs($passenger)->get($url)->assertOk(), $url);
        }
        // R-46 ops: /ops redirects to the operator's first screen; the live board is that screen for full access.
        $this->assertNoExternalResources($this->actingAs($this->makeOperator())->get(route('ops.live'))->assertOk(), 'ops');
    }

    public function test_layouts_never_reference_a_third_party_runtime_host(): void
    {
        foreach (glob(resource_path('views/layouts/{,partials/}*.blade.php'), GLOB_BRACE) as $file) {
            $this->assertDoesNotMatchRegularExpression('#https?://(?!www\.w3\.org)#', file_get_contents($file), basename($file));
        }
    }

    public function test_sign_in_page_uses_the_kit(): void
    {
        $xpath = $this->xpath($this->get('/login')->assertOk());
        $this->assertSame(1, $xpath->query('//h1[contains(., "Sign in")]')->length);
        $this->assertSame(1, $xpath->query('//label[@for="email"]')->length);
        $this->assertSame(1, $xpath->query('//input[@id="email"][@autocomplete="username"]')->length);
        $this->assertSame(1, $xpath->query('//form[@action="'.route('login').'"]//button[@type="submit"]')->length);
    }

    public function test_pwa_manifest_service_worker_and_offline_page(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('/home', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));

        $worker = file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("request.mode === 'navigate'", $worker);
        $this->assertStringContainsString("request.method !== 'GET'", $worker);
        $this->assertStringContainsString('url.origin !== self.location.origin', $worker);
        // Only hashed build assets, fonts and icons are written to the cache.
        $this->assertStringContainsString('build\/assets', $worker);
        $this->assertSame(1, substr_count($worker, 'cache.put('));

        $offline = file_get_contents(public_path('offline.html'));
        $this->assertDoesNotMatchRegularExpression('#(src|href)="https?://#', $offline);
        $this->assertStringContainsString('tel:10111', $offline);
    }
}
