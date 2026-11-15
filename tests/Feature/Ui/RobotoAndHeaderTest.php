<?php

namespace Tests\Feature\Ui;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\FleetFixtures;
use Tests\Concerns\OpsFixtures;
use Tests\TestCase;

/**
 * R-52: Roboto is the one face (the shared dot-ui files, served from this origin) and every signed-in shell carries the shared
 * header with the real search. The header's markup contract per shell, the type rules, the files and the absence of every old
 * face and of any external font host.
 */
class RobotoAndHeaderTest extends TestCase
{
    use CreatesEhailFixtures, FleetFixtures, OpsFixtures, RefreshDatabase;

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    /** @return array<string, array{0: User, 1: string, 2: string}> name => [user, url, search scope] */
    private function pages(): array
    {
        $rider = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();
        $operator = $this->operatorWith(['admin']);
        $fleetOwner = User::factory()->withPersonalTeam()->create();
        $this->fleetOwnedBy($fleetOwner);

        return [
            'rider: trips' => [$rider, route('ride.trips'), 'passenger'],
            'rider: account' => [$rider, route('ride.account'), 'passenger'],
            'rider: safety' => [$rider, route('ride.safety'), 'passenger'],
            'rider: booking' => [$rider, route('ride.home'), 'passenger'],
            'driver: console' => [$driver, route('drive.home'), 'driver'],
            'driver: trips' => [$driver, route('drive.trips'), 'driver'],
            'operations: live' => [$operator, route('ops.live'), 'ops'],
            'operations: rides' => [$operator, route('ops.rides'), 'ops'],
            'operations: finance' => [$operator, route('ops.finance'), 'ops'],
            'fleet: overview' => [$fleetOwner, route('fleet.home'), 'fleet'],
            'legacy: dashboard' => [$rider, route('dashboard'), 'passenger'],
            'legacy: rides' => [$rider, route('rides.index'), 'passenger'],
            'legacy: profile' => [$rider, route('profile.show'), 'passenger'],
            'search page' => [$rider, route('search', ['q' => 'kloof']), 'passenger'],
        ];
    }

    public function test_every_signed_in_page_type_has_one_shared_header_with_the_real_search(): void
    {
        foreach ($this->pages() as $name => [$user, $url, $scope]) {
            $response = $this->actingAs($user)->get($url);
            $this->assertSame(200, $response->status(), $name);
            $x = $this->xpath($response->getContent());

            $this->assertSame(1, $x->query('//header[contains(concat(" ", normalize-space(@class), " "), " dh ")]')->length, $name.': one shared header');
            $this->assertSame(0, $x->query('//header[contains(@class, "eh-topbar") or contains(@class, "eh-side__bar") or @class="topbar"]')->length, $name.': none of the old bars');
            $this->assertSame(1, $x->query('//header//div[contains(@class, "dsr")][@data-eh-search]')->length, $name.': the search pill is in the header');
            $this->assertSame(1, $x->query('//header//form[@role="search"][@action="'.route('search').'"]')->length, $name.': a plain GET form to the results page');
            $this->assertSame(1, $x->query('//header//form[@role="search"]/input[@type="hidden"][@name="scope"][@value="'.$scope.'"]')->length, $name.': scope '.$scope);
            $this->assertSame(1, $x->query('//header//input[@name="q"][@type="search"]')->length, $name);
            $this->assertSame(1, $x->query('//header//div[contains(@class, "dsr")][@data-dsr-nav][@data-dsr-enter-first]')->length, $name.': the shared results controller drives the rows');
            $this->assertSame(route('search.suggest'), $x->query('//header//div[@data-eh-search]')->item(0)->getAttribute('data-eh-search'), $name);
            $this->assertSame(1, $x->query('//header//div[contains(@class, "dh-account")]//form[@action="'.route('logout').'"]')->length, $name.': account menu with sign-out');
            $this->assertSame(1, $x->query('//header//button[@data-dh-menu-button][@aria-label]')->length, $name.': the menu button');
        }
    }

    public function test_the_header_markup_carries_no_inline_style_and_no_script_and_the_original_logo_files(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $html = $this->actingAs($user)->get(route('ride.trips'))->getContent();
        preg_match('#<header class="dh.*?</header>#s', $html, $m);
        $header = $m[0] ?? '';

        $this->assertNotSame('', $header);
        $this->assertStringNotContainsString(' style=', $header);
        $this->assertStringNotContainsString('<script', $header);
        $this->assertStringContainsString('/images/logo.png', $header);
        $this->assertStringContainsString('/images/logo-light.png', $header);
        $this->assertStringContainsString('dh-logo-day', $header);
        $this->assertStringContainsString('dh-logo-night', $header);
    }

