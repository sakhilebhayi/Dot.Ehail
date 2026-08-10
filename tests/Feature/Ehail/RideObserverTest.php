<?php

namespace Tests\Feature\Ehail;

use App\Events\RideStatusUpdated;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\RideCompletedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RideObserverTest extends TestCase
{
    use RefreshDatabase;

    public function test_transitioning_a_ride_to_completed_notifies_passenger_and_driver(): void
    {
        Notification::fake();

        $passenger = User::factory()->create();
        $driver = User::factory()->withPersonalTeam()->create();

        $ride = Ride::create([
            'passenger_id' => $passenger->id,
            'driver_id' => $driver->id,
            'pickup_address' => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'status' => 'accepted',
        ]);

        Notification::assertNothingSent();

        $ride->update([
            'status' => 'completed',
            'final_fare' => 64.00,
            'completed_at' => now(),
        ]);

        Notification::assertSentTo($passenger, RideCompletedNotification::class);
        Notification::assertSentTo($driver, RideCompletedNotification::class);
    }

    public function test_transitioning_a_ride_to_a_non_completed_status_does_not_notify(): void
    {
        Notification::fake();

        $passenger = User::factory()->create();
        $driver = User::factory()->withPersonalTeam()->create();

        $ride = Ride::create([
            'passenger_id' => $passenger->id,
            'driver_id' => $driver->id,
            'pickup_address' => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'status' => 'requested',
        ]);

        $ride->update(['status' => 'accepted']);

        Notification::assertNothingSent();
    }

    public function test_updating_an_already_completed_ride_without_changing_status_does_not_renotify(): void
    {
        $passenger = User::factory()->create();
        $driver = User::factory()->withPersonalTeam()->create();

        $ride = Ride::create([
            'passenger_id' => $passenger->id,
            'driver_id' => $driver->id,
            'pickup_address' => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'status' => 'accepted',
        ]);

        $ride->update(['status' => 'completed', 'final_fare' => 64.00]);

        $this->assertDatabaseCount('notifications', 2);

        // A follow-up update that doesn't touch `status` (e.g. a rating being
        // attached elsewhere) must not fire another notification.
        $ride->update(['final_fare' => 70.00]);

        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_ride_with_no_driver_yet_only_notifies_the_passenger(): void
    {
        Notification::fake();

        $passenger = User::factory()->create();

        $ride = Ride::create([
            'passenger_id' => $passenger->id,
            'pickup_address' => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'status' => 'requested',
        ]);

        $ride->update(['status' => 'completed', 'final_fare' => 64.00]);

        Notification::assertSentTo($passenger, RideCompletedNotification::class);
        Notification::assertCount(1);
    }

    // -----------------------------------------------------------------------
    // Real-time broadcasting -- separate from the completed-only in-app
    // notification above. RideStatusUpdated fires on ANY status transition.
    // -----------------------------------------------------------------------

    public function test_transitioning_to_a_non_completed_status_still_broadcasts_ride_status_updated(): void
    {
        Event::fake([RideStatusUpdated::class]);

        $passenger = User::factory()->create();
        $driver = User::factory()->create();

        $ride = Ride::create([
            'passenger_id' => $passenger->id,
            'driver_id' => $driver->id,
            'pickup_address' => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'status' => 'requested',
        ]);

        $ride->update(['status' => 'accepted']);

        Event::assertDispatched(RideStatusUpdated::class, fn ($event) => $event->ride->is($ride));
    }

    public function test_transitioning_to_completed_broadcasts_and_notifies(): void
    {
        Event::fake([RideStatusUpdated::class]);
        Notification::fake();

        $passenger = User::factory()->create();
        $driver = User::factory()->create();

        $ride = Ride::create([
            'passenger_id' => $passenger->id,
            'driver_id' => $driver->id,
            'pickup_address' => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'status' => 'accepted',
        ]);

        $ride->update(['status' => 'completed', 'final_fare' => 64.00]);

        Event::assertDispatched(RideStatusUpdated::class, fn ($event) => $event->ride->is($ride));
        Notification::assertSentTo($passenger, RideCompletedNotification::class);
    }

    public function test_updating_a_ride_without_changing_status_does_not_broadcast(): void
    {
        Event::fake([RideStatusUpdated::class]);

        $passenger = User::factory()->create();

        $ride = Ride::create([
            'passenger_id' => $passenger->id,
            'pickup_address' => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'status' => 'accepted',
        ]);

        $ride->update(['estimated_fare' => 55.00]);

        Event::assertNotDispatched(RideStatusUpdated::class);
    }
}
