<?php

namespace Tests\Feature\Analytics;

use App\Jobs\SendAnalyticsSnapshot;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\RideRating;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Analytics\AnalyticsClient;
use App\Services\Analytics\MetricsCollector;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ehail:publish-metrics end to end: one queued, signed job per company, the same snapshot
 * identity on every run of a day, and no row-level data anywhere in what leaves the app.
 */
class PublishMetricsCommandTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://analytics.test/api/ingest/snapshots';

    private const SECRET = 'analytics-ingest-secret-for-ehail-tests-0001';

    private const COMPANY_A = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const COMPANY_B = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private const OPERATOR = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

    private const CONTRACT = ['rides_requested_7d', 'rides_completed_7d', 'rides_expired_7d', 'drivers_online', 'avg_rating'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 00:15:00');
        Cache::flush();
        config([
            'analytics' => ['url' => self::URL, 'secret' => self::SECRET, 'platform' => 'ehail', 'timeout' => 5, 'company_uuid' => null,
                'circuit' => ['threshold' => 5, 'pause_seconds' => 60]],
        ]);
        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response(['data' => ['replayed' => false]], 201)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(?string $uuid, array $attributes = []): User
    {
        $user = User::factory()->withPersonalTeam()->create($attributes);

        if ($uuid !== null) {
            $user->currentTeam->forceFill(['infodot_team_uuid' => $uuid])->save();
        }

        return $user->fresh();
    }

    private function fleet(User $owner, array $attributes = []): Fleet
    {
        return Fleet::factory()->create($attributes + ['owner_user_id' => $owner->id, 'status' => 'approved']);
    }

    private function driver(Fleet $fleet, array $user = [], array $profile = []): User
    {
        $driver = User::factory()->withPersonalTeam()->create($user);
        DriverProfile::factory()->create($profile + ['user_id' => $driver->id, 'fleet_id' => $fleet->id, 'status' => 'approved', 'is_online' => true]);

        return $driver;
    }

    /** @return list<array{request: Request, json: array<string, mixed>}> */
    private function sent(): array
    {
        $out = [];
        foreach (Http::recorded() as [$request]) {
            $out[] = ['request' => $request, 'json' => json_decode($request->body(), true)];
        }

        return $out;
    }

    private function twoCompanies(): array
    {
        $a = $this->owner(self::COMPANY_A);
        $b = $this->owner(self::COMPANY_B);
        $this->driver($this->fleet($a));
        $this->driver($this->fleet($b));
        $this->driver($this->fleet($b));

        return [$a, $b];
    }

    // ---- one job per company ---------------------------------------------------------------

    public function test_one_job_is_queued_per_company_with_the_final_payload(): void
    {
        Bus::fake();
        $this->twoCompanies();
        $this->driver($this->fleet($this->owner(null)));   // an unlinked fleet owner is nobody's company
        $this->driver($this->fleet($this->owner(self::OPERATOR), ['status' => 'pending']));   // a pending fleet publishes nothing

        $this->artisan('ehail:publish-metrics')->assertSuccessful()->expectsOutputToContain('Queued 2 of 2 snapshot(s) for 2026-11-05T00:00:00Z.');

        Bus::assertDispatchedTimes(SendAnalyticsSnapshot::class, 2);
        $payloads = [];
        Bus::assertDispatched(SendAnalyticsSnapshot::class, function (SendAnalyticsSnapshot $job) use (&$payloads) {
            $payloads[$job->payload['company_uuid']] = $job->payload;

            return true;
        });
        ksort($payloads);
        $this->assertSame([self::COMPANY_A, self::COMPANY_B], array_keys($payloads));
        $this->assertSame(['platform', 'company_uuid', 'taken_at', 'period', 'metrics'], array_keys($payloads[self::COMPANY_A]));
        $this->assertSame(1, $payloads[self::COMPANY_A]['metrics']['drivers_online']);
        $this->assertSame(2, $payloads[self::COMPANY_B]['metrics']['drivers_online']);
        Http::assertNothingSent();
    }

    public function test_with_the_queue_running_inline_each_company_gets_one_signed_request_with_only_contract_keys(): void
    {
        $this->twoCompanies();

        $this->artisan('ehail:publish-metrics')->assertSuccessful();

        $sent = $this->sent();
        $this->assertCount(2, $sent);
        $byCompany = [];
        foreach ($sent as ['request' => $request, 'json' => $json]) {
            $timestamp = $request->header('X-InfoDot-Timestamp')[0];
            $this->assertSame(self::URL, $request->url());
            $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), self::SECRET), $request->header('X-InfoDot-Signature')[0]);
            $this->assertSame(['platform', 'company_uuid', 'taken_at', 'period', 'metrics'], array_keys($json));
            $this->assertSame('ehail', $json['platform']);
            $this->assertSame('2026-11-05T00:00:00Z', $json['taken_at']);
            $this->assertSame('day', $json['period']);
            $this->assertSame([], array_diff(array_keys($json['metrics']), self::CONTRACT), 'only contract keys');
            $byCompany[$json['company_uuid']] = $json['metrics'];
        }
        $this->assertSame(1, $byCompany[self::COMPANY_A]['drivers_online']);
        $this->assertSame(2, $byCompany[self::COMPANY_B]['drivers_online']);
    }

    // ---- replay safety ---------------------------------------------------------------------

    public function test_running_twice_on_the_same_day_sends_the_same_snapshot_identity_even_hours_apart(): void
    {
        $this->twoCompanies();

        $this->artisan('ehail:publish-metrics')->assertSuccessful();
        Carbon::setTestNow('2026-11-05 17:42:09');
        $this->artisan('ehail:publish-metrics')->assertSuccessful();

        $sent = $this->sent();
        $this->assertCount(4, $sent);
        $identities = array_map(fn ($e) => $e['json']['platform'].'|'.$e['json']['company_uuid'].'|'.$e['json']['taken_at'].'|'.$e['json']['period'], $sent);
        $this->assertCount(2, array_unique($identities), 'two companies, one identity each, however often it runs that day');
        foreach ($sent as $e) {
            $this->assertSame('2026-11-05T00:00:00Z', $e['json']['taken_at']);
        }
    }

    public function test_the_next_utc_day_is_a_new_snapshot_and_a_late_night_run_belongs_to_its_own_utc_day(): void
    {
        $this->twoCompanies();

        Carbon::setTestNow('2026-11-05 23:59:59');
        $this->artisan('ehail:publish-metrics')->assertSuccessful();
        Carbon::setTestNow('2026-11-06 00:00:00');
        $this->artisan('ehail:publish-metrics')->assertSuccessful();

        $days = array_unique(array_map(fn ($e) => $e['json']['taken_at'], $this->sent()));
        sort($days);
        $this->assertSame(['2026-11-05T00:00:00Z', '2026-11-06T00:00:00Z'], $days);
    }

    public function test_a_late_retry_resends_the_payload_it_was_queued_with_not_fresh_numbers(): void
    {
        Bus::fake();
        [$a] = $this->twoCompanies();
        $this->artisan('ehail:publish-metrics')->assertSuccessful();
        $job = Bus::dispatched(SendAnalyticsSnapshot::class)->first(fn ($j) => $j->payload['company_uuid'] === self::COMPANY_A);

        $this->driver($this->fleet($a));              // the world moves on...
        $this->driver($this->fleet($a));
        Carbon::setTestNow('2026-11-05 09:00:00');    // ...and the retry happens hours later
        $job->handle(app(AnalyticsClient::class));

        $body = $this->sent()[0]['json'];
        $this->assertSame(1, $body['metrics']['drivers_online']);
        $this->assertSame('2026-11-05T00:00:00Z', $body['taken_at']);
    }

    // ---- options ---------------------------------------------------------------------------

    public function test_company_limits_the_run_to_one_company(): void
    {
        $this->twoCompanies();

        $this->artisan('ehail:publish-metrics', ['--company' => strtoupper(self::COMPANY_B)])->assertSuccessful();

        $this->assertSame([self::COMPANY_B], array_map(fn ($e) => $e['json']['company_uuid'], $this->sent()));
    }

    public function test_company_with_nothing_to_publish_sends_nothing_and_a_malformed_uuid_is_an_error(): void
    {
        $this->twoCompanies();

        $this->artisan('ehail:publish-metrics', ['--company' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'])
            ->assertSuccessful()->expectsOutputToContain('No company has anything to publish for that uuid.');
        $this->artisan('ehail:publish-metrics', ['--company' => 'not-a-uuid'])->assertFailed();

        Http::assertNothingSent();
    }

    public function test_dry_run_prints_the_numbers_and_queues_and_sends_nothing(): void
    {
        Bus::fake();
        $this->twoCompanies();

        $this->artisan('ehail:publish-metrics', ['--dry-run' => true])->assertSuccessful()
            ->expectsOutputToContain(self::COMPANY_A.' {"rides_completed_7d":0,"drivers_online":1}')
            ->expectsOutputToContain('Dry run: 2 snapshot(s) for 2026-11-05T00:00:00Z would be sent. Nothing was queued.');

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_dry_run_works_before_the_secret_is_set_and_still_sends_nothing(): void
    {
        config(['analytics.secret' => null, 'analytics.url' => null]);
        $this->twoCompanies();

        $this->artisan('ehail:publish-metrics', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain(self::COMPANY_B);

        Http::assertNothingSent();
    }

    // ---- nothing until configured -----------------------------------------------------------

    public function test_nothing_is_collected_queued_or_sent_while_the_url_or_the_secret_is_blank(): void
    {
        Bus::fake();
        $this->twoCompanies();
        $this->mock(MetricsCollector::class)->shouldNotReceive('snapshots');

        foreach ([['url' => ''], ['secret' => ''], ['url' => null, 'secret' => null]] as $blank) {
            config(['analytics' => array_merge(config('analytics'), ['url' => self::URL, 'secret' => self::SECRET], $blank)]);

            $this->artisan('ehail:publish-metrics')->assertSuccessful()->expectsOutputToContain('publishing is off');
        }

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    // ---- Analytics can never hurt the app ----------------------------------------------------

    public function test_an_analytics_outage_leaves_the_command_the_data_and_the_app_untouched(): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response('boom', 503)]);
        $this->twoCompanies();
        $rides = Ride::query()->count();
        $drivers = DriverProfile::withoutGlobalScopes()->count();

        $this->artisan('ehail:publish-metrics')->assertSuccessful();

        $this->assertSame($rides, Ride::query()->count());
        $this->assertSame($drivers, DriverProfile::withoutGlobalScopes()->count());
        $this->assertGreaterThanOrEqual(2, count($this->sent()));
    }

    public function test_a_connection_failure_or_a_dead_queue_does_not_fail_the_command(): void
    {
        Http::swap(new HttpFactory);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));
        $this->twoCompanies();
        $this->artisan('ehail:publish-metrics')->assertSuccessful();

        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue backend down'));
        });
        $this->artisan('ehail:publish-metrics')->assertSuccessful()->expectsOutputToContain('Queued 0 of 2');
    }

    public function test_a_collector_bug_is_logged_and_fails_only_this_command_with_nothing_sent(): void
    {
        $this->mock(MetricsCollector::class)->shouldReceive('snapshots')->andThrow(new \RuntimeException('bad query'));

        $this->artisan('ehail:publish-metrics')->assertFailed()->expectsOutputToContain('Nothing was sent.');

        Http::assertNothingSent();
    }

    // ---- aggregates only ----------------------------------------------------------------------

    public function test_no_row_level_data_is_in_the_raw_body(): void
    {
        config(['analytics.company_uuid' => self::OPERATOR]);
        $long = fn (string $label, string $fill) => 'SENTINEL-'.$label.'-'.str_repeat($fill, 60);
        $sentinels = [
            'owner_name' => $long('OWNER-NAME', 'n'),
            'owner_email' => 'sentinel-owner-email-'.str_repeat('e', 40).'@example.test',
            'fleet_name' => $long('FLEET-NAME', 'f'),
            'driver_name' => $long('DRIVER-NAME', 'd'),
            'driver_email' => 'sentinel-driver-email-'.str_repeat('m', 40).'@example.test',
            'passenger_name' => $long('PASSENGER-NAME', 'p'),
            'passenger_email' => 'sentinel-passenger-email-'.str_repeat('q', 40).'@example.test',
            'licence' => $long('LICENCE', 'l'),
            'id_number' => $long('IDNUMBER', 'i'),
            'plate' => 'SENTINELPLATE'.str_repeat('Z', 30),
            'pickup' => $long('PICKUP-ADDRESS', 'u'),
            'dropoff' => $long('DROPOFF-ADDRESS', 'o'),
            'comment' => $long('RATING-COMMENT', 'c'),
            'cancel_note' => $long('CANCEL-NOTE', 'k'),
        ];
        $owner = $this->owner(self::COMPANY_A, ['name' => $sentinels['owner_name'], 'email' => $sentinels['owner_email']]);
        $owner->currentTeam->forceFill(['name' => $long('TEAM-NAME', 't')])->save();
        $fleet = $this->fleet($owner, ['name' => $sentinels['fleet_name']]);
        $driver = $this->driver($fleet, ['name' => $sentinels['driver_name'], 'email' => $sentinels['driver_email']], ['license_number' => $sentinels['licence'], 'id_number' => $sentinels['id_number']]);
        $passenger = User::factory()->create(['name' => $sentinels['passenger_name'], 'email' => $sentinels['passenger_email']]);
        Vehicle::factory()->create(['driver_profile_id' => DriverProfile::withoutGlobalScopes()->where('user_id', $driver->id)->value('id'), 'plate_number' => $sentinels['plate']]);
        $ride = Ride::create([
            'passenger_id' => $passenger->id, 'driver_id' => $driver->id, 'status' => 'completed', 'completed_at' => Carbon::parse('2026-11-02 10:00:00'),
            'pickup_address' => $sentinels['pickup'], 'dropoff_address' => $sentinels['dropoff'], 'estimated_fare' => '777.77', 'final_fare' => '777.77',
            'cancel_note' => $sentinels['cancel_note'], 'vehicle_type' => 'standard',
        ]);
        RideRating::create(['ride_id' => $ride->id, 'rated_by' => $passenger->id, 'rated_user_id' => $driver->id, 'rated_role' => 'driver', 'rating' => 4, 'comment' => $sentinels['comment']]);

        $this->artisan('ehail:publish-metrics')->assertSuccessful();

        $sent = $this->sent();
        $this->assertCount(2, $sent, 'the fleet company and the operating company');
        foreach ($sent as ['request' => $request, 'json' => $json]) {
            $body = $request->body();
            foreach ($sentinels as $label => $sentinel) {
                $this->assertStringNotContainsString($sentinel, $body, "{$label} leaked");
            }
            foreach (['SENTINEL', 'example.test', '777.77', 'address', 'plate', 'licen', 'comment', 'email', 'name', 'fare'] as $needle) {
                $this->assertStringNotContainsString($needle, $body, "{$needle} leaked");
            }
            $this->assertSame(['platform', 'company_uuid', 'taken_at', 'period', 'metrics'], array_keys($json));
            $this->assertSame([], array_diff(array_keys($json['metrics']), self::CONTRACT));
            foreach ($json['metrics'] as $key => $value) {
                $this->assertTrue(is_int($value) || ($key === 'avg_rating' && is_float($value)), "{$key} must be a number");
            }
        }
        $fleetBody = collect($sent)->first(fn ($e) => $e['json']['company_uuid'] === self::COMPANY_A)['json'];
        $this->assertEquals(['rides_completed_7d' => 1, 'drivers_online' => 1, 'avg_rating' => 4], $fleetBody['metrics'], 'a whole-number average is still a JSON number');
        $this->assertStringNotContainsString(self::OPERATOR, json_encode($fleetBody), 'the fleet snapshot carries only its own company');
    }

    public function test_the_operating_company_snapshot_is_published_only_when_configured(): void
    {
        $this->twoCompanies();

        $this->artisan('ehail:publish-metrics')->assertSuccessful();
        $this->assertCount(2, $this->sent(), 'two fleet companies and no operator');

        config(['analytics.company_uuid' => self::OPERATOR]);
        $this->artisan('ehail:publish-metrics')->assertSuccessful();
        $platform = collect($this->sent())->first(fn ($e) => $e['json']['company_uuid'] === self::OPERATOR);

        $this->assertNotNull($platform);
        $this->assertSame(['rides_requested_7d', 'rides_completed_7d', 'rides_expired_7d', 'drivers_online'], array_keys($platform['json']['metrics']), 'every key but the rating nobody has given');
        $this->assertSame(3, $platform['json']['metrics']['drivers_online']);
    }

    // ---- schedule ----------------------------------------------------------------------------

    public function test_it_is_scheduled_once_a_day_just_after_midnight_utc(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'ehail:publish-metrics'));

        $this->assertNotNull($event, 'ehail:publish-metrics is on the schedule');
        $this->assertSame('15 0 * * *', $event->expression);
        $this->assertSame('UTC', (string) $event->timezone);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_the_schedule_stays_on_utc_even_when_the_app_timezone_is_not(): void
    {
        config(['app.timezone' => 'Africa/Johannesburg']);
        $this->app->forgetInstance(Schedule::class);
        Facade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'ehail:publish-metrics'));

        Carbon::setTestNow(Carbon::parse('2026-11-05 00:15:00', 'UTC'));
        $dueAtUtcMidnight = $event->isDue($this->app);
        Carbon::setTestNow(Carbon::parse('2026-11-04 22:15:00', 'UTC')); // 00:15 in Johannesburg
        $dueAtLocalMidnight = $event->isDue($this->app);

        $this->assertTrue($dueAtUtcMidnight);
        $this->assertFalse($dueAtLocalMidnight);
    }
}
