<?php

namespace Tests\Feature\Perf;

use App\Domain\Payments\Webhooks\PaystackWebhookInbox;
use App\Domain\Perf\SystemInvariants;
use App\Models\RidePayment;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesPaystack;
use Tests\TestCase;
use Throwable;

/**
 * A burst of duplicate Paystack webhooks delivered at the same instant by separate PHP processes (as separate
 * PHP-FPM workers would receive them), each with its own database connection: SQLite file (WAL, IMMEDIATE) or the
 * PostgreSQL test database under bin/test-pgsql.sh. Exactly one event row, one payment transition, one journal.
 */
class WebhookBurstConcurrencyTest extends TestCase
{
    use FakesPaystack;

    private string $path;

    private string $db;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix are needed to run contenders in separate processes.');
        }

        $this->path = sys_get_temp_dir().'/ehail-webhooks-'.getmypid().'-'.bin2hex(random_bytes(4)).'.sqlite';
        $this->db = (string) config('database.default');

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
        config(['queue.default' => 'sync']);
        $this->enablePaystack();
        // Never the real Paystack: verify calls answer from the published response shapes.
        Http::preventStrayRequests();
        $this->fakePaystack();
    }

    protected function tearDown(): void
    {
        if (($this->db ?? 'sqlite') !== 'sqlite') {
            DB::purge($this->db);
            $this->artisan('migrate:fresh')->assertExitCode(0);
        }

        DB::disconnect($this->db ?? 'sqlite');

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
            $results[$i] = unserialize(file_get_contents("{$this->path}.result.{$i}"));
            unlink("{$this->path}.result.{$i}");
        }

        DB::purge($this->db);

        return $results;
    }

    public function test_six_identical_webhooks_at_once_are_one_event_and_one_payment(): void
    {
        $ride = $this->completedRide();
        $payment = $this->paymentFor($ride);
        $payload = $this->chargeSuccess($payment);

        $results = $this->race(array_fill(0, 6, fn () => app(PaystackWebhookInbox::class)->receive($payload)));

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], 'every delivery is acknowledged, none crashes: '.($result['error'] ?? ''));
        }

        $this->assertSame(1, DB::table('payment_webhook_events')->count(), 'one stored event');
        $this->assertSame(RidePayment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(1, DB::table('ride_payments')->where('ride_id', $ride->id)->where('status', RidePayment::STATUS_PAID)->count());
        $this->assertTrue(app(SystemInvariants::class)->check()['ok']);
    }
}