    public function test_rider_and_driver_keep_their_bottom_tab_bar_and_the_safety_control_in_the_header(): void
    {
        foreach ([[User::factory()->withPersonalTeam()->create(), route('ride.trips'), 4], [$this->makeApprovedDriver(), route('drive.trips'), 4]] as [$user, $url, $tabs]) {
            $x = $this->xpath($this->actingAs($user)->get($url)->getContent());

            $this->assertSame($tabs, $x->query('//nav[contains(@class, "eh-tabbar")]//a')->length, 'the tab bar stays: four destinations, each a 44px target');
            $this->assertSame(1, $x->query('//header[contains(@class, "dh")]//a[contains(@class, "eh-safety-btn")]')->length, 'Safety is in the bar at every width');
            $primary = $x->query('//header//nav[contains(@class, "dh-nav")]//a')->length;
            $this->assertGreaterThan(0, $primary);
            $this->assertLessThanOrEqual(7, $primary, 'a short primary nav');
            $this->assertSame(0, $x->query('//header//nav[contains(@class, "dh-nav")]//a[normalize-space()="Account" or normalize-space()="Safety"]')->length, 'the account has its menu and Safety its button: no duplicates');
        }
    }

    public function test_a_live_trip_screen_replaces_safety_with_sos_in_the_header_and_loads_every_dot_ui_script_deferred(): void
    {
        $rider = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide(['status' => 'searching'], $rider);
        $html = $this->actingAs($rider)->get(route('ride.trip', $ride))->assertOk()->getContent();
        $x = $this->xpath($html);

        $this->assertSame(1, $x->query('//header[contains(@class, "dh")]//button[@data-pax-sos-open]')->length, 'SOS is in the bar');
        foreach ($x->query('//script[contains(@src, "/dot-ui/")]') as $script) {
            $this->assertTrue($script->hasAttribute('defer'), $script->getAttribute('src').' must be deferred: nothing here blocks a trip screen');
        }
        $this->assertStringNotContainsString('dot-theme-boot.js', $html, 'the existing nonce-d first-paint script stays the only blocking one');
    }

    public function test_side_shells_adopt_their_sidebar_as_the_header_menu_and_keep_one_menu_at_a_time(): void
    {
        foreach ([[$this->operatorWith(['admin']), route('ops.drivers')], [User::factory()->withPersonalTeam()->create(), route('business.home')]] as [$user, $url]) {
            if (str_contains($url, '/business')) {
                continue;
            }
            $x = $this->xpath($this->actingAs($user)->get($url)->getContent());

            $this->assertSame(1, $x->query('//header[contains(@class, "dh--adopt")][contains(@class, "dh--nonav")]')->length);
            $this->assertSame('eh-side-nav', $x->query('//header//button[@data-dh-menu-button]')->item(0)->getAttribute('aria-controls'));
            $this->assertSame(1, $x->query('//nav[@id="eh-side-nav"]//button[@data-dh-close][@aria-label]')->length, 'the sidebar has its own close button');
            $this->assertSame(0, $x->query('//*[@data-eh-drawer-open or @data-eh-drawer-close or contains(@class, "eh-side__scrim")]')->length, 'the old drawer hooks and scrim are gone');
            $this->assertSame(0, $x->query('//nav[@id="eh-side-nav"]//form[@action="'.route('logout').'"]')->length, 'sign-out is in the account menu only');
            $this->assertGreaterThan(5, $x->query('//nav[@id="eh-side-nav"]//a[contains(@class, "eh-side__link")]')->length);
        }
    }

    public function test_roboto_is_declared_and_its_files_are_served_from_this_origin_for_everyone(): void
    {
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('/dot-ui/css/dot-roboto.min.css', $html);
        $this->assertStringContainsString('/dot-ui/css/dot-type.min.css', $html);
        $this->assertStringContainsString('as="font"', $html);
        $this->assertStringContainsString('class="eh dot-roboto"', $html);

        $css = (string) file_get_contents(public_path('dot-ui/css/dot-roboto.css'));
        $this->assertStringContainsString("font-family: 'Roboto'", $css);
        preg_match_all("#url\\('\\.\\./(fonts/roboto/[^']+\\.woff2)'\\)#", $css, $files);
        $this->assertCount(2, $files[1], 'latin and latin-ext');
        foreach ($files[1] as $file) {
            $this->assertFileExists(public_path('dot-ui/'.$file));
            $this->assertGreaterThan(20000, filesize(public_path('dot-ui/'.$file)), 'a real font file');
        }
    }

