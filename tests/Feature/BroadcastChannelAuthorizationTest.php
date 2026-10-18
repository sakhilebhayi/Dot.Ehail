<?php

namespace Tests\Feature;

use App\Models\Ride;
use App\Models\User;
use App\Providers\BroadcastServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * App\Providers\BroadcastServiceProvider authorizes the ride.{rideId}
 * private channel -- deliberately scoped to just the ride's own
 * passenger/driver, not RidePolicy::view()'s broader (any authenticated
 * user) rule, since that rule is a documented, intentional gap for the
 * platform-wide ops list, not something a live per-ride channel should
 * inherit. This exercises the real /broadcasting/auth endpoint, matching
 * the pattern established in Dot.Mines (see docs/DOT_REALTIME_STANDARD.md
 * there).
 */
class BroadcastChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml forces BROADCAST_CONNECTION=null in the test env so
        // other tests never attempt a real network call -- but the null
        // driver's auth() is a no-op that authorizes everything
        // unconditionally. Switch to a real Pusher-protocol driver for
        // this test class, and re-run channel registration afterward:
        // Broadcast::channel() registers against whichever driver instance
        // is current at call time, and the provider already booted against
        // the null driver during app bootstrap, before this setUp() runs.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
        ]);
        (new BroadcastServiceProvider($this->app))->boot();
    }

    private function ride(User $passenger, ?User $driver = null): Ride
    {
        return Ride::create([
            'passenger_id' => $passenger->id,
            'driver_id' => $driver?->id,
            'pickup_address' => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'status' => 'requested',
        ]);
    }

    private function authRequest(User $user, string $channelName): TestResponse
    {
        return $this->actingAs($user)->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channelName,
        ]);
    }

    public function test_passenger_can_authorize_their_ride_channel(): void
    {
        $passenger = User::factory()->create();
        $driver = User::factory()->create();
        $ride = $this->ride($passenger, $driver);

        $this->authRequest($passenger, "private-ride.{$ride->id}")
            ->assertOk();
    }

    public function test_driver_can_authorize_their_ride_channel(): void
    {
        $passenger = User::factory()->create();
        $driver = User::factory()->create();
        $ride = $this->ride($passenger, $driver);

        $this->authRequest($driver, "private-ride.{$ride->id}")
            ->assertOk();
    }

    /**
     * The whole point of not reusing RidePolicy::view() -- an unrelated,
     * fully authenticated user must not be able to watch someone else's
     * ride live, even though they could see it on the platform-wide
     * /rides ops list.
     */
    public function test_unrelated_authenticated_user_cannot_authorize_someone_elses_ride_channel(): void
    {
        $passenger = User::factory()->create();
        $driver = User::factory()->create();
        $ride = $this->ride($passenger, $driver);

        $outsider = User::factory()->create();

        $this->authRequest($outsider, "private-ride.{$ride->id}")
            ->assertForbidden();
    }

    public function test_authorization_fails_for_a_nonexistent_ride(): void
    {
        $user = User::factory()->create();

        $this->authRequest($user, 'private-ride.999999')
            ->assertForbidden();
    }

    public function test_non_numeric_ride_identifier_fails_closed_rather_than_erroring(): void
    {
        $user = User::factory()->create();

        $this->authRequest($user, 'private-ride.not-a-number')
            ->assertForbidden();
    }

    public function test_unauthenticated_user_cannot_authorize_any_ride_channel(): void
    {
        $passenger = User::factory()->create();
        $ride = $this->ride($passenger);

        $this->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-ride.{$ride->id}",
        ])->assertForbidden();
    }
}
