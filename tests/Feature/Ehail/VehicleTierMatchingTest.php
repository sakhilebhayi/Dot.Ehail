<?php

namespace Tests\Feature\Ehail;

use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class VehicleTierMatchingTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    public function test_a_premium_ride_is_refused_by_a_standard_vehicle(): void
    {
        $driver = $this->makeApprovedDriver('standard');
        $ride = $this->makeRide(['vehicle_type' => 'premium']);

        $this->actingAs($driver)->post(route('rides.accept', $ride))->assertForbidden();

        $this->assertSame('requested', $ride->fresh()->status);
        $this->assertNull($ride->fresh()->driver_id);
    }

    public function test_a_premium_ride_is_accepted_by_a_premium_vehicle_and_records_that_vehicle(): void
    {
        $driver = $this->makeApprovedDriver('premium');
        $ride = $this->makeRide(['vehicle_type' => 'premium']);

        $this->actingAs($driver)->post(route('rides.accept', $ride))->assertRedirect(route('rides.show', $ride));

        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->firstOrFail();
        $this->assertSame($profile->activeVehicle()->id, $ride->fresh()->vehicle_id);
    }

    public function test_each_tier_is_strict_by_default(): void
    {
        $tiers = ['economy', 'standard', 'premium', 'suv'];

        foreach ($tiers as $vehicleType) {
            $driver = $this->makeApprovedDriver($vehicleType);
            foreach ($tiers as $rideTier) {
                $ride = $this->makeRide(['vehicle_type' => $rideTier]);
                $response = $this->actingAs($driver)->post(route('rides.accept', $ride));

                $vehicleType === $rideTier
                    ? $response->assertRedirect(route('rides.show', $ride))
                    : $response->assertForbidden();
            }
        }
    }

    public function test_the_mapping_comes_from_config(): void
    {
        config(['ehail.vehicle_tiers.standard' => ['standard', 'premium']]);
        $premiumDriver = $this->makeApprovedDriver('premium');
        $standardRide = $this->makeRide(['vehicle_type' => 'standard']);

        $this->actingAs($premiumDriver)->post(route('rides.accept', $standardRide))->assertRedirect(route('rides.show', $standardRide));
    }

    public function test_an_inactive_vehicle_of_the_right_type_does_not_count(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $profile = DriverProfile::factory()->for($user)->create(['status' => 'approved', 'is_online' => true]);
        Vehicle::factory()->for($profile, 'driverProfile')->create(['type' => 'premium', 'is_active' => false]);
        Vehicle::factory()->for($profile, 'driverProfile')->create(['type' => 'standard', 'is_active' => true]);
        $ride = $this->makeRide(['vehicle_type' => 'premium']);

        $this->actingAs($user)->post(route('rides.accept', $ride))->assertForbidden();
    }

    public function test_the_claim_queue_only_lists_rides_the_active_vehicle_can_serve(): void
    {
        $driver = $this->makeApprovedDriver('premium');
        $this->makeRide(['vehicle_type' => 'premium', 'pickup_address' => 'Premium pickup at the Waterfront hotel']);
        $this->makeRide(['vehicle_type' => 'economy', 'pickup_address' => 'Economy pickup at the Bellville taxi rank']);

        $this->actingAs($driver)->get(route('rides.available'))
            ->assertOk()
            ->assertSee('Premium pickup at the Waterfront hotel')
            ->assertDontSee('Economy pickup at the Bellville taxi rank');
    }

    public function test_the_queue_says_why_nothing_is_listed_for_a_mismatched_vehicle(): void
    {
        $driver = $this->makeApprovedDriver('economy');
        $this->makeRide(['vehicle_type' => 'suv']);

        $this->actingAs($driver)->get(route('rides.available'))
            ->assertOk()
            ->assertSee('Showing requests your active vehicle can serve');
    }
}
