<?php

namespace Tests\Feature\Passenger;

use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\Receipt;
use App\Domain\Pricing\Models\RideFare;
use App\Domain\Safety\Models\SafetyIncident;
use App\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AcceptanceWorld;
use Tests\TestCase;

/**
 * The passenger app's pages through a whole ride against the integrated backend (every domain real, only the
 * provider edges faked by AcceptanceWorld): booking, live ride in each state, receipt, rating, safety.
 */
class PassengerPagesJourneyTest extends TestCase
{
    use AcceptanceWorld, RefreshDatabase;

    public function test_the_passenger_pages_follow_a_ride_from_booking_to_receipt(): void
    {
        $driver = $this->verifiedDriver('Lindiwe Dube');
        $this->goOnlineAt($driver);
        $passenger = $this->registerUser('Thandi Mokoena', 'thandi@example.test');

        // Booking page: map-first, geolocation allowed for this page only, tiles host in the passenger CSP.
        $booking = $this->actingAs($passenger)->get(route('ride.home'))->assertOk()
            ->assertSee('Where to?')->assertSee('data-geo-config', false)->assertSee('Use my location');
        $this->assertSame('camera=(), microphone=(), geolocation=(self), payment=(), usb=()', $booking->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('img-src \'self\' data: blob: https://tile.openstreetmap.org', $booking->headers->get('Content-Security-Policy'));

        // Card saved through the money flow returns to the passenger account page.
        $this->actingAs($passenger)->postJson(route('money.cards.start'))->assertCreated();
        $reference = $this->paystackRequests('/transaction/initialize')->last()->data()['reference'];
        $this->actingAs($passenger)->get(route('money.cards.callback', ['reference' => $reference]))->assertRedirect('/ride/account');
        $card = PaymentMethod::where('user_id', $passenger->id)->sole();

        // Pickup ETAs per category come from dispatch's own locator: a verified Comfort car 1 km away.
        $etas = $this->actingAs($passenger)->postJson(route('ride.api.pickup-etas'), ['lat' => self::PICKUP[0], 'lng' => self::PICKUP[1]])->assertOk()->json('categories');
        $this->assertSame('eta', $etas['standard']['state']);
        $this->assertGreaterThan(60, $etas['standard']['eta_s']);
        $this->assertSame('none', $etas['xl']['state'], 'no XL car is online');

        $quote = $this->quoteFor($passenger, 'standard');
        $rideId = $this->requestRide($passenger, $quote['quote_id'], ['payment_method' => 'card', 'payment_ref' => (string) $card->id], 'pages-0001')->assertCreated()->json('ride.id');

        // With a ride going, /ride opens that ride.
        $this->actingAs($passenger)->get(route('ride.home'))->assertRedirect(route('ride.trip', $rideId));

        // Searching.
        $this->actingAs($passenger)->get(route('ride.trip', $rideId))->assertOk()->assertSee('Finding a driver')->assertSee('data-pax-sos-open', false);
        $overview = $this->actingAs($passenger)->getJson(route('ride.api.rides.overview', $rideId))->assertOk()->json();
        $this->assertSame(0, $overview['cancellation_now']['fee_minor'], 'free to cancel while searching');
        $this->assertSame(2, $overview['policy']['cancel_free_minutes']);

        // Assigned: driver, car, plate and PIN on the server-rendered page.
        $offer = $this->driverFeed($driver)['offer'];
        $this->actingAs($driver)->postJson(route('ehail.dispatch.offers.accept', $offer['id']))->assertOk();
        $pin = (string) $this->passengerFeed($passenger, $rideId)['ride']['pin'];
        $page = $this->actingAs($passenger)->get(route('ride.trip', $rideId))->assertOk()
            ->assertSee('Lindiwe is on the way')->assertSee('Toyota Corolla')->assertSee('Your trip PIN');
        foreach (str_split($pin) as $digit) {
            $page->assertSee('<span class="pax-pincode__digit" aria-hidden="true">'.$digit.'</span>', false);
        }

        // Looking at what cancelling would cost never settles the fare (the dispatch seam would).
        $this->actingAs($passenger)->getJson(route('ride.api.rides.overview', $rideId))->assertOk()->assertJsonPath('cancellation_now.fee_minor', 0);
        $this->assertNull(RideFare::where('ride_id', $rideId)->value('finalised_at'), 'a preview must not finalise the fare');

        // Arrived, then the trip.
        $this->stage($driver, $rideId, 'arriving')->assertOk();
        $this->drive($driver, self::DRIVER_START, self::PICKUP);
        $this->stage($driver, $rideId, 'arrived')->assertOk();
        $this->actingAs($passenger)->get(route('ride.trip', $rideId))->assertOk()->assertSee('Lindiwe is here')->assertSee('Driver waiting for');
        $this->stage($driver, $rideId, 'start', $pin)->assertOk();
        $this->actingAs($passenger)->get(route('ride.trip', $rideId))->assertOk()->assertSee('On the way to Bree Street')->assertSee('Change the destination')->assertDontSee('class="pax-pincode__digit"', false);
        $this->drive($driver, self::PICKUP, self::DESTINATION);
        $this->stage($driver, $rideId, 'complete')->assertOk();

        // Completed: final fare, payment, receipt, rating.
        $receipt = Receipt::where('ride_id', $rideId)->sole();
        $overview = $this->actingAs($passenger)->getJson(route('ride.api.rides.overview', $rideId))->assertOk()->json();
        $this->assertSame('paid', $overview['payment']['status']);
        $this->assertSame($receipt->number, $overview['receipt']['number']);
        $this->assertTrue($overview['can_rate']);
        $this->assertFalse($overview['rated']);
        $this->actingAs($passenger)->get(route('ride.trip', $rideId))->assertOk()->assertSee('You have arrived')->assertSee('Rate your trip with Lindiwe');
        $this->actingAs($passenger)->postJson(route('safety.rides.rating', $rideId), ['rating' => 5, 'comment' => 'Safe driving, Clean car.'])->assertSuccessful();
        $this->assertTrue($this->actingAs($passenger)->getJson(route('ride.api.rides.overview', $rideId))->json('rated'));

        // Trips list shows the trip with its receipt; /ride is the booking page again.
        $this->actingAs($passenger)->get(route('ride.trips'))->assertOk()->assertSee('Receipt '.$receipt->number);
        $this->actingAs($passenger)->get(route('ride.home'))->assertOk()->assertSee('Where to?');
        $this->actingAs($passenger)->get(route('ride.home', ['again' => $rideId]))->assertOk()->assertSee('"again":{"pickup"', false);
    }

    public function test_sos_leads_to_what_we_did_with_the_emergency_numbers(): void
    {
        $driver = $this->verifiedDriver('Lindiwe Dube');
        $this->goOnlineAt($driver);
        $passenger = $this->registerUser('Thandi Mokoena', 'thandi@example.test');
        $quote = $this->quoteFor($passenger, 'standard');
        $rideId = $this->requestRide($passenger, $quote['quote_id'])->assertCreated()->json('ride.id');

        $this->actingAs($passenger)->get(route('ride.safety'))->assertOk()->assertSee('In danger? Call now')->assertSee('tel:10111', false)
            ->assertSee('Your ride now')->assertSee('Trusted contacts');

        $sos = $this->actingAs($passenger)->postJson(route('safety.rides.sos', $rideId))->assertCreated()->json('what_we_did.incident.id');
        $this->actingAs($passenger)->get(route('ride.safety.incident', $sos))->assertOk()
            ->assertSee('SOS sent')->assertSee('What we did')->assertSee('Dot.Ehail did not call the police')
            ->assertSee('tel:10177', false)->assertSee('tel:112', false)->assertSee('I am safe now');

        $this->assertSame(1, SafetyIncident::count());
        $this->actingAs($passenger)->get(route('ride.safety'))->assertSee('Emergency (SOS)');

        // Once operations resolve it, the passenger's page says so (QA, D-1618) and stops offering "I am safe now".
        SafetyIncident::find($sos)->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
        $this->actingAs($passenger)->get(route('ride.safety.incident', $sos))->assertOk()
            ->assertSee('Our operations team resolved this on')->assertDontSee('I am safe now')->assertSee('What we did not do');
    }
}
