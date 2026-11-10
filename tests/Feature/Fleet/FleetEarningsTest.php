<?php

namespace Tests\Feature\Fleet;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Fleet\FleetEarnings;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MoneyFixtures;
use Tests\TestCase;

/**
 * Fleet earnings come from money's records and agree with the ledger: trips completed through the real lifecycle
 * (which settles them in the money domain), then the fleet statement and its ledger check.
 */
class FleetEarningsTest extends TestCase
{
    use MoneyFixtures, RefreshDatabase;

    private Fleet $fleet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
        $this->fakeMoneyPaystack();
        config(['money.commission.percent' => 20, 'money.payouts.payee_for_fleet_drivers' => 'driver']);

        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->fleet = Fleet::create(['name' => 'Ledger Cabs', 'owner_user_id' => $this->owner->id, 'status' => 'approved']);
    }

    /** @return array{0: User, 1: Vehicle} */
    private function driverInFleet(string $name): array
    {
        $driver = $this->makeApprovedDriver();
        $driver->forceFill(['name' => $name])->save();
        $profile = DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->firstOrFail();
        $profile->update(['fleet_id' => $this->fleet->id]);
        $vehicle = $profile->vehicles()->firstOrFail();
        $vehicle->forceFill(['fleet_id' => $this->fleet->id])->save();

        return [$driver, $vehicle];
    }

    private function trip(User $driver, Vehicle $vehicle, string $fare): void
    {
        $passenger = User::factory()->create();
        $this->savedCard($passenger);
        $this->completeTrip($passenger, $driver, $fare, fn ($ride) => $ride->forceFill(['vehicle_id' => $vehicle->id])->save());
    }

    public function test_fleet_owner_payee_totals_match_the_fleet_payable_ledger_account(): void
    {
        $this->fleet->update(['payee_mode' => 'fleet_owner']);
        [$sipho, $siphoCar] = $this->driverInFleet('Sipho Dlamini');
        [$ayanda, $ayandaCar] = $this->driverInFleet('Ayanda Khumalo');

        $this->trip($sipho, $siphoCar, '100.00');
        $this->trip($sipho, $siphoCar, '150.00');
        $this->trip($ayanda, $ayandaCar, '80.00');

        $earnings = app(FleetEarnings::class);
        $statement = $earnings->statement($this->owner, $this->fleet, 'week');
        $reconciliation = $earnings->reconciliation($this->owner, $this->fleet);

        // 20% commission: nets 80 + 120 + 64.
        $this->assertSame(3, $statement['totals']['trips']);
        $this->assertSame(33000, $statement['totals']['fare_minor']);
        $this->assertSame(26400, $statement['totals']['net_minor']);
        $this->assertSame(6600, $statement['totals']['commission_minor']);
        $this->assertSame(['Sipho Dlamini' => 20000, 'Ayanda Khumalo' => 6400], collect($statement['by_driver'])->pluck('net_minor', 'name')->all());
        $this->assertSame([$siphoCar->plate_number => 20000, $ayandaCar->plate_number => 6400], collect($statement['by_vehicle'])->pluck('net_minor', 'plate')->all());

        $ledger = app(Ledger::class)->balance(Account::fleetPayable($this->fleet->id), 'ZAR');
        $this->assertSame(26400, $ledger);
        $this->assertSame(['ledger_minor' => 26400, 'earnings_minor' => 26400, 'matches' => true], $reconciliation['fleet']);
        $this->assertSame(array_sum(array_column($statement['by_driver'], 'net_minor')), $statement['totals']['net_minor']);

        $this->actingAs($this->owner)->get(route('fleet.earnings'))->assertOk()->assertSee('Matches')->assertSee('Sipho Dlamini')->assertSee($siphoCar->plate_number);
        $csv = $this->actingAs($this->owner)->get(route('fleet.earnings.csv'))->assertOk()->streamedContent();
        $this->assertSame(4, count(array_filter(explode("\n", trim($csv)))));
        $this->assertStringContainsString('Ayanda Khumalo', $csv);
    }

    public function test_driver_payee_earnings_match_each_drivers_ledger_account_and_a_mismatch_is_shown(): void
    {
        [$thabo, $car] = $this->driverInFleet('Thabo Nkosi');
        $this->trip($thabo, $car, '200.00');

        $reconciliation = app(FleetEarnings::class)->reconciliation($this->owner, $this->fleet);
        $this->assertSame(0, $reconciliation['fleet']['ledger_minor']);
        $this->assertTrue($reconciliation['fleet']['matches']);
        $this->assertSame([['driver_id' => $thabo->id, 'name' => 'Thabo Nkosi', 'ledger_minor' => 16000, 'earnings_minor' => 16000, 'matches' => true, 'note' => null]], $reconciliation['drivers']);

        // An earning the ledger never recorded is reported, not hidden.
        DriverEarning::create([
            'kind' => DriverEarning::KIND_ADJUSTMENT, 'source_key' => 'test:unbooked', 'driver_id' => $thabo->id, 'fleet_id' => $this->fleet->id,
            'payee_type' => 'driver', 'payee_user_id' => $thabo->id, 'currency' => 'ZAR', 'net_minor' => 500, 'status' => DriverEarning::AVAILABLE, 'earned_at' => now(),
        ]);
        $this->assertFalse(app(FleetEarnings::class)->reconciliation($this->owner, $this->fleet)['drivers'][0]['matches']);
        $this->actingAs($this->owner)->get(route('fleet.earnings'))->assertOk()->assertSee('Does not match');
    }

    public function test_the_fleet_payee_arrangement_routes_earnings_and_only_for_approved_fleets(): void
    {
        [$driver] = $this->driverInFleet('Payee Driver');
        $resolver = app(\App\Domain\Payments\Seams\PayeeResolver::class);

        $this->assertSame('driver', $resolver->payeeFor($driver)['payee_type']);
        $this->fleet->update(['payee_mode' => 'fleet_owner']);
        $this->assertSame(['payee_type' => 'fleet_owner', 'payee_user_id' => $this->owner->id, 'fleet_id' => $this->fleet->id], $resolver->payeeFor($driver));

        $this->fleet->update(['status' => 'pending']);
        $this->assertSame('driver', $resolver->payeeFor($driver)['payee_type']);

        // The owner driving in their own fleet is always paid as the driver.
        $this->fleet->update(['status' => 'approved']);
        DriverProfile::factory()->create(['user_id' => $this->owner->id, 'fleet_id' => $this->fleet->id, 'status' => 'approved']);
        $this->assertSame('driver', $resolver->payeeFor($this->owner)['payee_type']);
    }

    public function test_another_fleets_earnings_never_appear(): void
    {
        [$mine, $myCar] = $this->driverInFleet('My Driver');
        $this->trip($mine, $myCar, '100.00');
        $other = $this->makeApprovedDriver();
        $other->forceFill(['name' => 'Other Fleet Driver'])->save();
        $this->completeTrip(null, $other, '300.00');

        $statement = app(FleetEarnings::class)->statement($this->owner, $this->fleet, 'week');
        $this->assertSame(['My Driver'], collect($statement['by_driver'])->pluck('name')->all());
        $this->actingAs($this->owner)->get(route('fleet.earnings'))->assertDontSee('Other Fleet Driver');
    }
}
