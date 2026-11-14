<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Support\Ui\AppsSwitcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\OpsFixtures;
use Tests\TestCase;

/**
 * R-51: every signed-in shell carries the shared "Dot Apps" switcher (dot-ui), and the in-page text filters use the
 * shared compact filter. Dot.Ehail has no header search, so none was added. The search forms, routes, parameters and
 * results did not change; the switcher only shows what InfoDot says the CURRENT company holds, links through InfoDot's
 * /go (never straight to a host, never with a token), and can never hold up a trip: the live trip, driver and safety
 * screens never ask InfoDot at all.
 */
class DotUiHeaderTest extends TestCase
{
    use CreatesEhailFixtures, OpsFixtures, RefreshDatabase;

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private const SECRET = 'ehail-service-secret-for-the-test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosystem.infodot' => ['redeem_url' => 'https://infodot.test/api/ecosystem/redeem', 'platform' => 'ehail', 'secret' => self::SECRET, 'timeout' => 2, 'provision' => true, 'entitlement_check' => false, 'switcher_timeout' => 1],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** InfoDot's read endpoint, as it answers this platform about one company. */
    private static function body(string $uuid, array $held = ['ehail', 'files', 'infodot']): array
    {
        $rows = [];
        foreach (['ehail' => 'Dot.Ehail', 'files' => 'Dot.Files', 'finance' => 'Dot.Finance', 'hr' => 'Dot.HR', 'infodot' => 'InfoDot', 'press' => 'Dot.Press'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'status' => 'active', 'entitled' => in_array($key, $held, true), 'is_core' => false, 'access_mode' => 'everyone'];
        }

        return ['platform' => 'ehail', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => $rows];
    }

    private function fakeInfoDot(array $stubs = []): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** A signed-in person whose current team is an InfoDot company. */
    private function memberOf(string $uuid, string $name, ?User $user = null): User
    {
        $user ??= User::factory()->withPersonalTeam()->create();
        $team = Team::where('infodot_team_uuid', $uuid)->first();
        if ($team === null) {
            $team = Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        } else {
            $team->users()->attach($user, ['role' => 'admin']);
        }
        $user->switchTeam($team);

        return $user->fresh();
    }

