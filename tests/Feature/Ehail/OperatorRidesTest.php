<?php

namespace Tests\Feature\Ehail;

use App\Models\AuditLog;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesEhailFixtures;
use Tests\TestCase;

class OperatorRidesTest extends TestCase
{
    use CreatesEhailFixtures, RefreshDatabase;

    public function test_a_non_operator_cannot_open_the_page_or_act_on_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['status' => 'needs_review', 'driver_id' => $driver->id]);

        $this->actingAs($user)->get(route('operator.rides.index'))->assertForbidden();
        $this->actingAs($user)->post(route('operator.rides.complete', $ride), ['final_fare' => 50, 'reason' => 'x'])->assertForbidden();
        $this->actingAs($user)->post(route('operator.rides.cancel', $ride), ['reason' => 'x'])->assertForbidden();

        $this->assertSame('needs_review', $ride->fresh()->status);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('operator.rides.index'))->assertRedirect('/login');
    }

    public function test_the_page_lists_rides_needing_review_and_expired_rides(): void
    {
        $operator = $this->makeOperator();
        $driver = $this->makeApprovedDriver();
        $this->makeRide([
            'status' => 'needs_review', 'driver_id' => $driver->id,
            'pickup_address' => 'Stuck pickup at Voortrekker Road depot',
            'needs_review_at' => now(),
        ]);
        $this->makeRide([
            'status' => 'expired',
            'pickup_address' => 'Expired pickup at Bellville taxi rank',
            'expired_at' => now(),
        ]);
        $this->makeRide([
            'status' => 'completed', 'driver_id' => $driver->id, 'final_fare' => 99,
            'pickup_address' => 'Completed pickup at Claremont station',
        ]);

        $this->actingAs($operator)->get(route('operator.rides.index'))
            ->assertOk()
            ->assertSee('Stuck pickup at Voortrekker Road depot')
            ->assertSee('Expired pickup at Bellville taxi rank')
            ->assertDontSee('Completed pickup at Claremont station');
    }

    public function test_it_shows_pending_fleet_and_application_counts_with_a_link_to_the_review_pages(): void
    {
        $operator = $this->makeOperator();
        Fleet::factory()->count(3)->create(['status' => 'pending']);
        Fleet::factory()->create(['status' => 'approved']);

        $response = $this->actingAs($operator)->get(route('operator.rides.index'))->assertOk();

        $response->assertSee('Fleets awaiting approval')
            ->assertSee('data-figure="pending-fleets">3<', false);
        $response->assertSee(route('operator.fleets.index'), false);
    }

    public function test_it_counts_driver_applications_still_pending(): void
    {
        $operator = $this->makeOperator();
        DriverProfile::factory()->count(2)->create(['status' => 'pending']);
        DriverProfile::factory()->create(['status' => 'approved']);

        $this->actingAs($operator)->get(route('operator.rides.index'))
            ->assertOk()
            ->assertSee('Driver applications pending')
            ->assertSee('data-figure="pending-applications">2<', false);
    }

    public function test_the_fare_total_is_labelled_as_not_collected_and_only_sums_completed_rides(): void
    {
        $operator = $this->makeOperator();
        $driver = $this->makeApprovedDriver();
        $this->makeRide(['status' => 'completed', 'driver_id' => $driver->id, 'final_fare' => 120.50]);
        $this->makeRide(['status' => 'completed', 'driver_id' => $driver->id, 'final_fare' => 79.50]);
        $this->makeRide(['status' => 'completed', 'driver_id' => $driver->id, 'final_fare' => null]);
        $this->makeRide(['status' => 'cancelled', 'estimated_fare' => 5123.45, 'final_fare' => 5123.45]);
        $this->makeRide(['status' => 'requested', 'estimated_fare' => 7234.56]);

        $this->actingAs($operator)->get(route('operator.rides.index'))
            ->assertOk()
            ->assertSee('Estimated fare total, not collected')
            ->assertSee('R 200.00')
            ->assertDontSee('Revenue')
            ->assertDontSee('5,123.45')
            ->assertDontSee('7,234.56');
    }

    public function test_an_all_clear_page_says_so_in_words(): void
    {
        $operator = $this->makeOperator();

        $this->actingAs($operator)->get(route('operator.rides.index'))
            ->assertOk()
            ->assertSee('Nothing needs review.');
    }

    public function test_an_operator_completes_a_flagged_ride_with_a_fare_and_a_reason_and_it_is_audited(): void
    {
        $operator = $this->makeOperator();
        $driver = $this->makeApprovedDriver();
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide(['status' => 'needs_review', 'driver_id' => $driver->id, 'estimated_fare' => null], $passenger);

        $this->actingAs($operator)
            ->post(route('operator.rides.complete', $ride), ['final_fare' => '142.50', 'reason' => 'Driver phoned in; trip ended at the airport.'])
            ->assertRedirect(route('operator.rides.index'));

        $ride->refresh();
        $this->assertSame('completed', $ride->status);
        $this->assertSame('142.50', $ride->final_fare);
        $this->assertSame('Driver phoned in; trip ended at the airport.', $ride->final_fare_reason);
        $this->assertSame($operator->id, $ride->final_fare_set_by);
        $this->assertNotNull($ride->completed_at);

        $log = AuditLog::where('action', 'ride.fare_entered_by_operator')->first();
        $this->assertNotNull($log);
        $this->assertSame($operator->id, $log->actor_user_id);
        $this->assertSame($ride->id, (int) $log->subject_id);
        $this->assertSame('142.50', $log->meta['final_fare']);
        $this->assertSame('needs_review', $log->meta['previous_status']);
        $this->assertNull($log->meta['estimated_fare']);

        $this->assertNotNull($this->notificationsOfType($passenger, 'ride_completed')->first());
    }

    public function test_completing_needs_both_a_fare_and_a_reason(): void
    {
        $operator = $this->makeOperator();
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['status' => 'needs_review', 'driver_id' => $driver->id]);

        $this->actingAs($operator)->post(route('operator.rides.complete', $ride), ['final_fare' => '10'])->assertSessionHasErrors('reason');
        $this->actingAs($operator)->post(route('operator.rides.complete', $ride), ['reason' => 'x'])->assertSessionHasErrors('final_fare');
        $this->actingAs($operator)->post(route('operator.rides.complete', $ride), ['final_fare' => '-5', 'reason' => 'x'])->assertSessionHasErrors('final_fare');
        $this->actingAs($operator)->post(route('operator.rides.complete', $ride), ['final_fare' => 'abc', 'reason' => 'x'])->assertSessionHasErrors('final_fare');

        $this->assertSame('needs_review', $ride->fresh()->status);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_an_operator_can_complete_an_in_progress_ride_that_has_no_estimate(): void
    {
        $operator = $this->makeOperator();
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['status' => 'in_progress', 'driver_id' => $driver->id, 'estimated_fare' => null]);

        $this->actingAs($operator)
            ->post(route('operator.rides.complete', $ride), ['final_fare' => '88', 'reason' => 'Fixed price agreed with passenger at pickup.'])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame('completed', $ride->fresh()->status);
        $this->assertSame('88.00', $ride->fresh()->final_fare);
    }

    public function test_an_operator_cannot_complete_a_ride_that_was_never_started(): void
    {
        $operator = $this->makeOperator();
        $ride = $this->makeRide();

        $this->actingAs($operator)
            ->post(route('operator.rides.complete', $ride), ['final_fare' => '88', 'reason' => 'trying to skip every stage'])
            ->assertForbidden();

        $this->assertSame('requested', $ride->fresh()->status);
    }

    public function test_an_operator_cancels_a_flagged_ride_with_a_reason_and_both_parties_are_told(): void
    {
        $operator = $this->makeOperator();
        $driver = $this->makeApprovedDriver();
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->makeRide(['status' => 'needs_review', 'driver_id' => $driver->id], $passenger);

        $this->actingAs($operator)
            ->post(route('operator.rides.cancel', $ride), ['reason' => 'Neither party responded to the review call.'])
            ->assertRedirect(route('operator.rides.index'));

        $ride->refresh();
        $this->assertSame('cancelled', $ride->status);
        $this->assertSame($operator->id, $ride->cancelled_by);
        $this->assertSame('other', $ride->cancel_reason);
        $this->assertSame('Neither party responded to the review call.', $ride->cancel_note);
        $this->assertNotNull(AuditLog::where('action', 'ride.operator_cancelled')->first());
        $this->assertNotNull($this->notificationsOfType($passenger, 'ride_cancelled')->first());
        $this->assertNotNull($this->notificationsOfType($driver, 'ride_cancelled')->first());
    }

    public function test_the_nav_links_operators_to_the_page(): void
    {
        $operator = $this->makeOperator();

        $this->actingAs($operator)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('operator.rides.index'), false);
    }
}
