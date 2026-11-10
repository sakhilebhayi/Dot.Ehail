<?php

namespace Tests\Feature\Ehail\Ds;

use App\Models\Fleet;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

/** ds (R-46): the app shells render, are reachable by the right people, and only link to routes that exist. */
class ShellPagesTest extends TestCase
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

    private function assertShellLandmarks(DOMXPath $xpath, string $surface): void
    {
        $this->assertSame(1, $xpath->query('//meta[@name="viewport"][contains(@content, "width=device-width")]')->length);
        $this->assertSame(1, $xpath->query('//link[@rel="manifest"]')->length, 'manifest link');
        $this->assertSame(1, $xpath->query('//a[contains(@class, "eh-skip")][@href="#main"]')->length, 'skip link');
        $this->assertSame(1, $xpath->query('//main[@id="main"]')->length, 'one main landmark');
        $this->assertSame(1, $xpath->query('//*[@data-surface="'.$surface.'"]')->length, 'surface marker');
        $this->assertSame(1, $xpath->query('//h1')->length, 'one page heading');
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        foreach (['/home', '/ride', '/drive', '/ops', '/business', '/fleet'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_the_passenger_shell_shows_only_destinations_that_exist(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $response = $this->actingAs($passenger)->get(route('ride.home'))->assertOk()->assertSee('Booking opens here');
        $xpath = $this->xpath($response);
        $this->assertShellLandmarks($xpath, 'passenger');

        $tabs = $xpath->query('//nav[contains(@class, "eh-tabbar")]//a');
        $labels = array_map(fn ($node) => trim(preg_replace('/\s+/', ' ', $node->textContent)), iterator_to_array($tabs));
        $this->assertSame(['Ride', 'Trips', 'Account'], $labels, 'Safety stays hidden until its route exists');
        $this->assertSame(route('rides.index'), $tabs->item(1)->getAttribute('href'), 'Trips falls back to the working ride list');
        $this->assertSame('page', $tabs->item(0)->getAttribute('aria-current'));

        // The honest empty state points at the request form that works today.
        $this->assertSame(1, $xpath->query('//main//a[@href="'.route('rides.create').'"]')->length);
        // The default safety control offers South Africa's emergency numbers as calls.
        foreach (['10111', '10177', '112'] as $number) {
            $this->assertSame(1, $xpath->query('//dialog[@id="eh-safety-dialog"]//a[@href="tel:'.$number.'"]')->length, $number);
        }
    }

    public function test_the_driver_shell_reflects_the_real_driver_state(): void
    {
        $notADriver = User::factory()->withPersonalTeam()->create();
        $response = $this->actingAs($notADriver)->get(route('drive.home'))->assertOk()->assertSee('Not registered to drive');
        $xpath = $this->xpath($response);
        $this->assertShellLandmarks($xpath, 'driver');
        $this->assertSame(1, $xpath->query('//main//a[@href="'.route('drive.apply').'"]')->length);
        $labels = array_map(fn ($n) => trim(preg_replace('/\s+/', ' ', $n->textContent)), iterator_to_array($xpath->query('//nav[contains(@class, "eh-tabbar")]//a')));
        $this->assertSame(['Drive', 'Account'], $labels, 'earnings and trips are for drivers only');
        $this->assertSame('night', $xpath->query('//html')->item(0)->getAttribute('data-theme-default'));

        // Driver app (R-46 wave 2): "online" is dispatch's availability (the state offers come from), not the pre-R-46
        // profile flag, so the driver goes online through dispatch here. Documents are not part of this shell test.
        config(['ehail_safety.verification.require_documents' => false]);
        $online = $this->makeApprovedDriver(online: false);
        app(\App\Domain\Dispatch\AvailabilityService::class)->goOnline($online);
        $xpath = $this->xpath($this->actingAs($online)->get(route('drive.home'))->assertOk()->assertSee('You are online'));
        $this->assertSame(1, $xpath->query('//section[contains(@class, "eh-statebar")][@data-state="online"]')->length);
        $this->assertSame(1, $xpath->query('//main//a[@href="'.route('rides.available').'"]')->length, 'the working driver desk stays reachable');

        $offline = $this->makeApprovedDriver(online: false);
        $this->actingAs($offline)->get(route('drive.home'))->assertOk()->assertSee('You are offline');
    }

    public function test_operations_is_for_operators_only_and_lists_working_pages(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('ops.home'))->assertForbidden();

        // R-46 ops: /ops sends an operator to the first screen their role opens.
        $this->actingAs($this->makeOperator())->get(route('ops.home'))->assertRedirect(route('ops.live'));
        $xpath = $this->xpath($this->actingAs($this->makeOperator())->get(route('ops.live'))->assertOk());
        $this->assertShellLandmarks($xpath, 'ops');
        $links = $xpath->query('//nav[@id="eh-side-nav"]//a[contains(@class, "eh-side__link")]');
        $hrefs = array_map(fn ($n) => $n->getAttribute('href'), iterator_to_array($links));
        $this->assertContains(route('ops.live'), $hrefs);
        $this->assertContains(route('ops.rides'), $hrefs);
        $this->assertContains(route('ops.fleets'), $hrefs);
        $this->assertSame(1, $xpath->query('//button[@data-eh-drawer-open][@aria-controls="eh-side-nav"][@aria-expanded="false"]')->length);
    }

    public function test_business_and_fleet_shells_render_honest_states(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $this->assertShellLandmarks($this->xpath($this->actingAs($owner)->get(route('business.home'))->assertOk()->assertSee('Business accounts open here')), 'business');
        $this->actingAs($owner)->get(route('fleet.home'))->assertOk()->assertSee('Register a fleet');

        $fleet = Fleet::create(['name' => 'Soweto Cabs', 'owner_user_id' => $owner->id, 'status' => 'pending']);
        $xpath = $this->xpath($this->actingAs($owner)->get(route('fleet.home'))->assertOk()->assertSee('Soweto Cabs')->assertSee('In review'));
        $this->assertShellLandmarks($xpath, 'fleet');
        $this->assertSame(1, $xpath->query('//main//a[@href="'.route('fleets.show', $fleet).'"]')->length);
    }

    public function test_home_sends_each_role_to_its_surface(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get('/home')->assertRedirect(route('ride.home'));
        $this->actingAs($this->makeApprovedDriver())->get('/home')->assertRedirect(route('drive.home'));
        $this->actingAs($this->makeOperator())->get('/home')->assertRedirect(route('ops.home'));
    }

    public function test_the_dashboard_routes_people_to_their_home(): void
    {
        $driver = $this->makeApprovedDriver();
        $xpath = $this->xpath($this->actingAs($driver)->get(route('dashboard'))->assertOk());
        $links = $xpath->query('//nav[@aria-label="Your Dot.Ehail apps"]//a');
        $this->assertSame(route('drive.home'), $links->item(0)->getAttribute('href'), 'primary home first');
        $this->assertSame(route('ride.home'), $links->item(1)->getAttribute('href'));
    }

    public function test_the_account_menu_offers_only_surfaces_the_person_has(): void
    {
        $operator = $this->makeOperator();
        $xpath = $this->xpath($this->actingAs($operator)->get(route('ride.home'))->assertOk());
        $switches = array_map(fn ($n) => $n->getAttribute('href'), iterator_to_array($xpath->query('//details[@data-eh-menu]//a[contains(@class, "eh-account__item")]')));
        $this->assertContains(route('ops.home'), $switches);
        $this->assertNotContains(route('drive.home'), $switches);
        $this->assertSame(1, $xpath->query('//details[@data-eh-menu]//form[@action="'.route('logout').'"]')->length);
    }
}
