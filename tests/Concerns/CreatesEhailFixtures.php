<?php

namespace Tests\Concerns;

use App\Models\DriverProfile;
use App\Models\Ride;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * Shared builders for the ride-operations tests. Everything is created
 * through the real models/factories -- no stubs of the code under test.
 */
trait CreatesEhailFixtures
{
    protected function makeApprovedDriver(string $vehicleType = 'standard', bool $online = true): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $profile = DriverProfile::factory()->for($user)->create(['status' => 'approved', 'is_online' => $online]);
        Vehicle::factory()->for($profile, 'driverProfile')->create(['is_active' => true, 'type' => $vehicleType]);

        return $user;
    }

    protected function makeOperator(): User
    {
        $operator = User::factory()->withPersonalTeam()->create();
        $operator->forceFill(['is_platform_operator' => true])->save();

        return $operator;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeRide(array $attributes = [], ?User $passenger = null): Ride
    {
        $passenger ??= User::factory()->withPersonalTeam()->create();

        return Ride::create(array_merge([
            'passenger_id' => $passenger->id,
            'pickup_address' => '10 Kloof Street',
            'dropoff_address' => '20 Long Street',
            'status' => 'requested',
            'vehicle_type' => 'standard',
            'estimated_fare' => 80,
            'requested_at' => now(),
        ], $attributes));
    }

    /**
     * Notifications of one kind, filtered in PHP: notifications.data is a
     * text column, so a JSON ->> query is not portable to Postgres.
     */
    protected function notificationsOfType(User $user, string $type): Collection
    {
        return $user->fresh()->notifications->filter(fn ($n) => ($n->data['type'] ?? null) === $type)->values();
    }
}
