<?php

namespace Tests\Feature\Dispatch;

use App\Domain\Dispatch\AvailabilityService;
use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Rides\Events\DriverAssigned;
use App\Domain\Rides\Events\RideExpired;
use App\Domain\Rides\Events\RideRequested;
use App\Enums\CancelReason;
use App\Enums\RideStatus;
use App\Models\Ride;
use App\Models\User;
use App\Services\Ehail\InvalidRideTransition;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\TestCase;

class DispatcherTest extends TestCase
{
    use CreatesDispatchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeQuotes();
    }

    private function dispatcher(): Dispatcher
    {
        return app(Dispatcher::class);
    }

    private function pendingOffer(Ride $ride): ?RideOffer
    {
        return RideOffer::where('pending_ride_id', $ride->id)->first();
    }

    public function test_a_request_starts_searching_and_offers_the_closest_driver_first(): void
    {
        $far = $this->onlineDriverAt(...$this->northOfPickup(1800));
        $near = $this->onlineDriverAt(...$this->northOfPickup(300));

        $ride = $this->requestRide();

        $this->assertSame('searching', $ride->status);
        $offer = $this->pendingOffer($ride);
        $this->assertNotNull($offer);
        $this->assertSame($near->id, $offer->driver_id);
        $this->assertSame('straight_line_estimate', $offer->eta_method);
        $this->assertSame(1, $offer->score_terms['rank']);
        $this->assertSame(2, $offer->score_terms['candidates']);
        $this->assertArrayHasKey('weights', $offer->score_terms, 'the weights used are logged on every offer');
        $this->assertNotNull($ride->fresh()->pending_offer_expires_at);
        $this->assertNotSame($far->id, $offer->driver_id);
    }

    public function test_the_request_emits_ride_requested_once(): void
    {
        Event::fake([RideRequested::class]);
        $this->requestRide();
        Event::assertDispatchedTimes(RideRequested::class, 1);
    }

    public function test_an_unanswered_offer_times_out_and_moves_to_the_next_driver(): void
    {
        $first = $this->onlineDriverAt(...$this->northOfPickup(200));
        $second = $this->onlineDriverAt(...$this->northOfPickup(900));
        $ride = $this->requestRide();
        $this->assertSame($first->id, $this->pendingOffer($ride)->driver_id);

        $this->travel(10)->seconds();
        $this->heartbeat($first, $this->northOfPickup(200));
        $this->heartbeat($second, $this->northOfPickup(900));
        $this->dispatcher()->tick($ride);
        $this->assertSame($first->id, $this->pendingOffer($ride)->driver_id, 'still inside the 15 s window');

        $this->travel(6)->seconds();
        $this->dispatcher()->tick($ride);

        $this->assertSame($second->id, $this->pendingOffer($ride)->driver_id);
        $this->assertSame(RideOffer::EXPIRED, RideOffer::where('ride_id', $ride->id)->where('driver_id', $first->id)->value('status'));
        $this->assertSame(1, $ride->events()->where('type', 'offer_expired')->count());
    }

    public function test_a_decline_offers_the_next_driver_at_once(): void
    {
        $first = $this->onlineDriverAt(...$this->northOfPickup(200));
        $second = $this->onlineDriverAt(...$this->northOfPickup(900));
        $ride = $this->requestRide();

        $this->dispatcher()->offerResponse($this->pendingOffer($ride), $first, 'decline', 'too_far');

        $this->assertSame($second->id, $this->pendingOffer($ride)->driver_id);
        $declined = RideOffer::where('ride_id', $ride->id)->where('driver_id', $first->id)->first();
        $this->assertSame(RideOffer::DECLINED, $declined->status);
        $this->assertSame('too_far', $declined->reason);
    }

    public function test_accepting_assigns_the_driver_and_marks_them_on_trip(): void
    {
        Event::fake([DriverAssigned::class]);
        $driver = $this->onlineDriverAt(...$this->northOfPickup(200));
        $ride = $this->requestRide();

        $this->dispatcher()->offerResponse($this->pendingOffer($ride), $driver, 'accept');

        $ride->refresh();
        $this->assertSame('driver_assigned', $ride->status);
        $this->assertSame($driver->id, $ride->driver_id);
        $this->assertSame($driver->id, $ride->active_driver_id);
        $this->assertNull($ride->pending_offer_expires_at);
        $availability = DriverAvailability::where('user_id', $driver->id)->first();
        $this->assertSame(DriverAvailability::ON_TRIP, $availability->state);
        $this->assertSame($ride->id, $availability->current_ride_id);
        Event::assertDispatched(DriverAssigned::class, fn ($e) => $e->rideId === $ride->id && $e->actorId === $driver->id);
    }

    public function test_an_expired_offer_cannot_be_accepted(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(200));
        $ride = $this->requestRide();
        $offer = $this->pendingOffer($ride);

        $this->travel(16)->seconds();

        $this->expectExceptionMessage('This offer is no longer available.');
        $this->dispatcher()->offerResponse($offer, $driver, 'accept');
    }

    public function test_a_driver_cannot_answer_someone_elses_offer(): void
    {
        $this->onlineDriverAt(...$this->northOfPickup(200));
        $other = $this->onlineDriverAt(...$this->northOfPickup(5000));
        $ride = $this->requestRide();

        $this->expectExceptionMessage('That offer was not made to you.');
        $this->dispatcher()->offerResponse($this->pendingOffer($ride), $other, 'accept');
    }

    public function test_the_search_widens_when_the_first_ring_is_empty(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(5500));
        $ride = $this->requestRide();

        $offer = $this->pendingOffer($ride);
        $this->assertSame($driver->id, $offer->driver_id);
        $this->assertSame(7000, $offer->radius_m);
        $this->assertSame(2, $offer->round);
    }

    public function test_nobody_in_range_keeps_searching_then_expires_after_the_window(): void
    {
        $this->freezeSecond();
        Event::fake([RideExpired::class]);
        $this->onlineDriverAt(...$this->northOfPickup(20000));
        $ride = $this->requestRide();

        $this->assertNull($this->pendingOffer($ride));
        $this->assertSame('searching', $ride->fresh()->status);

        $this->travel(10)->minutes();
        $this->dispatcher()->tick($ride);
        $this->assertSame('searching', $ride->fresh()->status, 'exactly at the window is still inside it');

        $this->travel(1)->seconds();
        $this->dispatcher()->tick($ride);
        $this->assertSame('expired', $ride->fresh()->status);
        Event::assertDispatched(RideExpired::class);
    }

    public function test_a_stale_driver_is_not_offered(): void
    {
        $stale = $this->onlineDriverAt(...$this->northOfPickup(100));
        $this->travel(60)->seconds();
        $fresh = $this->onlineDriverAt(...$this->northOfPickup(3000));

        $ride = $this->requestRide();

        $this->assertSame($fresh->id, $this->pendingOffer($ride)->driver_id);
        $this->assertNotSame($stale->id, $this->pendingOffer($ride)->driver_id);
    }

    public function test_vehicle_category_paused_and_on_trip_drivers_are_skipped(): void
    {
        // Since integration the ride-category catalogue decides (D-700): the "standard" (Comfort) category
        // takes standard and premium vehicles, so an economy car is the one that cannot serve it.
        $economy = $this->onlineDriverAt(...$this->northOfPickup(100), vehicleType: 'economy');
        $paused = $this->onlineDriverAt(...$this->northOfPickup(150));
        app(AvailabilityService::class)->pause($paused);
        $standard = $this->onlineDriverAt(...$this->northOfPickup(2500));

        $ride = $this->requestRide();

        $this->assertSame($standard->id, $this->pendingOffer($ride)->driver_id);
        $this->assertNotContains($economy->id, RideOffer::pluck('driver_id')->all());
    }

    public function test_the_passenger_is_never_offered_their_own_ride(): void
    {
        $passengerWhoDrives = $this->onlineDriverAt(...$this->northOfPickup(100));
        $other = $this->onlineDriverAt(...$this->northOfPickup(2000));

        $ride = $this->requestRide($passengerWhoDrives);

        $this->assertSame($other->id, $this->pendingOffer($ride)->driver_id);
    }

    public function test_a_passenger_cancelling_during_search_withdraws_the_offer(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(200));
        $passenger = $this->passenger();
        $ride = $this->requestRide($passenger);
        $offer = $this->pendingOffer($ride);

        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.cancel', $ride), ['reason' => 'changed_plans'])->assertOk();

        $this->assertSame('cancelled_by_passenger', $ride->fresh()->status);
        $this->assertSame(RideOffer::WITHDRAWN, $offer->fresh()->status);
        $this->assertNull(RideOffer::where('pending_driver_id', $driver->id)->first(), 'the driver is free for other offers');
    }

    public function test_a_driver_backing_out_requeues_with_priority_and_is_not_offered_it_again(): void
    {
        $first = $this->onlineDriverAt(...$this->northOfPickup(100));
        $second = $this->onlineDriverAt(...$this->northOfPickup(1500));
        $ride = $this->requestRide();
        $this->dispatcher()->offerResponse($this->pendingOffer($ride), $first, 'accept');

        $this->dispatcher()->driverCancel($ride->fresh(), $first, CancelReason::VehicleProblem);

        $ride->refresh();
        $this->assertSame('searching', $ride->status);
        $this->assertSame(1, $ride->dispatch_priority);
        $this->assertSame(1, $ride->reassignment_count);
        $this->assertSame($second->id, $this->pendingOffer($ride)->driver_id);
        $this->assertSame(DriverAvailability::ONLINE, DriverAvailability::where('user_id', $first->id)->value('state'));
    }

    public function test_priority_rides_are_advanced_first_by_the_scheduler(): void
    {
        $older = $this->requestRide();
        $this->travel(1)->seconds();
        $priority = $this->requestRide();
        Ride::whereKey($priority->id)->update(['dispatch_priority' => 2]);
        $this->travel(5)->seconds();

        $driver = $this->onlineDriverAt(...$this->northOfPickup(100));
        $this->dispatcher()->tickDue(1);

        $this->assertSame($driver->id, $this->pendingOffer($priority)->driver_id);
        $this->assertNull($this->pendingOffer($older));
    }

    public function test_the_open_board_waits_for_its_delay_and_withdraws_the_pending_offer_on_claim(): void
    {
        config(['ehail_dispatch.open_board_after_seconds' => 45]);
        $offered = $this->onlineDriverAt(...$this->northOfPickup(100));
        $claimer = $this->onlineDriverAt(...$this->northOfPickup(9000));
        $ride = $this->requestRide();

        $this->assertFalse($this->dispatcher()->onBoard($ride->fresh()));
        $this->actingAs($claimer)->post(route('rides.accept', $ride))->assertForbidden();

        $this->travel(46)->seconds();
        $this->assertTrue($this->dispatcher()->onBoard($ride->fresh()));
        $this->actingAs($claimer)->post(route('rides.accept', $ride))->assertRedirect(route('rides.show', $ride));

        $this->assertSame($claimer->id, $ride->fresh()->driver_id);
        $this->assertSame(0, RideOffer::where('ride_id', $ride->id)->where('status', RideOffer::PENDING)->count());
        $this->assertNotNull(RideOffer::where('ride_id', $ride->id)->where('driver_id', $offered->id)->whereIn('status', [RideOffer::WITHDRAWN, RideOffer::EXPIRED])->first());
    }

    public function test_a_negative_board_delay_switches_the_board_off(): void
    {
        config(['ehail_dispatch.open_board_after_seconds' => -1]);
        $ride = $this->requestRide();
        $this->travel(5)->minutes();

        $this->assertFalse($this->dispatcher()->onBoard($ride->fresh()));
    }

    public function test_concurrent_ticks_respect_the_lease(): void
    {
        $this->onlineDriverAt(...$this->northOfPickup(100));
        $ride = $this->requestRide();
        $offerId = $this->pendingOffer($ride)->id;

        // The offer has lapsed, but another tick holds the lease: this one must not touch the ride.
        $this->travel(16)->seconds();
        Ride::whereKey($ride->id)->update(['dispatch_lease_until' => now()->addSeconds(5), 'dispatch_lease_owner' => 'other-worker']);
        $this->dispatcher()->tick($ride);
        $this->assertSame(RideOffer::PENDING, RideOffer::find($offerId)->status);

        $this->travel(6)->seconds();
        $this->dispatcher()->tick($ride);
        $this->assertSame(RideOffer::EXPIRED, RideOffer::find($offerId)->status, 'a stale lease is taken over');
    }

    public function test_the_scheduler_command_advances_lapsed_offers(): void
    {
        $first = $this->onlineDriverAt(...$this->northOfPickup(100));
        $second = $this->onlineDriverAt(...$this->northOfPickup(800));
        $ride = $this->requestRide();
        $this->travel(16)->seconds();
        $this->heartbeat($second, $this->northOfPickup(800));

        $this->artisan('ehail:dispatch-tick')->assertExitCode(0);

        $this->assertSame($second->id, $this->pendingOffer($ride)->driver_id);
        $this->assertNotSame($first->id, $this->pendingOffer($ride)->driver_id);
    }

    public function test_the_dispatch_tick_is_scheduled_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'ehail:dispatch-tick'));

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
    }

    public function test_no_show_needs_the_wait_and_ends_the_ride(): void
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(100));
        $ride = $this->requestRide();
        $this->dispatcher()->offerResponse($this->pendingOffer($ride), $driver, 'accept');
        $this->dispatcher()->advance($ride->fresh(), $driver, RideStatus::DriverArriving);
        $this->dispatcher()->advance($ride->fresh(), $driver, RideStatus::DriverArrived);

        $this->travel(4)->minutes();
        try {
            $this->dispatcher()->recordNoShow($ride->fresh(), $driver);
            $this->fail('a no-show before the wait must be refused');
        } catch (InvalidRideTransition $e) {
            $this->assertStringContainsString('5 minutes', $e->getMessage());
        }

        $this->travel(61)->seconds();
        $this->dispatcher()->recordNoShow($ride->fresh(), $driver);

        $this->assertSame('no_show', $ride->fresh()->status);
        $this->assertNull($ride->fresh()->active_driver_id);
        $this->assertSame(DriverAvailability::ONLINE, DriverAvailability::where('user_id', $driver->id)->value('state'));
        $this->assertSame(1, $this->notificationsCount($ride->passenger_id, 'ride_no_show'));
    }

    private function notificationsCount(int $userId, string $type): int
    {
        return User::find($userId)->notifications->filter(fn ($n) => ($n->data['type'] ?? null) === $type)->count();
    }
}
