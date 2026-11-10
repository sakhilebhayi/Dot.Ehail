<?php

namespace Tests\Feature\Dispatch;

use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Exceptions\DispatchRefused;
use App\Domain\Dispatch\Models\RideOffer;
use App\Domain\Rides\Events\RideCancelled;
use App\Domain\Rides\Events\RideEventBroadcast;
use App\Domain\Rides\Events\TripCompleted;
use App\Domain\Rides\Events\TripStarted;
use App\Domain\Rides\Models\RideEvent;
use App\Domain\Rides\RideStateMachine;
use App\Domain\Rides\TransitionOptions;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\User;
use App\Services\Ehail\InvalidRideTransition;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class RideStateMachineTest extends TestCase
{
    use CreatesDispatchFixtures, CreatesEhailFixtures, RefreshDatabase {
        CreatesDispatchFixtures::passenger insteadof CreatesEhailFixtures;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeQuotes();
    }

    private function machine(): RideStateMachine
    {
        return app(RideStateMachine::class);
    }

    /**
     * @return array{0: Ride, 1: User}
     */
    private function assignedRide(): array
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(200));
        $ride = $this->requestRide();
        app(Dispatcher::class)->offerResponse(RideOffer::where('pending_ride_id', $ride->id)->firstOrFail(), $driver, 'accept');

        return [$ride->fresh(), $driver];
    }

    public function test_every_transition_appends_to_the_timeline_with_who_what_and_why(): void
    {
        [$ride, $driver] = $this->assignedRide();

        $types = $ride->events()->pluck('type')->all();
        $this->assertSame('created', $types[0]);
        $this->assertContains('offer_sent', $types);

        $assigned = $ride->events()->where('to_status', 'driver_assigned')->first();
        $this->assertSame('searching', $assigned->from_status);
        $this->assertSame('driver', $assigned->actor_type);
        $this->assertSame($driver->id, $assigned->actor_id);
        $this->assertSame('offer_accepted', $assigned->reason_code);
        $this->assertArrayHasKey('offer_id', $assigned->context);

        $sequences = $ride->events()->pluck('sequence')->all();
        $this->assertSame(range(1, count($sequences)), $sequences, 'sequence numbers are gapless and ordered');
    }

    public function test_ride_events_are_append_only(): void
    {
        [$ride] = $this->assignedRide();
        $event = RideEvent::where('ride_id', $ride->id)->first();

        $this->expectException(LogicException::class);
        $event->update(['type' => 'tampered']);
    }

    public function test_ride_events_cannot_be_deleted_through_the_model(): void
    {
        [$ride] = $this->assignedRide();

        $this->expectException(LogicException::class);
        RideEvent::where('ride_id', $ride->id)->first()->delete();
    }

    public function test_a_transition_key_makes_a_retry_a_no_op(): void
    {
        $passenger = $this->passenger();
        $ride = $this->requestRide($passenger);
        $options = new TransitionOptions(key: 'client-retry-1', reason: 'changed_plans');

        $this->machine()->transition($ride, RideStatus::CancelledByPassenger, RideActor::Passenger, $passenger, $options);
        $again = $this->machine()->transition($ride, RideStatus::CancelledByPassenger, RideActor::Passenger, $passenger, $options);

        $this->assertSame('cancelled_by_passenger', $again->status);
        $this->assertSame(1, $ride->events()->where('transition_key', 'client-retry-1')->count());
    }

    public function test_the_wrong_actor_or_party_is_refused(): void
    {
        [$ride, $driver] = $this->assignedRide();
        $stranger = $this->passenger();

        foreach ([
            fn () => $this->machine()->transition($ride, RideStatus::DriverArriving, RideActor::Driver, $stranger),
            fn () => $this->machine()->transition($ride, RideStatus::CancelledByPassenger, RideActor::Passenger, $stranger),
            fn () => $this->machine()->transition($ride, RideStatus::DriverArriving, RideActor::Passenger, User::find($ride->passenger_id)),
            fn () => $this->machine()->transition($ride, RideStatus::Completed, RideActor::Driver, $driver),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('the transition must be refused');
            } catch (InvalidRideTransition) {
                $this->assertSame('driver_assigned', $ride->fresh()->status);
            }
        }
    }

    public function test_starting_a_trip_needs_the_passengers_pin_and_wrong_pins_are_counted_and_locked(): void
    {
        Event::fake([TripStarted::class]);
        [$ride, $driver] = $this->assignedRide();
        $dispatcher = app(Dispatcher::class);
        $dispatcher->advance($ride, $driver, RideStatus::DriverArriving);
        $dispatcher->advance($ride->fresh(), $driver, RideStatus::DriverArrived);

        $pin = $ride->fresh()->pin_encrypted;
        $this->assertMatchesRegularExpression('/^\d{4}$/', $pin);
        $this->assertNotSame($pin, $ride->fresh()->pin_hash, 'only an HMAC is used for checking');
        $wrong = $pin === '0000' ? '1111' : '0000';

        for ($i = 1; $i <= 5; $i++) {
            try {
                $dispatcher->advance($ride->fresh(), $driver, RideStatus::InProgress, $wrong);
                $this->fail('a wrong PIN must be refused');
            } catch (DispatchRefused $e) {
                $this->assertSame('pin_wrong', $e->reason);
            }
        }

        $this->assertNotNull($ride->fresh()->pin_locked_until, 'five wrong PINs lock entry');
        $this->assertSame(5, AuditLog::where('action', 'ride.pin_rejected')->count());

        try {
            $dispatcher->advance($ride->fresh(), $driver, RideStatus::InProgress, $pin);
            $this->fail('even the right PIN waits out the lock');
        } catch (DispatchRefused $e) {
            $this->assertSame('pin_locked', $e->reason);
        }

        $this->travel(11)->minutes();
        $dispatcher->advance($ride->fresh(), $driver, RideStatus::InProgress, $pin);
        $this->assertSame('in_progress', $ride->fresh()->status);
        Event::assertDispatched(TripStarted::class);
    }

    public function test_a_market_can_switch_the_pin_off(): void
    {
        config(['ehail_dispatch.pin.required' => false]);
        [$ride, $driver] = $this->assignedRide();
        $dispatcher = app(Dispatcher::class);

        foreach ([RideStatus::DriverArriving, RideStatus::DriverArrived, RideStatus::InProgress] as $stage) {
            $dispatcher->advance($ride->fresh(), $driver, $stage);
        }

        $this->assertSame('in_progress', $ride->fresh()->status);
    }

    public function test_completing_a_quoted_ride_uses_the_agreed_fare_in_minor_units(): void
    {
        config(['ehail_dispatch.pin.required' => false]);
        Event::fake([TripCompleted::class]);
        [$ride, $driver] = $this->assignedRide();
        $dispatcher = app(Dispatcher::class);

        foreach ([RideStatus::DriverArriving, RideStatus::DriverArrived, RideStatus::InProgress, RideStatus::Completed] as $stage) {
            $dispatcher->advance($ride->fresh(), $driver, $stage);
        }

        $ride->refresh();
        $this->assertSame(8500, $ride->final_fare_minor);
        $this->assertSame('85.00', (string) $ride->final_fare);
        $this->assertSame('ZAR', $ride->currency);
        $this->assertNull($ride->active_driver_id);
        $this->assertNull($ride->active_passenger_id);
        Event::assertDispatched(TripCompleted::class, fn ($e) => $e->rideId === $ride->id && $e->fromStatus === 'in_progress');
    }

    public function test_domain_events_wait_for_the_commit(): void
    {
        Event::fake([RideCancelled::class]);
        $passenger = $this->passenger();
        $ride = $this->requestRide($passenger);

        try {
            DB::transaction(function () use ($ride, $passenger) {
                $this->machine()->transition($ride, RideStatus::CancelledByPassenger, RideActor::Passenger, $passenger,
                    new TransitionOptions(reason: 'changed_plans'));

                throw new \RuntimeException('roll back');
            });
        } catch (\RuntimeException) {
        }

        Event::assertNotDispatched(RideCancelled::class);
        $this->assertSame('searching', $ride->fresh()->status);
    }

    public function test_broadcast_variants_are_sent_only_when_switched_on(): void
    {
        Event::fake([RideEventBroadcast::class, RideCancelled::class]);
        $passenger = $this->passenger();
        $ride = $this->requestRide($passenger);
        $this->machine()->transition($ride, RideStatus::CancelledByPassenger, RideActor::Passenger, $passenger, new TransitionOptions(reason: 'changed_plans'));
        Event::assertNotDispatched(RideEventBroadcast::class);

        config(['ehail_dispatch.broadcast_events' => true]);
        $other = $this->passenger();
        $second = $this->requestRide($other);
        $this->machine()->transition($second, RideStatus::CancelledByPassenger, RideActor::Passenger, $other, new TransitionOptions(reason: 'changed_plans'));
        Event::assertDispatched(RideEventBroadcast::class, fn ($e) => $e->broadcastAs() === 'ride.cancelled'
            && $e->broadcastOn()[0]->name === 'private-ride.'.$second->id
            && ! array_key_exists('pickup', $e->broadcastWith()));
    }

    public function test_the_database_refuses_a_second_active_ride_for_one_driver(): void
    {
        [$ride, $driver] = $this->assignedRide();

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeRide(['status' => 'driver_assigned', 'driver_id' => $driver->id]);
    }

    public function test_the_database_refuses_a_second_active_ride_for_one_passenger(): void
    {
        $passenger = $this->passenger();
        $this->requestRide($passenger);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->makeRide([], $passenger);
    }

    public function test_the_state_machine_refuses_a_busy_driver_with_a_clear_message(): void
    {
        [, $driver] = $this->assignedRide();
        $other = $this->makeRide(['status' => 'searching']);

        $this->expectExceptionMessage('You already have an active ride.');
        $this->machine()->transition($other, RideStatus::DriverAssigned, RideActor::Driver, $driver);
    }

    public function test_an_operator_cannot_assign_a_driver_who_is_on_a_ride(): void
    {
        [, $driver] = $this->assignedRide();
        $other = $this->makeRide(['status' => 'searching']);

        $this->expectExceptionMessage('That driver already has an active ride.');
        $this->machine()->transition($other, RideStatus::DriverAssigned, RideActor::Operator, $this->makeOperator(), new TransitionOptions(driver: $driver));
    }

    public function test_finished_rides_release_both_guards_so_the_next_ride_can_start(): void
    {
        $passenger = $this->passenger();
        $ride = $this->requestRide($passenger);
        $this->machine()->transition($ride, RideStatus::CancelledByPassenger, RideActor::Passenger, $passenger, new TransitionOptions(reason: 'changed_plans'));

        $next = $this->requestRide($passenger);
        $this->assertSame('searching', $next->status);
    }
}
