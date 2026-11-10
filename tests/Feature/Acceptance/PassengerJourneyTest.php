<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Geo\Models\RideRoute;
use App\Domain\Geo\Models\TripPoint;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\Receipt;
use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Models\RideFare;
use App\Models\Ride;
use App\Models\RideRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AcceptanceWorld;
use Tests\TestCase;

/**
 * (P) The passenger journey end to end through the real HTTP API (R46_INTEGRATION.md section 5).
 */
class PassengerJourneyTest extends TestCase
{
    use AcceptanceWorld, RefreshDatabase;

    public function test_a_passenger_books_rides_pays_once_and_rates(): void
    {
        // A verified driver is online one kilometre south of the pickup.
        $driver = $this->verifiedDriver('Lindiwe Dube');
        $this->goOnlineAt($driver);

        // Register and add a card (Paystack card-only checkout, R1 check refunded).
        $passenger = $this->registerUser('Thandi Mokoena', 'thandi@example.test');
        $init = $this->actingAs($passenger)->postJson(route('money.cards.start'))->assertCreated()->json();
        $this->assertStringStartsWith('https://checkout.paystack.com/', $init['authorization_url']);
        $reference = $this->paystackRequests('/transaction/initialize')->last()->data()['reference'];
        $this->actingAs($passenger)->get(route('money.cards.callback', ['reference' => $reference]))->assertRedirect();
        $card = PaymentMethod::where('user_id', $passenger->id)->sole();

        // Set pickup and destination by searching (geocoder), one search per second (Nominatim policy).
        $pickup = $this->actingAs($passenger)->getJson(route('geo.search', ['q' => 'Kloof Street']))->assertOk()->json('results.0');
        $this->travel(2)->seconds();
        $destination = $this->actingAs($passenger)->getJson(route('geo.search', ['q' => 'Bree Street']))->assertOk()->json('results.0');
        $this->assertEqualsWithDelta(self::PICKUP[0], $pickup['lat'], 0.00001);

        // Compare categories: each priced on the same road route, every breakdown adds up to its total.
        $compare = $this->actingAs($passenger)->postJson(route('pricing.quotes.store'), [
            'pickup_lat' => $pickup['lat'], 'pickup_lng' => $pickup['lng'], 'dropoff_lat' => $destination['lat'], 'dropoff_lng' => $destination['lng'],
        ])->assertOk()->assertJsonPath('available', true)->json();
        $this->assertSame('osrm', $compare['route']['provider']);
        $this->assertEqualsWithDelta(5000, $compare['route']['distance_m'], 60);
        $options = collect($compare['options'])->keyBy('category.key');
        $this->assertTrue($options->has('standard'));
        $this->assertTrue($options->has('economy'));
        $this->assertSame('available', $options['standard']['supply']);

        foreach ($options as $option) {
            $sum = array_sum(array_column($option['fare']['components'], 'amount_minor'));
            $this->assertSame($option['fare']['total']['amount_minor'], $sum, 'components sum to the total for '.$option['category']['key']);
            // Every category is priced (D-1902); the ones with a nearby driver say so, the rest say none.
            $this->assertContains($option['supply'], ['available', 'none']);
            $this->assertSame($option['supply'] !== 'none', $option['requestable_now']);
        }

        // Quote one category and request it with an idempotency key, paying by the saved card.
        $quote = $this->quoteFor($passenger, 'standard', [$pickup['lat'], $pickup['lng']], [$destination['lat'], $destination['lng']]);
        $first = $this->requestRide($passenger, $quote['quote_id'], ['payment_method' => 'card', 'payment_ref' => (string) $card->id], 'tap-thandi-0001')
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'false')->assertJsonPath('ride.status', 'searching');
        $rideId = $first->json('ride.id');
        $this->requestRide($passenger, $quote['quote_id'], ['payment_method' => 'card', 'payment_ref' => (string) $card->id], 'tap-thandi-0001')
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('ride.id', $rideId);
        $this->assertSame(1, Ride::where('passenger_id', $passenger->id)->count());

        $ride = Ride::findOrFail($rideId);
        $this->assertSame($quote['fare']['total']['amount_minor'], $ride->quoted_total_minor, 'the ride carries the quoted total');
        $this->assertNotNull(FareQuote::findOrFail($quote['quote_id'])->consumed_at, 'the quote was bound');
        $this->assertNotNull(RideRoute::where('ride_id', $rideId)->value('planned_polyline'), 'the quoted road route is attached');
        $this->assertSame('card', $ride->payment_method_kind);

