<?php

namespace Tests\Feature\Money;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Ledger\Models\FinanceIssue;
use App\Domain\Payments\Jobs\SettleCompletedRide;
use App\Domain\Payments\Models\Payment;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PDOException;
use Tests\Concerns\MoneyFixtures;
use Tests\TestCase;

/**
 * A completed ride must always be settled (QA defect D-1607: in the browser suite a completed ride stayed unpaid and
 * no SettleCompletedRide job was queued). Root cause: the job is ShouldBeUnique with a lock that never expired;
 * both completion triggers (the ride row, TripCompleted) were skipped because a lock for that ride id was held by a
 * job whose release could never reach it (there: web workers and the queue worker on different cache stores, so an
 * earlier run's lock for ride #1 outlived its job; in production the same holds for any job lost after it was
 * queued). Fix: the lock expires (10 min) and ehail:settlement-sweep re-queues any completed ride with a fare that
 * was never settled, releasing a stale lock and listing the ride on the finance exceptions list.
 * The retry and rollback cases below prove the transaction paths are not the cause (Laravel releases the lock on
 * rollback) and stay covered.
 */
class SettlementDispatchTest extends TestCase
{
    use DatabaseMigrations, MoneyFixtures;

    private User $passenger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
        $this->fakeMoneyPaystack();
        config(['money.commission.percent' => 20]);
        $this->passenger = User::factory()->withPersonalTeam()->create(['email' => 'rider@example.test']);
        // As on the host: jobs go to the database and a worker (cron) runs them later, outside the request.
        config(['queue.default' => 'database']);
    }

    private function drainQueue(): void
    {
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 3, '--sleep' => 0])->assertSuccessful();
    }

    private function queuedSettlements(int $rideId): int
    {
        return DB::table('jobs')->get()->filter(fn ($j) => str_contains($j->payload, 'SettleCompletedRide') && str_contains($j->payload, 's:6:\"rideId\";i:'.$rideId.';'))->count();
    }

    private function inProgressRide(): array
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id, 'estimated_fare' => '125.50',
            'started_at' => now()->subMinutes(20), 'accepted_at' => now()->subMinutes(30)], $this->passenger);

        return [$ride, $driver];
    }

    /** The first completion attempt fails the way SQLite/Postgres lock contention does, after the ride row was saved. */
    private function failFirstCompletionWithLockContention(): void
    {
        $failed = false;
        Ride::updated(function (Ride $ride) use (&$failed) {
            if (! $failed && $ride->wasChanged('status') && $ride->status === 'completed') {
                $failed = true;

                throw new QueryException('sqlite', 'update "rides" set ...', [], new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
            }
        });
    }

    public function test_a_completion_retried_after_lock_contention_still_charges_the_card(): void
    {
        $this->savedCard($this->passenger);
        [$ride, $driver] = $this->inProgressRide();
        $this->failFirstCompletionWithLockContention();

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'complete']))
            ->assertOk()->assertJsonPath('ride_status', 'completed');
        $this->assertSame(1, $this->queuedSettlements($ride->id), 'one settlement job is waiting for the worker');
        $this->drainQueue();

        $payment = Payment::where('ride_id', $ride->id)->where('purpose', Payment::PURPOSE_RIDE_FARE)->sole();
        $this->assertSame(Payment::PAID, $payment->status);
        $this->assertSame(12550, $payment->amountMinor());
        $this->assertSame('paid', $ride->fresh()->payment_status);
        $this->assertSame(1, DriverEarning::where('ride_id', $ride->id)->count());
    }

    public function test_a_completion_that_rolled_back_entirely_does_not_block_the_next_one(): void
    {
        $this->savedCard($this->passenger);
        [$ride, $driver] = $this->inProgressRide();

        // A failure that is not retried: the driver's request fails, the ride is still in progress.
        $failed = false;
        Ride::updated(function (Ride $r) use (&$failed) {
            if (! $failed && $r->wasChanged('status') && $r->status === 'completed') {
                $failed = true;

                throw new \RuntimeException('something else broke');
            }
        });

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'complete']))->assertStatus(500);
        $this->assertSame('in_progress', $ride->fresh()->status);

        // The driver taps Complete again.
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'complete']))->assertOk();
        $this->assertSame(1, $this->queuedSettlements($ride->id));
        $this->drainQueue();

        $this->assertSame('paid', $ride->fresh()->payment_status);
        $this->assertSame(1, Payment::where('ride_id', $ride->id)->where('purpose', Payment::PURPOSE_RIDE_FARE)->count());
    }

    public function test_completing_queues_exactly_one_settlement_job_after_commit(): void
    {
        [$ride, $driver] = $this->inProgressRide();

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'complete']))->assertOk();

        // The ride row and TripCompleted both trigger settlement: one job, not two.
        $this->assertSame(1, $this->queuedSettlements($ride->id));
    }

    public function test_an_orphaned_lock_for_the_ride_no_longer_blocks_settlement_forever(): void
    {
        $this->savedCard($this->passenger);
        [$ride, $driver] = $this->inProgressRide();
        // A lock nobody will release (taken by a job that is gone).
        cache()->lock(UniqueLock::getKey(new SettleCompletedRide($ride->id)), 600)->get();

        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'complete']))->assertOk();
        $this->assertSame(0, $this->queuedSettlements($ride->id), 'both triggers skipped while the lock is held');

        // The lock expires; the sweep (after its threshold) queues settlement and the card is charged.
        $this->travel(16)->minutes();
        $this->artisan('ehail:settlement-sweep')->assertSuccessful();
        $this->drainQueue();
        $this->assertSame('paid', $ride->fresh()->payment_status);
    }

    public function test_the_sweep_settles_a_completed_ride_that_was_never_settled_and_tells_ops(): void
    {
        $this->savedCard($this->passenger);
        [$ride, $driver] = $this->inProgressRide();

        // The settlement job is lost after it was queued (a worker killed mid-job, a jobs row cleared by hand):
        // the ride stays unpaid and the job's unique lock is still held.
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'complete']))->assertOk();
        $this->assertSame(1, $this->queuedSettlements($ride->id));
        DB::table('jobs')->delete();
        $this->assertSame('unpaid', $ride->fresh()->payment_status);
        $this->assertSame(0, Payment::where('ride_id', $ride->id)->count());

        // Too recent: settlement may still be on its way.
        $this->artisan('ehail:settlement-sweep')->assertSuccessful();
        $this->assertSame(0, Payment::where('ride_id', $ride->id)->count());

        $this->travel(16)->minutes();
        $this->artisan('ehail:settlement-sweep')->assertSuccessful();
        $this->assertSame(1, $this->queuedSettlements($ride->id), 'the sweep queued settlement despite the stale lock');
        $this->drainQueue();

        $this->assertSame('paid', $ride->fresh()->payment_status);
        $this->assertSame(1, Payment::where('ride_id', $ride->id)->where('purpose', Payment::PURPOSE_RIDE_FARE)->count());
        $issue = FinanceIssue::where('kind', 'settlement_missing')->sole();
        $this->assertSame($ride->id, $issue->ride_id);

        // Idempotent: another run changes nothing.
        $this->travel(10)->minutes();
        $this->artisan('ehail:settlement-sweep')->assertSuccessful();
        $this->assertSame(0, $this->queuedSettlements($ride->id));
        $this->assertSame(1, Payment::where('ride_id', $ride->id)->count());
        $this->assertSame(1, FinanceIssue::where('kind', 'settlement_missing')->count());
    }

    public function test_the_sweep_leaves_settled_and_checkout_rides_alone(): void
    {
        // No card: settlement records the fare and waits for the passenger to pay from the ride page.
        [$ride, $driver] = $this->inProgressRide();
        $this->actingAs($driver)->postJson(route('ehail.dispatch.driver.rides.stage', [$ride, 'complete']))->assertOk();
        $this->drainQueue();
        $this->assertSame('unpaid', $ride->fresh()->payment_status);
        $this->assertSame(1, DriverEarning::where('ride_id', $ride->id)->count());

        $this->travel(30)->minutes();
        Queue::fake();
        $this->artisan('ehail:settlement-sweep')->assertSuccessful();

        Queue::assertNotPushed(SettleCompletedRide::class);
        $this->assertSame(0, FinanceIssue::where('kind', 'settlement_missing')->count());
    }
}
