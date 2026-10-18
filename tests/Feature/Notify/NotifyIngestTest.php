<?php

namespace Tests\Feature\Notify;

use App\Jobs\SendNotifyEvent;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\RideNoticeNotification;
use App\Services\Ehail\RideLifecycle;
use App\Services\Notify\NotifyClient;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Monolog\Handler\TestHandler;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

/**
 * Dot.Ehail tells Dot.Notify about the ride and driver events it already
 * raises. Nothing here may slow, break or change a ride action or the in-app
 * notification, and no personal identifier may ever leave in a payload.
 */
class NotifyIngestTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    private const URL = 'https://notify.test/api/ingest/events';

    private const SECRET = 'notify-ingest-secret-for-ehail-tests-0001';

    private const PASSENGER_UUID = '11111111-1111-4111-8111-111111111111';

    private const DRIVER_UUID = '22222222-2222-4222-8222-222222222222';

    private const OPERATOR_UUID = '33333333-3333-4333-8333-333333333333';

    // Sentinels that must never appear in a request body.
    private const PICKUP = 'PICKUP-SENTINEL-ADDRESS-QWERTY';

    private const DROPOFF = 'DROPOFF-SENTINEL-ADDRESS-ASDFGH';

    private const LICENCE = 'LICENCE-SENTINEL-ZXCVBN-7781';

    private const ID_NUMBER = 'IDNUMBER-SENTINEL-POIUYT-9902';

    private const PLATE = 'PLATE SENTINEL LKJHGF 5513';

    private const NOTE = 'NOTE-SENTINEL call me on my own number MNBVCX';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-05 09:00:00');
        Cache::flush();
        config([
            'notify' => ['url' => self::URL, 'secret' => self::SECRET, 'platform' => 'ehail', 'timeout' => 5,
                'circuit' => ['threshold' => 5, 'pause_seconds' => 60]],
        ]);
        url()->forceRootUrl('https://ehail.test');
        $this->fakeNotify(Http::response(['id' => 1, 'status' => 'routed'], 202));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Replace the fake (a second Http::fake() would sit behind the first). */
    private function fakeNotify(mixed $stub = null): void
    {
        Http::swap(new HttpFactory);
        Http::fake($stub === null ? null : ['*' => $stub]);
    }

    private function member(?string $uuid, string $name = 'Person'): User
    {
        $user = User::factory()->withPersonalTeam()->create(['name' => $name]);
        $user->currentTeam->forceFill(['infodot_team_uuid' => $uuid ?? (string) Str::uuid()])->save();

        return $user->fresh();
    }

    private function driver(?string $uuid = self::DRIVER_UUID, string $name = 'Thandi Driver'): User
    {
        $user = $this->member($uuid, $name);
        $profile = DriverProfile::factory()->for($user)->create([
            'status' => 'approved', 'is_online' => true,
            'license_number' => self::LICENCE.Str::random(4), 'id_number' => self::ID_NUMBER.Str::random(4),
        ]);
        Vehicle::factory()->for($profile, 'driverProfile')->create([
            'is_active' => true, 'type' => 'standard', 'make' => 'Toyota', 'model' => 'Corolla', 'color' => 'White',
            'plate_number' => self::PLATE.Str::random(3),
        ]);

        return $user;
    }

    private function ride(User $passenger, array $extra = []): Ride
    {
        return $this->makeRide($extra + [
            'pickup_address' => self::PICKUP, 'dropoff_address' => self::DROPOFF,
            'pickup_lat' => -33.9249271, 'pickup_lng' => 18.4241461, 'dropoff_lat' => -33.9188112, 'dropoff_lng' => 18.4233921,
        ], $passenger);
    }

    /** @return list<array{request: Request, json: array<string, mixed>}> */
    private function sent(?string $event = null): array
    {
        $out = [];
        foreach (Http::recorded() as [$request]) {
            $json = json_decode($request->body(), true);
            if ($event === null || $json['event'] === $event) {
                $out[] = ['request' => $request, 'json' => $json];
            }
        }

        return $out;
    }

    private function allBodies(): string
    {
        return implode("\n", array_map(fn ($e) => $e['request']->body(), $this->sent()));
    }

    private function acceptedRide(?User $passenger = null, ?User $driver = null): array
    {
        $passenger ??= $this->member(self::PASSENGER_UUID, 'Pat Passenger');
        $driver ??= $this->driver();
        $ride = $this->ride($passenger);
        $this->actingAs($driver)->post(route('rides.accept', $ride))->assertRedirect(route('rides.show', $ride));

        return [$ride->fresh(), $passenger, $driver];
    }

    // ---- ride.accepted ------------------------------------------------------------------

    public function test_accepting_a_ride_posts_a_signed_ride_accepted_event_to_the_passenger(): void
    {
        [$ride, $passenger, $driver] = $this->acceptedRide();

        $events = $this->sent('ride.accepted');
        $this->assertCount(1, $events);
        $request = $events[0]['request'];
        $json = $events[0]['json'];
        $body = $request->body();
        $timestamp = $request->header('X-InfoDot-Timestamp')[0] ?? '';

        $this->assertSame(self::URL, $request->url());
        $this->assertSame('POST', $request->method());
        $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET), $request->header('X-InfoDot-Signature')[0] ?? '');
        $this->assertLessThanOrEqual(5, abs((int) $timestamp - now()->timestamp));
        $this->assertSame(['platform', 'company_uuid', 'event', 'recipient', 'data', 'idempotency_key'], array_keys($json));
        $this->assertSame('ehail', $json['platform']);
        $this->assertSame(self::PASSENGER_UUID, $json['company_uuid']);
        $this->assertSame(['email' => $passenger->email], $json['recipient']);
        $this->assertSame(['driver_name', 'vehicle', 'url'], array_keys($json['data']));
        $this->assertSame('Thandi Driver', $json['data']['driver_name']);
        $this->assertSame('White Toyota Corolla', $json['data']['vehicle']);
        $this->assertSame('https://ehail.test/rides/'.$ride->id, $json['data']['url']);
        $this->assertSame('ride.accepted:'.$ride->id.':'.sha1($passenger->email).':accepted-0', $json['idempotency_key']);
        $this->assertStringNotContainsString($driver->email, $body, 'the passenger is not given the driver email');
        $this->assertStringNotContainsString(self::SECRET, $body);
    }

    public function test_the_request_uses_a_five_second_timeout(): void
    {
        $seen = null;
        $this->fakeNotify(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response([], 202);
        });

        $this->acceptedRide();

        $this->assertSame(5, (int) ($seen['timeout'] ?? 0));
        $this->assertSame(5, (int) ($seen['connect_timeout'] ?? 0));
    }

    // ---- ride.cancelled ------------------------------------------------------------------

    public function test_a_passenger_cancelling_tells_the_driver(): void
    {
        [$ride, $passenger, $driver] = $this->acceptedRide();

        $this->actingAs($passenger)->post(route('rides.cancel', $ride), ['reason' => 'changed_plans', 'note' => self::NOTE])->assertRedirect();

        $events = $this->sent('ride.cancelled');
        $this->assertCount(1, $events);
        $json = $events[0]['json'];
        $this->assertSame(self::DRIVER_UUID, $json['company_uuid']);
        $this->assertSame(['email' => $driver->email], $json['recipient']);
        $this->assertSame(['cancelled_by_role', 'reason', 'url'], array_keys($json['data']));
        $this->assertSame('passenger', $json['data']['cancelled_by_role']);
        $this->assertSame('My plans changed', $json['data']['reason']);
        $this->assertSame('https://ehail.test/rides/'.$ride->id, $json['data']['url']);
        $this->assertSame('ride.cancelled:'.$ride->id.':'.sha1($driver->email).':cancelled', $json['idempotency_key']);
        $this->assertStringNotContainsString($passenger->email, json_encode($json));
        $this->assertStringNotContainsString('MNBVCX', $this->allBodies(), 'the free-text note never leaves');
    }

    public function test_an_operator_cancelling_tells_both_parties(): void
    {
        [$ride, $passenger, $driver] = $this->acceptedRide();
        $ride->update(['status' => 'needs_review']);
        $operator = $this->makeOperator();

        $this->actingAs($operator)->post(route('operator.rides.cancel', $ride), ['reason' => 'Fraud review: '.self::NOTE])->assertRedirect();

        $events = $this->sent('ride.cancelled');
        $this->assertEqualsCanonicalizing(
            [$passenger->email, $driver->email],
            array_map(fn ($e) => $e['json']['recipient']['email'], $events),
        );
        foreach ($events as $event) {
            $this->assertSame('operator', $event['json']['data']['cancelled_by_role']);
            $this->assertSame('Cancelled by a platform operator after review', $event['json']['data']['reason']);
        }
        $this->assertStringNotContainsString('MNBVCX', $this->allBodies());
    }

    public function test_a_driver_backing_out_tells_the_passenger_as_a_driver_cancellation(): void
    {
        [$ride, $passenger, $driver] = $this->acceptedRide();

        $this->actingAs($driver)->post(route('rides.cancel', $ride), ['reason' => 'vehicle_problem']);

        $events = $this->sent('ride.cancelled');
        $this->assertCount(1, $events);
        $this->assertSame(['email' => $passenger->email], $events[0]['json']['recipient']);
        $this->assertSame('driver', $events[0]['json']['data']['cancelled_by_role']);
        $this->assertSame('Vehicle problem', $events[0]['json']['data']['reason']);
        $this->assertSame('ride.cancelled:'.$ride->id.':'.sha1($passenger->email).':driver-cancelled-1', $events[0]['json']['idempotency_key']);
    }

    // ---- ride.expired --------------------------------------------------------------------

    public function test_an_expired_request_tells_the_passenger(): void
    {
        $passenger = $this->member(self::PASSENGER_UUID, 'Pat Passenger');
        $ride = $this->ride($passenger, ['requested_at' => now()]);

        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('ehail:expire-rides')->assertSuccessful();

        $events = $this->sent('ride.expired');
        $this->assertCount(1, $events);
        $json = $events[0]['json'];
        $this->assertSame(self::PASSENGER_UUID, $json['company_uuid']);
        $this->assertSame(['email' => $passenger->email], $json['recipient']);
        $this->assertSame(['url'], array_keys($json['data']));
        $this->assertSame('https://ehail.test/rides/'.$ride->id, $json['data']['url']);
        $this->assertSame('ride.expired:'.$ride->id.':'.sha1($passenger->email).':expired', $json['idempotency_key']);
    }

    public function test_running_the_expiry_sweep_twice_sends_one_event(): void
    {
        $passenger = $this->member(self::PASSENGER_UUID);
        $this->ride($passenger, ['requested_at' => now()]);

        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('ehail:expire-rides')->assertSuccessful();
        $this->artisan('ehail:expire-rides')->assertSuccessful();

        $this->assertCount(1, $this->sent('ride.expired'));
    }

    public function test_a_ride_ended_by_repeated_driver_cancellations_tells_the_passenger_it_expired(): void
    {
        config(['ehail.max_reassignments' => 0]);
        [$ride, $passenger, $driver] = $this->acceptedRide();

        $this->actingAs($driver)->post(route('rides.cancel', $ride), ['reason' => 'vehicle_problem']);

        $this->assertSame('expired', $ride->fresh()->status);
        $this->assertCount(1, $this->sent('ride.expired'));
        $this->assertCount(0, $this->sent('ride.cancelled'));
    }

    // ---- driver.application_decided --------------------------------------------------------

    private function pendingApplication(Fleet $fleet, ?string $uuid = self::DRIVER_UUID): DriverProfile
    {
        $applicant = $this->member($uuid, 'Applicant Person');

        return DriverProfile::factory()->for($applicant)->create([
            'fleet_id' => $fleet->id, 'status' => 'pending',
            'license_number' => self::LICENCE.Str::random(4), 'id_number' => self::ID_NUMBER.Str::random(4),
        ]);
    }

    public function test_approving_an_application_tells_the_applicant(): void
    {
        $owner = $this->member(self::OPERATOR_UUID, 'Fleet Owner');
        $fleet = Fleet::create(['status' => 'approved', 'name' => 'Cape Cabs', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $this->actingAs($owner)->post(route('fleets.drivers.approve', [$fleet, $profile->id]))->assertRedirect();

        $events = $this->sent('driver.application_decided');
        $this->assertCount(1, $events);
        $json = $events[0]['json'];
        $this->assertSame(self::DRIVER_UUID, $json['company_uuid']);
        $this->assertSame(['email' => $profile->user->email], $json['recipient']);
        $this->assertSame(['decision', 'fleet_name', 'note', 'url'], array_keys($json['data']));
        $this->assertSame('approved', $json['data']['decision']);
        $this->assertSame('Cape Cabs', $json['data']['fleet_name']);
        $this->assertSame('', $json['data']['note']);
        $this->assertSame('https://ehail.test/drivers/'.$profile->id, $json['data']['url']);
        $this->assertSame('driver.application_decided:'.$profile->id.':'.sha1($profile->user->email).':approved', $json['idempotency_key']);
        $this->assertStringNotContainsString($owner->email, json_encode($json));
    }

    public function test_rejecting_an_application_tells_the_applicant_with_the_reviewers_note(): void
    {
        $owner = $this->member(self::OPERATOR_UUID, 'Fleet Owner');
        $fleet = Fleet::create(['status' => 'approved', 'name' => 'Cape Cabs', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $this->actingAs($owner)->post(route('fleets.drivers.reject', [$fleet, $profile->id]), ['reason' => 'Vehicle inspection is out of date'])->assertRedirect();

        $events = $this->sent('driver.application_decided');
        $this->assertCount(1, $events);
        $this->assertSame('rejected', $events[0]['json']['data']['decision']);
        $this->assertSame('Vehicle inspection is out of date', $events[0]['json']['data']['note']);
        $this->assertSame('rejected', substr($events[0]['json']['idempotency_key'], -8));
    }

    public function test_a_very_long_reviewer_note_is_cut_short(): void
    {
        $owner = $this->member(self::OPERATOR_UUID, 'Fleet Owner');
        $fleet = Fleet::create(['status' => 'approved', 'name' => 'Cape Cabs', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet);

        $this->actingAs($owner)->post(route('fleets.drivers.reject', [$fleet, $profile->id]), ['reason' => str_repeat('n', 900)]);

        $this->assertSame(300, mb_strlen($this->sent('driver.application_decided')[0]['json']['data']['note']));
    }

    // ---- privacy ----------------------------------------------------------------------------

    public function test_no_payload_ever_carries_a_phone_number_id_or_licence_number_address_or_coordinate(): void
    {
        [$ride, $passenger, $driver] = $this->acceptedRide();
        $this->actingAs($driver)->post(route('rides.cancel', $ride), ['reason' => 'vehicle_problem', 'note' => self::NOTE]);
        $second = $this->driver(self::OPERATOR_UUID, 'Second Driver');
        $this->actingAs($second)->post(route('rides.accept', $ride))->assertRedirect();
        $this->actingAs($passenger)->post(route('rides.cancel', $ride), ['reason' => 'other', 'note' => self::NOTE]);
        $flagged = $this->ride($passenger, ['status' => 'needs_review', 'driver_id' => $second->id]);
        $this->actingAs($this->makeOperator())->post(route('operator.rides.cancel', $flagged), ['reason' => self::NOTE]);
        $owner = $this->member(null, 'Fleet Owner');
        $fleet = Fleet::create(['status' => 'approved', 'name' => 'Cape Cabs', 'owner_user_id' => $owner->id]);
        $profile = $this->pendingApplication($fleet, null);
        $this->actingAs($owner)->post(route('fleets.drivers.approve', [$fleet, $profile->id]));
        $this->ride($passenger, ['requested_at' => now()]);
        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('ehail:expire-rides')->assertSuccessful();

        $bodies = $this->allBodies();
        $this->assertGreaterThanOrEqual(5, count($this->sent()), 'every kind of event was exercised');
        foreach ([self::PICKUP, self::DROPOFF, self::LICENCE, self::ID_NUMBER, 'PLATE SENTINEL', 'MNBVCX', '-33.92', '18.42', 'pickup', 'dropoff', '_lat', '_lng', '"lat"', '"lng"', 'latitude', 'longitude', 'phone', 'licen', 'id_number', 'plate'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $bodies, "'{$forbidden}' must never be sent to Notify");
        }
        foreach ($this->sent() as $event) {
            $this->assertSame(['email'], array_keys($event['json']['recipient']));
        }
    }

    // ---- nothing is sent unless it can be routed ---------------------------------------------

    public function test_every_url_is_https_on_the_app_host_even_when_the_app_url_is_http(): void
    {
        url()->forceRootUrl('http://ehail.test');

        $this->acceptedRide();

        $url = $this->sent('ride.accepted')[0]['json']['data']['url'];
        $this->assertStringStartsWith('https://ehail.test/rides/', $url);
        $this->assertSame('ehail.test', parse_url($url, PHP_URL_HOST));
    }

    public function test_it_is_disabled_when_the_url_or_the_secret_is_blank(): void
    {
        foreach ([['url' => ''], ['secret' => ''], ['url' => null, 'secret' => null]] as $blank) {
            config(['notify' => array_merge(config('notify'), $blank)]);
            $this->assertFalse(app(NotifyClient::class)->enabled());
            $this->acceptedRide($this->member(null), $this->driver(null));
        }

        Http::assertNothingSent();
        Queue::fake();
        $this->acceptedRide($this->member(null), $this->driver(null));
        Queue::assertNotPushed(SendNotifyEvent::class);
    }

    public function test_a_recipient_without_a_company_uuid_is_skipped(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create(); // personal team, never linked to InfoDot
        $ride = $this->ride($passenger);

        $this->actingAs($this->driver())->post(route('rides.accept', $ride))->assertRedirect();

        Http::assertNothingSent();
        $this->assertSame('accepted', $ride->fresh()->status);
    }

    public function test_a_user_with_no_team_at_all_is_skipped_without_writing_anything(): void
    {
        $passenger = User::factory()->create(); // no team
        $ride = $this->ride($passenger);

        $this->actingAs($this->driver())->post(route('rides.accept', $ride))->assertRedirect();

        Http::assertNothingSent();
        $this->assertNull($passenger->fresh()->current_team_id, 'resolving a company must not switch the user team');
    }

    public function test_the_personal_team_uuid_is_used_when_the_current_team_has_none(): void
    {
        $passenger = $this->member(self::PASSENGER_UUID);
        $other = Team::forceCreate(['user_id' => $passenger->id, 'name' => 'Side project', 'personal_team' => false]);
        $passenger->forceFill(['current_team_id' => $other->id])->save();
        $ride = $this->ride($passenger->fresh());

        $this->actingAs($this->driver())->post(route('rides.accept', $ride))->assertRedirect();

        $this->assertSame(self::PASSENGER_UUID, $this->sent('ride.accepted')[0]['json']['company_uuid']);
    }

    public function test_a_recipient_without_an_email_is_skipped(): void
    {
        $user = $this->member(self::PASSENGER_UUID);
        $user->email = '';
        $ride = $this->ride($user);

        $user->notify(new RideNoticeNotification($ride, 'ride_expired', 'No driver found', 'Expired', null, null));

        Http::assertNothingSent();
    }

    // ---- Notify can never hurt the user ------------------------------------------------------

    public function test_a_failing_notify_leaves_the_accept_and_the_in_app_notices_untouched(): void
    {
        foreach ([
            fn () => Http::response('boom', 500),
            fn () => Http::response('slow down', 429),
            fn () => Http::response('nope', 401),
            fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
            fn () => throw new \RuntimeException('something unexpected'),
        ] as $failure) {
            Cache::flush();
            $calls = 0;
            $this->fakeNotify(function () use ($failure, &$calls) {
                $calls++;

                return $failure();
            });

            [$ride, $passenger, $driver] = $this->acceptedRide($this->member(null), $this->driver(null));
            $this->assertSame('accepted', $ride->status);
            $this->assertSame($driver->id, $ride->driver_id);
            $this->assertSame(1, $calls, 'Notify was attempted once and its failure stayed contained');

            $this->actingAs($passenger)->post(route('rides.cancel', $ride), ['reason' => 'changed_plans'])->assertRedirect();
            $this->assertSame('cancelled', $ride->fresh()->status);
            $this->assertSame(1, $this->notificationsOfType($driver, 'ride_cancelled')->count(), 'the in-app notice is untouched');
        }
    }

    public function test_a_failing_notify_does_not_stop_the_expiry_sweep(): void
    {
        $this->fakeNotify(Http::response('boom', 503));
        $passenger = $this->member(self::PASSENGER_UUID);
        $ride = $this->ride($passenger, ['requested_at' => now()]);

        Carbon::setTestNow(now()->addMinutes(11));
        $this->artisan('ehail:expire-rides')->assertSuccessful();

        $this->assertSame('expired', $ride->fresh()->status);
        $this->assertSame(1, $this->notificationsOfType($passenger, 'ride_expired')->count());
    }

    public function test_a_failure_to_even_queue_the_event_never_reaches_the_caller(): void
    {
        $passenger = $this->member(self::PASSENGER_UUID);
        $ride = $this->ride($passenger);
        $driver = $this->driver();
        $this->mock(Dispatcher::class, function ($mock) {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue backend down'));
        });

        $this->actingAs($driver)->post(route('rides.accept', $ride))->assertRedirect(route('rides.show', $ride));

        $this->assertSame('accepted', $ride->fresh()->status);
    }

    public function test_a_transient_failure_asks_the_queue_to_retry_and_a_permanent_one_does_not(): void
    {
        $payload = $this->payload();
        $client = app(NotifyClient::class);
        $this->fakeNotify(Http::sequence()->push('x', 503)->push('x', 429)->push('x', 422)->push([], 200)->pushFailedConnection());

        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        $this->assertSame(NotifyClient::GIVE_UP, $client->deliver($payload), '422: retrying cannot fix a bad payload');
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload));
        $this->assertSame(NotifyClient::RETRY, $client->deliver($payload), 'connection failure');
    }

    public function test_the_job_retries_three_times_with_backoff_and_never_throws(): void
    {
        $job = new SendNotifyEvent($this->payload());

        $this->assertSame(3, $job->tries);
        $this->assertTrue($job->afterCommit, 'dispatched only once the surrounding transaction has committed');
        $this->assertCount(2, $job->backoff());
        $this->assertSame($job->backoff(), array_values(array_unique($job->backoff())));

        $this->fakeNotify(fn () => throw new \RuntimeException('unexpected'));
        $job->handle(app(NotifyClient::class));
        $this->assertTrue(true, 'no exception escaped');
    }

    public function test_a_retry_is_signed_afresh_with_a_new_timestamp(): void
    {
        $payload = $this->payload();

        app(NotifyClient::class)->deliver($payload);
        Carbon::setTestNow(now()->addMinutes(2));
        app(NotifyClient::class)->deliver($payload);

        $stamps = array_map(fn ($e) => $e['request']->header('X-InfoDot-Timestamp')[0], $this->sent());
        $this->assertCount(2, array_unique($stamps));
    }

    public function test_the_idempotency_key_is_stable_across_retries_and_double_dispatch(): void
    {
        [$ride, $passenger, $driver] = $this->acceptedRide();
        $notice = fn () => new RideNoticeNotification($ride->fresh(), 'ride_expired', 'No driver found', 'Expired');

        $passenger->notify($notice());
        Carbon::setTestNow(now()->addHour());
        $passenger->notify($notice());
        $passenger->notify($notice());

        $keys = array_map(fn ($e) => $e['json']['idempotency_key'], $this->sent('ride.expired'));
        $this->assertCount(3, $keys);
        $this->assertCount(1, array_unique($keys));
    }

    // ---- circuit breaker -----------------------------------------------------------------------

    public function test_five_consecutive_failures_pause_calls_for_sixty_seconds_then_it_recovers(): void
    {
        $client = app(NotifyClient::class);
        $payload = $this->payload();
        $this->fakeNotify(Http::response('boom', 500));

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(NotifyClient::RETRY, $client->deliver($payload));
        }
        Http::assertSentCount(5);

        $this->assertSame(NotifyClient::PAUSED, $client->deliver($payload));
        $this->assertSame(NotifyClient::PAUSED, $client->deliver($payload));
        Http::assertSentCount(5);

        Carbon::setTestNow(now()->addSeconds(59));
        $this->assertSame(NotifyClient::PAUSED, $client->deliver($payload));
        Http::assertSentCount(5);

        Carbon::setTestNow(now()->addSeconds(2));
        $this->fakeNotify(Http::response([], 202));
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload), 'the probe after the pause is allowed through');
        $this->assertSame(NotifyClient::DONE, $client->deliver($payload));
        Http::assertSentCount(2);
    }

    public function test_a_success_resets_the_failure_count(): void
    {
        $client = app(NotifyClient::class);
        $payload = $this->payload();

        $this->fakeNotify(Http::response('boom', 500));
        for ($i = 0; $i < 4; $i++) {
            $client->deliver($payload);
        }
        $this->fakeNotify(Http::response([], 202));
        $client->deliver($payload);
        $this->fakeNotify(Http::response('boom', 500));
        for ($i = 0; $i < 4; $i++) {
            $this->assertSame(NotifyClient::RETRY, $client->deliver($payload), 'four more failures are still below the threshold');
        }
    }

    public function test_a_refusal_does_not_count_towards_the_breaker(): void
    {
        $client = app(NotifyClient::class);
        $payload = $this->payload();
        $this->fakeNotify(Http::response('no', 422));

        for ($i = 0; $i < 8; $i++) {
            $this->assertSame(NotifyClient::GIVE_UP, $client->deliver($payload));
        }
        Http::assertSentCount(8);
    }

    public function test_a_paused_job_is_released_without_calling_notify(): void
    {
        Cache::put('notify.circuit.open_until', now()->timestamp + 40, 60);
        $delay = null;
        $queueJob = \Mockery::mock(Job::class);
        $queueJob->shouldReceive('attempts')->andReturn(1);
        $queueJob->shouldReceive('release')->once()->andReturnUsing(function ($seconds) use (&$delay) {
            $delay = $seconds;
        });
        $job = new SendNotifyEvent($this->payload());
        $job->setJob($queueJob);

        $job->handle(app(NotifyClient::class));

        Http::assertNothingSent();
        $this->assertGreaterThanOrEqual(40, $delay);
    }

    // ---- after commit -----------------------------------------------------------------------------

    public function test_nothing_is_queued_inside_an_accept_that_rolls_back(): void
    {
        $passenger = $this->member(self::PASSENGER_UUID);
        $driver = $this->driver();
        $ride = $this->ride($passenger);
        Queue::fake();
        $this->fakeNotify();

        try {
            DB::transaction(function () use ($ride, $driver) {
                app(RideLifecycle::class)->accept($ride, $driver);
                throw new \RuntimeException('something later in the request failed');
            });
        } catch (\RuntimeException) {
        }

        Queue::assertNotPushed(SendNotifyEvent::class);
        Http::assertNothingSent();
        $this->assertSame('requested', $ride->fresh()->status);
    }

    public function test_the_event_is_queued_once_the_accept_commits_and_not_sent_in_the_request(): void
    {
        $passenger = $this->member(self::PASSENGER_UUID);
        $driver = $this->driver();
        $ride = $this->ride($passenger);
        Queue::fake();
        $this->fakeNotify();

        app(RideLifecycle::class)->accept($ride, $driver);

        Queue::assertPushed(SendNotifyEvent::class, 1);
        Queue::assertPushed(SendNotifyEvent::class, fn (SendNotifyEvent $job) => $job->payload['event'] === 'ride.accepted');
        Http::assertNothingSent();
    }

    // ---- privacy of logs ------------------------------------------------------------------------------

    public function test_failures_are_logged_without_emails_or_secrets(): void
    {
        config([
            'logging.default' => 'notifytest',
            'logging.channels.notifytest' => ['driver' => 'monolog', 'handler' => TestHandler::class, 'level' => 'debug'],
        ]);
        Log::forgetChannel('notifytest');
        $this->fakeNotify(Http::response('{"error":"a@b.test leaked"}', 500));

        [$ride, $passenger, $driver] = $this->acceptedRide();

        $handler = Log::channel('notifytest')->getLogger()->getHandlers()[0];
        $logged = json_encode(array_map(fn ($r) => $r->message.json_encode($r->context), $handler->getRecords()));
        $this->assertNotSame('[]', $logged, 'the failure is logged');
        foreach ([$passenger->email, $driver->email, self::SECRET, 'leaked'] as $secret) {
            $this->assertStringNotContainsString($secret, $logged);
        }
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'platform' => 'ehail', 'company_uuid' => self::PASSENGER_UUID, 'event' => 'ride.expired',
            'recipient' => ['email' => 'someone@example.test'], 'data' => ['url' => 'https://ehail.test/rides/1'],
            'idempotency_key' => 'ride.expired:1:abc:expired',
        ];
    }
}
