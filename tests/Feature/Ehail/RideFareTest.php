<?php

namespace Tests\Feature\Ehail;

use App\Domain\Pricing\Models\RideFare;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\Concerns\PricingFixtures;
use Tests\TestCase;

class RideFareTest extends TestCase
{
    use CreatesEhailFixtures, PricingFixtures, RefreshDatabase;

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

    /**
     * Updated for R-46: the estimate now comes from the pricing engine on a road route under a published rule set
     * (the old config tariff and straight-line distance were removed, decision D-4), and the ride gets a fare snapshot.
     */
    public function test_coordinates_produce_an_estimate_from_the_published_tariff_on_a_road_route(): void
    {
        $this->publishedRuleSet(['base_minor' => 1000, 'per_km_minor' => 200, 'per_minute_minor' => 0, 'minimum_minor' => 0]);
        $this->fakeRoute(5_000, 600);
        $passenger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($passenger)->post(route('rides.store'), $this->addressOnly + [
            'pickup_lat' => -33.9249, 'pickup_lng' => 18.4241,
            'dropoff_lat' => -33.9150, 'dropoff_lng' => 18.3870,
        ])->assertRedirect();

        $ride = Ride::firstOrFail();
        $this->assertSame('20.00', $ride->estimated_fare);
        $this->assertEquals(5.0, (float) $ride->distance_km, 'road distance from the route, in km');
        $this->assertSame(2000, RideFare::where('ride_id', $ride->id)->value('estimated_total_minor'));
    }

    public function test_coordinates_without_a_routing_provider_give_no_estimate_rather_than_a_straight_line_one(): void
    {
        $this->publishedRuleSet();
        $passenger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($passenger)->post(route('rides.store'), $this->addressOnly + [
            'pickup_lat' => -33.9249, 'pickup_lng' => 18.4241,
            'dropoff_lat' => -33.9150, 'dropoff_lng' => 18.3870,
        ])->assertRedirect();

        $this->assertNull(Ride::firstOrFail()->estimated_fare);
        $this->assertSame(0, RideFare::count());
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
