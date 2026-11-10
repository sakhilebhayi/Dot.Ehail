<?php

namespace Tests\Feature\Perf;

use App\Domain\Comms\CommsMessage;
use App\Domain\Comms\Models\NotificationDelivery;
use App\Domain\Comms\NotificationRouter;
use App\Domain\Payments\Models\Payment;
use App\Domain\Perf\DatabaseOutage;
use App\Domain\Perf\SystemInvariants;
use App\Models\Ride;
use App\Models\RidePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AcceptanceWorld;
use Tests\TestCase;

/**
 * Failure scenarios under realistic conditions (docs/R46_PERF.md section 3), through the real HTTP API with fakes
 * only at the edges (AcceptanceWorld). Each ends by asserting the system invariants (SystemInvariants): no driver
 * with two active rides, no ride with two drivers, no double charge, a balanced ledger, no lost trip events.
 * Runs on SQLite and, through bin/test-pgsql.sh, on PostgreSQL.
 */
class ReliabilityScenariosTest extends TestCase
{
    use AcceptanceWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAcceptanceWorld();
    }

    private function passengerWithCard(string $name): User
    {
        $passenger = User::factory()->withPersonalTeam()->create(['name' => $name]);
        $this->savedCard($passenger);

        return $passenger;
    }

    private function assertInvariants(): void
    {
        $result = app(SystemInvariants::class)->check();
        $this->assertTrue($result['ok'], 'invariants: '.json_encode($result['breaches']));
        $this->assertTrue($this->ledgerBalances(), 'ledger balanced');
    }

    public function test_a_routing_outage_mid_trip_falls_back_to_labelled_estimates_and_the_trip_completes_once(): void
    {
        $driver = $this->verifiedDriver('Sipho Ndlovu');
        $this->goOnlineAt($driver);
        $passenger = $this->passengerWithCard('Ayesha Patel');
        $rideId = $this->tripUnderWay($passenger, $driver);
        $this->assertSame('road', $this->passengerFeed($passenger, $rideId)['driver_position']['eta_method']);

        // The provider goes down mid-trip.
        $this->routingDown = true;
        $this->travel(31)->seconds();
        $this->postPosition($driver, -33.9300, 18.4200, 8.3);
        $feed = $this->passengerFeed($passenger, $rideId);
        $this->assertSame('straight_line_estimate', $feed['driver_position']['eta_method'], 'the fallback is labelled, never presented as a road ETA');
        $this->assertIsInt($feed['driver_position']['eta_s']);

        // Quoting during the outage is refused honestly; nothing is priced from a straight line.
        $this->actingAs($passenger)->postJson(route('pricing.quotes.store'), [
            'pickup_lat' => self::PICKUP[0], 'pickup_lng' => self::PICKUP[1], 'dropoff_lat' => self::FURTHER_EAST[0], 'dropoff_lng' => self::FURTHER_EAST[1], 'category' => 'standard',
        ])->assertOk()->assertJsonPath('available', false)->assertJsonPath('reason', 'routing_unavailable');

        $this->drive($driver, [-33.9300, 18.4200], self::DESTINATION);
        $this->stage($driver, $rideId, 'complete')->assertOk()->assertJsonPath('ride_status', 'completed');
        $this->stage($driver, $rideId, 'complete')->assertStatus(409);

        $ride = Ride::findOrFail($rideId);
        $this->assertLessThanOrEqual($ride->quoted_total_minor, $ride->final_fare_minor, 'an outage never raises the agreed fare');
        $this->assertSame(1, Payment::where('ride_id', $rideId)->where('purpose', Payment::PURPOSE_RIDE_FARE)->where('status', RidePayment::STATUS_PAID)->count(), 'charged once');
        $this->assertInvariants();
    }

    public function test_a_flaky_gateway_and_duplicate_webhooks_never_charge_a_ride_twice(): void
    {
        $driver = $this->verifiedDriver('Thandeka Mokoena');
        $this->goOnlineAt($driver);
        $rides = [];

        // Three trips complete while the gateway times out and its verify endpoint errors.
        $this->chargeOutcome = 'throw';
        $this->verifyStatus = 500;

        foreach (['Lerato Dlamini', 'Kagiso Molefe', 'Naledi Khumalo'] as $name) {
            $passenger = $this->passengerWithCard($name);
            $rideId = $this->tripUnderWay($passenger, $driver);
            $this->drive($driver, self::PICKUP, self::DESTINATION);
            $this->stage($driver, $rideId, 'complete')->assertOk();
            $rides[] = $rideId;
            $this->drive($driver, self::DESTINATION, self::DRIVER_START);
        }

        foreach ($rides as $rideId) {
            $this->assertSame(0, Payment::where('ride_id', $rideId)->where('status', RidePayment::STATUS_PAID)->count(), 'nothing is claimed paid while the gateway is silent');
        }

        // The gateway recovers; Paystack delivers each success webhook five times, and the sweep runs twice.
        $this->chargeOutcome = 'success';
        $this->verifyStatus = 200;

        foreach ($rides as $rideId) {
            foreach (RidePayment::where('ride_id', $rideId)->get() as $payment) {
                foreach (range(1, 5) as $i) {
                    $this->postWebhook($this->chargeSuccess($payment, ['id' => 7000000 + $payment->id]))->assertOk();
                }
            }
        }

        $this->travel(10)->minutes();
        $this->artisan('money:sweep')->assertSuccessful();
        $this->artisan('money:sweep')->assertSuccessful();

        foreach ($rides as $rideId) {
            $this->assertSame(1, Payment::where('ride_id', $rideId)->where('purpose', Payment::PURPOSE_RIDE_FARE)->where('status', RidePayment::STATUS_PAID)->count(), "ride {$rideId} paid exactly once");
        }

        $this->assertSame(count($rides), DB::table('payment_webhook_events')->count(), 'duplicates are one event each');
        $this->assertInvariants();
    }

    public function test_a_lost_database_is_a_503_with_retry_after_for_apps_and_pages_not_a_500(): void
    {
        $driver = $this->verifiedDriver('Bongani Zulu');
        $this->actingAs($driver);

        $default = config('database.default');
        $outage = $default === 'pgsql'
            ? array_merge(config('database.connections.pgsql'), ['host' => '127.0.0.1', 'port' => 1])
            : array_merge(config('database.connections.sqlite'), ['database' => sys_get_temp_dir()]);
        config(['database.connections.outage' => $outage, 'database.default' => 'outage']);

        try {
            $this->postJson(route('ehail.dispatch.driver.sync'), ['samples' => []])
                ->assertStatus(503)
                ->assertHeader('Retry-After', '5')
                ->assertExactJson(['error' => 'database_unavailable', 'message' => DatabaseOutage::MESSAGE]);

            $this->get(route('drive.home'))->assertStatus(503)->assertHeader('Retry-After', '5');
        } finally {
            config(['database.default' => $default]);
            DB::purge('outage');
        }
    }

    public function test_the_delivery_cron_drains_a_backlog_larger_than_one_batch_in_one_run(): void
    {
        config(['queue.default' => 'database', 'ehail_comms.delivery.batch' => 100]);
        $users = User::factory()->count(5)->create();
        $router = app(NotificationRouter::class);

        foreach (range(1, 130) as $i) {
            $router->send(new CommsMessage($users[$i % 5], 'ride_paid', "backlog:{$i}", 'Payment received', 'Your card was charged R85.00.', '/ride/trips'));
        }

        // The queued jobs were lost (e.g. the jobs table was cleared): the rows themselves are the work queue.
        DB::table('jobs')->delete();
        $due = NotificationDelivery::where('status', NotificationDelivery::QUEUED)->count();
        $this->assertGreaterThan(100, $due);

        $this->artisan('ehail:comms-deliver')->expectsOutputToContain("Attempted {$due} deliveries")->assertSuccessful();
        $this->assertSame(0, NotificationDelivery::where('status', NotificationDelivery::QUEUED)->whereNull('next_attempt_at')->count());
    }
}
