<?php

namespace Tests\Feature\Driver;

use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Pricing\Models\RideFare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\Concerns\PricingFixtures;
use Tests\TestCase;

/**
 * The offer card's earnings reconcile on their own (D-1901): the endpoint lists every step from the passenger's fare
 * to the driver's net, fees included, and names the amount commission was charged on. The jury's example: a R64.00
 * fare, 20% commission of R12.20 (which is 20% of R61.00, the fare before the R3.00 booking fee), net R48.80.
 */
class OfferEarningsWalkTest extends TestCase
{
    use CreatesDispatchFixtures, PricingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeQuotes();
        config(['ehail_safety.verification.require_documents' => false]);
    }

    public function test_the_offer_earnings_walk_from_fare_to_net_adds_up_and_states_the_commission_base(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(300));
        $ride = $this->requestRide();
        $offer = RideOffer::query()->where('pending_ride_id', $ride->id)->firstOrFail();
        $this->publishedCommissionPlan(['percent_bp' => 2000]);

        RideFare::query()->create([
            'ride_id' => $ride->id, 'currency' => 'ZAR', 'estimated_total_minor' => 6400,
            'estimated_components' => [
                ['code' => 'base', 'label' => 'Base fare', 'amount_minor' => 1000],
                ['code' => 'distance', 'label' => 'Distance', 'amount_minor' => 4275, 'detail' => '5.7 km x R7.50/km'],
                ['code' => 'time', 'label' => 'Time', 'amount_minor' => 798, 'detail' => '11.4 min x R0.70/min'],
                ['code' => 'booking_fee', 'label' => 'Booking fee', 'amount_minor' => 300],
                ['code' => 'rounding', 'label' => 'Rounding', 'amount_minor' => 27],
            ],
        ]);

        $json = $this->actingAs($driver)->getJson(route('drive.api.offer-earnings', $offer))->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('fare_minor', 6400)
            ->assertJsonPath('commission_minor', 1220)
            ->assertJsonPath('net_minor', 4880)
            ->assertJsonPath('commission_base', 'R61.00 (fare before fees)')
            ->json();

        $lines = collect($json['lines'])->keyBy('code');

        // Every step is listed, and fare + steps = net: R64.00 - R3.00 - R12.20 = R48.80.
        $this->assertSame($json['net_minor'], $json['fare_minor'] + array_sum(array_column($json['lines'], 'amount_minor')));
        $this->assertSame(-300, $lines['not_earned:booking_fee']['amount_minor']);
        $this->assertStringContainsString('kept by the platform', $lines['not_earned:booking_fee']['label']);
        $this->assertSame(-1220, $lines['commission']['amount_minor']);
        $this->assertSame('20% of R61.00 (fare before fees)', $lines['commission']['detail']);

        foreach ($json['lines'] as $line) {
            $this->assertNotSame('', trim((string) $line['label']), 'every step has words');
            $this->assertIsInt($line['amount_minor']);
        }
    }
}
