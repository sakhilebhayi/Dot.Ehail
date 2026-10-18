<?php

namespace Tests\Feature\Ehail;

use App\Events\RideStatusUpdated;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class ExpireRidesCommandTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_command_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'ehail:expire-rides'));

        $this->assertNotNull($event, 'ehail:expire-rides is not on the scheduler');
        $this->assertSame('* * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping, 'overlapping runs would double-notify');
    }

    public function test_defaults_are_ten_minutes_and_six_hours(): void
    {
        $this->assertSame(10, config('ehail.ride_expiry_minutes'));
        $this->assertSame(6, config('ehail.stuck_ride_hours'));
    }

    public function test_a_request_older_than_the_window_expires_and_the_passenger_is_told(): void
    {
        Carbon::setTestNow('2026-10-18 09:00:00');
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide(['requested_at' => now()], $passenger);

        Carbon::setTestNow('2026-10-18 09:10:01');
        $this->artisan('ehail:expire-rides')->assertSuccessful();

        $ride->refresh();
        $this->assertSame('expired', $ride->status);
        $this->assertSame('2026-10-18 09:10:01', $ride->expired_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $this->notificationsOfType($passenger, 'ride_expired')->count());
    }

    public function test_a_request_exactly_at_the_limit_or_younger_is_left_alone(): void
    {
        Carbon::setTestNow('2026-10-18 09:00:00');
        $exactlyAtLimit = $this->makeRide(['requested_at' => now()]);
        Carbon::setTestNow('2026-10-18 09:05:00');
        $younger = $this->makeRide(['requested_at' => now()]);

        Carbon::setTestNow('2026-10-18 09:10:00');
        $this->artisan('ehail:expire-rides')->assertSuccessful();

        $this->assertSame('requested', $exactlyAtLimit->fresh()->status);
        $this->assertSame('requested', $younger->fresh()->status);
    }

    public function test_the_window_comes_from_config(): void
    {
        config(['ehail.ride_expiry_minutes' => 2]);
        Carbon::setTestNow('2026-10-18 09:00:00');
        $ride = $this->makeRide(['requested_at' => now()]);

        Carbon::setTestNow('2026-10-18 09:03:00');
        $this->artisan('ehail:expire-rides')->assertSuccessful();

        $this->assertSame('expired', $ride->fresh()->status);
    }

    public function test_running_twice_is_idempotent_and_notifies_once(): void
    {
        Carbon::setTestNow('2026-10-18 09:00:00');
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide(['requested_at' => now()], $passenger);

        Carbon::setTestNow('2026-10-18 09:30:00');
        $this->artisan('ehail:expire-rides')->assertSuccessful();
        $firstExpiredAt = $ride->fresh()->expired_at;

        Carbon::setTestNow('2026-10-18 09:45:00');
        $this->artisan('ehail:expire-rides')->assertSuccessful();

        $this->assertEquals($firstExpiredAt, $ride->fresh()->expired_at);
        $this->assertSame(1, $this->notificationsOfType($passenger, 'ride_expired')->count());
    }

    public function test_only_requested_rides_expire(): void
    {
        Carbon::setTestNow('2026-10-18 09:00:00');
        $driver = $this->makeApprovedDriver();
        $accepted = $this->makeRide(['status' => 'accepted', 'driver_id' => $driver->id, 'requested_at' => now()->subHour()]);
        $completed = $this->makeRide(['status' => 'completed', 'driver_id' => $driver->id, 'requested_at' => now()->subHour(), 'final_fare' => 80]);

        $this->artisan('ehail:expire-rides')->assertSuccessful();

        $this->assertSame('accepted', $accepted->fresh()->status);
        $this->assertSame('completed', $completed->fresh()->status);
    }

    public function test_an_in_progress_ride_older_than_the_limit_is_flagged_not_completed(): void
    {
        Carbon::setTestNow('2026-10-18 02:00:00');
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id, 'started_at' => now()]);

        Carbon::setTestNow('2026-10-18 08:00:01');
        $this->artisan('ehail:expire-rides')->assertSuccessful();

        $ride->refresh();
        $this->assertSame('needs_review', $ride->status);
        $this->assertSame('2026-10-18 08:00:01', $ride->needs_review_at->format('Y-m-d H:i:s'));
        $this->assertNull($ride->completed_at, 'a stuck ride is never auto-completed');
        $this->assertNull($ride->final_fare);
    }

    public function test_a_recent_in_progress_ride_is_left_alone(): void
    {
        Carbon::setTestNow('2026-10-18 02:00:00');
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id, 'started_at' => now()]);

        Carbon::setTestNow('2026-10-18 08:00:00');
        $this->artisan('ehail:expire-rides')->assertSuccessful();

        $this->assertSame('in_progress', $ride->fresh()->status);
    }

    public function test_flagging_is_idempotent(): void
    {
        Carbon::setTestNow('2026-10-18 02:00:00');
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id, 'started_at' => now()]);

        Carbon::setTestNow('2026-10-18 09:00:00');
        $this->artisan('ehail:expire-rides')->assertSuccessful();
        $flaggedAt = $ride->fresh()->needs_review_at;

        Carbon::setTestNow('2026-10-18 12:00:00');
        $this->artisan('ehail:expire-rides')->assertSuccessful();

        $this->assertSame('needs_review', $ride->fresh()->status);
        $this->assertEquals($flaggedAt, $ride->fresh()->needs_review_at);
    }

    public function test_a_legacy_in_progress_ride_without_started_at_falls_back_to_updated_at(): void
    {
        Carbon::setTestNow('2026-10-18 01:00:00');
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id]);

        Carbon::setTestNow('2026-10-18 07:30:00');
        $this->artisan('ehail:expire-rides')->assertSuccessful();

        $this->assertSame('needs_review', $ride->fresh()->status);
    }

    public function test_each_expiry_and_flag_broadcasts_a_status_update(): void
    {
        Carbon::setTestNow('2026-10-18 02:00:00');
        $driver = $this->makeApprovedDriver();
        $this->makeRide(['requested_at' => now()]);
        $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id, 'started_at' => now()]);

        Carbon::setTestNow('2026-10-18 09:00:00');
        Event::fake([RideStatusUpdated::class]);
        $this->artisan('ehail:expire-rides')->assertSuccessful();

        Event::assertDispatchedTimes(RideStatusUpdated::class, 2);
    }

    public function test_the_command_reports_what_it_did(): void
    {
        Carbon::setTestNow('2026-10-18 02:00:00');
        $this->makeRide(['requested_at' => now()]);
        Carbon::setTestNow('2026-10-18 09:00:00');

        $this->artisan('ehail:expire-rides')
            ->expectsOutputToContain('Expired 1 stale ride request(s)')
            ->expectsOutputToContain('Flagged 0 stuck trip(s) for review')
            ->assertSuccessful();
    }
}
