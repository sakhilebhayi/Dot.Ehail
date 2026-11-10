<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Ledger\Account;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Receipt;
use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Models\RideFare;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AcceptanceWorld;
use Tests\TestCase;

/**
 * (F) Pricing integrity end to end: server-side prices, a bound quote, final reconciliation for a passenger detour
 * and for a traffic delay, and the money that follows (R46_INTEGRATION.md section 5).
 */
class PricingJourneyTest extends TestCase
{
    use AcceptanceWorld, RefreshDatabase;

    public function test_prices_are_server_side_bound_reconciled_with_reasons_and_booked_in_a_balanced_ledger(): void
    {
        $driver = $this->verifiedDriver('Kagiso Molefe');
        $this->goOnlineAt($driver);

        // Anonymous estimate: labelled as an estimate, never stored.
        $estimate = $this->postJson(route('pricing.estimate'), [
            'pickup_lat' => self::PICKUP[0], 'pickup_lng' => self::PICKUP[1], 'dropoff_lat' => self::DESTINATION[0], 'dropoff_lng' => self::DESTINATION[1], 'category' => 'standard',
        ])->assertOk()->assertJsonPath('kind', 'estimate')->assertJsonPath('quote_id', null)->json();
        $this->assertSame(0, FareQuote::count());

        // A signed-in quote: the client's own total is ignored; the components add up to the server's total.
        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Refilwe Sithole']);
        $this->savedCard($passenger);
        $quote = $this->actingAs($passenger)->postJson(route('pricing.quotes.store'), [
            'pickup_lat' => self::PICKUP[0], 'pickup_lng' => self::PICKUP[1], 'dropoff_lat' => self::DESTINATION[0], 'dropoff_lng' => self::DESTINATION[1],
            'category' => 'standard', 'total_minor' => 100, 'fare' => ['total' => 1],
        ])->assertOk()->json();
        $total = $quote['fare']['total']['amount_minor'];
        $this->assertSame($estimate['fare']['total']['amount_minor'], $total, 'the same route and tariff give the same price');
        $this->assertSame($total, array_sum(array_column($quote['fare']['components'], 'amount_minor')));
        $this->assertSame(['base', 'distance', 'time', 'booking_fee'], array_values(array_intersect(array_column($quote['fare']['components'], 'code'), ['base', 'distance', 'time', 'booking_fee'])));
        $this->assertGreaterThan(100, $total);

        // Booking binds the quote; a client fare in the request is ignored; the quote cannot be used twice.
        $rideId = $this->requestRide($passenger, $quote['quote_id'], ['total_minor' => 1, 'estimated_fare' => 1])->assertCreated()
            ->assertJsonPath('ride.fare.quoted_total_minor', $total)->json('ride.id');
        $snapshot = RideFare::where('ride_id', $rideId)->sole();
        $this->assertSame($quote['quote_id'], $snapshot->fare_quote_id);
        $this->assertSame($total, $snapshot->estimated_total_minor);

        // The passenger asks for a further destination half-way: the longer trip is recalculated, uncapped,
        // with the reason shown.
        $pin = $this->acceptAndArrive($driver, $passenger, $rideId);
        $this->stage($driver, $rideId, 'start', $pin)->assertOk();
        $corner = [self::DESTINATION[0], self::PICKUP[1]];
        $this->drive($driver, self::PICKUP, $corner);
        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.trip-change', $rideId), [
            'kind' => 'destination', 'lat' => self::FURTHER_EAST[0], 'lng' => self::FURTHER_EAST[1], 'address' => 'Harbour Road, Foreshore',
        ])->assertOk()->assertJsonPath('ride.dropoff.address', 'Harbour Road, Foreshore');
        $this->drive($driver, $corner, self::FURTHER_EAST);
        $this->stage($driver, $rideId, 'complete')->assertOk();

        $detour = $this->actingAs($passenger)->getJson(route('pricing.rides.fare', $rideId))->assertOk()->json('final');
        $this->assertSame('recalculated', $detour['basis']);
        $this->assertGreaterThan($total, $detour['final']['amount_minor']);
        $change = collect($detour['changes'])->firstWhere('code', 'destination_changed');
        $this->assertStringContainsString('You changed the destination', $change['text']);
        $this->assertSame($detour['final']['amount_minor'] - $total, array_sum(array_column(array_column($detour['changes'], 'amount'), 'amount_minor')), 'the reasons account for every cent');
        $this->assertSame($detour['final']['amount_minor'], Payment::where('ride_id', $rideId)->where('purpose', Payment::PURPOSE_RIDE_FARE)->sole()->amountMinor(), 'the card is charged the final fare');
        // The receipt says why the fare differs from the quote (QA, D-1616).
        $receipt = Receipt::where('ride_id', $rideId)->sole();
        $this->assertStringContainsString('You changed the destination', collect($receipt->snapshot['fare_changes'])->pluck('text')->implode(' '));
        $this->actingAs($passenger)->get(route('money.receipts.show', $receipt))->assertOk()
            ->assertSee('Why the fare differs from the quote')->assertSee('You changed the destination');

        // A second trip on the original route in slow traffic (+20 % time): inside the tolerance, the quote stands.
        $this->travel(5)->minutes();
        $this->postPosition($driver, self::DRIVER_START[0], self::DRIVER_START[1]);
        $second = $this->quoteFor($passenger);
        $slowRideId = $this->requestRide($passenger, $second['quote_id'])->assertCreated()->json('ride.id');
        $pin = $this->acceptAndArrive($driver, $passenger, $slowRideId);
        $this->stage($driver, $slowRideId, 'start', $pin)->assertOk();
        $seconds = $this->drive($driver, self::PICKUP, self::DESTINATION, self::CITY_SPEED_MS * 0.85);
        $this->assertGreaterThan(1.1 * RideFare::where('ride_id', $slowRideId)->value('quoted_duration_s'), $seconds);
        $this->stage($driver, $slowRideId, 'complete')->assertOk();
        $slow = $this->actingAs($passenger)->getJson(route('pricing.rides.fare', $slowRideId))->assertOk()->json('final');
        $this->assertSame('quoted_fare_stands', $slow['basis']);
        $this->assertSame($second['fare']['total']['amount_minor'], $slow['final']['amount_minor']);
        $this->assertSame($second['fare']['total']['amount_minor'], Ride::find($slowRideId)->final_fare_minor);

        // Money: every journal balances; the driver's earning is the fare less commission and platform fees;
        // the platform's commission and fees are in the ledger.
        $this->assertTrue($this->ledgerBalances());
        $earnings = DriverEarning::where('driver_id', $driver->id)->where('kind', DriverEarning::KIND_TRIP)->get();
        $this->assertCount(2, $earnings);

        foreach ($earnings as $earning) {
            $this->assertSame($earning->fare_minor - $earning->commission_minor - $earning->deductions_minor, $earning->net_minor);
            $this->assertGreaterThan(0, $earning->commission_minor);
        }

        $this->assertSame((int) $earnings->sum('commission_minor'), $this->platformCommission());
        $this->assertSame((int) $earnings->sum('deductions_minor'), $this->ledgerBalance(Account::PLATFORM_REVENUE_FEES), 'booking fees');
        $this->assertSame($detour['final']['amount_minor'], $earnings->firstWhere('ride_id', $rideId)->fare_minor);
    }
}
