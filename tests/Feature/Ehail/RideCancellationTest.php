<?php

namespace Tests\Feature\Ehail;

use App\Events\RideStatusUpdated;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class RideCancellationTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    private function accepted(User $passenger, User $driver, array $attributes = []): Ride
    {
        $ride = $this->makeRide($attributes, $passenger);
        $this->actingAs($driver)->post(route('rides.accept', $ride))->assertRedirect();

        return $ride->fresh();
    }

    public function test_a_cancellation_must_carry_a_reason(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide([], $passenger);

        $this->actingAs($passenger)->post(route('rides.cancel', $ride))->assertSessionHasErrors('reason');
        $this->actingAs($passenger)->post(route('rides.cancel', $ride), ['reason' => 'not-a-reason'])->assertSessionHasErrors('reason');

        $this->assertSame('requested', $ride->fresh()->status);
    }

    public function test_a_passenger_cancelling_records_who_why_and_when(): void
    {
        Carbon::setTestNow('2026-10-18 09:00:00');
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide([], $passenger);

        $this->actingAs($passenger)
            ->post(route('rides.cancel', $ride), ['reason' => 'changed_plans', 'note' => 'Meeting moved to tomorrow'])
            ->assertRedirect(route('rides.show', $ride));

        $ride->refresh();
        $this->assertSame('cancelled', $ride->status);
        $this->assertSame($passenger->id, $ride->cancelled_by);
        $this->assertSame('changed_plans', $ride->cancel_reason);
        $this->assertSame('Meeting moved to tomorrow', $ride->cancel_note);
        $this->assertSame('2026-10-18 09:00:00', $ride->cancelled_at->format('Y-m-d H:i:s'));
    }

    public function test_a_passenger_cannot_use_a_driver_only_reason(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide([], $passenger);

        $this->actingAs($passenger)
            ->post(route('rides.cancel', $ride), ['reason' => 'passenger_no_show'])
            ->assertSessionHasErrors('reason');

        $this->assertSame('requested', $ride->fresh()->status);
    }

    public function test_the_note_is_capped_at_500_characters(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide([], $passenger);

        $this->actingAs($passenger)
            ->post(route('rides.cancel', $ride), ['reason' => 'other', 'note' => str_repeat('x', 501)])
            ->assertSessionHasErrors('note');
    }

    public function test_the_driver_is_notified_when_the_passenger_cancels_after_accepting(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();
        $ride = $this->accepted($passenger, $driver);

        $this->actingAs($passenger)
            ->post(route('rides.cancel', $ride), ['reason' => 'found_other_ride'])
            ->assertRedirect();

        $this->assertSame('cancelled', $ride->fresh()->status);
        $notice = $this->notificationsOfType($driver, 'ride_cancelled')->first();
        $this->assertNotNull($notice, 'the driver must be told the passenger cancelled');
        $this->assertSame($ride->id, $notice->data['ride_id']);
        $this->assertSame(0, $this->notificationsOfType($passenger, 'ride_cancelled')->count());
    }

    public function test_a_driver_cancelling_after_accept_returns_the_ride_to_the_queue(): void
    {
        Carbon::setTestNow('2026-10-18 09:00:00');
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();
        $ride = $this->accepted($passenger, $driver);

        Carbon::setTestNow('2026-10-18 09:04:00');
        $this->actingAs($driver)
            ->post(route('rides.cancel', $ride), ['reason' => 'vehicle_problem'])
            ->assertRedirect();

        $ride->refresh();
        $this->assertSame('requested', $ride->status);
        $this->assertNull($ride->driver_id);
        $this->assertNull($ride->vehicle_id);
        $this->assertNull($ride->accepted_at);
        $this->assertSame(1, $ride->reassignment_count);
        $this->assertNull($ride->cancelled_by, 'the ride itself was not cancelled, only the assignment');
        $this->assertSame(
            '2026-10-18 09:04:00',
            $ride->requested_at->format('Y-m-d H:i:s'),
            'the passenger gets a fresh waiting window rather than expiring immediately',
        );

        $notice = $this->notificationsOfType($passenger, 'ride_requeued')->first();
        $this->assertNotNull($notice, 'the passenger must be told their driver cancelled');
    }

    public function test_the_driver_cancellation_survives_in_the_audit_log_even_though_the_ride_is_requeued(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();
        $ride = $this->accepted($passenger, $driver);

        $this->actingAs($driver)->post(route('rides.cancel', $ride), ['reason' => 'safety_concern', 'note' => 'Road closed']);

        $row = AuditLog::where('action', 'ride.driver_cancelled')->first();
        $this->assertNotNull($row);
        $this->assertSame($driver->id, $row->actor_user_id);
        $this->assertSame($ride->id, (int) $row->subject_id);
        $this->assertSame('safety_concern', $row->meta['reason']);
        $this->assertSame('requeued', $row->meta['outcome']);
    }

    public function test_a_requeued_ride_can_be_accepted_by_another_driver(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $first = $this->makeApprovedDriver();
        $second = $this->makeApprovedDriver();
        $ride = $this->accepted($passenger, $first);

        $this->actingAs($first)->post(route('rides.cancel', $ride), ['reason' => 'vehicle_problem']);
        $this->actingAs($second)->post(route('rides.accept', $ride))->assertRedirect(route('rides.show', $ride));

        $this->assertSame($second->id, $ride->fresh()->driver_id);
        $this->assertSame('accepted', $ride->fresh()->status);
    }

    public function test_after_three_reassignments_the_next_driver_cancel_expires_the_ride(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide([], $passenger);

        foreach ([1, 2, 3] as $expectedCount) {
            $driver = $this->makeApprovedDriver();
            $this->actingAs($driver)->post(route('rides.accept', $ride))->assertRedirect();
            $this->actingAs($driver)->post(route('rides.cancel', $ride), ['reason' => 'vehicle_problem']);

            $this->assertSame('requested', $ride->fresh()->status);
            $this->assertSame($expectedCount, $ride->fresh()->reassignment_count);
        }

        $fourth = $this->makeApprovedDriver();
        $this->actingAs($fourth)->post(route('rides.accept', $ride))->assertRedirect();
        $this->actingAs($fourth)->post(route('rides.cancel', $ride), ['reason' => 'vehicle_problem'])->assertRedirect();

        $ride->refresh();
        $this->assertSame('expired', $ride->status);
        $this->assertNull($ride->driver_id);
        $this->assertNotNull($ride->expired_at);
        $this->assertNotNull(
            $this->notificationsOfType($passenger, 'ride_expired')->first(),
            'the passenger is told the request ended',
        );
        $this->assertSame('expired', AuditLog::where('action', 'ride.driver_cancelled')->latest('id')->first()->meta['outcome']);
    }

    public function test_the_reassignment_cap_comes_from_config(): void
    {
        config(['ehail.max_reassignments' => 1]);
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide([], $passenger);

        $one = $this->makeApprovedDriver();
        $this->actingAs($one)->post(route('rides.accept', $ride));
        $this->actingAs($one)->post(route('rides.cancel', $ride), ['reason' => 'other']);
        $this->assertSame('requested', $ride->fresh()->status);

        $two = $this->makeApprovedDriver();
        $this->actingAs($two)->post(route('rides.accept', $ride));
        $this->actingAs($two)->post(route('rides.cancel', $ride), ['reason' => 'other']);
        $this->assertSame('expired', $ride->fresh()->status);
    }

    public function test_a_driver_cannot_cancel_a_ride_that_is_not_theirs_or_is_in_progress(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();
        $other = $this->makeApprovedDriver();
        $ride = $this->accepted($passenger, $driver);

        $this->actingAs($other)->post(route('rides.cancel', $ride), ['reason' => 'other'])->assertForbidden();

        $ride->update(['status' => 'in_progress']);
        $this->actingAs($driver)->post(route('rides.cancel', $ride), ['reason' => 'other'])->assertForbidden();
        $this->actingAs($passenger)->post(route('rides.cancel', $ride), ['reason' => 'other'])->assertForbidden();
        $this->assertSame('in_progress', $ride->fresh()->status);
    }

    public function test_every_cancellation_path_broadcasts_the_status_change(): void
    {
        Event::fake([RideStatusUpdated::class]);
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();
        $ride = $this->accepted($passenger, $driver);
        Event::assertDispatchedTimes(RideStatusUpdated::class, 1);

        $this->actingAs($driver)->post(route('rides.cancel', $ride), ['reason' => 'other']);
        Event::assertDispatchedTimes(RideStatusUpdated::class, 2);
        Event::assertDispatched(RideStatusUpdated::class, fn ($e) => $e->ride->status === 'requested');

        $this->actingAs($passenger)->post(route('rides.cancel', $ride), ['reason' => 'changed_plans']);
        Event::assertDispatchedTimes(RideStatusUpdated::class, 3);
        Event::assertDispatched(RideStatusUpdated::class, fn ($e) => $e->ride->status === 'cancelled');
    }

    public function test_the_status_broadcast_waits_for_the_transaction_to_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new RideStatusUpdated(new Ride));

        $ride = $this->makeRide();

        // Rolled back: nothing may reach the broadcaster.
        Queue::fake();
        try {
            DB::transaction(function () use ($ride) {
                $ride->update(['status' => 'cancelled']);
                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }
        Queue::assertNothingPushed();

        // Committed: exactly one broadcast job, queued once the commit lands.
        // (The rolled-back update left the in-memory model changed; re-read it.)
        $ride = $ride->fresh();
        $this->assertSame('requested', $ride->status);
        DB::transaction(function () use ($ride) {
            $ride->update(['status' => 'cancelled']);
            Queue::assertNothingPushed();
        });
        Queue::assertPushed(BroadcastEvent::class, 1);
    }
}