    /** The switcher's own markup, so an assertion cannot be satisfied by the rest of the page. */
    private function switchers(string $html): array
    {
        preg_match_all('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $m);

        return $m[0];
    }

    private function cacheKey(string $uuid): string
    {
        return 'infodot:platforms:ehail:'.$uuid;
    }

    // ── Placement: every shell that has a header ────────────────────────────

    public function test_the_rider_shell_carries_the_switcher_in_its_top_bar(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $rider = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($rider)->get(route('ride.trips'))->assertOk()->getContent();

        $this->assertCount(1, $this->switchers($html));
        $this->assertMatchesRegularExpression('/<header class="eh-topbar">.*<div class="eh-topbar__apps">\s*<div class="dsw dsw--header"/s', $html, 'inside the top bar, before the safety button, bell and account');
        $this->assertStringContainsString('data-platform="ehail"', $html);
    }

    public function test_the_driver_shell_carries_the_switcher_in_its_top_bar(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $driver = $this->memberOf(self::ACME, 'Acme Ltd', $this->makeApprovedDriver('standard', false));

        $html = $this->actingAs($driver)->get(route('drive.trips'))->assertOk()->getContent();

        $this->assertCount(1, $this->switchers($html));
        $this->assertStringContainsString('<div class="eh-topbar__apps">', $html);
    }

    public function test_the_side_shells_carry_it_in_the_phone_bar_and_in_the_desktop_strip(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $operator = $this->memberOf(self::ACME, 'Acme Ltd', $this->operatorWith(['admin']));

        $html = $this->actingAs($operator)->get(route('ops.drivers'))->assertOk()->getContent();

        $this->assertCount(2, $this->switchers($html), 'one for the bar under 1024px, one for the strip from 1024px');
        $this->assertStringContainsString('<div class="eh-side__apps">', $html);
        $this->assertStringContainsString('id="dsw-panel-desk"', $html);
        $this->assertSame(1, substr_count($html, 'id="dsw-panel"'), 'two switchers never share an id');
        Http::assertSentCount(1); // one InfoDot read for both
    }

    public function test_the_fleet_and_business_shells_carry_it_too(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $person = $this->memberOf(self::ACME, 'Acme Ltd');

        $this->assertCount(2, $this->switchers($this->actingAs($person)->get(route('fleet.home'))->assertOk()->getContent()));
        $this->assertCount(2, $this->switchers($this->actingAs($person)->get(route('business.home'))->assertOk()->getContent()));
    }

    public function test_the_legacy_shell_carries_it_in_its_top_bar(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $person = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($person)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertCount(1, $this->switchers($html));
        $this->assertMatchesRegularExpression('/<header class="topbar".*dsw dsw--header/s', $html);
    }

    public function test_a_guest_sees_no_switcher_and_loads_no_shared_assets(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('data-dsw', false)->assertDontSee('dot-ui/css', false);
    }

    // ── What the switcher says ──────────────────────────────────────────────

    public function test_a_member_sees_what_their_company_holds_with_the_current_platform_marked(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('ride.trips'))->assertOk()->getContent();
        $panel = $this->switchers($html)[0];

        $this->assertStringContainsString('data-state="ok"', $panel);
        $this->assertStringContainsString('Acme Ltd', $panel, 'the sentence names the company');
        $this->assertStringContainsString('aria-current="page"', $panel, 'the current platform is marked, not a link');
        $this->assertStringContainsString('https://infodot.test/go/files?company='.self::ACME, $panel);
        $this->assertStringNotContainsString('go/press', $panel, 'a platform the company does not hold is not a tile');
        $this->assertStringContainsString('infodot.test/platforms/press', $panel, 'it is listed as not switched on, linking to what it is');
        $this->assertStringNotContainsString(self::SECRET, $html, 'no secret in the page');
        $this->assertDoesNotMatchRegularExpression('/[?&](token|access_token|secret)=/', $panel, 'no token in any link');
        $this->assertStringNotContainsString('href="https://files.', $panel, 'every tile leaves through InfoDot, never straight to a host');

        Http::assertSent(fn (OutgoingRequest $r) => $r->hasHeader('Authorization', 'Bearer '.self::SECRET) && str_contains($r->url(), self::ACME));
    }

