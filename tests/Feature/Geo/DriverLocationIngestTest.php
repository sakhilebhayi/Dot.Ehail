<?php

namespace Tests\Feature\Geo;

use App\Domain\Geo\LatLng;
use App\Domain\Geo\Models\DriverLocation;
use App\Domain\Geo\Models\TripPoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsGeoTrails;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class DriverLocationIngestTest extends TestCase
{
    use BuildsGeoTrails, CreatesEhailFixtures, RefreshDatabase;

    private LatLng $sandton;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandton = new LatLng(-26.1076, 28.0567);
        $this->freezeTime();
    }

    /**
     * @return array<string, mixed>
     */
    private function sample(LatLng $at, int $secondsAgo = 0, float $accuracy = 8, ?float $heading = 90): array
    {
        return [
            'lat' => $at->lat,
            'lng' => $at->lng,
            'accuracy' => $accuracy,
            'speed' => 10,
            'heading' => $heading,
            'recorded_at' => now()->subSeconds($secondsAgo)->getTimestampMs(),
        ];
    }

    private function postSamples(User $driver, array ...$samples)
    {
        return $this->actingAs($driver)->postJson(route('geo.driver.location'), ['samples' => $samples]);
    }

    public function test_an_online_driver_updates_their_latest_location(): void
    {
        $driver = $this->makeApprovedDriver();

        $this->postSamples($driver, $this->sample($this->sandton))
            ->assertOk()
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('outcomes.0.latest_updated', true)
            ->assertJsonPath('outcomes.0.trip_point_stored', false)
            ->assertJsonPath('on_trip', false);

        $row = DriverLocation::where('driver_id', $driver->id)->sole();
        $this->assertEqualsWithDelta(-26.1076, $row->lat, 1e-7);
        $this->assertSame(8.0, $row->accuracy_m);
        $this->assertSame(0, TripPoint::count(), 'no trail is kept outside a trip');
    }

    public function test_the_latest_row_is_one_row_per_driver(): void
    {
        $driver = $this->makeApprovedDriver();

        $this->postSamples($driver, $this->sample($this->sandton))->assertOk();
        $this->travel(3)->seconds();
        $this->postSamples($driver, $this->sample($this->offset($this->sandton, 30)))->assertOk();

        $this->assertSame(1, DriverLocation::count());
        $this->assertEqualsWithDelta($this->offset($this->sandton, 30)->lng, DriverLocation::sole()->lng, 1e-7);
    }

    public function test_offline_drivers_and_passengers_cannot_report_a_location(): void
    {
        $offline = $this->makeApprovedDriver(online: false);
        $passenger = User::factory()->withPersonalTeam()->create();

        $this->postSamples($offline, $this->sample($this->sandton))->assertStatus(409)->assertJsonPath('reason', 'not_online');
        $this->postSamples($passenger, $this->sample($this->sandton))->assertStatus(409);

        $this->assertSame(0, DriverLocation::count(), 'a passenger\'s position is never stored');
    }

    public function test_posts_are_throttled_to_one_every_two_seconds(): void
    {
        $driver = $this->makeApprovedDriver();

        $this->postSamples($driver, $this->sample($this->sandton))->assertOk();
        $this->postSamples($driver, $this->sample($this->offset($this->sandton, 10)))->assertStatus(429);
        $this->travel(2)->seconds();
        $this->postSamples($driver, $this->sample($this->offset($this->sandton, 20)))->assertOk();
    }

    public function test_bad_samples_are_rejected_with_reasons(): void
    {
        $driver = $this->makeApprovedDriver();

        $this->postSamples($driver,
            $this->sample($this->sandton, accuracy: 900),          // inaccurate
            $this->sample($this->sandton, secondsAgo: -120),        // two minutes in the future
            $this->sample($this->sandton, secondsAgo: 3600),        // an hour old
        )->assertOk()
            ->assertJsonPath('accepted', 0)
            ->assertJsonPath('outcomes.0.reason', 'inaccurate')
            ->assertJsonPath('outcomes.1.reason', 'future')
            ->assertJsonPath('outcomes.2.reason', 'stale');

        $this->assertSame(0, DriverLocation::count());
    }

    public function test_a_sample_older_than_the_latest_is_out_of_order(): void
    {
        $driver = $this->makeApprovedDriver();
        $this->postSamples($driver, $this->sample($this->sandton))->assertOk();
        $this->travel(2)->seconds();

        $this->postSamples($driver, $this->sample($this->offset($this->sandton, 5), secondsAgo: 10))
            ->assertJsonPath('outcomes.0.reason', 'out_of_order');
    }

    public function test_teleporting_samples_are_rejected_until_three_in_a_row_agree(): void
    {
        $driver = $this->makeApprovedDriver();
        $this->postSamples($driver, $this->sample($this->sandton))->assertOk();
        $faraway = $this->offset($this->sandton, 30_000);

        foreach (['implausible_jump', 'implausible_jump'] as $expected) {
            $this->travel(4)->seconds();
            $this->postSamples($driver, $this->sample($faraway))->assertJsonPath('outcomes.0.reason', $expected);
        }

        // The third consecutive fix at the new place is believed (the first one was the wrong fix).
        $this->travel(4)->seconds();
        $this->postSamples($driver, $this->sample($faraway))->assertJsonPath('outcomes.0.accepted', true);
        $this->assertEqualsWithDelta($faraway->lng, DriverLocation::sole()->lng, 1e-7);
    }

    public function test_samples_are_validated(): void
    {
        $driver = $this->makeApprovedDriver();

        $this->actingAs($driver)->postJson(route('geo.driver.location'), ['samples' => [['lat' => 120, 'lng' => 28, 'accuracy' => 5, 'recorded_at' => now()->getTimestampMs()]]])
            ->assertUnprocessable()->assertJsonValidationErrors('samples.0.lat');
        $this->travel(3)->seconds();
        $this->actingAs($driver)->postJson(route('geo.driver.location'), ['samples' => array_fill(0, 21, $this->sample($this->sandton))])
            ->assertUnprocessable();
        $this->travel(3)->seconds();
        $this->actingAs($driver)->postJson(route('geo.driver.location'), ['samples' => [['lat' => -26, 'lng' => 28, 'recorded_at' => now()->getTimestampMs()]]])
            ->assertUnprocessable()->assertJsonValidationErrors('samples.0.accuracy');
    }

    public function test_trip_points_are_recorded_only_while_the_ride_is_in_progress(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['driver_id' => $driver->id, 'status' => 'en_route']);

        $this->postSamples($driver, $this->sample($this->sandton))->assertOk()->assertJsonPath('on_trip', false);
        $this->assertSame(0, TripPoint::count(), 'the approach to the pickup is not part of the trail');

        $ride->update(['status' => 'in_progress']);
        $this->travel(4)->seconds();
        $this->postSamples($driver, $this->sample($this->offset($this->sandton, 40)))->assertOk()->assertJsonPath('on_trip', true);

        $ride->update(['status' => 'completed']);
        $this->travel(4)->seconds();
        $this->postSamples($driver, $this->sample($this->offset($this->sandton, 80)))->assertOk();

        $this->assertSame([$ride->id], TripPoint::pluck('ride_id')->all());
    }

    public function test_a_queue_from_a_dead_zone_backfills_the_trail_but_only_the_fresh_fix_moves_the_marker(): void
    {
        $driver = $this->makeApprovedDriver();
        $this->makeRide(['driver_id' => $driver->id, 'status' => 'in_progress']);

        $queued = [];
        foreach ([300, 240, 180, 120, 50, 0] as $i => $ago) {
            $queued[] = $this->sample($this->offset($this->sandton, $i * 500), secondsAgo: $ago);
        }

        $response = $this->postSamples($driver, ...$queued)->assertOk()->assertJsonPath('accepted', 6);

        $this->assertSame(6, TripPoint::count());
        $this->assertSame([false, false, false, false, true, true], array_column($response->json('outcomes'), 'latest_updated'));
        $this->assertEqualsWithDelta($this->offset($this->sandton, 2500)->lng, DriverLocation::sole()->lng, 1e-7);
    }

    public function test_trip_points_are_sampled_not_every_fix(): void
    {
        config(['ehail-geo.locations.trip_point_min_interval_seconds' => 10, 'ehail-geo.locations.trip_point_min_distance_m' => 100]);
        $driver = $this->makeApprovedDriver();
        $this->makeRide(['driver_id' => $driver->id, 'status' => 'in_progress']);

        $this->postSamples($driver,
            $this->sample($this->sandton, secondsAgo: 8),
            $this->sample($this->offset($this->sandton, 20), secondsAgo: 6),
            $this->sample($this->offset($this->sandton, 40), secondsAgo: 4),
            $this->sample($this->offset($this->sandton, 150), secondsAgo: 2),
        )->assertJsonPath('accepted', 4);

        $this->assertSame(2, TripPoint::count());
    }
}
