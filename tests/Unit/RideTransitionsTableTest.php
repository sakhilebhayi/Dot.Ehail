<?php

namespace Tests\Unit;

use App\Domain\Rides\RideTransitions;
use App\Enums\RideActor;
use App\Enums\RideStatus;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive checks of the edge table (state machine v2): every
 * (from, to, actor) triple is either an edge the table lists or refused.
 */
class RideTransitionsTableTest extends TestCase
{
    public function test_every_state_has_a_row_and_every_target_is_a_state(): void
    {
        $table = RideTransitions::table();

        foreach (RideStatus::cases() as $status) {
            $this->assertArrayHasKey($status->value, $table, "{$status->value} has no row");

            foreach (array_keys($table[$status->value]) as $to) {
                $this->assertNotNull(RideStatus::tryFrom($to), "{$status->value} -> {$to} targets an unknown state");
            }
        }
    }

    public function test_every_triple_matches_the_table_exactly(): void
    {
        $checked = 0;

        foreach (RideStatus::cases() as $from) {
            foreach (RideStatus::cases() as $to) {
                foreach (RideActor::cases() as $actor) {
                    $edge = RideTransitions::edge($from, $to);
                    $expected = $edge !== null && in_array($actor, $edge['actors'], true);
                    $this->assertSame($expected, $from->canTransitionTo($to, $actor), "{$actor->value}: {$from->value} -> {$to->value}");
                    $checked++;
                }
            }
        }

        $this->assertSame(15 * 15 * 4, $checked);
    }

    public function test_no_state_transitions_to_itself(): void
    {
        foreach (RideStatus::cases() as $status) {
            $this->assertFalse($status->canTransitionTo($status), $status->value);
        }
    }

    public function test_terminal_states_are_exactly_the_documented_ones(): void
    {
        $terminal = array_values(array_map(fn (RideStatus $s) => $s->value, array_filter(RideStatus::cases(), fn (RideStatus $s) => $s->isTerminal())));

        $this->assertSame(['completed', 'cancelled_by_passenger', 'cancelled_by_driver', 'cancelled_by_operator', 'expired', 'no_show', 'failed'], $terminal);
    }

    public function test_every_non_terminal_state_can_reach_a_terminal_state_and_an_operator_can_end_it(): void
    {
        foreach (RideStatus::cases() as $status) {
            if ($status->isTerminal()) {
                continue;
            }

            $this->assertTrue($status->canTransitionTo(RideStatus::CancelledByOperator, RideActor::Operator), "an operator cannot end {$status->value}");
        }
    }

    public function test_every_state_is_reachable_from_scheduled_or_requested(): void
    {
        $reached = ['scheduled' => true, 'requested' => true];
        $frontier = ['scheduled', 'requested'];

        while ($frontier !== []) {
            $from = array_pop($frontier);

            foreach (array_keys(RideTransitions::table()[$from]) as $to) {
                if (! isset($reached[$to])) {
                    $reached[$to] = true;
                    $frontier[] = $to;
                }
            }
        }

        foreach (RideStatus::cases() as $status) {
            $this->assertArrayHasKey($status->value, $reached, "{$status->value} is unreachable");
        }
    }

    public function test_only_the_system_moves_rides_into_searching_from_requested_or_expires_them(): void
    {
        foreach ([RideActor::Passenger, RideActor::Driver, RideActor::Operator] as $actor) {
            $this->assertFalse(RideStatus::Requested->canTransitionTo(RideStatus::Searching, $actor));
            $this->assertFalse(RideStatus::Searching->canTransitionTo(RideStatus::Expired, $actor));
        }
    }

    public function test_guards_and_effects_are_named_from_the_known_sets(): void
    {
        $guards = ['party', 'lead_time', 'unassigned', 'driver_free', 'pin', 'no_show_wait', 'can_requeue', 'requeue_spent', 'final_fare', 'not_started', 'window_elapsed', 'slot_missed'];
        $effects = ['stamp_requested', 'start_search', 'assign_driver', 'withdraw_offers', 'stamp_arriving', 'stamp_arrived', 'stamp_started',
            'complete_trip', 'release_driver', 'unassign', 'requeue', 'stamp_cancelled', 'cancellation_fee', 'stamp_expired', 'stamp_no_show',
            'stamp_failed', 'stamp_needs_review'];

        foreach (RideTransitions::table() as $from => $edges) {
            foreach ($edges as $to => $edge) {
                $this->assertSame([], array_diff($edge['guards'], $guards), "{$from} -> {$to} names an unknown guard");
                $this->assertSame([], array_diff($edge['effects'], $effects), "{$from} -> {$to} names an unknown effect");
            }
        }
    }

    public function test_every_edge_into_a_driver_holding_state_from_outside_assigns_a_driver(): void
    {
        foreach (RideTransitions::table() as $from => $edges) {
            foreach ($edges as $to => $edge) {
                if (RideStatus::from($to)->holdsDriver() && ! RideStatus::from($from)->holdsDriver()) {
                    $this->assertContains('assign_driver', $edge['effects'], "{$from} -> {$to}");
                    $this->assertContains('driver_free', $edge['guards'], "{$from} -> {$to}");
                }

                if (RideStatus::from($from)->holdsDriver() && ! RideStatus::from($to)->holdsDriver()) {
                    $this->assertContains('release_driver', $edge['effects'], "{$from} -> {$to} must release the driver");
                }
            }
        }
    }
}
