<?php

namespace Tests\Feature\Ehail;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class RideFareTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    private array $addressOnly = [
        'pickup_address' => '1 Adderley Street, Cape Town',
        'dropoff_address' => '99 Main Road, Sea Point',
        'vehicle_type' => 'standard',
    ];

    public function test_a_request_with_addresses_only_is_accepted_and_has_no_fabricated_estimate(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($passenger)->post(route('rides.store'), $this->addressOnly)->assertRedirect();

        $ride = Ride::firstOrFail();
        $this->assertNull($ride->estimated_fare);
        $this->assertNull($ride->distance_km);
    }

    public function test_the_ride_page_says_the_estimate_is_not_available_when_there_are_no_coordinates(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $this->actingAs($passenger)->post(route('rides.store'), $this->addressOnly);
        $ride = Ride::firstOrFail();

        $this->actingAs($passenger)->get(route('rides.show', $ride))
            ->assertOk()
            ->assertSee('Fare estimate not available')
            ->assertSee('coordinates');
    }

    public function test_coordinates_produce_an_estimate_from_the_configured_tariff(): void
    {
        config(['ehail.tariff.base_fare' => 10.0, 'ehail.tariff.per_km' => 2.0]);
        config(['ehail.tariff.multipliers.standard' => 1.0]);
        $passenger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($passenger)->post(route('rides.store'), $this->addressOnly + [
            'pickup_lat' => -33.9249, 'pickup_lng' => 18.4241,
            'dropoff_lat' => -33.9249, 'dropoff_lng' => 18.4241,
        ])->assertRedirect();

        $this->assertSame('10.00', Ride::firstOrFail()->estimated_fare);
    }

    public function test_a_latitude_without_its_longitude_is_rejected(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($passenger)->post(route('rides.store'), $this->addressOnly + ['pickup_lat' => -33.9])
            ->assertSessionHasErrors('pickup_lng');
        $this->actingAs($passenger)->post(route('rides.store'), $this->addressOnly + ['dropoff_lng' => 18.4])
            ->assertSessionHasErrors('dropoff_lat');

        $this->assertSame(0, Ride::count());
    }

    public function test_only_one_end_having_coordinates_still_gives_no_estimate(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($passenger)->post(route('rides.store'), $this->addressOnly + ['pickup_lat' => -33.9, 'pickup_lng' => 18.4])
            ->assertSessionDoesntHaveErrors();

        $this->assertNull(Ride::firstOrFail()->estimated_fare);
    }

    public function test_a_driver_cannot_complete_a_ride_without_an_estimate_and_is_pointed_at_the_operator(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id, 'estimated_fare' => null]);

        $this->actingAs($driver)
            ->patch(route('rides.advance', $ride), ['status' => 'completed'])
            ->assertSessionHasErrors(['status' => 'No fare estimate exists for this ride, so it cannot be completed here. A platform operator has to enter the final fare.']);

        $this->assertSame('in_progress', $ride->fresh()->status);
    }

    public function test_the_request_form_explains_what_coordinates_do(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($passenger)->get(route('rides.create'))
            ->assertOk()
            ->assertSee('Without coordinates the fare estimate is not available');
    }
}
