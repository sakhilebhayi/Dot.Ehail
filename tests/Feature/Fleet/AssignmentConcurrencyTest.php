<?php

namespace Tests\Feature\Fleet;

use App\Domain\Fleet\Models\FleetVehicleAssignment;
use App\Domain\Fleet\VehicleAssignments;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use App\Models\Vehicle;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\FleetFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Real concurrency for fleet assignments, the same way dispatch proves its guards (tests/Feature/Dispatch/
 * ConcurrencyTest): each contender runs in its own forked process with its own SQLite connection to one shared
 * database file (WAL, 10 s busy timeout), released at the same instant. lockForUpdate is a no-op on SQLite, so
 * these prove the guards that do not depend on row locks: the unique open-assignment column, the holder
 * compare-and-swap and the partial "one active vehicle per driver" index.
 */
class AssignmentConcurrencyTest extends TestCase
{
    use FleetFixtures;

    private const ROUNDS = 4;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are needed to run contenders in separate processes.');
        }

        $this->path = sys_get_temp_dir().'/ehail-fleet-race-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
        touch($this->path);
        // A race on one SQLite file, whatever the suite's default connection: under phpunit.pgsql.xml the forked
        // contenders would otherwise share the parent's PostgreSQL socket and hang (PostgreSQL row locking is raced in
        // Dispatch\ConcurrencyTest, which opens one connection per contender).
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->path,
            'database.connections.sqlite.busy_timeout' => 10000,
            'database.connections.sqlite.journal_mode' => 'wal',
            'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
        ]);
        DB::purge('sqlite');
        $this->artisan('migrate:fresh')->assertExitCode(0);
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

        return $results;
    }

    public function test_two_managers_giving_one_vehicle_to_two_drivers_at_once_never_both_win(): void
    {
        $fleet = $this->fleetOwnedBy();
        $owner = User::find($fleet->owner_user_id);
        $assignments = app(VehicleAssignments::class);

        for ($round = 0; $round < self::ROUNDS; $round++) {
            [, $a] = $this->fleetDriver($fleet, "Driver A{$round}");
            [, $b] = $this->fleetDriver($fleet, "Driver B{$round}");
            $vehicle = $this->fleetVehicle($fleet, "GP RACE {$round}");
            $assignments->grantPermit($fleet, $vehicle, $a, $owner);
            $assignments->grantPermit($fleet, $vehicle, $b, $owner);

            $results = $this->race([
                fn () => app(VehicleAssignments::class)->assign(Fleet::find($fleet->id), Vehicle::find($vehicle->id), DriverProfile::withoutGlobalScope('user')->find($a->id), User::find($owner->id))->driver_profile_id,
                fn () => app(VehicleAssignments::class)->assign(Fleet::find($fleet->id), Vehicle::find($vehicle->id), DriverProfile::withoutGlobalScope('user')->find($b->id), User::find($owner->id))->driver_profile_id,
            ]);

            // Both may succeed one after the other (the second takes it over from an offline first driver),
            // but at every moment one vehicle has exactly one holder and one open assignment.
            $this->assertGreaterThanOrEqual(1, count(array_filter($results, fn ($r) => $r['ok'])), json_encode($results));
            foreach ($results as $result) {
                if (! $result['ok']) {
                    $this->assertStringStartsWith('App\\Domain\\Fleet\\Exceptions\\FleetActionRefused:', $result['error']);
                }
            }

            $open = FleetVehicleAssignment::query()->open()->where('vehicle_id', $vehicle->id)->get();
            $this->assertCount(1, $open, 'round '.$round);
            $this->assertSame($open->first()->driver_profile_id, Vehicle::find($vehicle->id)->driver_profile_id);
        }
    }

    public function test_one_driver_given_two_vehicles_at_once_ends_with_one_active_vehicle(): void
    {
        $fleet = $this->fleetOwnedBy();
        $owner = User::find($fleet->owner_user_id);
        $assignments = app(VehicleAssignments::class);

        for ($round = 0; $round < self::ROUNDS; $round++) {
            [, $driver] = $this->fleetDriver($fleet, "Driver {$round}");
            $first = $this->fleetVehicle($fleet, "GP ONE {$round}");
            $second = $this->fleetVehicle($fleet, "GP TWO {$round}");
            $assignments->grantPermit($fleet, $first, $driver, $owner);
            $assignments->grantPermit($fleet, $second, $driver, $owner);

            $results = $this->race([
                fn () => app(VehicleAssignments::class)->assign(Fleet::find($fleet->id), Vehicle::find($first->id), DriverProfile::withoutGlobalScope('user')->find($driver->id), User::find($owner->id))->id,
                fn () => app(VehicleAssignments::class)->assign(Fleet::find($fleet->id), Vehicle::find($second->id), DriverProfile::withoutGlobalScope('user')->find($driver->id), User::find($owner->id))->id,
            ]);

            $this->assertSame(2, count(array_filter($results, fn ($r) => $r['ok'])), json_encode($results));
            $this->assertSame(2, Vehicle::where('driver_profile_id', $driver->id)->count());
            $this->assertSame(1, Vehicle::where('driver_profile_id', $driver->id)->where('is_active', true)->count(), 'round '.$round);
        }
    }
}
