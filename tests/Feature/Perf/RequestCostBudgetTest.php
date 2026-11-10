<?php

namespace Tests\Feature\Perf;

use App\Domain\Dispatch\Contracts\PickupEtaEstimator;
use App\Domain\Dispatch\Data\EtaEstimate;
use App\Domain\Dispatch\Dispatcher;
use App\Domain\Perf\QueryProbe;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\TestCase;

/**
 * Performance budgets as operation counts, not wall clock (docs/R46_PERF.md section 5): every SQL statement a
 * polling request runs on the production middleware stack (database sessions, database cache, a real session
 * cookie, no actingAs shortcut), and how many of them write. The rate limiter keeps the store it was built with
 * (array in tests); its own cost on the database store (about 4 statements, 1 write) is measured by the HTTP load
 * runs instead (X-Ehail-Queries, docs/R46_PERF.md section 1). A change that adds a query to a poll
 * multiplies by every online phone every few seconds, so it has to be a decision, not an accident.
 *
 * Run with EHAIL_PERF_DUMP=1 to print each request's statements.
 */
class RequestCostBudgetTest extends TestCase
{
    use CreatesDispatchFixtures, RefreshDatabase;

    /** @var list<string> */
    private array $statements = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeQuotes();
        config(['session.driver' => 'database', 'cache.default' => 'database', 'ehail_perf.session_touch_seconds' => 60]);
        $this->app['session']->forgetDrivers();
        $this->app['cache']->forgetDriver();
        DB::listen(fn (QueryExecuted $q) => $this->statements[] = $q->sql);
    }

    /**
     * A signed-in browser session stored in the sessions table, as the phone has after logging in.
     */
    private function signedInSession(User $user): array
    {
        $id = Str::random(40);
        $token = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Symfony',
            'payload' => base64_encode(json_encode(['_token' => $token, 'login_web_'.sha1(SessionGuard::class) => $user->id, '_flash' => ['old' => [], 'new' => []]])),
            'last_activity' => now()->getTimestamp(),
        ]);

        return [$id, $token];
    }

    /**
     * @return array{0: TestResponse, 1: int, 2: int} response, statements, writes
     */
    private function measure(callable $request): array
    {
        $this->app['auth']->forgetGuards();
        $this->statements = [];
        $response = $request();
        $statements = $this->statements;

        if (getenv('EHAIL_PERF_DUMP')) {
            fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $statements).PHP_EOL);
        }

        return [$response, count($statements), count(array_filter($statements, fn ($sql) => QueryProbe::isWrite($sql)))];
    }

    private function asPhone(array $session): static
    {
        return $this->withCredentials()->withCookie(config('session.cookie'), $session[0])->withHeaders(['X-CSRF-TOKEN' => $session[1], 'X-Requested-With' => 'XMLHttpRequest']);
    }

    private function warm(array $session, string $method, string $uri, array $body = []): void
    {
        // The first request after login stores the password hash in the session (Jetstream); not a steady-state cost.
        $this->measure(fn () => $this->asPhone($session)->json($method, $uri, $body)->assertSuccessful());
    }

    public function test_an_idle_online_driver_sync_stays_within_budget_and_writes_nothing(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(800));
        $session = $this->signedInSession($driver);
        $this->warm($session, 'POST', route('ehail.dispatch.driver.sync'));
        $etag = $this->asPhone($session)->postJson(route('ehail.dispatch.driver.sync'))->headers->get('ETag');

        [$response, $queries, $writes] = $this->measure(fn () => $this->asPhone($session)->postJson(route('ehail.dispatch.driver.sync'), ['samples' => []], ['If-None-Match' => $etag]));

        $response->assertOk()->assertJsonPath('feed', null);
        // session 1, user 1, heartbeat age 1, pending offer 1, global tick lock 1 (+2 when it is due: lock insert,
        // due rides), feed sources 4 = 9 to 11.
        $this->assertLessThanOrEqual(11, $queries, 'idle driver sync statements');
        $this->assertSame(0, $writes, 'an idle sync writes nothing (session untouched, heartbeat fresh)');
    }

    public function test_a_moving_driver_sync_costs_two_position_writes(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(800));
        $session = $this->signedInSession($driver);
        $this->warm($session, 'POST', route('ehail.dispatch.driver.sync'));
        $this->travel(4)->seconds();

        [$response, $queries, $writes] = $this->measure(fn () => $this->asPhone($session)->postJson(route('ehail.dispatch.driver.sync'), ['samples' => [
            ['lat' => $this->northOfPickup(760)[0], 'lng' => $this->northOfPickup(760)[1], 'accuracy' => 8, 'speed' => 9, 'heading' => 0, 'recorded_at' => now()->getTimestampMs()],
        ]]));

        $response->assertOk()->assertJsonPath('location.accepted', 1);
        $this->assertLessThanOrEqual(19, $queries, 'moving driver sync statements');
        // driver_locations upsert + driver_availability mirror; when the global tick is due (every 2 s across all
        // polls, not per driver) the cache store also clears the expired lock and inserts the new one.
        $this->assertLessThanOrEqual(4, $writes, 'moving driver sync writes');
    }

    public function test_polls_rewrite_the_session_row_at_most_once_a_minute(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(800));
        $session = $this->signedInSession($driver);
        $this->warm($session, 'GET', route('ehail.dispatch.driver.feed'));
        $sessionWrites = 0;
        DB::listen(function (QueryExecuted $q) use (&$sessionWrites) {
            if (QueryProbe::isWrite($q->sql) && str_contains($q->sql, 'sessions')) {
                $sessionWrites++;
            }
        });

        for ($i = 0; $i < 20; $i++) {
            $this->travel(3)->seconds();
            $this->asPhone($session)->getJson(route('ehail.dispatch.driver.feed'))->assertOk();
        }

        // 60 s of polling every 3 s: one touch when last_activity passed a minute.
        $this->assertLessThanOrEqual(1, $sessionWrites, 'session writes during a minute of polling');
        $this->assertSame((string) $driver->id, (string) DB::table('sessions')->where('id', $session[0])->value('user_id'));
    }

    public function test_a_changed_session_is_still_written_at_once(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(800));
        $session = $this->signedInSession($driver);
        $this->warm($session, 'GET', route('ehail.dispatch.driver.feed'));
        $before = DB::table('sessions')->where('id', $session[0])->value('payload');

        // A page view stores _previous.url: the payload changes and must be written immediately.
        $this->asPhone($session)->withHeaders(['X-Requested-With' => ''])->get(route('drive.home'))->assertOk();

        $this->assertNotSame($before, DB::table('sessions')->where('id', $session[0])->value('payload'));
    }

    public function test_poll_answers_carry_no_cookie_or_policy_overhead_once_the_session_is_settled(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(800));
        $session = $this->signedInSession($driver);
        $this->warm($session, 'POST', route('ehail.dispatch.driver.sync'));

        $response = $this->asPhone($session)->postJson(route('ehail.dispatch.driver.sync'))->assertOk();
        $names = array_map(fn ($c) => $c->getName(), $response->headers->getCookies());

        $this->assertNotContains('XSRF-TOKEN', $names);
        $this->assertNotContains(config('session.cookie'), $names, 'session unchanged and touched recently: cookie not re-sent');
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
        $this->assertTrue($response->headers->has('X-Content-Type-Options'), 'the other security headers stay');

        // A page keeps every header and cookie.
        $page = $this->asPhone($session)->withHeaders(['X-Requested-With' => ''])->get(route('drive.home'))->assertOk();
        $this->assertContains('XSRF-TOKEN', array_map(fn ($c) => $c->getName(), $page->headers->getCookies()));
    }

    public function test_the_passenger_feed_stays_within_budget_and_asks_the_router_at_most_twice_a_minute(): void
    {
        config(['ehail_perf.eta_reuse_seconds' => 30]);
        $calls = 0;
        $this->app->instance(PickupEtaEstimator::class, new class($calls) implements PickupEtaEstimator
        {
            public function __construct(public int &$calls) {}

            public function estimate(float $fromLat, float $fromLng, float $toLat, float $toLng): EtaEstimate
            {
                $this->calls++;

                return new EtaEstimate(300, 2000, EtaEstimate::ROAD);
            }
        });

        $driver = $this->onlineDriverAt(...$this->northOfPickup(2000));
        $passenger = $this->passenger();
        $ride = $this->requestRide($passenger);
        app(Dispatcher::class)->offerResponse($ride->offers()->where('status', 'pending')->firstOrFail(), $driver, 'accept');
        $session = $this->signedInSession($passenger);
        $this->warm($session, 'GET', route('ehail.dispatch.rides.feed', $ride));
        $calls = 0;
        $etas = [];

        // The driver approaches for a minute, a new position every 3 s; the passenger polls every 3 s.
        for ($i = 1; $i <= 20; $i++) {
            $this->travel(3)->seconds();
            $this->heartbeat($driver, $this->northOfPickup(2000 - 25 * $i));
            [$response, $queries] = $this->measure(fn () => $this->asPhone($session)->getJson(route('ehail.dispatch.rides.feed', $ride)));
            $response->assertOk();
            $etas[] = $response->json('driver_position.eta_s');
            $this->assertLessThanOrEqual(19, $queries, 'passenger feed statements');
        }

        $this->assertLessThanOrEqual(2, $calls, 'road ETA asked at most every 30 s');
        $this->assertSame($etas, array_values(array_filter($etas, fn ($s) => $s !== null)), 'an ETA on every poll');
        $this->assertLessThan($etas[0], $etas[8], 'the reused ETA counts down');
    }

    public function test_the_ride_record_is_untouched_by_feed_polls(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(400));
        $ride = $this->requestRide();
        app(Dispatcher::class)->offerResponse($ride->offers()->where('status', 'pending')->firstOrFail(), $driver, 'accept');
        $version = Ride::find($ride->id)->version;

        $session = $this->signedInSession($driver);
        for ($i = 0; $i < 5; $i++) {
            $this->asPhone($session)->postJson(route('ehail.dispatch.driver.sync'))->assertOk();
        }

        $this->assertSame($version, Ride::find($ride->id)->version);
    }
}
