<?php

namespace Tests\Feature\Acceptance;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Earnings\Models\Payout;
use App\Domain\Verification\DocumentRequirements;
use App\Domain\Verification\Models\VerificationDocument;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AcceptanceWorld;
use Tests\TestCase;

/**
 * (D) The driver journey end to end through the real HTTP API (R46_INTEGRATION.md section 5).
 */
class DriverJourneyTest extends TestCase
{
    use AcceptanceWorld, RefreshDatabase;

    public function test_a_driver_onboards_drives_a_trip_and_is_paid_in_the_weekly_batch(): void
    {
        $fleet = $this->approvedFleet();
        $operator = $this->operator();

        // Register and apply (licence, ID, the vehicle) to an approved fleet.
        $driver = $this->registerUser('Sipho Ndlovu', 'sipho.driver@example.test');
        $this->actingAs($driver)->post(route('drive.apply.store'), [
            'license_number' => 'ZA-DL-55120087', 'id_number' => '8501015800087', 'existing_fleet_id' => $fleet->id,
            'vehicle_make' => 'Toyota', 'vehicle_model' => 'Corolla Quest', 'vehicle_year' => 2022, 'vehicle_color' => 'Silver',
            'vehicle_plate_number' => 'CA 482-118', 'vehicle_type' => 'standard',
        ])->assertRedirect();
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->sole();
        $vehicle = Vehicle::where('driver_profile_id', $profile->id)->sole();
        $this->assertSame('pending', $profile->status);

        // The fleet owner approves the application; the driver activates the vehicle.
        $this->actingAs(User::findOrFail($fleet->owner_user_id))->post(route('fleets.drivers.approve', [$fleet, $profile->id]))->assertRedirect();
        $this->assertSame('approved', $profile->fresh()->status);
        $this->actingAs($driver)->post(route('drivers.vehicles.activate', $vehicle))->assertRedirect();
        $this->assertTrue($vehicle->fresh()->is_active);

        // Not verified yet: going online is refused with the reasons.
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.online'))->assertForbidden()->assertJsonPath('error', 'document_missing');

        // Upload every mandatory document for the driver and the vehicle.
        $requirements = app(DocumentRequirements::class);
        foreach (['driver' => null, 'vehicle' => $vehicle] as $subject => $forVehicle) {
            foreach ($requirements->required($subject, $subject === 'vehicle' ? $vehicle->type : null) as $type => $rule) {
                $this->actingAs($driver)->postJson(route('verification.documents.store'), array_filter([
                    'type' => $type,
                    // Photos of the documents (every type accepts an image; EXIF is stripped on upload).
                    'document' => $this->jpegUpload($type.'.jpg'),
                    'vehicle_id' => $forVehicle?->id,
                    'expires_on' => $rule['expiry_required'] ? now()->addYear()->toDateString() : null,
                ]))->assertCreated();
            }
        }
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.online'))->assertForbidden()->assertJsonPath('error', 'document_pending_review');

        // Operations review and approve each document (the vehicle is approved with its own documents).
        foreach (VerificationDocument::where('user_id', $driver->id)->get() as $document) {
            $this->actingAs($operator)->postJson(route('safety.ops.verification.approve', $document))->assertOk();
        }
        $this->actingAs($driver)->getJson(route('verification.eligibility', ['vehicle_id' => $vehicle->id]))->assertOk()->assertJsonPath('allowed', true);

        // Online, with a first position from the phone.
        $this->goOnlineAt($driver);

        // A passenger books (card on file).
        $passenger = User::factory()->withPersonalTeam()->create(['name' => 'Naledi Khumalo']);
        $this->savedCard($passenger);
        $quote = $this->quoteFor($passenger);
        $rideId = $this->requestRide($passenger, $quote['quote_id'])->assertCreated()->json('ride.id');

        // The offer shows what the driver needs to decide: pickup distance and ETA, trip, fare and earnings.
        $offer = $this->driverFeed($driver)['offer'];
        $this->assertSame($rideId, $offer['ride_id']);
        $this->assertEqualsWithDelta(1000, $offer['pickup_distance_m'], 50);
        $this->assertEqualsWithDelta(120, $offer['pickup_eta_s'], 10);
        $this->assertEqualsWithDelta(5000, $offer['trip_distance_m'], 60);
        $this->assertSame($quote['fare']['total']['amount_minor'], $offer['fare_total_minor']);
        $this->assertGreaterThan(0, $offer['earnings_estimate_minor']);
        $this->assertLessThan($offer['fare_total_minor'], $offer['earnings_estimate_minor']);
        $this->assertArrayNotHasKey('lat', $offer['dropoff'], 'the destination is shown by address only before accepting');

        $this->actingAs($driver)->postJson(route('ehail.dispatch.offers.accept', $offer['id']))->assertOk();
        $navigation = $this->driverFeed($driver)['ride']['navigation'];
        $this->assertSame('pickup', $navigation['target']);
        $this->assertStringContainsString('destination=-33.940000,18.410000', $navigation['google_maps']);
        $this->assertStringStartsWith('https://waze.com/ul?ll=-33.940000,18.410000', $navigation['waze']);

        $this->stage($driver, $rideId, 'arriving')->assertOk();
        $this->drive($driver, self::DRIVER_START, self::PICKUP);
        $this->stage($driver, $rideId, 'arrived')->assertOk();
        $pin = (string) $this->passengerFeed($passenger, $rideId)['ride']['pin'];
        $this->stage($driver, $rideId, 'start', $pin)->assertOk();
        $this->assertSame('dropoff', $this->driverFeed($driver)['ride']['navigation']['target']);
        $this->drive($driver, self::PICKUP, self::DESTINATION);
        $this->stage($driver, $rideId, 'complete')->assertOk()->assertJsonPath('ride_status', 'completed');
        $this->assertSame('online', $this->driverFeed($driver)['availability']['state'], 'back online for the next ride');

        // Earnings: fare, commission (20 % example plan on the trip fare), platform fees, net.
        $statement = $this->actingAs($driver)->getJson(route('money.driver.earnings', ['period' => 'day']))->assertOk()->json();
        $earning = collect($statement['earnings'])->firstWhere('ride_id', $rideId);
        $this->assertSame($quote['fare']['total']['amount_minor'], $earning['fare_minor']);
        $bookingFee = collect($quote['fare']['components'])->firstWhere('code', 'booking_fee')['amount_minor'];
        $this->assertSame(intdiv(($earning['fare_minor'] - $bookingFee) * 2000 + 5000, 10000), $earning['commission_minor']);
        $this->assertSame($bookingFee, $earning['deductions_minor'], 'the booking fee is the platform\'s, not commissioned');
        $this->assertSame($earning['fare_minor'] - $earning['commission_minor'] - $earning['deductions_minor'], $earning['net_minor']);
        $this->assertSame(DriverEarning::AVAILABLE, $earning['status'], 'the card payment settled');
        $this->assertSame(1, $statement['totals']['trips']);

        // The weekly payout batch includes it.
        $this->actingAs($driver)->postJson(route('money.payout-accounts.store'), [
            'payee_type' => 'driver', 'account_holder' => 'S Ndlovu', 'account_number' => '62000011112', 'bank_code' => '250655', 'bank_name' => 'First National Bank', 'account_type' => 'personal',
        ])->assertCreated();
        $this->travel(1)->days();
        $this->actingAs($operator)->postJson(route('money.ops.batches.draft'), ['period_end' => now()->subDay()->toDateString()])->assertCreated();
        $payout = Payout::where('payee_user_id', $driver->id)->sole();
        $this->assertSame($earning['net_minor'], $payout->earnings_minor);
        $this->assertSame($payout->id, DriverEarning::where('ride_id', $rideId)->value('payout_id'));
        $this->assertTrue($this->ledgerBalances());
    }
}
