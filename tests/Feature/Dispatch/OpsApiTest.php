<?php

namespace Tests\Feature\Dispatch;

use App\Domain\Dispatch\Dispatcher;
use App\Domain\Dispatch\Models\DriverAvailability;
use App\Domain\Dispatch\Models\RideOffer;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesDispatchFixtures;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class OpsApiTest extends TestCase
{
    use CreatesDispatchFixtures, CreatesEhailFixtures, RefreshDatabase {
        CreatesDispatchFixtures::passenger insteadof CreatesEhailFixtures;
    }

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeQuotes();
        $this->operator = $this->makeOperator();
    }

    /**
     * @return array{0: Ride, 1: User}
     */
    private function assigned(): array
    {
        $driver = $this->onlineDriverAt(...$this->northOfPickup(200));
        $ride = $this->requestRide();
        app(Dispatcher::class)->offerResponse(RideOffer::where('pending_ride_id', $ride->id)->firstOrFail(), $driver, 'accept');

        return [$ride->fresh(), $driver];
    }

    public function test_only_operators_reach_the_ops_api(): void
    {
        [$ride] = $this->assigned();
        $user = $this->passenger();

        $this->actingAs($user)->getJson(route('ehail.dispatch.ops.rides.index'))->assertForbidden();
        $this->actingAs($user)->postJson(route('ehail.dispatch.ops.rides.force-cancel', $ride), ['reason' => 'test'])->assertForbidden();
        $this->assertSame('driver_assigned', $ride->fresh()->status);
    }

    public function test_the_live_list_shows_unfinished_rides_with_triage_fields(): void
    {
        [$assigned] = $this->assigned();
        $searching = $this->requestRide();
        $done = $this->makeRide(['status' => 'completed', 'final_fare' => 50]);

        $response = $this->actingAs($this->operator)->getJson(route('ehail.dispatch.ops.rides.index'))->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($assigned->id, $ids);
        $this->assertContains($searching->id, $ids);
        $this->assertNotContains($done->id, $ids);
        $this->assertSame(2, $response->json('meta.total'));

        $this->actingAs($this->operator)->getJson(route('ehail.dispatch.ops.rides.index', ['status' => 'searching']))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $searching->id);
    }

    public function test_the_timeline_lists_events_and_offers_with_their_score_terms(): void
    {
        [$ride] = $this->assigned();

        $this->actingAs($this->operator)->getJson(route('ehail.dispatch.ops.rides.timeline', $ride))->assertOk()
            ->assertJsonPath('events.0.type', 'created')
            ->assertJsonPath('offers.0.status', 'accepted')
            ->assertJsonStructure(['offers' => [['score', 'score_terms' => ['eta_s', 'idle_s', 'weights']]]]);
    }

    public function test_force_cancel_needs_a_reason_and_is_audited(): void
    {
        [$ride, $driver] = $this->assigned();

        $this->actingAs($this->operator)->postJson(route('ehail.dispatch.ops.rides.force-cancel', $ride))->assertStatus(422);
        $this->actingAs($this->operator)->postJson(route('ehail.dispatch.ops.rides.force-cancel', $ride), ['reason' => 'Passenger called support'])
            ->assertOk()->assertJsonPath('status', 'cancelled_by_operator');

        $this->assertNotNull(AuditLog::where('action', 'ride.operator_cancelled')->where('subject_id', $ride->id)->first());
        $this->assertSame(DriverAvailability::ONLINE, DriverAvailability::where('user_id', $driver->id)->value('state'));
    }

    public function test_reassign_back_to_searching_or_to_a_named_driver(): void
    {
        [$ride, $first] = $this->assigned();
        $second = $this->onlineDriverAt(...$this->northOfPickup(6000));

        $this->actingAs($this->operator)->postJson(route('ehail.dispatch.ops.rides.reassign', $ride), ['reason' => 'Driver reported a flat tyre'])
            ->assertOk()->assertJsonPath('status', 'searching');
        $this->assertSame(1, $ride->fresh()->dispatch_priority);
        $this->assertNotNull(AuditLog::where('action', 'ride.operator_reassigned')->first());

        $this->actingAs($this->operator)->postJson(route('ehail.dispatch.ops.rides.reassign', $ride), ['reason' => 'Assigning the fleet\'s standby driver', 'driver_id' => $second->id])
            ->assertOk()->assertJsonPath('status', 'driver_assigned');
        $this->assertSame($second->id, $ride->fresh()->driver_id);
        $this->assertNotNull(AuditLog::where('action', 'ride.operator_assigned')->first());
        $this->assertSame(0, RideOffer::where('ride_id', $ride->id)->where('status', 'pending')->count());
    }

    public function test_an_operator_cannot_assign_a_busy_driver(): void
    {
        [, $busy] = $this->assigned();
        $other = $this->requestRide();

        $this->actingAs($this->operator)->postJson(route('ehail.dispatch.ops.rides.reassign', $other), ['reason' => 'manual', 'driver_id' => $busy->id])
            ->assertStatus(409)->assertJsonPath('error', 'invalid_transition');
    }

    public function test_flag_then_resolve_each_outcome(): void
    {
        [$ride] = $this->assigned();

        $this->actingAs($this->operator)->postJson(route('ehail.dispatch.ops.rides.needs-review', $ride), ['reason' => 'Passenger dispute'])
            ->assertOk()->assertJsonPath('status', 'needs_review');
        $this->assertNull($ride->fresh()->active_driver_id, 'a flagged ride releases the driver');

        $this->actingAs($this->operator)->postJson(route('ehail.dispatch.ops.rides.resolve', $ride), ['reason' => 'Driver never started', 'outcome' => 'redispatch'])
            ->assertOk()->assertJsonPath('status', 'searching');

        $this->actingAs($this->operator)->postJson(route('ehail.dispatch.ops.rides.resolve', $ride), ['reason' => 'Not flagged any more', 'outcome' => 'cancel'])
            ->assertStatus(422)->assertJsonPath('error', 'not_in_review');

        $inTrip = $this->makeRide(['status' => 'in_progress', 'driver_id' => $this->makeApprovedDriver()->id, 'started_at' => now()]);
        $this->actingAs($this->operator)->postJson(route('ehail.dispatch.ops.rides.needs-review', $inTrip), ['reason' => 'GPS lost']);
        $this->actingAs($this->operator)->postJson(route('ehail.dispatch.ops.rides.resolve', $inTrip), ['reason' => 'Fare from the receipt', 'outcome' => 'complete'])
            ->assertStatus(422)->assertJsonPath('error', 'fare_required');
        $this->actingAs($this->operator)->postJson(route('ehail.dispatch.ops.rides.resolve', $inTrip), ['reason' => 'Fare from the receipt', 'outcome' => 'complete', 'final_fare_minor' => 12350])
            ->assertOk()->assertJsonPath('status', 'completed');
        $this->assertSame(12350, $inTrip->fresh()->final_fare_minor);
        $this->assertSame($this->operator->id, $inTrip->fresh()->final_fare_set_by);
    }
}
