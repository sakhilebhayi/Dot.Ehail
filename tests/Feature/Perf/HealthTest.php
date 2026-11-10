<?php

namespace Tests\Feature\Perf;

use App\Domain\Geo\Support\CircuitBreaker;
use App\Domain\Health\HealthReport;
use App\Models\User;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

/**
 * ehail:health, the ops status data source and the alert email (docs/R46_PERF.md section 6).
 */
class HealthTest extends TestCase
{
    use CreatesDispatchFixtures, CreatesEhailFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database']);
        $this->fakeQuotes();
    }

    private function cronRan(): void
    {
        Cache::forever(HealthReport::SCHEDULE_KEY, now()->getTimestamp());
        Cache::forever(HealthReport::TICK_KEY, now()->getTimestamp());
    }

    private function report(): array
    {
        return app(HealthReport::class)->run();
    }

    public function test_a_quiet_healthy_system_reports_ok_in_polling_mode(): void
    {
        $this->cronRan();
        $report = $this->report();

        $this->assertSame('ok', $report['status']);
        $this->assertSame('polling', $report['mode']);
        $this->assertSame('off', $report['checks']['routing']['status'], 'no routing provider is "off", not a fault');
        $this->artisan('ehail:health')->assertExitCode(0);
    }

    public function test_the_cron_records_its_own_heartbeat_for_the_scheduler_and_dispatch_checks(): void
    {
        $this->assertSame('degraded', $this->report()['checks']['dispatch']['status'], 'never ticked');

        Artisan::call('ehail:dispatch-tick');
        event(new CommandFinished('ehail:dispatch-tick', new ArrayInput([]), new NullOutput, 0));
        event(new CommandFinished('schedule:run', new ArrayInput([]), new NullOutput, 0));

        $report = $this->report();
        $this->assertSame('ok', $report['checks']['dispatch']['status']);
        $this->assertSame('ok', $report['checks']['scheduler']['status']);

        $this->travel(11)->minutes();
        $this->assertSame('failing', $this->report()['checks']['dispatch']['status'], 'ten minutes without a tick');
    }

    public function test_queue_lag_and_failed_jobs_are_measured_from_the_tables(): void
    {
        $this->cronRan();
        $now = now()->getTimestamp();
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => $now - 200, 'created_at' => $now - 200]);
        $queue = $this->report()['checks']['queue'];
        $this->assertSame('degraded', $queue['status']);
        $this->assertSame(1, $queue['value']['pending']);
        $this->assertGreaterThanOrEqual(200, $queue['value']['lag_s']);

        DB::table('jobs')->update(['available_at' => $now - 900]);
        $this->assertSame('failing', $this->report()['checks']['queue']['status']);
        $this->artisan('ehail:health')->assertExitCode(2);

        DB::table('jobs')->delete();
        DB::table('failed_jobs')->insert(['uuid' => 'f-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        $this->assertSame('degraded', $this->report()['checks']['failed_jobs']['status']);
    }

    public function test_silent_drivers_searching_rides_left_alone_and_an_open_circuit_are_reported(): void
    {
        $this->cronRan();
        $drivers = collect(range(1, 6))->map(fn ($i) => $this->onlineDriverAt(...$this->northOfPickup(300 * $i)));
        $this->assertSame('ok', $this->report()['checks']['drivers']['status']);

        $this->travel(2)->minutes();
        $this->heartbeat($drivers[0]);
        $drivers = $this->report()['checks']['drivers'];
        $this->assertSame('failing', $drivers['status'], '5 of 6 phones silent');
        $this->assertSame(['online' => 6, 'silent' => 5], $drivers['value']);

        // A searching ride nobody has moved for two minutes (no polls, no cron).
        $ride = $this->requestRide();
        DB::table('ride_offers')->where('ride_id', $ride->id)->delete();
        DB::table('rides')->where('id', $ride->id)->update(['status' => 'searching', 'pending_offer_expires_at' => null, 'next_dispatch_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)]);
        $dispatch = $this->report()['checks']['dispatch'];
        $this->assertSame('degraded', $dispatch['status']);
        $this->assertSame(1, $dispatch['value']['overdue_rides']);

        config(['ehail-geo.routing.driver' => 'osrm']);
        $breaker = app(CircuitBreaker::class);
        foreach (range(1, (int) config('ehail-geo.circuit_breaker.failures')) as $i) {
            $breaker->recordFailure('routing:osrm');
        }
        $this->assertSame(['driver' => 'osrm', 'circuit' => 'open'], $this->report()['checks']['routing']['value']);
    }

    public function test_unprocessed_payment_webhooks_and_failed_notifications_are_reported(): void
    {
        $this->cronRan();
        DB::table('payment_webhook_events')->insert(['provider' => 'paystack', 'event_key' => str_repeat('a', 64), 'event' => 'charge.success', 'payload' => '{}', 'status' => 'received', 'created_at' => now()->subHours(2), 'updated_at' => now()->subHours(2)]);
        $webhooks = $this->report()['checks']['payment_webhooks'];
        $this->assertSame('failing', $webhooks['status']);
        $this->assertSame(1, $webhooks['value']['unprocessed']);

        $user = User::factory()->create();
        foreach (range(1, 6) as $i) {
            DB::table('notification_deliveries')->insert(['event_key' => "e:{$i}", 'user_id' => $user->id, 'channel' => 'email', 'category' => 'trip', 'type' => 'driver_assigned', 'status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
        }
        $notifications = $this->report()['checks']['notifications'];
        $this->assertSame('degraded', $notifications['status']);
        $this->assertSame(6, $notifications['value']['failed_24h']);
    }

    public function test_alerts_email_the_ops_address_once_per_worsening_and_say_when_it_recovered(): void
    {
        config(['ehail_perf.health.alert_email' => 'ops@example.test', 'mail.default' => 'smtp']);
        Mail::fake();
        $this->cronRan();
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->getTimestamp() - 300, 'created_at' => now()->getTimestamp() - 300]);

        $this->artisan('ehail:health --alert')->expectsOutputToContain('Alert emailed')->assertExitCode(1);
        $this->artisan('ehail:health --alert')->doesntExpectOutputToContain('Alert emailed')->assertExitCode(1);
        Mail::assertSentCount(1);

        DB::table('jobs')->delete();
        $this->artisan('ehail:health --alert')->expectsOutputToContain('Alert emailed')->assertExitCode(0);
        Mail::assertSentCount(2);
    }

    public function test_without_an_address_or_a_delivering_mailer_nothing_pretends_to_alert(): void
    {
        Mail::fake();
        $this->artisan('ehail:health --alert')->expectsOutputToContain('No alert address configured')->assertExitCode(1);

        Cache::flush();
        config(['ehail_perf.health.alert_email' => 'ops@example.test', 'mail.default' => 'log']);
        $this->artisan('ehail:health --alert')->expectsOutputToContain('Mail is not delivering on this host')->assertExitCode(1);
        Mail::assertNothingSent();
    }

    public function test_operators_read_the_status_data_source_and_nobody_else_does(): void
    {
        $this->cronRan();
        $this->onlineDriverAt(...$this->northOfPickup(300));

        $body = $this->actingAs($this->makeOperator())->getJson(route('ops.status'))->assertOk()
            ->assertJsonPath('mode', 'polling')
            ->assertJsonStructure(['status', 'checked_at', 'checks' => ['database', 'scheduler', 'dispatch', 'queue', 'failed_jobs', 'drivers', 'payment_webhooks', 'notifications', 'routing', 'geocoding']])
            ->getContent();
        $this->assertStringNotContainsString('@example', $body, 'counts and ages only');

        $this->actingAs(User::factory()->withPersonalTeam()->create())->getJson(route('ops.status'))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson(route('ops.status'))->assertUnauthorized();
    }
}
