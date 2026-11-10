<?php

namespace Tests\Feature\Dispatch;

use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Rides\IdempotentRequests;
use App\Domain\Rides\RideRequestService;
use App\Models\Ride;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Real concurrency: each contender runs in its own forked PHP process with
 * its own SQLite connection to one shared database file (WAL journal, 10 s
 * busy timeout), released at the same instant by a time barrier. SQLite
 * serialises writers at the database level (lockForUpdate is a no-op
 * there), so these tests prove the guards that do not depend on row locks:
 * the compare-and-swap offer update, the dispatch lease, the nullable-unique
 * guard columns, the transaction retry on "database is locked", and the
 * idempotency key's unique row. The PostgreSQL run of the same scenarios
 * (where lockForUpdate takes real row locks) belongs to the wave-3
 * performance agent; docs/R46_DISPATCH.md "Concurrency" says how.
 */
class ConcurrencyTest extends TestCase
{
    use CreatesDispatchFixtures;

    private string $path;

    private const ROUNDS = 4;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are needed to run contenders in separate processes.');
        }

        $this->path = sys_get_temp_dir().'/ehail-concurrency-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
        touch($this->path);

        config([
            'database.connections.sqlite.database' => $this->path,
            'database.connections.sqlite.busy_timeout' => 10000,
            'database.connections.sqlite.journal_mode' => 'wal',
            // BEGIN IMMEDIATE: a write transaction takes SQLite's write lock up front and waits
            // (busy_timeout) instead of failing on a stale snapshot. Recommended for any SQLite
            // deployment with concurrent writers; test_correctness_holds_in_deferred_mode_too
            // shows the guards do not depend on it.
            'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
            'ehail_dispatch.open_board_after_seconds' => 0,
        ]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->fakeQuotes();
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');

        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink(($this->path ?? '/nonexistent').$suffix);
        }

        parent::tearDown();
    }

    /**
     * Run each closure in its own process at the same moment.
     *
     * @param  list<Closure(): mixed>  $contenders
     * @return list<array{ok: bool, value?: mixed, error?: string}>
     */
    private function race(array $contenders): array
    {
        DB::disconnect('sqlite');
        $start = microtime(true) + 0.25;
        $pids = [];

        foreach ($contenders as $i => $contender) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'fork failed');

            if ($pid === 0) {
                DB::purge('sqlite');

                while (microtime(true) < $start) {
                    usleep(100);
                }

                try {
                    $result = ['ok' => true, 'value' => $contender()];
                } catch (Throwable $e) {
                    $result = ['ok' => false, 'error' => get_class($e).': '.$e->getMessage()];
                }

                file_put_contents("{$this->path}.result.{$i}", serialize($result));
                // Leave without running PHPUnit's shutdown handlers in the child.
                posix_kill(getmypid(), SIGKILL);
            }

            $pids[$i] = $pid;
        }

        $results = [];

        foreach ($pids as $i => $pid) {
            pcntl_waitpid($pid, $status);
            $file = "{$this->path}.result.{$i}";
            $this->assertFileExists($file, "contender {$i} produced no result");
            $results[$i] = unserialize(file_get_contents($file));
            unlink($file);
        }

        DB::purge('sqlite');

        if (getenv('EHAIL_RACE_DEBUG')) {
            fwrite(STDERR, json_encode($results).PHP_EOL);
        }

        return $results;
    }

    /**
     * @param  list<array{ok: bool}>  $results
     */
    private function successes(array $results): int
    {
        return count(array_filter($results, fn ($r) => $r['ok']));
    }

    /**
     * Losers must get a refusal a person can read, not a database error.
     *
     * @param  list<array{ok: bool, error?: string}>  $results
     */
    private function assertCleanRefusals(array $results): void
    {
        foreach ($results as $result) {
            if (! $result['ok']) {
                $this->assertMatchesRegularExpression('/^App\\\\(Domain\\\\Dispatch\\\\Exceptions\\\\DispatchRefused|Services\\\\Ehail\\\\InvalidRideTransition):/', $result['error']);
            }
        }
    }

    public function test_a_driver_accepting_an_offer_and_another_claiming_the_board_never_both_win(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $offered = $this->onlineDriverAt(...$this->northOfPickup(100));
            $claimer = $this->onlineDriverAt(...$this->northOfPickup(150));
            $ride = $this->requestRide();
            $offer = RideOffer::where('pending_ride_id', $ride->id)->firstOrFail();
            $this->assertSame($offered->id, $offer->driver_id);

            $results = $this->race([
                fn () => app(Dispatcher::class)->offerResponse($offer, $offered, 'accept')->driver_id,
                fn () => app(Dispatcher::class)->claimFromBoard(Ride::find($ride->id), $claimer)->driver_id,
            ]);

            $this->assertSame(1, $this->successes($results), 'round '.$round.': '.json_encode($results));
            $this->assertCleanRefusals($results);
            $ride->refresh();
            $winner = $results[0]['ok'] ? $offered : $claimer;
            $this->assertSame($winner->id, $ride->driver_id);
            $this->assertSame('driver_assigned', $ride->status);
            $this->assertSame(1, $ride->events()->where('to_status', 'driver_assigned')->count());
            $this->assertSame(1, DriverAvailability::where('current_ride_id', $ride->id)->count());

            // Free both drivers for the next round.
            DriverAvailability::whereIn('user_id', [$offered->id, $claimer->id])->update(['state' => 'offline']);
        }
    }

    public function test_correctness_holds_in_deferred_mode_too(): void
    {
        config(['database.connections.sqlite.transaction_mode' => 'DEFERRED']);
        DB::purge('sqlite');

        for ($round = 0; $round < self::ROUNDS; $round++) {
            $offered = $this->onlineDriverAt(...$this->northOfPickup(100));
            $claimer = $this->onlineDriverAt(...$this->northOfPickup(150));
            $ride = $this->requestRide();
            $offer = RideOffer::where('pending_ride_id', $ride->id)->firstOrFail();

            $results = $this->race([
                fn () => app(Dispatcher::class)->offerResponse($offer, $offered, 'accept')->driver_id,
                fn () => app(Dispatcher::class)->claimFromBoard(Ride::find($ride->id), $claimer)->driver_id,
            ]);

            // With deferred transactions the loser may see "database is locked" (the API maps it
            // to 409 conflict_retry); what matters is that there is never a second winner.
            $this->assertLessThanOrEqual(1, $this->successes($results), json_encode($results));
            $this->assertSame(1, Ride::find($ride->id)->events()->where('to_status', 'driver_assigned')->count() + ($this->successes($results) === 0 ? 1 : 0));
            $this->assertLessThanOrEqual(1, DriverAvailability::where('current_ride_id', $ride->id)->count());

            DriverAvailability::whereIn('user_id', [$offered->id, $claimer->id])->update(['state' => 'offline']);
        }
    }

    public function test_a_double_tap_on_accept_from_two_devices_assigns_once(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $driver = $this->onlineDriverAt(...$this->northOfPickup(100));
            $ride = $this->requestRide();
            $offer = RideOffer::where('pending_ride_id', $ride->id)->firstOrFail();

            $results = $this->race([
                fn () => app(Dispatcher::class)->offerResponse($offer, $driver, 'accept')->driver_id,
                fn () => app(Dispatcher::class)->offerResponse($offer, $driver, 'accept')->driver_id,
            ]);

            // The second tap is either an idempotent success (same ride) or a clean refusal; never a second assignment.
            $this->assertGreaterThanOrEqual(1, $this->successes($results), json_encode($results));
            $this->assertCleanRefusals($results);
            $this->assertSame(1, Ride::find($ride->id)->events()->where('to_status', 'driver_assigned')->count());
            $this->assertSame($driver->id, Ride::find($ride->id)->driver_id);

            DriverAvailability::where('user_id', $driver->id)->update(['state' => 'offline']);
        }
    }

    public function test_one_driver_can_never_end_up_holding_two_rides(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $driver = $this->onlineDriverAt(...$this->northOfPickup(100));
            $first = $this->requestRide();
            $offer = RideOffer::where('pending_ride_id', $first->id)->firstOrFail();
            $second = $this->requestRide();
            $this->assertNull(RideOffer::where('pending_ride_id', $second->id)->first(), 'a driver sees one offer at a time');

            $results = $this->race([
                fn () => app(Dispatcher::class)->offerResponse($offer, $driver, 'accept')->id,
                fn () => app(Dispatcher::class)->claimFromBoard(Ride::find($second->id), $driver)->id,
            ]);

            $this->assertSame(1, $this->successes($results), 'round '.$round.': '.json_encode($results));
            $this->assertCleanRefusals($results);
            $this->assertSame(1, Ride::where('active_driver_id', $driver->id)->count());
            $this->assertSame(1, Ride::where('driver_id', $driver->id)->whereIn('status', ['driver_assigned'])->count());

            DriverAvailability::where('user_id', $driver->id)->update(['state' => 'offline']);
            Ride::whereIn('id', [$first->id, $second->id])->update(['status' => 'cancelled_by_operator', 'active_driver_id' => null, 'active_passenger_id' => null]);
        }
    }

    public function test_concurrent_ticks_place_exactly_one_offer(): void
    {
        config(['ehail_dispatch.pin.required' => false]);

        for ($round = 0; $round < self::ROUNDS; $round++) {
            $passenger = $this->passenger();
            $ride = app(RideRequestService::class)->request($passenger, $this->quotes->issue($passenger)->id);
            $this->assertNull(RideOffer::where('pending_ride_id', $ride->id)->first(), 'nobody online yet');

            foreach ([100, 200, 300] as $m) {
                $this->onlineDriverAt(...$this->northOfPickup($m));
            }

            $results = $this->race([
                fn () => app(Dispatcher::class)->tickRide(Ride::find($ride->id))?->id,
                fn () => app(Dispatcher::class)->tickRide(Ride::find($ride->id))?->id,
                fn () => app(Dispatcher::class)->tickRide(Ride::find($ride->id))?->id,
            ]);

            $this->assertSame(3, $this->successes($results), json_encode($results));
            $this->assertSame(1, RideOffer::where('ride_id', $ride->id)->count(), 'round '.$round);
            $this->assertSame(1, RideOffer::where('pending_ride_id', $ride->id)->count());

            DriverAvailability::query()->update(['state' => 'offline']);
            Ride::whereKey($ride->id)->update(['status' => 'cancelled_by_operator', 'active_passenger_id' => null]);
            RideOffer::query()->update(['status' => 'withdrawn', 'pending_ride_id' => null, 'pending_driver_id' => null]);
        }
    }

    public function test_a_double_request_with_one_idempotency_key_creates_one_ride(): void
    {
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $passenger = $this->passenger();
            $quote = $this->quotes->issue($passenger);
            $key = 'race-key-'.$round.'-'.bin2hex(random_bytes(4));

            $request = fn () => app(IdempotentRequests::class)->run($passenger, 'ride.request', $key, ['quote_id' => $quote->id], function () use ($passenger, $quote) {
                $ride = app(RideRequestService::class)->request($passenger, $quote->id);

                return [201, ['ride_id' => $ride->id], $ride->id];
            });

            $results = $this->race([$request, $request]);

            $this->assertSame(1, Ride::where('passenger_id', $passenger->id)->count(), 'round '.$round.': '.json_encode($results));
            $this->assertGreaterThanOrEqual(1, $this->successes($results));

            foreach ($results as $result) {
                if (! $result['ok']) {
                    // The loser is told the first request is still running (409) or quote_used/active_ride (409); never a second ride.
                    $this->assertMatchesRegularExpression('/request_in_progress|still being processed|already used|already have a ride/', $result['error']);
                }
            }
        }
    }
}