    public function test_the_design_tokens_point_at_roboto_and_no_other_face_or_font_host_is_left_anywhere(): void
    {
        $tokens = (string) file_get_contents(resource_path('css/ds/tokens.css'));
        $this->assertMatchesRegularExpression('/--eh-font-ui:\s*var\(--dot-font-sans,\s*"Roboto"/', $tokens);
        $this->assertMatchesRegularExpression('/--eh-font-display:\s*var\(--eh-font-ui\)/', $tokens);

        $this->assertDirectoryDoesNotExist(public_path('fonts'), 'the Barlow files are gone');
        $this->assertFileDoesNotExist(resource_path('css/ds/fonts.css'));
        $this->assertSame([], glob(public_path('dot-ui/switcher-fonts/*')) ?: [], 'no stencil face');
        $this->assertFileDoesNotExist(public_path('dot-ui/css/dot-fonts.css'));

        $banned = ['Barlow', 'Syne', 'Figtree', 'Archivo', 'IBM Plex', 'Big Shoulders', 'fonts.googleapis.com', 'fonts.gstatic.com', 'use.typekit.net', 'cdn.jsdelivr.net/npm/@fontsource'];
        $roots = [resource_path('css'), resource_path('views'), resource_path('js'), base_path('tailwind.config.js'), public_path('offline.html'), public_path('sw.js')];
        foreach ($roots as $root) {
            $files = is_dir($root) ? collect(File::allFiles($root))->map->getPathname()->all() : [$root];
            foreach ($files as $file) {
                if (str_ends_with($file, '.png') || str_ends_with($file, '.md')) {
                    continue;
                }
                $text = (string) file_get_contents($file);
                foreach ($banned as $word) {
                    // The wiki credits and a comment that names the face it replaced are the only mentions allowed.
                    $stripped = preg_replace('#/\*.*?\*/|\{\{--.*?--\}\}#s', '', $text);
                    $this->assertStringNotContainsString($word, (string) $stripped, str_replace(base_path().'/', '', $file).' still names '.$word);
                }
            }
        }
    }

    public function test_the_service_worker_and_the_offline_page_use_roboto_from_dot_ui(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString('/dot-ui/css/dot-roboto.min.css', $sw);
        $this->assertStringContainsString('/dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2', $sw);
        $this->assertStringContainsString("'eh-shell-v3'", $sw, 'the cache name changed, so the old Barlow files are dropped');
        $offline = (string) file_get_contents(public_path('offline.html'));
        $this->assertStringContainsString('/dot-ui/css/dot-roboto.min.css', $offline);
        $this->assertStringNotContainsString('@font-face', $offline);
    }

    public function test_every_other_standalone_page_loads_roboto_too(): void
    {
        $rider = User::factory()->withPersonalTeam()->create();

        $this->get(route('login'))->assertSee('class="eh dot-roboto"', false);
        $this->get('/no-such-page-xyz')->assertNotFound()->assertSee('/dot-ui/css/dot-roboto.min.css', false);
        $this->actingAs($rider)->get(route('rides.index'))->assertOk()->assertSee('/dot-ui/css/dot-ui.min.css', false);
    }

    public function test_the_display_type_keeps_its_tight_tracked_signage_character_in_roboto_weights(): void
    {
        // The identity (heavy, tight, tracked display lettering and number plates) is kept: display styles use weight 700 with the display token,
        // which is Roboto now, and the plates keep their tracking.
        $driverCss = (string) file_get_contents(resource_path('css/driver.css'));
        $this->assertMatchesRegularExpression('/\.dr-plate\s*\{[^}]*font-family:\s*var\(--eh-font-display\);\s*font-weight:\s*700;\s*letter-spacing:\s*0\.02em/', $driverCss);
        $components = (string) file_get_contents(resource_path('css/ds/components.css'));
        $this->assertMatchesRegularExpression('/\.eh-money--xl\s*\{[^}]*font-weight:\s*700;\s*letter-spacing:\s*-0\.015em/', $components);
    }
}