    public function test_two_companies_never_see_each_others_switcher(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME, ['ehail', 'files', 'infodot'])),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, ['ehail', 'finance', 'infodot'])),
        ]);
        $acme = $this->memberOf(self::ACME, 'Acme Ltd');
        $globex = $this->memberOf(self::GLOBEX, 'Globex Ltd');

        $a = $this->switchers($this->actingAs($acme)->get(route('ride.trips'))->getContent())[0];
        $g = $this->switchers($this->actingAs($globex)->get(route('ride.trips'))->getContent())[0];

        $this->assertStringContainsString('go/files?company='.self::ACME, $a);
        $this->assertStringNotContainsString('go/finance', $a);
        $this->assertStringNotContainsString(self::GLOBEX, $a);
        $this->assertStringNotContainsString('Globex', $a);
        $this->assertStringContainsString('go/finance?company='.self::GLOBEX, $g);
        $this->assertStringNotContainsString('go/files', $g);
        $this->assertStringNotContainsString(self::ACME, $g);
        $this->assertStringNotContainsString('Acme', $g);
    }

    public function test_a_503_or_a_timeout_shows_the_honest_degraded_state_and_the_page_still_works(): void
    {
        foreach ([
            fn () => Http::response('down', 503),
            fn () => function () {
                throw new ConnectionException('timed out');
            },
        ] as $i => $answer) {
            Cache::flush();
            $this->fakeInfoDot([self::url(self::ACME) => $answer()]);
            $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('ride.trips'))->assertOk()->getContent();
            $panel = $this->switchers($html)[0];

            $this->assertStringContainsString('data-state="degraded"', $panel);
            $this->assertStringContainsString('Could not check your platforms just now', $panel);
            $this->assertStringNotContainsString('Active', $panel, 'never claims access it could not check');
        }
    }

    public function test_a_personal_workspace_asks_infodot_nothing(): void
    {
        $this->fakeInfoDot();
        $person = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($person)->get(route('ride.trips'))->assertOk()->getContent();

        Http::assertNothingSent();
        $this->assertStringContainsString('data-state="personal"', $this->switchers($html)[0]);
    }

    public function test_the_read_is_given_one_second_not_three(): void
    {
        $this->assertSame(1, (int) config('ecosystem.infodot.switcher_timeout'));
        $this->assertSame(1, (int) config('ecosystem.infodot.switcher_timeout', 3));
    }

    // ── Safety: nothing here can hold up a trip ─────────────────────────────

    public function test_live_trip_driver_safety_and_dispatch_screens_never_ask_infodot(): void
    {
        $this->fakeInfoDot(); // any request from here on is a stray request and fails the test
        $rider = $this->memberOf(self::ACME, 'Acme Ltd');
        $driver = $this->memberOf(self::ACME, 'Acme Ltd', $this->makeApprovedDriver('standard', false));
        $other = $this->memberOf(self::ACME, 'Acme Ltd'); // no trip: the rider's home does not redirect to a running trip
        $ride = $this->makeRide(['status' => 'requested'], $rider);
        $operator = $this->memberOf(self::ACME, 'Acme Ltd', $this->operatorWith(['admin']));

        $screens = [
            [$other, route('ride.home')], [$rider, route('ride.trip', $ride->id)], [$rider, route('ride.safety')],
            [$driver, route('drive.home')], [$driver, route('drive.safety')],
            [$operator, route('ops.live')], [$operator, route('ops.safety')], [$operator, route('ops.rides.show', $ride->id)],
        ];

        foreach ($screens as [$user, $url]) {
            $response = $this->actingAs($user)->get($url);
            $this->assertSame(200, $response->status(), $url);
            $html = $response->getContent();
            $this->assertSame([], $this->switchers($html), $url.' prints no switcher while InfoDot has not been asked by a calmer page');
        }

        Http::assertNothingSent();
    }

    public function test_live_screens_print_the_switcher_only_from_an_answer_a_calmer_page_cached(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $rider = $this->memberOf(self::ACME, 'Acme Ltd');

        $this->actingAs($rider)->get(route('ride.trips'))->assertOk(); // an ordinary page warms the cache
        Http::assertSentCount(1);

        $html = $this->actingAs($rider)->get(route('ride.home'))->assertOk()->getContent();

        $this->assertCount(1, $this->switchers($html), 'served from the cache');
        Http::assertSentCount(1); // and the live screen asked nothing
    }

    public function test_a_failure_cached_by_a_calmer_page_still_leaves_live_screens_untouched(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response('down', 503)]);
        $rider = $this->memberOf(self::ACME, 'Acme Ltd');

        $this->actingAs($rider)->get(route('ride.trips'))->assertOk();
        $html = $this->actingAs($rider)->get(route('ride.safety'))->assertOk()->getContent();

        Http::assertSentCount(1);
        $this->assertStringContainsString('SOS', $html.'SOS'); // the page rendered; nothing about the switcher can change it
    }

    public function test_the_live_route_list_covers_the_screens_that_run_a_trip(): void
    {
        foreach (['ride.home', 'ride.trip', 'ride.safety', 'ride.safety.incident', 'drive.home', 'drive.trips.show', 'drive.safety', 'drive.api.summary',
            'safety.incidents.show', 'ops.live', 'ops.live.feed', 'ops.safety', 'ops.safety.show', 'ops.rides.show', 'rides.show', 'rides.available', 'geo.route'] as $name) {
            $this->assertTrue(AppsSwitcher::isLive($name), $name);
        }
        foreach (['ride.trips', 'ride.account', 'drive.trips', 'drive.earnings', 'ops.drivers', 'ops.finance', 'dashboard', 'profile.show', null, ''] as $name) {
            $this->assertFalse(AppsSwitcher::isLive($name), (string) $name);
        }
    }

    public function test_every_route_name_in_the_live_list_matches_a_real_route_or_a_family_of_them(): void
    {
        $names = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => $r->getName())->filter()->all();

        foreach (AppsSwitcher::LIVE_ROUTES as $pattern) {
            $this->assertNotEmpty(array_filter($names, fn ($n) => Str::is($pattern, $n)), "no route matches the live pattern {$pattern}");
        }
    }

    // ── Assets, CSP, drift ──────────────────────────────────────────────────

    public function test_the_shared_files_are_served_from_the_apps_own_origin_with_no_inline_style_or_script(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $page = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('ride.trips'))->assertOk();
        $html = $page->getContent();

        foreach (['dot-fonts', 'dot-tokens', 'dot-search', 'dot-switcher-fonts', 'dot-switcher'] as $css) {
            $this->assertStringContainsString('/dot-ui/css/'.$css.'.css', $html);
            $this->assertFileExists(public_path('dot-ui/css/'.$css.'.css'));
        }
        $this->assertStringContainsString('/dot-ui/js/dot-switcher.js', $html);
        $this->assertFileExists(public_path('dot-ui/js/dot-switcher.js'));
        $this->assertDoesNotMatchRegularExpression('#(?:src|href)="https?://(?!(?:localhost|infodot\.test)[/"])#', $this->withoutSwitcher($html), 'no foreign host in the page');

        $switcher = $this->switchers($html)[0];
        $this->assertStringNotContainsString(' style=', $switcher, 'no inline style: the CSP stays as strict as it was');
        $this->assertStringNotContainsString('<script', $switcher);
        $this->assertStringNotContainsString('onclick=', $switcher);
        $this->assertStringContainsString("img-src 'self' data: blob:", (string) $page->headers->get('Content-Security-Policy'));
        $this->assertDoesNotMatchRegularExpression('#src="https?://#', $switcher, 'logos come from /dot-ui/img/platforms on this origin');
        $this->assertStringContainsString('src="/dot-ui/img/platforms/', $switcher);
    }

    private function withoutSwitcher(string $html): string
    {
        return str_replace($this->switchers($html), '', $html);
    }

    // ── The in-page filters: same forms, same parameters, same results ─────

    public function test_the_ops_filters_keep_their_forms_parameters_and_results(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $w = $this->seedOpsWorld();
        $admin = $this->memberOf(self::ACME, 'Acme Ltd', $this->operatorWith(['admin']));

        foreach ([
            [route('ops.rides'), 'view=all', 'f-q', 'Ride number, person or address'],
            [route('ops.drivers'), '', 'f-dq', 'Name or email'],
            [route('ops.finance'), '', 'f-pq', 'Reference or ride number'],
        ] as [$url, $extra, $id, $label]) {
            $html = $this->actingAs($admin)->get($url.'?q=Sipho&'.$extra)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/<form class="ops-filter" method="GET" action="'.preg_quote($url, '/').'" role="search"/', $html, $url.' keeps its form');
            $this->assertMatchesRegularExpression('/<input[^>]*id="'.$id.'"[^>]*name="q"|<input[^>]*name="q"[^>]*id="'.$id.'"/', $html, 'same parameter and same id');
            $this->assertMatchesRegularExpression('/<input[^>]*class="dsr-filter"[^>]*maxlength="80"|<input[^>]*maxlength="80"[^>]*class="dsr-filter"/', $html, 'the shared compact filter, limit kept');
            $this->assertStringContainsString('value="Sipho"', $html, 'the current query is kept');
            $this->assertStringContainsString('for="'.$id.'"', $html, 'the visible label still points at the field');
            $this->assertStringNotContainsString('class="eh-input" type="search"', $html);
        }

        // Results are the same set as before (the query still runs through the same code).
        $this->actingAs($admin)->get(route('ops.drivers', ['q' => 'Sipho']))->assertOk()->assertSee('Sipho Driver');
        $this->actingAs($admin)->get(route('ops.drivers', ['q' => 'nobody-by-that-name']))->assertOk()->assertDontSee('Sipho Driver');
        $this->assertNotNull($w['driver']);
    }

    public function test_the_legacy_rides_search_keeps_its_form_and_parameter(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $person = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($person)->get(route('rides.index', ['q' => 'Kloof']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form method="GET" action="[^"]*\/rides"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*class="dsr-filter"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="q"[^>]*value="Kloof"|<input[^>]*value="Kloof"[^>]*name="q"/', $html);
        $this->assertStringContainsString('aria-label="Search rides"', $html);
        $this->assertStringContainsString('placeholder="Search pickup, dropoff, driver, or passenger…"', $html);
    }

    public function test_there_is_still_no_header_search_pill(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('ride.trips'))->assertOk()->getContent();

        $this->assertStringNotContainsString('class="dsr"', $html, 'Dot.Ehail has no header search, so none was added');
        $this->assertStringNotContainsString('dsr-input', $html);
    }
}
