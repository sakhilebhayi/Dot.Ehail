<?php

namespace Tests\Feature\Perf;

use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Geo\Models\DriverLocation;
use App\Domain\Geo\Models\TripPoint;
use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\TestCase;

/**
 * POST /api/ehail/driver/sync (docs/R46_PERF.md section 2): positions up, feed down, adaptive Retry-After.
 */
class DriverSyncTest extends TestCase
{
    use CreatesDispatchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeQuotes();
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points
     */
    private function sync(User $driver, array $points = [], ?string $etag = null, array $extra = []): TestResponse
    {
        $samples = array_map(fn (array $p) => ['lat' => $p[0], 'lng' => $p[1], 'accuracy' => 8, 'speed' => 9.5, 'heading' => 90, 'recorded_at' => now()->getTimestampMs()], $points);

        return $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.sync'), ['samples' => $samples] + $extra, $etag ? ['If-None-Match' => $etag] : []);
    }

    public function test_a_sync_records_the_position_and_returns_the_feed_with_an_etag_and_a_poll_hint(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(800));
        $this->travel(5)->seconds();

        $response = $this->sync($driver, [$this->northOfPickup(700)])->assertOk()
            ->assertHeader('Retry-After', '3')
            ->assertJsonPath('poll_after_s', 3)
            ->assertJsonPath('location.accepted', 1)
            ->assertJsonPath('location.on_trip', false)
            ->assertJsonPath('feed.availability.state', 'online')
            ->assertJsonPath('feed.offer', null);

        $this->assertSame('"v'.$response->json('version').'"', $response->headers->get('ETag'));
        $this->assertEqualsWithDelta($this->northOfPickup(700)[0], DriverLocation::where('driver_id', $driver->id)->value('lat'), 1e-6, 'geo latest row moved');
        $this->assertEqualsWithDelta($this->northOfPickup(700)[0], DriverAvailability::where('user_id', $driver->id)->value('last_lat'), 1e-6, 'dispatch availability mirrored');
    }

    public function test_an_unchanged_feed_comes_back_null_even_when_the_position_moved(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(800));
        $etag = $this->sync($driver)->assertOk()->headers->get('ETag');

        // A new position moves the passenger-facing location version, not the driver's own feed (D-1504).
        $this->travel(4)->seconds();
        $this->sync($driver, [$this->northOfPickup(760)], $etag)->assertOk()->assertJsonPath('feed', null)->assertJsonPath('location.accepted', 1)->assertHeader('ETag', $etag);
        $this->travel(4)->seconds();
        $this->sync($driver, [], $etag)->assertOk()->assertJsonPath('feed', null)->assertJsonPath('location', null);

        // The plain feed agrees: 304 after heartbeats and positions.
        $this->heartbeat($driver, $this->northOfPickup(700));
        $this->actingAs($driver)->getJson(route('ehail.dispatch.driver.feed'), ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_an_offer_changes_the_feed_and_asks_for_faster_polls_until_answered(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(400));
        $etag = $this->sync($driver)->headers->get('ETag');
        $ride = $this->requestRide();

        $this->sync($driver, [], $etag)->assertOk()
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('feed.offer.ride_id', $ride->id);
    }

    public function test_saver_mode_slows_an_idle_driver_but_never_an_offer(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(400));
        $this->sync($driver, [], null, ['saver' => true])->assertHeader('Retry-After', '6');

        $this->requestRide();
        $this->sync($driver, [], null, ['saver' => true])->assertHeader('Retry-After', '2');
    }

    public function test_an_offline_driver_sends_no_position_and_gets_the_offline_interval(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $profile = DriverProfile::factory()->for($user)->create(['status' => 'approved', 'is_online' => false]);
        Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true]);

        $this->sync($user, [$this->northOfPickup(100)])->assertOk()
            ->assertHeader('Retry-After', '30')
            ->assertJsonPath('location.error', 'not_online');

        $this->assertFalse(DriverLocation::where('driver_id', $user->id)->exists(), 'no position kept for an offline driver');
        $this->assertFalse(DriverAvailability::where('user_id', $user->id)->exists(), 'a sync creates no availability row');
    }

    public function test_a_parked_sync_without_a_position_refreshes_the_heartbeat_at_most_every_20_seconds(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(400));
        $first = DriverAvailability::where('user_id', $driver->id)->value('last_heartbeat_at');

        $this->travel(5)->seconds();
        $this->sync($driver)->assertOk();
        $this->assertSame((string) $first, (string) DriverAvailability::where('user_id', $driver->id)->value('last_heartbeat_at'), 'within 20 s: no write');

        $this->travel(20)->seconds();
        $this->sync($driver)->assertOk();
        $this->assertNotSame((string) $first, (string) DriverAvailability::where('user_id', $driver->id)->value('last_heartbeat_at'), 'after 20 s: refreshed');
        $this->assertFalse(DriverAvailability::where('user_id', $driver->id)->firstOrFail()->isStale());
    }

    public function test_on_a_trip_the_positions_build_the_trail(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(400));
        $ride = $this->requestRide();
        $this->sync($driver);
        $offer = $ride->fresh()->offers()->where('status', 'pending')->firstOrFail();
        $this->actingAs($driver)->postJson(route('ehail.dispatch.offers.accept', $offer))->assertOk();
        Ride::whereKey($ride->id)->update(['status' => 'in_progress', 'started_at' => now()]);

        foreach ([300, 200, 100] as $i => $metres) {
            $this->travel(5)->seconds();
            $this->sync($driver, [$this->northOfPickup($metres)])->assertOk()->assertJsonPath('location.on_trip', true)->assertHeader('Retry-After', '3');
        }

        $this->assertSame(3, TripPoint::where('ride_id', $ride->id)->count());
    }

    public function test_bad_samples_are_refused_with_the_json_error_shape(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(400));

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.sync'), ['samples' => [['lat' => 200, 'lng' => 18.4]]])
            ->assertStatus(422)->assertJsonPath('error', 'validation_failed')->assertJsonStructure(['message', 'errors']);
    }

    public function test_guests_are_refused(): void
    {
        $this->postJson(route('ehail.dispatch.driver.sync'), [])->assertUnauthorized();
    }
}
