<?php

namespace Tests\Feature\Dispatch;

use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Rides\Events\RideRequested;
use App\Domain\Rides\Events\ScheduledRideReminderDue;
use App\Domain\Rides\Events\ScheduledRideRequoteRequired;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\TestCase;

class ScheduledRidesTest extends TestCase
{
    use CreatesDispatchFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeQuotes();
        $this->freezeSecond();
    }

    private function book(User $passenger, string $in = '+2 hours'): Ride
    {
        $quote = $this->quotes->issue($passenger);
        $id = $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), [
            'quote_id' => $quote->id, 'scheduled_for' => now()->modify($in)->toIso8601String(),
        ])->assertCreated()->json('ride.id');

        return Ride::findOrFail($id);
    }

    public function test_booking_ahead_creates_a_scheduled_ride_awaiting_a_driver(): void
    {
        Event::fake([RideRequested::class]);
        $passenger = $this->passenger();
        $ride = $this->book($passenger);

        $this->assertSame('scheduled', $ride->status);
        $this->assertNull($ride->active_passenger_id, 'a future booking is not an active ride');
        $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', $ride))
            ->assertOk()->assertJsonPath('ride.schedule_phase', 'awaiting_driver');
        Event::assertNotDispatched(RideRequested::class);

        // A passenger with a scheduled ride can still ride now.
        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $this->quotes->issue($passenger)->id])->assertCreated();
    }

    public function test_lead_time_limits_are_enforced(): void
    {
        $passenger = $this->passenger();

        foreach (['+10 minutes', '+31 days'] as $when) {
            $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), [
                'quote_id' => $this->quotes->issue($passenger)->id, 'scheduled_for' => now()->modify($when)->toIso8601String(),
            ])->assertStatus(422)->assertJsonPath('error', 'schedule_out_of_range');
        }
    }

    public function test_reminder_then_allocation_at_the_lead_time_with_the_booked_fare_honoured(): void
    {
        Event::fake([ScheduledRideReminderDue::class, RideRequested::class]);
        $passenger = $this->passenger();
        $ride = $this->book($passenger);
        $this->quotes->nextRequoteTotal = 8900; // +4.7%: inside the 10% guarantee

        $this->travel(61)->minutes(); // 59 minutes before pickup: inside the 60-minute reminder lead
        $this->artisan('ehail:dispatch-tick');
        Event::assertDispatchedTimes(ScheduledRideReminderDue::class, 1);
        $this->assertSame('scheduled', $ride->fresh()->status);

        $this->artisan('ehail:dispatch-tick');
        Event::assertDispatchedTimes(ScheduledRideReminderDue::class, 1);

        $this->travel(44)->minutes(); // 15 minutes before pickup
        $driver = $this->onlineDriverAt(...$this->northOfPickup(300));
        $this->artisan('ehail:dispatch-tick');

        $ride->refresh();
        $this->assertSame('searching', $ride->status);
        $this->assertSame(8500, $ride->quoted_total_minor, 'the booked fare stands inside the guarantee');
        $this->assertSame($driver->id, RideOffer::where('pending_ride_id', $ride->id)->value('driver_id'));
        Event::assertDispatched(RideRequested::class, fn ($e) => $e->rideId === $ride->id);

        app(Dispatcher::class)->offerResponse(RideOffer::where('pending_ride_id', $ride->id)->first(), $driver, 'accept');
        $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', $ride))->assertJsonPath('ride.schedule_phase', 'driver_confirmed');
    }

    public function test_a_fare_outside_the_guarantee_waits_for_the_passenger(): void
    {
        Event::fake([ScheduledRideRequoteRequired::class]);
        $passenger = $this->passenger();
        $ride = $this->book($passenger);
        $this->quotes->nextRequoteTotal = 10200; // +20%

        $this->travel(106)->minutes();
        $this->artisan('ehail:dispatch-tick');

        $ride->refresh();
        $this->assertSame('scheduled', $ride->status);
        $this->assertSame(10200, $ride->requote_total_minor);
        Event::assertDispatched(ScheduledRideRequoteRequired::class);
        $this->actingAs($passenger)->getJson(route('ehail.dispatch.rides.feed', $ride))->assertJsonPath('ride.requote.total_minor', 10200);

        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.requote.accept', $ride))->assertOk()->assertJsonPath('ride.status', 'searching');
        $this->assertSame(10200, $ride->fresh()->quoted_total_minor);
    }

    public function test_when_pricing_cannot_requote_the_booked_fare_is_honoured(): void
    {
        $ride = $this->book($this->passenger());
        $this->quotes->nextRequoteTotal = null;

        $this->travel(106)->minutes();
        $this->artisan('ehail:dispatch-tick');

        $this->assertSame('searching', $ride->fresh()->status);
        $this->assertSame('booked_fare_honoured_no_requote', $ride->events()->where('to_status', 'requested')->value('reason_code'));
    }

    public function test_an_unanswered_requote_misses_the_slot_and_expires(): void
    {
        $ride = $this->book($this->passenger());
        $this->quotes->nextRequoteTotal = 20000;
        $this->travel(106)->minutes();
        $this->artisan('ehail:dispatch-tick');

        $this->travel(26)->minutes();
        $this->artisan('ehail:dispatch-tick');

        $this->assertSame('expired', $ride->fresh()->status);
    }

    public function test_reschedule_and_cancel_policies(): void
    {
        $passenger = $this->passenger();
        $ride = $this->book($passenger, '+3 hours');

        $this->actingAs($passenger)->patchJson(route('ehail.dispatch.rides.reschedule', $ride), ['scheduled_for' => now()->addHours(4)->toIso8601String()])
            ->assertOk();
        $this->assertTrue($ride->fresh()->scheduled_for->eq(now()->addHours(4)));

        $this->travel(185)->minutes(); // 55 minutes before pickup: inside the 60-minute no-change window
        $this->actingAs($passenger)->patchJson(route('ehail.dispatch.rides.reschedule', $ride), ['scheduled_for' => now()->addHours(2)->toIso8601String()])
            ->assertStatus(422)->assertJsonPath('error', 'reschedule_too_late');

        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.cancel', $ride), ['reason' => 'changed_plans'])->assertOk()
            ->assertJsonPath('ride.status', 'cancelled_by_passenger');

        $stranger = $this->passenger();
        $this->actingAs($stranger)->patchJson(route('ehail.dispatch.rides.reschedule', $ride), ['scheduled_for' => now()->addHours(5)->toIso8601String()])->assertForbidden();
    }

    public function test_a_passenger_still_riding_delays_allocation_of_their_next_booking(): void
    {
        $passenger = $this->passenger();
        $booking = $this->book($passenger);
        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => $this->quotes->issue($passenger)->id])->assertCreated();

        $this->travel(106)->minutes();
        // The on-demand ride's search window has run out by now; keep it active for this test.
        Ride::where('passenger_id', $passenger->id)->where('status', 'searching')->update(['search_started_at' => now()]);
        $this->artisan('ehail:dispatch-tick');

        $this->assertSame('scheduled', $booking->fresh()->status);
    }
}
