<?php

namespace Tests\Feature\A11y;

use App\Domain\Business\CategoryNames;
use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Dispatch\RideFeed;
use App\Models\User;
use App\Support\DriverIdentifiers;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\CreatesSafetyFixtures;
use Tests\Concerns\OpsFixtures;
use Tests\TestCase;

/**
 * R-46 accessibility regressions (docs/R46_A11Y.md): the markup fixes the axe sweep, the keyboard walk and the
 * screen-reader review asked for, pinned so they do not come back.
 */
class AccessibilityMarkupTest extends TestCase
{
    use CreatesEhailFixtures, CreatesSafetyFixtures, OpsFixtures, RefreshDatabase;

    private function xpath(TestResponse|string $page): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML(is_string($page) ? $page : $page->getContent());

        return new DOMXPath($dom);
    }

    public function test_the_account_menu_is_named_by_its_words_only_and_its_initials_are_drawn(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Mokoena']);
        $xpath = $this->xpath($this->actingAs($user)->get('/ride/trips')->assertOk());

        $summary = $xpath->query('//details[contains(@class, "eh-account")]/summary')->item(0);
        $this->assertSame('Account menu for Thandi Mokoena', $summary->getAttribute('aria-label'));
        // WCAG 2.5.3: no visible text in the control that its name does not contain.
        $this->assertSame('', trim($summary->textContent));
        $this->assertSame('TM', $xpath->query('.//span[contains(@class, "eh-avatar")]', $summary)->item(0)->getAttribute('data-initials'));

        // Elsewhere the avatar keeps its text initials (the component's default).
        $this->assertStringContainsString('>TN<', Blade::render('<x-eh.avatar name="Thandi Nkosi" />'));
    }

    public function test_legal_pages_have_one_h1_and_no_dashes(): void
    {
        foreach (['/terms-of-service' => 'Terms of Service', '/privacy-policy' => 'Privacy Policy', '/cookies' => 'Cookie Policy'] as $url => $title) {
            $page = $this->get($url)->assertOk();
            $xpath = $this->xpath($page);
            $this->assertSame(1, $xpath->query('//h1')->length, $url);
            $this->assertSame($title, trim($xpath->query('//h1')->item(0)->textContent));
            $main = $xpath->query('//main')->item(0)?->textContent ?? $page->getContent();
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $main, "$url shows an en or em dash");
        }
    }

    public function test_a_missing_identifier_reads_as_words_not_a_dash(): void
    {
        $this->assertSame('Not given', DriverIdentifiers::mask(null));
    }

    public function test_the_driver_console_announces_without_repeating_and_its_status_bar_is_one_named_region(): void
    {
        [$driver] = $this->makeVerifiedDriver();
        $xpath = $this->xpath($this->actingAs($driver)->get('/drive')->assertOk());

        $bar = $xpath->query('//section[contains(@class, "eh-statebar")]')->item(0);
        $this->assertSame('Driver status', $bar->getAttribute('aria-label'));
        $this->assertSame('-1', $xpath->query('//h1[@id="drive-state-title"]')->item(0)->getAttribute('tabindex'), 'focus returns here after an offer');
        // PIN progress is said in words by a polite region; the dots are hidden from screen readers.
        $this->assertSame('true', $xpath->query('//*[@data-dr-pin-boxes]')->item(0)->getAttribute('aria-hidden'));
        $said = $xpath->query('//*[@data-dr-pin-said]')->item(0);
        $this->assertSame('polite', $said->getAttribute('aria-live'));
        $this->assertSame('0 of 4 digits entered', trim($said->textContent));
        // The offer is a modal dialog; the SOS result heading can take focus.
        $offer = $xpath->query('//*[@data-dr-offer]')->item(0);
        $this->assertSame('dialog', $offer->getAttribute('role'));
        $this->assertSame('true', $offer->getAttribute('aria-modal'));
        $this->assertSame('-1', $xpath->query('//*[@data-dr-sos-result-title]')->item(0)->getAttribute('tabindex'));
    }

    public function test_the_live_ride_has_a_map_in_words_and_a_pin_with_a_valid_name(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide(['status' => 'driver_assigned', 'pin' => '0969'], $passenger);
        $xpath = $this->xpath($this->actingAs($passenger)->get(route('ride.trip', $ride))->assertOk());

        $map = $xpath->query('//*[@id="pax-map"]')->item(0);
        $this->assertSame('pax-map-summary', $map->getAttribute('aria-describedby'));
        $this->assertSame(1, $xpath->query('//*[@id="pax-map-summary"][contains(@class, "eh-sr-only")]')->length);

        $pin = $xpath->query('//*[@data-pax-pin]')->item(0);
        $this->assertSame('img', $pin->getAttribute('role'), 'aria-label on a plain paragraph is not allowed');
    }

    public function test_ops_figures_are_valid_description_lists_and_action_columns_are_named(): void
    {
        $admin = $this->operatorWith(['admin']);
        foreach (['/ops/rides', '/ops/live', '/ops/safety'] as $url) {
            $xpath = $this->xpath($this->actingAs($admin)->get($url)->assertOk());
            $this->assertSame(0, $xpath->query('//dl//a[dt or dd]')->length, "$url: a link may not wrap dt or dd");
            foreach ($xpath->query('//dl/div/*') as $child) {
                $this->assertContains($child->nodeName, ['dt', 'dd'], "$url: a dl group holds only dt and dd");
            }
        }
        foreach (['/ops/tariffs/commission', '/ops/tariffs/promotions'] as $url) {
            $xpath = $this->xpath($this->actingAs($admin)->get($url)->assertOk());
            foreach ($xpath->query('//th') as $th) {
                $this->assertNotSame('', trim($th->textContent), "$url: every column header has words");
            }
        }
    }

    public function test_a_ride_category_has_one_name_on_every_surface(): void
    {
        RideCategory::query()->updateOrCreate(['key' => 'standard'], ['name' => 'Comfort']);
        $this->assertSame('Comfort', CategoryNames::for('standard'));
        $this->assertSame('Night shuttle', CategoryNames::for('night_shuttle'), 'an unknown key still reads as words');

        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide(['status' => 'searching', 'category_code' => 'standard'], $passenger);
        $feed = app(RideFeed::class)->forPassenger($ride->fresh(), 0);
        $this->assertSame('Comfort', data_get($feed, 'body.ride.category_name'), 'the driver and passenger read the catalogue name');
    }
}
