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
            'driver accepts' => ['requested', 'accepted', 'driver'],
            'passenger cancels a request' => ['requested', 'cancelled', 'passenger'],
            'request expires' => ['requested', 'expired', 'system'],
            'driver heads out' => ['accepted', 'en_route', 'driver'],
            'passenger cancels after accept' => ['accepted', 'cancelled', 'passenger'],
            'driver cancel re-queues from accepted' => ['accepted', 'requested', 'driver'],
            'driver cancel re-queues from en_route' => ['en_route', 'requested', 'driver'],
            'driver cancel re-queues from arrived' => ['arrived', 'requested', 'driver'],
            'driver arrives' => ['en_route', 'arrived', 'driver'],
            'trip starts' => ['arrived', 'in_progress', 'driver'],
            'driver completes' => ['in_progress', 'completed', 'driver'],
            'operator completes' => ['in_progress', 'completed', 'operator'],
            'stuck trip is flagged' => ['in_progress', 'needs_review', 'system'],
            'operator completes a flagged trip' => ['needs_review', 'completed', 'operator'],
            'operator cancels a flagged trip' => ['needs_review', 'cancelled', 'operator'],
            'too many reassignments expire it' => ['accepted', 'expired', 'driver'],
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
            'requested straight to en_route' => ['requested', 'en_route'],
            'accepted straight to arrived' => ['accepted', 'arrived'],
            'accepted straight to in_progress' => ['accepted', 'in_progress'],
            'accepted straight to completed' => ['accepted', 'completed'],
            'en_route straight to in_progress' => ['en_route', 'in_progress'],
            'arrived straight to completed' => ['arrived', 'completed'],
            'backwards: en_route to accepted' => ['en_route', 'accepted'],
            'backwards: completed to in_progress' => ['completed', 'in_progress'],
            'completed to cancelled' => ['completed', 'cancelled'],
            'cancelled to requested' => ['cancelled', 'requested'],
            'expired to accepted' => ['expired', 'accepted'],
            'needs_review back to in_progress' => ['needs_review', 'in_progress'],
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
        $this->assertFalse(RideStatus::Requested->canTransitionTo(RideStatus::Accepted, RideActor::Passenger));
        $this->assertFalse(RideStatus::Requested->canTransitionTo(RideStatus::Expired, RideActor::Passenger));
        $this->assertFalse(RideStatus::InProgress->canTransitionTo(RideStatus::Cancelled, RideActor::Passenger));
        $this->assertFalse(RideStatus::InProgress->canTransitionTo(RideStatus::Cancelled, RideActor::Driver));
        $this->assertFalse(RideStatus::NeedsReview->canTransitionTo(RideStatus::Completed, RideActor::Driver));
    }

    public function test_terminal_states_have_no_exits(): void
    {
        foreach ([RideStatus::Completed, RideStatus::Cancelled, RideStatus::Expired] as $status) {
            $this->assertTrue($status->isTerminal());
            $this->assertSame([], $status->allowedTransitions());
        }

        $this->assertFalse(RideStatus::NeedsReview->isTerminal());
    }

    public function test_the_driver_forward_ladder_is_one_step_at_a_time(): void
    {
        $this->assertSame(RideStatus::EnRoute, RideStatus::Accepted->nextStage());
        $this->assertSame(RideStatus::Arrived, RideStatus::EnRoute->nextStage());
        $this->assertSame(RideStatus::InProgress, RideStatus::Arrived->nextStage());
        $this->assertSame(RideStatus::Completed, RideStatus::InProgress->nextStage());
        $this->assertNull(RideStatus::Completed->nextStage());
        $this->assertNull(RideStatus::Requested->nextStage());
    }

    public function test_every_database_status_value_has_a_case(): void
    {
        $this->assertSame(
            ['requested', 'accepted', 'en_route', 'arrived', 'in_progress', 'completed', 'cancelled', 'expired', 'needs_review'],
            array_map(fn (RideStatus $s) => $s->value, RideStatus::cases()),
        );
    }
}