        // Dispatch offered the ride to the verified online driver; they accept.
        $offer = $this->driverFeed($driver)['offer'];
        $this->assertSame($rideId, $offer['ride_id']);
        $this->assertEqualsWithDelta(1000, $offer['pickup_distance_m'], 50);
        $this->assertSame('road', $offer['eta_method']);
        $this->assertNotNull($offer['earnings_estimate_minor']);
        $this->actingAs($driver)->postJson(route('ehail.dispatch.offers.accept', $offer['id']))->assertOk();

        // The passenger sees the driver, their position and a road ETA; the PIN shows once assigned.
        $feed = $this->passengerFeed($passenger, $rideId);
        $this->assertSame('driver_assigned', $feed['ride']['status']);
        $this->assertSame('Lindiwe', $feed['ride']['driver']['first_name']);
        $this->assertEqualsWithDelta(self::DRIVER_START[0], $feed['driver_position']['lat'], 0.00001);
        $this->assertSame('road', $feed['driver_position']['eta_method']);
        $this->assertGreaterThan(60, $feed['driver_position']['eta_s']);
        $pin = (string) $feed['ride']['pin'];
        $this->assertMatchesRegularExpression('/^\d{4}$/', $pin);

        $this->stage($driver, $rideId, 'arriving')->assertOk();
        $this->drive($driver, self::DRIVER_START, self::PICKUP);
        $moving = $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', $rideId), ['If-None-Match' => '"v'.$feed['version'].'"'])->assertOk()->json();
        $this->assertEqualsWithDelta(self::PICKUP[0], $moving['driver_position']['lat'], 0.0002, 'the feed delta carries the new position');
        $this->stage($driver, $rideId, 'arrived')->assertOk();
        $this->assertSame('driver_arrived', $this->passengerFeed($passenger, $rideId)['ride']['status']);

        // A wrong PIN is refused; the passenger's PIN starts the trip.
        $wrong = $pin === '0000' ? '1111' : '0000';
        $this->stage($driver, $rideId, 'start', $wrong)->assertStatus(422)->assertJsonPath('error', 'pin_wrong');
        $this->stage($driver, $rideId, 'start', $pin)->assertOk()->assertJsonPath('ride_status', 'in_progress');

        // The trip: the phone posts fixes, geo keeps the trail.
        $this->drive($driver, self::PICKUP, self::DESTINATION);
        $this->assertGreaterThan(30, TripPoint::where('ride_id', $rideId)->count());
        $this->stage($driver, $rideId, 'complete')->assertOk()->assertJsonPath('ride_status', 'completed');

        // The final fare is reconciled: within tolerance the quoted fare stands.
        $fare = $this->actingAs($passenger)->getJson(route('pricing.rides.fare', $rideId))->assertOk()->json();
        $this->assertSame('quoted_fare_stands', $fare['final']['basis']);
        $this->assertSame($quote['fare']['total']['amount_minor'], $fare['final']['final']['amount_minor']);
        $this->assertSame($quote['fare']['total']['amount_minor'], Ride::find($rideId)->final_fare_minor);
        $this->assertEqualsWithDelta(5000, RideFare::where('ride_id', $rideId)->value('actual_distance_m'), 250, 'billable distance from the GPS trail');

        // Charged once to the saved card; a duplicated webhook changes nothing.
        $payment = Payment::where('ride_id', $rideId)->where('purpose', Payment::PURPOSE_RIDE_FARE)->sole();
        $this->assertSame(Payment::PAID, $payment->status);
        $this->assertSame($quote['fare']['total']['amount_minor'], $payment->amountMinor());
        $this->assertCount(1, $this->paystackRequests('/transaction/charge_authorization'));
        $webhook = ['event' => 'charge.success', 'data' => ['id' => 5001, 'status' => 'success', 'reference' => $payment->reference, 'amount' => $payment->amountMinor(), 'currency' => 'ZAR']];
        $this->postWebhook($webhook)->assertOk();
        $this->postWebhook($webhook)->assertOk();
        $this->assertSame(1, Payment::where('ride_id', $rideId)->where('purpose', Payment::PURPOSE_RIDE_FARE)->count());
        $this->assertSame('paid', Ride::find($rideId)->payment_status);
        $this->assertTrue($this->ledgerBalances());

        // Receipt and rating.
        $receipt = Receipt::where('ride_id', $rideId)->sole();
        $this->actingAs($passenger)->getJson(route('money.receipts.show', $receipt))->assertOk()
            ->assertJsonPath('total_minor', $quote['fare']['total']['amount_minor']);
        $this->actingAs($passenger)->postJson(route('safety.rides.rating', $rideId), ['rating' => 5, 'comment' => 'Safe, friendly driver'])->assertSuccessful();
        $this->assertSame(5, (int) RideRating::where('ride_id', $rideId)->where('rated_user_id', $driver->id)->value('rating'));
    }
}
