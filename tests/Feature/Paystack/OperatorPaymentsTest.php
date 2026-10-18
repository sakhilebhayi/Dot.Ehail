<?php

namespace Tests\Feature\Paystack;

use App\Models\AuditLog;
use App\Models\RidePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesPaystack;
use Tests\TestCase;

class OperatorPaymentsTest extends TestCase
{
    use FakesPaystack, RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enablePaystack();
        Http::preventStrayRequests();
        $this->operator = $this->makeOperator();
    }

    private function desk()
    {
        return $this->actingAs($this->operator)->get(route('operator.rides.index'));
    }

    // ─── Waiving ─────────────────────────────────────────────────────────

    public function test_an_operator_can_waive_an_unpaid_fare_with_a_reason_and_it_is_audited(): void
    {
        $ride = $this->completedRide(null, '99.00');
        $live = $this->paymentFor($ride);
        $ride->forceFill(['payment_status' => 'pending'])->save();

        $this->actingAs($this->operator)
            ->post(route('operator.rides.waive', $ride), ['reason' => 'Driver cancelled the meter after a breakdown'])
            ->assertRedirect(route('operator.rides.index'))
            ->assertSessionHas('status');

        $fresh = $ride->fresh();
        $this->assertSame('waived', $fresh->payment_status);
        $this->assertSame('Driver cancelled the meter after a breakdown', $fresh->fare_waived_reason);
        $this->assertSame($this->operator->id, $fresh->fare_waived_by);
        $this->assertNotNull($fresh->fare_waived_at);
        $this->assertSame(RidePayment::STATUS_EXPIRED, $live->fresh()->status, 'a waived ride keeps no payable link');

        $audit = AuditLog::where('action', 'ride.fare_waived')->sole();
        $this->assertSame($this->operator->id, $audit->actor_user_id);
        $this->assertSame($ride->id, $audit->subject_id);
        $this->assertSame('Driver cancelled the meter after a breakdown', $audit->meta['reason']);
        $this->assertSame(9900, $audit->meta['amount_cents']);
        $this->assertSame('pending', $audit->meta['previous_payment_status']);
        $this->assertCount(1, $this->notificationsOfType($ride->passenger, 'ride_fare_waived'));
    }

    public function test_a_waiver_needs_a_reason(): void
    {
        $ride = $this->completedRide();

        foreach ([[], ['reason' => ''], ['reason' => '   '], ['reason' => str_repeat('x', 501)]] as $input) {
            $this->actingAs($this->operator)->post(route('operator.rides.waive', $ride), $input)->assertSessionHasErrors('reason');
        }

        $this->assertSame('unpaid', $ride->fresh()->payment_status);
        $this->assertSame(0, AuditLog::where('action', 'ride.fare_waived')->count());
    }

    public function test_only_operators_can_waive(): void
    {
        $ride = $this->completedRide();

        foreach ([$ride->passenger, $ride->driver, User::factory()->withPersonalTeam()->create()] as $user) {
            $this->actingAs($user)->post(route('operator.rides.waive', $ride), ['reason' => 'free ride please'])->assertForbidden();
        }

        $this->assertSame('unpaid', $ride->fresh()->payment_status);
    }

    public function test_paid_waived_or_not_due_fares_cannot_be_waived(): void
    {
        foreach (['paid', 'waived', 'not_due'] as $state) {
            $ride = $this->completedRide(null, '10.00', $state);

            $this->actingAs($this->operator)->post(route('operator.rides.waive', $ride), ['reason' => 'because'])->assertForbidden();
            $this->assertSame($state, $ride->fresh()->payment_status);
        }

        $this->assertSame(0, AuditLog::where('action', 'ride.fare_waived')->count());
    }

    public function test_a_waived_fare_cannot_be_paid_afterwards(): void
    {
        $ride = $this->completedRide();
        $this->actingAs($this->operator)->post(route('operator.rides.waive', $ride), ['reason' => 'goodwill']);
        $this->fakePaystack();

        $this->actingAs($ride->passenger)->post(route('rides.pay', $ride))->assertSessionHasErrors('payment');
        $this->assertCount(0, $this->paystackRequests('/transaction/initialize'));
    }

    // ─── The desk ────────────────────────────────────────────────────────

    public function test_the_desk_lists_unpaid_completed_rides_older_than_the_threshold_only(): void
    {
        config(['ehail.unpaid_fare_alert_hours' => 24]);
        $old = $this->completedRide(null, '40.00');
        $old->forceFill(['completed_at' => now()->subHours(30), 'pickup_address' => 'Old unpaid pickup at Salt River'])->save();
        $recent = $this->completedRide(null, '41.00');
        $recent->forceFill(['completed_at' => now()->subHours(2), 'pickup_address' => 'Recent unpaid pickup at Woodstock'])->save();
        $paid = $this->completedRide(null, '42.00', 'paid');
        $paid->forceFill(['completed_at' => now()->subHours(40), 'pickup_address' => 'Old but paid pickup at Observatory'])->save();

        $this->desk()->assertOk()
            ->assertSee('Unpaid fares')
            ->assertSee('Old unpaid pickup at Salt River')
            ->assertDontSee('Recent unpaid pickup at Woodstock')
            ->assertDontSee('Old but paid pickup at Observatory')
            ->assertSee(route('operator.rides.waive', $old), false);
    }

    public function test_the_threshold_is_configurable(): void
    {
        config(['ehail.unpaid_fare_alert_hours' => 1]);
        $ride = $this->completedRide();
        $ride->forceFill(['completed_at' => now()->subHours(2), 'pickup_address' => 'Two hours ago at Mowbray'])->save();

        $this->desk()->assertSee('Two hours ago at Mowbray');

        config(['ehail.unpaid_fare_alert_hours' => 3]);
        $this->desk()->assertDontSee('Two hours ago at Mowbray');
    }

    public function test_unmatched_payments_are_listed_and_can_be_marked_reviewed_with_a_note(): void
    {
        $ride = $this->completedRide();
        $payment = $this->paymentFor($ride, RidePayment::STATUS_UNMATCHED, [
            'unmatched_reason' => 'amount_mismatch', 'reference' => 'ehail-unmatched-ref-1',
        ]);

        $this->desk()->assertSee('Unmatched payments')
            ->assertSee('ehail-unmatched-ref-1')
            ->assertSee('Amount did not match')
            ->assertSee(route('operator.payments.review', $payment), false);

        $this->actingAs($this->operator)->post(route('operator.payments.review', $payment), [])->assertSessionHasErrors('note');

        $this->actingAs($this->operator)->post(route('operator.payments.review', $payment), ['note' => 'Refunded R10 on the Paystack dashboard'])
            ->assertRedirect(route('operator.rides.index'));

        $fresh = $payment->fresh();
        $this->assertNotNull($fresh->reviewed_at);
        $this->assertSame($this->operator->id, $fresh->reviewed_by);
        $this->assertSame('Refunded R10 on the Paystack dashboard', $fresh->review_note);
        $this->assertSame(1, AuditLog::where('action', 'ride.payment_reviewed')->count());

        $this->desk()->assertDontSee('ehail-unmatched-ref-1');
    }

    public function test_only_operators_can_review_unmatched_payments(): void
    {
        $payment = $this->paymentFor($this->completedRide(), RidePayment::STATUS_UNMATCHED, ['unmatched_reason' => 'amount_mismatch']);

        $this->actingAs(User::factory()->withPersonalTeam()->create())
            ->post(route('operator.payments.review', $payment), ['note' => 'x'])->assertForbidden();

        $this->assertNull($payment->fresh()->reviewed_at);
    }

    public function test_only_unmatched_payments_can_be_reviewed(): void
    {
        $payment = $this->paymentFor($this->completedRide(), RidePayment::STATUS_PAID, ['provider_transaction_id' => '5']);

        $this->actingAs($this->operator)->post(route('operator.payments.review', $payment), ['note' => 'x'])->assertNotFound();
    }

    public function test_waivers_are_listed_with_who_and_why(): void
    {
        $ride = $this->completedRide(null, '77.00');
        $this->actingAs($this->operator)->post(route('operator.rides.waive', $ride), ['reason' => 'Complaint upheld, vehicle was dirty']);

        $this->desk()->assertSee('Waived fares')
            ->assertSee('Complaint upheld, vehicle was dirty')
            ->assertSee($this->operator->name);
    }

    public function test_collected_comes_only_from_paid_payments_and_is_labelled_apart_from_the_estimate(): void
    {
        $paidRide = $this->completedRide(null, '125.50', 'paid');
        $this->paymentFor($paidRide, RidePayment::STATUS_PAID, ['provider_transaction_id' => '1', 'amount_cents' => 12550, 'paid_at' => now()]);
        $this->paymentFor($this->completedRide(null, '200.00'), RidePayment::STATUS_INITIATED, ['amount_cents' => 20000]);
        $this->paymentFor($this->completedRide(null, '300.00'), RidePayment::STATUS_UNMATCHED, ['amount_cents' => 30000, 'unmatched_reason' => 'amount_mismatch']);

        $response = $this->desk()->assertOk();

        $response->assertSee('Fares collected through Paystack')
            ->assertSee('data-figure="fares-collected">R 125.50<', false)
            ->assertSee('Estimated fare total, not collected')
            ->assertSee('data-figure="fare-total">R 625.50<', false);
    }

    public function test_collected_is_zero_when_nothing_was_paid(): void
    {
        $this->completedRide(null, '80.00');

        $this->desk()->assertSee('data-figure="fares-collected">R 0.00<', false);
    }

    public function test_the_collected_row_says_test_money_in_test_mode(): void
    {
        $this->desk()->assertSee('TEST MODE')->assertSee('not real money');
    }

    public function test_without_paystack_the_desk_says_so_and_still_works(): void
    {
        config(['paystack.secret_key' => '']);

        $this->desk()->assertOk()
            ->assertSee('Online payment is not enabled')
            ->assertSee('Estimated fare total, not collected');
    }

    public function test_the_existing_estimated_total_is_unchanged_by_payments(): void
    {
        $this->completedRide(null, '100.00', 'paid');
        $this->completedRide(null, '55.50');

        $this->desk()->assertSee('data-figure="fare-total">R 155.50<', false);
    }

    public function test_a_non_operator_cannot_see_payment_sections(): void
    {
        $this->actingAs(User::factory()->withPersonalTeam()->create())->get(route('operator.rides.index'))->assertForbidden();
    }

    public function test_the_dashboard_does_not_claim_money_was_collected(): void
    {
        $this->completedRide(null, '100.00', 'paid');

        $this->actingAs($this->operator)->get(route('dashboard'))->assertOk()
            ->assertSee('Estimated fare total, not collected')
            ->assertDontSee('No payments are processed here');
    }
}
