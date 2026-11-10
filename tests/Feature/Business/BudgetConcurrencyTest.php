<?php

namespace Tests\Feature\Business;

use App\Domain\Business\BusinessClock;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessSpendCounter;
use App\Domain\Business\Models\BusinessTrip;
use App\Domain\Rides\RideRequestService;
use App\Models\Ride;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BusinessFixtures;
use Tests\TestCase;
use Throwable;

/**
 * Budget consumption under real concurrency: each ride request runs in its own forked PHP process with its
 * own SQLite connection to one shared database file, released together. The conditional UPDATE in
 * SpendLimits must let exactly as many rides through as the budget covers, never one more.
 */
class BudgetConcurrencyTest extends TestCase
{
    use BusinessFixtures;

    private string $path;

    /** The connection under test: SQLite (file) by default, or the PostgreSQL test database under bin/test-pgsql.sh. */
    private string $db;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are needed to run contenders in separate processes.');
        }

        $this->path = sys_get_temp_dir().'/ehail-business-race-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
        $this->db = (string) config('database.default');

        // On PostgreSQL every contender opens its own connection: a forked child must never share the parent's
        // socket (it hung the PostgreSQL run, R-46 perf D-1513).
        if ($this->db === 'sqlite') {
            touch($this->path);

            config([
                'database.connections.sqlite.database' => $this->path,
                'database.connections.sqlite.busy_timeout' => 10000,
                'database.connections.sqlite.journal_mode' => 'wal',
                'database.connections.sqlite.transaction_mode' => 'IMMEDIATE',
            ]);
        }

        DB::purge($this->db);
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->setUpBusiness();
    }

    protected function tearDown(): void
    {
        if (($this->db ?? 'sqlite') !== 'sqlite') {
            // The contenders committed for real: leave the shared test database clean and migrated.
            DB::purge($this->db);
            $this->artisan('migrate:fresh')->assertExitCode(0);
        }

        DB::disconnect($this->db ?? 'sqlite');

        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink(($this->path ?? '/nonexistent').$suffix);
        }

        parent::tearDown();
    }

    public function test_concurrent_requests_never_overspend_a_department_budget(): void
    {
        [$account] = $this->businessAccount([], 10_000_000);
        $sales = $this->department($account, 'Sales', 10_000); // R100
        $travellers = collect(range(1, 5))->map(fn () => $this->member($account, BusinessMember::TRAVELLER, ['department_id' => $sales->id]));
        $quotes = $travellers->map(fn (BusinessMember $m) => $this->quotes->issue($m->user, ['totalMinor' => 4000])->id);

        $results = $this->race($travellers->map(fn (BusinessMember $m, int $i) => fn () => app(RideRequestService::class)
            ->request($m->user, $quotes[$i], 'business_account', (string) $m->payment_method_id)->id)->all());

        $this->assertSame(2, count(array_filter($results, fn ($r) => $r['ok'])), json_encode($results));

        foreach ($results as $result) {
            if (! $result['ok']) {
                $this->assertMatchesRegularExpression('/DispatchRefused: .*budget/', $result['error']);
            }
        }

        $counter = BusinessSpendCounter::where('scope', 'department')->where('scope_id', $sales->id)->where('period', BusinessClock::monthKey())->sole();
        $this->assertSame(8000, $counter->reserved_minor);
        $this->assertSame(2, Ride::count());
        $this->assertSame(2, BusinessTrip::where('status', BusinessTrip::BOOKED)->count());
    }

    public function test_concurrent_requests_never_pass_the_credit_limit(): void
    {
        [$account] = $this->businessAccount([], 10_000); // R100 credit
        $travellers = collect(range(1, 4))->map(fn () => $this->member($account));
        $quotes = $travellers->map(fn (BusinessMember $m) => $this->quotes->issue($m->user, ['totalMinor' => 3000])->id);

        $results = $this->race($travellers->map(fn (BusinessMember $m, int $i) => fn () => app(RideRequestService::class)
            ->request($m->user, $quotes[$i], 'business_account', (string) $m->payment_method_id)->id)->all());

        $this->assertSame(3, count(array_filter($results, fn ($r) => $r['ok'])), json_encode($results));
        $this->assertSame(9000, (int) BusinessSpendCounter::where('scope', 'credit')->where('scope_id', $account->id)->value('reserved_minor'));
    }

    /**
     * @param  list<Closure(): mixed>  $contenders
     * @return list<array{ok: bool, value?: mixed, error?: string}>
     */
    private function race(array $contenders): array
    {
        DB::disconnect($this->db);
        $start = microtime(true) + 0.25;
        $pids = [];

        foreach ($contenders as $i => $contender) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'fork failed');

            if ($pid === 0) {
                DB::purge($this->db);

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

        DB::purge($this->db);

        return $results;
    }
}
