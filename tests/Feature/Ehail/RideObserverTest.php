<?php

namespace Tests\Feature\Ehail;

use App\Models\Ride;
use App\Models\User;
use App\Notifications\RideCompletedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
