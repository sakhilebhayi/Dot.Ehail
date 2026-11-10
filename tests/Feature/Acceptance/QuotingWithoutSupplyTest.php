<?php

namespace Tests\Feature\Acceptance;

use App\Models\Ride;
use App\Models\RideRating;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AcceptanceWorld;
use Tests\TestCase;

/**
 * Pricing never depends on who is online right now (D-1902), through the real HTTP API: with no driver online the
 * calculator and the booking sheet still price, a scheduled ride can be booked, and only a request for now is
 * refused. Also the passenger's view of the driver's rating once a driver is on the ride (D-1907).
 */
class QuotingWithoutSupplyTest extends TestCase
{
    use AcceptanceWorld, RefreshDatabase;

    private function passenger(): User
    {
        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Zanele Mthembu']);
        $this->savedCard($passenger);

        return $passenger;
    }

    /** @return array<string, float> */
    private function trip(): array
    {
        return ['pickup_lat' => self::PICKUP[0], 'pickup_lng' => self::PICKUP[1], 'dropoff_lat' => self::DESTINATION[0], 'dropoff_lng' => self::DESTINATION[1]];
    }

    public function test_with_nobody_online_the_calculator_and_the_booking_sheet_still_price(): void
    {
        // The public calculator: no sign-in, no drivers.
        $estimate = $this->postJson(route('pricing.estimate'), $this->trip() + ['category' => 'standard'])->assertOk()
            ->assertJsonPath('available', true)->json();
        $this->assertGreaterThan(0, $estimate['fare']['total']['amount_minor']);

        // The booking sheet: every category priced, each saying nobody is nearby and that it cannot be requested now.
        $passenger = $this->passenger();
        $compare = $this->actingAs($passenger)->postJson(route('pricing.quotes.store'), $this->trip())->assertOk()->assertJsonPath('available', true)->json();
        $this->assertNotEmpty($compare['options']);

        foreach ($compare['options'] as $option) {
            $this->assertGreaterThan(0, $option['fare']['total']['amount_minor'], $option['category']['key']);
            $this->assertSame('none', $option['supply']);
            $this->assertSame(0, $option['drivers_nearby']);
            $this->assertFalse($option['requestable_now']);
            $this->assertStringEndsWith('A driver is not guaranteed.', $option['supply_note']);
        }

        // The calculator and the booking sheet price the same trip identically.
        $quote = $this->quoteFor($passenger);
        $this->assertSame($estimate['fare']['components'], $quote['fare']['components']);
        $this->assertSame($estimate['fare']['total']['amount_minor'], $quote['fare']['total']['amount_minor']);
    }

    public function test_a_scheduled_ride_books_with_nobody_online_and_a_request_for_now_is_refused(): void
    {
        $passenger = $this->passenger();
        $quote = $this->quoteFor($passenger);

        // For now: refused honestly, nothing created, nothing charged.
        $this->requestRide($passenger, $quote['quote_id'])->assertStatus(409)->assertJsonPath('error', 'no_supply');
        $this->assertSame(0, Ride::count());

        // For tomorrow morning: booked at the quoted price. Who is online now says nothing about then.
        $later = $this->quoteFor($passenger);
        $response = $this->requestRide($passenger, $later['quote_id'], ['scheduled_for' => now()->addDay()->toIso8601String()])->assertCreated()
            ->assertJsonPath('ride.status', 'scheduled');
        $ride = Ride::findOrFail($response->json('ride.id'));

        $this->assertSame($later['fare']['total']['amount_minor'], $ride->quoted_total_minor);
        $this->assertNotNull($ride->scheduled_for);
    }

    public function test_the_passenger_sees_the_drivers_rating_as_a_band_from_counted_ratings(): void
    {
        $driver = $this->verifiedDriver('Thabo Nkosi');
        $this->goOnlineAt($driver);
        $passenger = $this->passenger();
        $quote = $this->quoteFor($passenger);
        $rideId = $this->requestRide($passenger, $quote['quote_id'])->assertCreated()->json('ride.id');
        $this->acceptAndArrive($driver, $passenger, $rideId);

        // No ratings yet: new, and the count says so.
        $this->assertSame(['band' => 'new', 'label' => 'New on Dot.Ehail', 'count' => 0], $this->passengerFeed($passenger, $rideId)['ride']['driver']['rating']);

        // Five counted five-star ratings from five earlier trips: no longer "new".
        for ($i = 0; $i < 5; $i++) {
            $rater = User::factory()->create();
            $earlier = Ride::create(['passenger_id' => $rater->id, 'driver_id' => $driver->id, 'pickup_address' => 'A', 'dropoff_address' => 'B', 'status' => 'completed', 'vehicle_type' => 'standard', 'requested_at' => now()->subDays($i + 1), 'completed_at' => now()->subDays($i + 1)]);
            RideRating::create(['ride_id' => $earlier->id, 'rated_by' => $rater->id, 'rated_user_id' => $driver->id, 'rated_role' => 'driver', 'rating' => 5]);
        }

        $this->travel(5)->seconds();
        $rating = $this->passengerFeed($passenger, $rideId)['ride']['driver']['rating'];
        $this->assertSame(['band' => 'excellent', 'label' => 'Excellent', 'count' => 5], $rating);
        $this->assertArrayNotHasKey('average', $rating, 'the passenger never sees a decimal average');
    }
}
