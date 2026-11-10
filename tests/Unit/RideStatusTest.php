<?php

namespace Tests\Unit;

use App\Enums\RideActor;
use App\Enums\RideStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RideStatusTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function legalTransitions(): array
    {
        return [
            // R-46 state machine v2: the pre-R-46 edges under their v2 names.
            'driver claims from the open board' => ['requested', 'driver_assigned', 'driver'],
            'dispatch starts searching' => ['requested', 'searching', 'system'],
            'driver accepts an offer' => ['searching', 'driver_assigned', 'driver'],
            'passenger cancels a request' => ['requested', 'cancelled_by_passenger', 'passenger'],
            'passenger cancels while searching' => ['searching', 'cancelled_by_passenger', 'passenger'],
            'request expires' => ['requested', 'expired', 'system'],
            'search expires' => ['searching', 'expired', 'system'],
            'driver heads out' => ['driver_assigned', 'driver_arriving', 'driver'],
            'passenger cancels after accept' => ['driver_assigned', 'cancelled_by_passenger', 'passenger'],
            'driver cancel re-queues from driver_assigned' => ['driver_assigned', 'searching', 'driver'],
            'driver cancel re-queues from driver_arriving' => ['driver_arriving', 'searching', 'driver'],
            'driver cancel re-queues from driver_arrived' => ['driver_arrived', 'searching', 'driver'],
            'driver arrives' => ['driver_arriving', 'driver_arrived', 'driver'],
            'trip starts' => ['driver_arrived', 'in_progress', 'driver'],
            'driver records a no-show' => ['driver_arrived', 'no_show', 'driver'],
            'driver completes' => ['in_progress', 'completed', 'driver'],
            'operator completes' => ['in_progress', 'completed', 'operator'],
            'stuck trip is flagged' => ['in_progress', 'needs_review', 'system'],
            'operator completes a flagged trip' => ['needs_review', 'completed', 'operator'],
            'operator cancels a flagged trip' => ['needs_review', 'cancelled_by_operator', 'operator'],
            'too many reassignments end it as cancelled_by_driver' => ['driver_assigned', 'cancelled_by_driver', 'driver'],
            'scheduled ride reaches its lead time' => ['scheduled', 'requested', 'system'],
            'passenger cancels a scheduled ride' => ['scheduled', 'cancelled_by_passenger', 'passenger'],
            'operator reassigns' => ['driver_arriving', 'searching', 'operator'],
            'system fault fails a search' => ['searching', 'failed', 'system'],
        ];
    }

    #[DataProvider('legalTransitions')]
    public function test_legal_transitions_are_allowed_for_the_right_actor(string $from, string $to, string $actor): void
    {
        $this->assertTrue(RideStatus::from($from)->canTransitionTo(RideStatus::from($to), RideActor::from($actor)));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function illegalTransitions(): array
    {
        return [
            'requested straight to driver_arriving' => ['requested', 'driver_arriving'],
            'driver_assigned straight to driver_arrived' => ['driver_assigned', 'driver_arrived'],
            'driver_assigned straight to in_progress' => ['driver_assigned', 'in_progress'],
            'driver_assigned straight to completed' => ['driver_assigned', 'completed'],
            'driver_arriving straight to in_progress' => ['driver_arriving', 'in_progress'],
            'driver_arrived straight to completed' => ['driver_arrived', 'completed'],
            'backwards: driver_arriving to driver_assigned' => ['driver_arriving', 'driver_assigned'],
            'backwards: completed to in_progress' => ['completed', 'in_progress'],
            'completed to cancelled' => ['completed', 'cancelled_by_operator'],
            'cancelled to requested' => ['cancelled_by_passenger', 'requested'],
            'expired to driver_assigned' => ['expired', 'driver_assigned'],
            'needs_review back to in_progress' => ['needs_review', 'in_progress'],
            'in_progress back to searching' => ['in_progress', 'searching'],
            'no_show to anything' => ['no_show', 'searching'],
            'scheduled straight to driver_assigned' => ['scheduled', 'driver_assigned'],
        ];
    }

    #[DataProvider('illegalTransitions')]
    public function test_skipped_or_backward_transitions_are_refused_for_every_actor(string $from, string $to): void
    {
        foreach (RideActor::cases() as $actor) {
            $this->assertFalse(
                RideStatus::from($from)->canTransitionTo(RideStatus::from($to), $actor),
                "{$actor->value} must not move a ride {$from} -> {$to}",
            );
        }
    }

    public function test_actors_cannot_use_transitions_that_belong_to_another_role(): void
    {
        $this->assertFalse(RideStatus::Requested->canTransitionTo(RideStatus::DriverAssigned, RideActor::Passenger));
        $this->assertFalse(RideStatus::Requested->canTransitionTo(RideStatus::Expired, RideActor::Passenger));
        $this->assertFalse(RideStatus::InProgress->canTransitionTo(RideStatus::CancelledByPassenger, RideActor::Passenger));
        $this->assertFalse(RideStatus::InProgress->canTransitionTo(RideStatus::CancelledByDriver, RideActor::Driver));
        $this->assertFalse(RideStatus::NeedsReview->canTransitionTo(RideStatus::Completed, RideActor::Driver));
        $this->assertFalse(RideStatus::DriverArrived->canTransitionTo(RideStatus::NoShow, RideActor::Passenger));
        $this->assertFalse(RideStatus::Searching->canTransitionTo(RideStatus::CancelledByPassenger, RideActor::Driver));
    }

    public function test_terminal_states_have_no_exits(): void
    {
        foreach ([RideStatus::Completed, RideStatus::CancelledByPassenger, RideStatus::CancelledByDriver, RideStatus::CancelledByOperator, RideStatus::Expired, RideStatus::NoShow, RideStatus::Failed] as $status) {
            $this->assertTrue($status->isTerminal());
            $this->assertSame([], $status->allowedTransitions());
        }

        $this->assertFalse(RideStatus::NeedsReview->isTerminal());
    }

    public function test_the_driver_forward_ladder_is_one_step_at_a_time(): void
    {
        $this->assertSame(RideStatus::DriverArriving, RideStatus::DriverAssigned->nextStage());
        $this->assertSame(RideStatus::DriverArrived, RideStatus::DriverArriving->nextStage());
        $this->assertSame(RideStatus::InProgress, RideStatus::DriverArrived->nextStage());
        $this->assertSame(RideStatus::Completed, RideStatus::InProgress->nextStage());
        $this->assertNull(RideStatus::Completed->nextStage());
        $this->assertNull(RideStatus::Requested->nextStage());
    }

    public function test_every_database_status_value_has_a_case(): void
    {
        $this->assertSame(
            ['scheduled', 'requested', 'searching', 'driver_assigned', 'driver_arriving', 'driver_arrived', 'in_progress', 'completed',
                'cancelled_by_passenger', 'cancelled_by_driver', 'cancelled_by_operator', 'expired', 'no_show', 'failed', 'needs_review'],
            array_map(fn (RideStatus $s) => $s->value, RideStatus::cases()),
        );
    }

    public function test_pre_v2_values_still_resolve_through_their_aliases(): void
    {
        $this->assertSame(RideStatus::DriverAssigned, RideStatus::fromStored('accepted'));
        $this->assertSame(RideStatus::DriverArriving, RideStatus::fromStored('en_route'));
        $this->assertSame(RideStatus::DriverArrived, RideStatus::fromStored('arrived'));
        $this->assertSame(RideStatus::CancelledByOperator, RideStatus::fromStored('cancelled'));
        $this->assertSame(RideStatus::Searching, RideStatus::fromStored('searching'));
    }
}
