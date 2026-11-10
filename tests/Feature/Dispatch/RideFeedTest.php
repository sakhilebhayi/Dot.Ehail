<?php

namespace Tests\Feature\Dispatch;

use App\Domain\Dispatch\Contracts\DriverEarningsEstimator;
use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Dispatch\RideFeed;
use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\TestCase;

class RideFeedTest extends TestCase
{
    use CreatesDispatchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeQuotes();
    }

    /**
     * @return array{0: Ride, 1: User, 2: User}
     */
    private function assigned(): array
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(400), name: 'Thabo Driver Mokoena');
        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Lerato Passenger Dlamini', 'email' => 'lerato@example.test']);
        $ride = $this->requestRide($passenger);
        app(Dispatcher::class)->offerResponse(RideOffer::where('pending_ride_id', $ride->id)->firstOrFail(), $driver, 'accept');

        return [$ride->fresh(), $passenger, $driver];
    }

    public function test_the_passenger_feed_has_the_documented_shape_and_shows_the_pin_and_driver(): void
    {
        [$ride, $passenger, $driver] = $this->assigned();

        $response = $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', $ride))->assertOk()
            ->assertHeader('Retry-After', '3')
            ->assertJsonStructure([
                'version', 'server_time', 'poll_after_s',
                'ride' => ['id', 'status', 'status_label', 'pickup' => ['address', 'lat', 'lng'], 'dropoff', 'category',
                    'fare' => ['quoted_total_minor', 'final_total_minor', 'currency'], 'pin', 'driver' => ['first_name', 'rating', 'vehicle' => ['make', 'model', 'color', 'plate']], 'timestamps'],
                'driver_position' => ['lat', 'lng', 'heading', 'recorded_at', 'age_s', 'stale', 'eta_s', 'eta_to', 'eta_method'],
                'connection' => ['driver_position'],
            ])
            ->assertJsonPath('ride.status', 'driver_assigned')
            ->assertJsonPath('ride.driver.first_name', 'Thabo')
            ->assertJsonPath('driver_position.eta_to', 'pickup')
            ->assertJsonPath('driver_position.eta_method', 'straight_line_estimate')
            ->assertJsonPath('connection.driver_position', 'live');

        $this->assertSame($ride->pin_encrypted, $response->json('ride.pin'));
        $this->assertStringNotContainsString('Mokoena', $response->getContent(), 'only the driver\'s first name');
        $this->assertStringNotContainsString('pin_hash', $response->getContent());
    }

    public function test_the_etag_and_since_version_answer_304_until_something_changes(): void
    {
        [$ride, $passenger, $driver] = $this->assigned();

        $first = $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', $ride))->assertOk();
        $etag = $first->headers->get('ETag');
        $version = $first->json('version');
        $this->assertSame('"v'.$version.'"', $etag);

        $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', $ride), ['If-None-Match' => $etag])->assertStatus(304)->assertHeader('ETag', $etag);
        $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', ['ride' => $ride, 'since' => $version]))->assertStatus(304);

        // The driver moves: only the position section comes back.
        $this->travel(4)->seconds();
        $this->heartbeat($driver, $this->northOfPickup(250));
        $delta = $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', $ride), ['If-None-Match' => $etag])->assertOk();
        $this->assertGreaterThan($version, $delta->json('version'));
        $this->assertArrayNotHasKey('ride', $delta->json());
        $this->assertArrayHasKey('driver_position', $delta->json());

        // The ride changes: the ride section comes back. (The test clock is frozen between
        // travel() calls; real clocks move, so two sources never share a microsecond in practice.)
        $this->travel(1)->seconds();
        app(Dispatcher::class)->advance($ride->fresh(), $driver, RideStatus::DriverArriving);
        $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', ['ride' => $ride, 'since' => $delta->json('version')]))
            ->assertOk()->assertJsonPath('ride.status', 'driver_arriving');
    }

    public function test_a_stale_driver_position_is_flagged(): void
    {
        [$ride, $passenger] = $this->assigned();
        $this->travel(2)->minutes();

        $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', $ride))
            ->assertOk()->assertJsonPath('driver_position.stale', true)->assertJsonPath('connection.driver_position', 'stale');
    }

    public function test_only_the_rides_passenger_may_read_the_passenger_feed(): void
    {
        [$ride, , $driver] = $this->assigned();
        $stranger = $this->passenger();

        $this->actingAs($stranger)->getJson(route('ehail.dispatch.rides.feed', $ride))->assertForbidden();
        $this->actingAs($driver)->getJson(route('ehail.dispatch.rides.feed', $ride))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson(route('ehail.dispatch.rides.feed', $ride))->assertUnauthorized();
    }

    public function test_no_driver_position_before_assignment_or_after_the_trip(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(400));
        $passenger = $this->passenger();
        $ride = $this->requestRide($passenger);

        $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', $ride))->assertOk()
            ->assertJsonPath('ride.status', 'searching')->assertJsonMissingPath('driver_position')->assertJsonPath('ride.driver', null)
            ->assertJsonPath('ride.pin', null);

        app(Dispatcher::class)->offerResponse(RideOffer::where('pending_ride_id', $ride->id)->first(), $driver, 'accept');
        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.cancel', $ride), ['reason' => 'changed_plans'])->assertOk();

        $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', $ride))->assertOk()
            ->assertJsonMissingPath('driver_position')->assertJsonPath('ride.driver', null)->assertJsonPath('ride.pin', null);
    }

    public function test_the_driver_feed_shows_the_offer_with_what_is_needed_to_decide_and_never_the_passenger_location(): void
    {
        $this->app->instance(DriverEarningsEstimator::class, new class implements DriverEarningsEstimator
        {
            public function estimateMinor(Ride $ride, User $driver): ?int
            {
                return 6800;
            }
        });
        $driver = $this->onlineDriverAt(...$this->northOfPickup(400));
        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Lerato Passenger Dlamini', 'email' => 'lerato@example.test']);
        $this->requestRide($passenger);

        $response = $this->actingAs($driver)->getJson(route('ehail.dispatch.driver.feed'))->assertOk()
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('availability.state', 'online')
            ->assertJsonPath('offer.passenger.first_name', 'Lerato')
            ->assertJsonPath('offer.passenger.rating_band', 'new')
            ->assertJsonPath('offer.earnings_estimate_minor', 6800)
            ->assertJsonPath('offer.trip_distance_m', 7400)
            ->assertJsonPath('offer.pickup.address', '10 Kloof Street, Gardens')
            ->assertJsonPath('ride', null);

        $this->assertGreaterThan(300, $response->json('offer.pickup_distance_m'));
        $this->assertLessThanOrEqual(15, $response->json('offer.seconds_left'));
        $body = $response->getContent();
        $this->assertStringNotContainsString('Dlamini', $body);
        $this->assertStringNotContainsString('lerato@example.test', $body);
        $this->assertStringNotContainsString('pin', strtolower(json_encode($response->json('offer'))));
    }

    public function test_the_driver_feed_without_an_earnings_estimate_sends_null_not_a_guess(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(400));
        $this->requestRide();

        $this->actingAs($driver)->getJson(route('ehail.dispatch.driver.feed'))->assertOk()->assertJsonPath('offer.earnings_estimate_minor', null);
    }

    public function test_the_driver_feed_answers_304_and_changes_when_an_offer_arrives(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(400));
        $first = $this->actingAs($driver)->getJson(route('ehail.dispatch.driver.feed'))->assertOk()->assertJsonPath('offer', null);
        $etag = $first->headers->get('ETag');

        $this->actingAs($driver)->getJson(route('ehail.dispatch.driver.feed'), ['If-None-Match' => $etag])->assertStatus(304);

        $this->requestRide();
        $this->actingAs($driver)->getJson(route('ehail.dispatch.driver.feed'), ['If-None-Match' => $etag])->assertOk()->assertJsonPath('offer.ride_id', Ride::first()->id);
    }

    public function test_the_driver_feed_poll_moves_a_lapsed_offer_on(): void
    {
        $first = $this->onlineDriverAt(...$this->northOfPickup(100));
        $second = $this->onlineDriverAt(...$this->northOfPickup(900));
        $ride = $this->requestRide();
        $this->travel(16)->seconds();
        $this->heartbeat($second, $this->northOfPickup(900));

        $this->actingAs($first)->getJson(route('ehail.dispatch.driver.feed'))->assertOk()->assertJsonPath('offer', null);
        $this->assertSame($second->id, RideOffer::where('pending_ride_id', $ride->id)->value('driver_id'));
    }

    public function test_the_driver_trip_flow_through_the_api(): void
    {
        [$ride, $passenger, $driver] = $this->assigned();

        $this->actingAs($driver)->getJson(route('ehail.dispatch.driver.feed'))->assertOk()
            ->assertJsonPath('ride.id', $ride->id)->assertJsonPath('ride.pin_required', true)->assertJsonPath('ride.passenger.first_name', 'Lerato');

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'arriving']))->assertOk()->assertJsonPath('ride_status', 'driver_arriving');
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'arrived']))->assertOk();
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'start']), ['pin' => '99999'])->assertStatus(422)->assertJsonPath('error', 'pin_wrong');
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'start']), ['pin' => $ride->fresh()->pin_encrypted])->assertOk()->assertJsonPath('ride_status', 'in_progress');
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'complete']))->assertOk()->assertJsonPath('ride_status', 'completed');

        $this->assertSame(DriverAvailability::ONLINE, DriverAvailability::where('user_id', $driver->id)->value('state'));
        $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', $ride))->assertOk()
            ->assertJsonPath('ride.fare.final_total_minor', 8500)->assertJsonMissingPath('driver_position');

        $stranger = $this->onlineDriverAt(...$this->northOfPickup(500));
        $this->actingAs($stranger)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'complete']))->assertForbidden();
    }

    /**
     * Brief target: a feed answer under 20 ms with 500 online drivers on SQLite.
     * Measures the feed queries (the poll's own work); the dispatch tick a poll
     * triggers is measured separately. Medians of 50 runs, printed for the docs.
     */
    public function test_feed_queries_stay_cheap_with_500_online_drivers(): void
    {
        $now = now();
        $rows = [];
        $users = User::factory()->count(500)->create();

        foreach ($users as $i => $user) {
            $rows[] = [
                'user_id' => $user->id, 'state' => 'online', 'since' => $now, 'online_since' => $now, 'last_heartbeat_at' => $now,
                'last_lat' => -33.9 + ($i % 25) * 0.004, 'last_lng' => 18.4 + intdiv($i, 25) * 0.004, 'last_location_at' => $now,
                'version' => 1, 'location_version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        DB::table('driver_availability')->insert($rows);
        [$ride, $passenger, $driver] = $this->assigned();
        $feed = app(RideFeed::class);
        $dispatcher = app(Dispatcher::class);

        $time = function (callable $fn): float {
            $samples = [];

            for ($i = 0; $i < 50; $i++) {
                $t = hrtime(true);
                $fn();
                $samples[] = (hrtime(true) - $t) / 1e6;
            }

            sort($samples);

            return $samples[25];
        };

        $passengerMs = $time(fn () => $feed->forPassenger($ride->fresh()));
        $driverMs = $time(fn () => $feed->forDriver($driver));
        $searching = $this->requestRide();
        $tickMs = $time(function () use ($dispatcher, $searching) {
            RideOffer::where('ride_id', $searching->id)->update(['status' => 'expired', 'pending_ride_id' => null, 'pending_driver_id' => null]);
            Ride::whereKey($searching->id)->update(['pending_offer_expires_at' => null]);
            $dispatcher->tickRide($searching->fresh());
        });

        fwrite(STDERR, sprintf("\n[feed perf, 500 online drivers, SQLite memory] passenger %.2f ms, driver %.2f ms, dispatch tick %.2f ms (medians of 50)\n", $passengerMs, $driverMs, $tickMs));

        $this->assertLessThan(20, $passengerMs);
        $this->assertLessThan(20, $driverMs);
        $this->assertLessThan(50, $tickMs, 'a tick (candidate search over 500 drivers, scoring, offer) stays well inside a poll');
    }
}
