<?php

namespace Tests\Feature\Analytics;

use App\Jobs\SendAnalyticsSnapshot;
use App\Services\Analytics\AnalyticsClient;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The signed, queued, never-throwing call to Dot.Analytics. Nothing here may ever slow, break or
 * change the app, so most of these tests prove what does NOT happen.
 */
class AnalyticsClientTest extends TestCase
{
    private const URL = 'https://analytics.test/api/ingest/snapshots';

    private const SECRET = 'analytics-ingest-secret-for-ehail-tests-0001';

    private const COMPANY = '11111111-1111-4111-8111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 00:15:00');
        Cache::flush();
        config(['analytics' => [
            'url' => self::URL, 'secret' => self::SECRET, 'platform' => 'ehail', 'timeout' => 5,
            'circuit' => ['threshold' => 5, 'pause_seconds' => 60],
        ]]);
        $this->fakeAnalytics(Http::response(['data' => ['replayed' => false]], 201));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Replace the fake (a second Http::fake() would sit behind the first). */
    private function fakeAnalytics(mixed $stub = null): void
    {
        Http::swap(new HttpFactory);
        Http::fake($stub === null ? null : ['*' => $stub]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return app(AnalyticsClient::class)->payload(self::COMPANY, Carbon::parse('2026-11-05 13:45:10', 'UTC'), ['rides_completed_7d' => 7]);
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

    // ---- the signed request ---------------------------------------------------------------

    public function test_the_snapshot_is_posted_signed_over_timestamp_dot_raw_body(): void
    {
        $this->assertSame(AnalyticsClient::DONE, app(AnalyticsClient::class)->deliver($this->payload()));

        $sent = $this->sent();
        $this->assertCount(1, $sent);
        $request = $sent[0]['request'];
        $body = $request->body();
        $timestamp = $request->header('X-InfoDot-Timestamp')[0] ?? '';

        $this->assertSame(self::URL, $request->url());
        $this->assertSame('POST', $request->method());
        $this->assertSame((string) now()->timestamp, $timestamp);
        $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET), $request->header('X-InfoDot-Signature')[0] ?? '');
        $this->assertStringContainsString('application/json', $request->header('Content-Type')[0] ?? '');
        $this->assertNotSame('sha256='.hash_hmac('sha256', $body, self::SECRET), $request->header('X-InfoDot-Signature')[0], 'the timestamp is part of what is signed');
    }

    public function test_the_payload_has_exactly_the_contract_shape(): void
    {
        app(AnalyticsClient::class)->deliver($this->payload());

        $json = $this->sent()[0]['json'];
        $this->assertSame(['platform', 'company_uuid', 'taken_at', 'period', 'metrics'], array_keys($json));
        $this->assertSame('ehail', $json['platform']);
        $this->assertSame(self::COMPANY, $json['company_uuid']);
        $this->assertSame('2026-11-05T00:00:00Z', $json['taken_at'], 'start of the UTC day, whatever time it was built');
        $this->assertSame('day', $json['period']);
        $this->assertSame(['rides_completed_7d' => 7], $json['metrics']);
    }

    public function test_taken_at_is_the_start_of_the_utc_day_for_any_zone_the_time_arrives_in(): void
    {
        $client = app(AnalyticsClient::class);

        $johannesburg = $client->payload(self::COMPANY, Carbon::parse('2026-11-05 01:30:00', 'Africa/Johannesburg'), []);
        $newYork = $client->payload(self::COMPANY, Carbon::parse('2026-11-04 22:30:00', 'America/New_York'), []);

        $this->assertSame('2026-11-04T00:00:00Z', $johannesburg['taken_at'], '01:30 SAST is 23:30 UTC the day before');
        $this->assertSame('2026-11-05T00:00:00Z', $newYork['taken_at'], '22:30 EST is 03:30 UTC the next day');
    }

    public function test_a_blank_platform_key_falls_back_to_the_apps_own_key(): void
    {
        config(['analytics.platform' => '']);

        $this->assertSame('ehail', $this->payload()['platform']);
    }

    public function test_blank_config_sends_nothing(): void
    {
        foreach ([['url' => null], ['url' => ''], ['secret' => null], ['secret' => ''], ['secret' => '  '], ['url' => null, 'secret' => null]] as $blank) {
            config(['analytics' => array_merge(config('analytics'), $blank)]);

            $this->assertFalse(app(AnalyticsClient::class)->enabled());
            $this->assertSame(AnalyticsClient::GIVE_UP, app(AnalyticsClient::class)->deliver($this->payload()));

            config(['analytics' => array_merge(config('analytics'), ['url' => self::URL, 'secret' => self::SECRET])]);
        }

        Http::assertNothingSent();
    }

    // ---- Analytics can never hurt the app -------------------------------------------------

    public function test_transient_failures_ask_the_queue_to_retry_and_permanent_ones_do_not(): void
    {
        $payload = $this->payload();
        $client = app(AnalyticsClient::class);
        $this->fakeAnalytics(Http::sequence()->push('x', 503)->push('x', 429)->push('x', 500)->push('x', 422)->push('x', 401)->push('x', 404)->push([], 200)->push([], 201)->pushFailedConnection());

        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload), '503');
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload), '429');
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload), '500');
        $this->assertSame(AnalyticsClient::GIVE_UP, $client->deliver($payload), '422: retrying cannot fix a bad payload');
        $this->assertSame(AnalyticsClient::GIVE_UP, $client->deliver($payload), '401: retrying cannot fix a wrong secret');
        $this->assertSame(AnalyticsClient::GIVE_UP, $client->deliver($payload), '404: unknown company');
        $this->assertSame(AnalyticsClient::DONE, $client->deliver($payload), '200: a replay Analytics already had');
        $this->assertSame(AnalyticsClient::DONE, $client->deliver($payload), '201');
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload), 'connection failure');
    }

    public function test_no_failure_mode_throws_out_of_the_client_or_the_job(): void
    {
        foreach ([
            fn () => Http::response('boom', 500),
            fn () => Http::response('slow down', 429),
            fn () => Http::response('nope', 401),
            fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds'),
            fn () => throw new \RuntimeException('something unexpected'),
            fn () => throw new \Error('even a PHP error'),
        ] as $failure) {
            Cache::flush();
            $calls = 0;
            $this->fakeAnalytics(function () use ($failure, &$calls) {
                $calls++;

                return $failure();
            });

            (new SendAnalyticsSnapshot($this->payload()))->handle(app(AnalyticsClient::class));
            dispatch_sync(new SendAnalyticsSnapshot($this->payload()));

            $this->assertSame(2, $calls, 'the call was attempted and its failure stayed contained');
        }
    }

    public function test_a_broken_cache_does_not_break_delivery(): void
    {
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('add')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('increment')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('forget')->andThrow(new \RuntimeException('cache down'));
        $this->fakeAnalytics(Http::response('boom', 500));

        $this->assertSame(AnalyticsClient::RETRY, app(AnalyticsClient::class)->deliver($this->payload()));

        $this->fakeAnalytics(Http::response([], 201));
        $this->assertSame(AnalyticsClient::DONE, app(AnalyticsClient::class)->deliver($this->payload()));
    }

    public function test_the_http_call_uses_a_five_second_timeout(): void
    {
        $this->fakeAnalytics(function (Request $request, array $options) {
            $this->assertSame(5, (int) $options['timeout']);
            $this->assertSame(5, (int) $options['connect_timeout']);

            return Http::response([], 201);
        });

        $this->assertSame(AnalyticsClient::DONE, app(AnalyticsClient::class)->deliver($this->payload()));
    }

    public function test_the_job_retries_three_times_with_backoff(): void
    {
        $job = new SendAnalyticsSnapshot($this->payload());

        $this->assertSame(3, $job->tries);
        $this->assertSame(20, $job->timeout);
        $this->assertCount(2, $job->backoff());
        $this->assertSame([30, 120], $job->backoff());
    }

    public function test_a_failing_attempt_is_released_with_the_backoff_and_the_last_one_is_abandoned_quietly(): void
    {
        $this->fakeAnalytics(Http::response('boom', 500));

        foreach ([[1, 30], [2, 120]] as [$attempt, $delay]) {
            $queueJob = \Mockery::mock(Job::class);
            $queueJob->shouldReceive('attempts')->andReturn($attempt);
            $queueJob->shouldReceive('release')->once()->with($delay);
            $queueJob->shouldReceive('isReleased')->andReturn(true);
            $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(true);

            $job = new SendAnalyticsSnapshot($this->payload());
            $job->setJob($queueJob);
            $job->handle(app(AnalyticsClient::class));
        }

        Log::spy();
        $last = \Mockery::mock(Job::class);
        $last->shouldReceive('attempts')->andReturn(3);
        $last->shouldReceive('release')->never();
        $job = new SendAnalyticsSnapshot($this->payload());
        $job->setJob($last);
        $job->handle(app(AnalyticsClient::class));

        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'abandoned'))->once();
    }

    public function test_a_retry_is_signed_afresh_with_a_new_timestamp_but_the_same_body(): void
    {
        $payload = $this->payload();

        app(AnalyticsClient::class)->deliver($payload);
        Carbon::setTestNow(now()->addMinutes(2));
        app(AnalyticsClient::class)->deliver($payload);

        $sent = $this->sent();
        $this->assertCount(2, array_unique(array_map(fn ($e) => $e['request']->header('X-InfoDot-Timestamp')[0], $sent)));
        $this->assertCount(2, array_unique(array_map(fn ($e) => $e['request']->header('X-InfoDot-Signature')[0], $sent)));
        $this->assertCount(1, array_unique(array_map(fn ($e) => $e['request']->body(), $sent)), 'the body, and so taken_at, is identical on a retry');
    }

    public function test_logs_never_carry_the_secret_or_a_metric_value(): void
    {
        Log::spy();
        $this->fakeAnalytics(Http::response('boom', 500));
        $payload = app(AnalyticsClient::class)->payload(self::COMPANY, now(), ['rides_completed_7d' => 987654321]);

        app(AnalyticsClient::class)->deliver($payload);

        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context = []) {
            $line = $message.json_encode($context);

            return ! str_contains($line, self::SECRET) && ! str_contains($line, '987654321');
        });
    }

    // ---- circuit breaker ------------------------------------------------------------------

    public function test_five_consecutive_failures_pause_calls_for_sixty_seconds_then_it_recovers(): void
    {
        $client = app(AnalyticsClient::class);
        $payload = $this->payload();
        $this->fakeAnalytics(Http::response('boom', 500));

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload));
        }
        Http::assertSentCount(5);

        $this->assertSame(AnalyticsClient::PAUSED, $client->deliver($payload), 'the breaker is open');
        $this->assertSame(AnalyticsClient::PAUSED, $client->deliver($payload));
        $this->assertSame(60, $client->pausedFor());
        Http::assertSentCount(5);

        Carbon::setTestNow(now()->addSeconds(59));
        $this->assertSame(AnalyticsClient::PAUSED, $client->deliver($payload));
        Http::assertSentCount(5);

        Carbon::setTestNow(now()->addSeconds(2));
        $this->fakeAnalytics(Http::response([], 201));
        $this->assertSame(AnalyticsClient::DONE, $client->deliver($payload), 'the probe after the pause is allowed through and closes the breaker');
        $this->assertSame(AnalyticsClient::DONE, $client->deliver($payload));
        $this->assertSame(0, $client->pausedFor());
        Http::assertSentCount(2);
    }

    public function test_a_failed_probe_reopens_the_breaker_straight_away(): void
    {
        $client = app(AnalyticsClient::class);
        $this->fakeAnalytics(Http::response('boom', 500));
        for ($i = 0; $i < 5; $i++) {
            $client->deliver($this->payload());
        }

        Carbon::setTestNow(now()->addSeconds(61));
        $this->assertSame(AnalyticsClient::RETRY, $client->deliver($this->payload()), 'the probe goes out and fails');
        $this->assertSame(AnalyticsClient::PAUSED, $client->deliver($this->payload()), 'one more failure is enough to reopen it');
        Http::assertSentCount(6);
    }

    public function test_a_success_resets_the_failure_count(): void
    {
        $client = app(AnalyticsClient::class);
        $payload = $this->payload();

        $this->fakeAnalytics(Http::response('boom', 500));
        for ($i = 0; $i < 4; $i++) {
            $client->deliver($payload);
        }
        $this->fakeAnalytics(Http::response([], 201));
        $client->deliver($payload);
        $this->fakeAnalytics(Http::response('boom', 500));
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(AnalyticsClient::RETRY, $client->deliver($payload));
        }

        $this->assertSame(0, $client->pausedFor(), '4 failures, a success, then 4 more never reach the threshold of 5');
    }

    public function test_a_refusal_counts_as_an_answer_not_an_outage(): void
    {
        $client = app(AnalyticsClient::class);
        $this->fakeAnalytics(Http::response('nope', 401));

        for ($i = 0; $i < 8; $i++) {
            $this->assertSame(AnalyticsClient::GIVE_UP, $client->deliver($this->payload()));
        }

        $this->assertSame(0, $client->pausedFor());
        Http::assertSentCount(8);
    }

    public function test_the_breaker_state_is_this_clients_own_not_dot_notifys(): void
    {
        Cache::put('notify.circuit.open_until', now()->timestamp + 600, 700);

        $this->assertSame(0, app(AnalyticsClient::class)->pausedFor());
        $this->assertSame(AnalyticsClient::DONE, app(AnalyticsClient::class)->deliver($this->payload()));
    }

    public function test_an_open_breaker_makes_the_job_wait_without_calling_out(): void
    {
        $this->fakeAnalytics(Http::response('boom', 500));
        for ($i = 0; $i < 5; $i++) {
            app(AnalyticsClient::class)->deliver($this->payload());
        }
        Http::assertSentCount(5);

        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->with(60);
        $job = new SendAnalyticsSnapshot($this->payload());
        $job->setJob($queueJob);
        $job->handle(app(AnalyticsClient::class));

        Http::assertSentCount(5);
    }

    public function test_a_client_that_throws_still_cannot_make_the_job_throw(): void
    {
        $client = \Mockery::mock(AnalyticsClient::class);
        $client->shouldReceive('deliver')->andThrow(new \RuntimeException('unexpected'));
        $client->shouldReceive('pausedFor')->andReturn(0);

        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->with(30);
        $job = new SendAnalyticsSnapshot($this->payload());
        $job->setJob($queueJob);
        $job->handle($client);

        $this->assertTrue(true, 'no exception escaped and the attempt was released for a retry');
    }
}
