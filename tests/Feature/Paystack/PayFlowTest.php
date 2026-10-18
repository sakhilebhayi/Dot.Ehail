<?php

namespace Tests\Feature\Paystack;

use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\RidePayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesPaystack;
use Tests\TestCase;

class PayFlowTest extends TestCase
{
    use FakesPaystack, RefreshDatabase;

    private User $passenger;

    private Ride $ride;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enablePaystack();
        $this->fakePaystack();
        $this->passenger = User::factory()->withPersonalTeam()->create(['email' => 'rider@example.test']);
        $this->ride = $this->completedRide($this->passenger, '125.50');
    }

    private function pay(?User $as = null, ?Ride $ride = null)
    {
        return $this->actingAs($as ?? $this->passenger)->post(route('rides.pay', $ride ?? $this->ride));
    }

    private function returnFromPaystack(?User $as = null, ?Ride $ride = null, array $query = [])
    {
        return $this->actingAs($as ?? $this->passenger)->get(route('rides.pay.callback', $ride ?? $this->ride).($query ? '?'.http_build_query($query) : ''));
    }

    // ─── Showing the button ──────────────────────────────────────────────

    public function test_the_passenger_sees_pay_fare_with_the_amount_and_a_test_mode_badge(): void
    {
        $this->actingAs($this->passenger)->get(route('rides.show', $this->ride))
            ->assertOk()
            ->assertSee('Pay fare')
            ->assertSee(route('rides.pay', $this->ride), false)
            ->assertSee('R 125.50')
            ->assertSee('TEST MODE');
    }

    public function test_the_driver_never_sees_pay_fare_but_is_told_payouts_are_not_built(): void
    {
        $this->actingAs($this->ride->driver)->get(route('rides.show', $this->ride))
            ->assertOk()
            ->assertDontSee('Pay fare')
            ->assertDontSee(route('rides.pay', $this->ride), false)
            ->assertSee('Payout is not available yet')
            ->assertSee('settlement to drivers is manual');
    }

    public function test_a_fleet_owner_sees_the_same_payout_notice_and_no_pay_button(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $fleet = Fleet::factory()->create(['owner_user_id' => $owner->id, 'status' => 'approved']);
        DriverProfile::withoutGlobalScope('user')->where('user_id', $this->ride->driver_id)->update(['fleet_id' => $fleet->id]);

        $this->actingAs($owner)->get(route('rides.show', $this->ride))
            ->assertOk()
            ->assertDontSee('Pay fare')
            ->assertSee('Payout is not available yet');
    }

    public function test_without_paystack_the_page_says_payment_is_not_enabled_and_has_no_button(): void
    {
        config(['paystack.secret_key' => '']);
        $ride = $this->completedRide($this->passenger, '80.00', 'not_due');

        $this->actingAs($this->passenger)->get(route('rides.show', $ride))
            ->assertOk()
            ->assertSee('Payment is not enabled')
            ->assertDontSee('Pay fare')
            ->assertDontSee('TEST MODE');
    }

    public function test_a_paid_ride_shows_paid_and_no_button(): void
    {
        $this->ride->forceFill(['payment_status' => 'paid'])->save();
        $this->paymentFor($this->ride, RidePayment::STATUS_PAID, ['paid_at' => now(), 'provider_transaction_id' => '11']);

        $this->actingAs($this->passenger)->get(route('rides.show', $this->ride))
            ->assertOk()
            ->assertSee('Fare paid')
            ->assertDontSee('Pay fare');
    }

    // ─── Starting a payment ──────────────────────────────────────────────

    public function test_paying_initialises_a_transaction_and_redirects_to_paystack(): void
    {
        $response = $this->pay();

        $payment = RidePayment::firstOrFail();
        $response->assertRedirect($payment->authorization_url);
        $this->assertStringStartsWith('https://checkout.paystack.com/', $payment->authorization_url);

        $this->assertSame($this->ride->id, $payment->ride_id);
        $this->assertSame($this->passenger->id, $payment->user_id);
        $this->assertSame(12550, $payment->amount_cents);
        $this->assertSame('ZAR', $payment->currency);
        $this->assertSame('paystack', $payment->provider);
        $this->assertSame(RidePayment::STATUS_INITIATED, $payment->status);
        $this->assertStringStartsWith('ehail-'.$this->ride->id.'-', $payment->reference);
        $this->assertSame('pending', $this->ride->fresh()->payment_status);
    }

    public function test_the_initialize_payload_is_exact_and_takes_the_amount_from_the_stored_fare(): void
    {
        $this->actingAs($this->passenger)->post(route('rides.pay', $this->ride), ['amount' => 1, 'amount_cents' => 1, 'currency' => 'USD', 'email' => 'attacker@example.test']);

        $requests = $this->paystackRequests('/transaction/initialize');
        $this->assertCount(1, $requests);

        /** @var Request $request */
        $request = $requests->first();
        $payment = RidePayment::firstOrFail();

        $this->assertEqualsCanonicalizing(['email', 'amount', 'currency', 'reference', 'callback_url', 'metadata'], array_keys($request->data()));
        $this->assertSame('rider@example.test', $request['email']);
        $this->assertSame(12550, $request['amount']);
        $this->assertSame('ZAR', $request['currency']);
        $this->assertSame($payment->reference, $request['reference']);
        $this->assertSame(route('rides.pay.callback', $this->ride), $request['callback_url']);
        $this->assertSame(['ride_id' => $this->ride->id, 'purpose' => 'ride_fare'], json_decode($request['metadata'], true));
        $this->assertSame('POST', $request->method());
    }

    public function test_a_second_click_reuses_the_live_attempt(): void
    {
        $first = $this->pay();
        $second = $this->pay();

        $this->assertSame(1, RidePayment::count());
        $this->assertCount(1, $this->paystackRequests('/transaction/initialize'));
        $second->assertRedirect(RidePayment::first()->authorization_url);
        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
    }

    public function test_an_expired_attempt_is_retired_and_a_new_one_is_started(): void
    {
        $this->pay();
        $old = RidePayment::firstOrFail();
        $old->update(['expires_at' => now()->subMinute()]);

        $this->pay()->assertRedirect();

        $this->assertSame(2, RidePayment::count());
        $this->assertSame(RidePayment::STATUS_EXPIRED, $old->fresh()->status);
        $this->assertSame(1, RidePayment::where('status', RidePayment::STATUS_INITIATED)->count());
    }

    public function test_a_paystack_outage_leaves_everything_unchanged_and_can_be_retried(): void
    {
        $this->initializeThrows = true;

        $this->pay()->assertRedirect(route('rides.show', $this->ride))->assertSessionHasErrors('payment');

        $this->assertSame(0, RidePayment::count());
        $this->assertSame('unpaid', $this->ride->fresh()->payment_status);

        $this->initializeThrows = false;
        $this->pay()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, RidePayment::count());
        $this->assertSame('pending', $this->ride->fresh()->payment_status);
    }

    public function test_a_refusal_from_paystack_shows_a_safe_message_and_changes_nothing(): void
    {
        $this->initializeStatus = 401;

        $response = $this->pay()->assertSessionHasErrors('payment');

        $this->assertSame(0, RidePayment::count());
        $this->assertStringNotContainsString(self::TEST_KEY, json_encode(session('errors')->getBag('default')->all()));
        $response->assertRedirect(route('rides.show', $this->ride));
    }

    public function test_paying_is_refused_when_nothing_is_due_or_it_is_already_settled(): void
    {
        foreach (['not_due', 'paid', 'waived'] as $state) {
            $ride = $this->completedRide($this->passenger, '50.00', $state);

            $this->pay(null, $ride)->assertSessionHasErrors('payment');
        }

        $this->assertSame(0, RidePayment::count());
        $this->paystackRequests('/transaction/initialize')->each(fn () => $this->fail('no request expected'));
    }

    public function test_paying_is_refused_on_a_ride_that_is_not_completed(): void
    {
        $ride = $this->makeRide(['status' => 'in_progress', 'driver_id' => $this->makeApprovedDriver()->id, 'final_fare' => '10.00'], $this->passenger);
        $ride->forceFill(['payment_status' => 'unpaid'])->save();

        $this->pay(null, $ride)->assertSessionHasErrors('payment');
        $this->assertSame(0, RidePayment::count());
    }

    public function test_paying_does_nothing_when_paystack_is_not_configured(): void
    {
        config(['paystack.secret_key' => '']);

        $this->pay()->assertSessionHasErrors('payment');

        $this->assertSame(0, RidePayment::count());
        Http::assertNothingSent();
    }

    public function test_a_live_key_without_the_second_switch_cannot_start_a_payment(): void
    {
        $this->enablePaystack('sk_live_not_allowed');

        $this->pay()->assertSessionHasErrors('payment');

        Http::assertNothingSent();
        $this->assertSame(0, RidePayment::count());
    }

    // ─── Who may pay ─────────────────────────────────────────────────────

    public function test_only_the_passenger_may_pay(): void
    {
        $stranger = User::factory()->withPersonalTeam()->create();
        $owner = User::factory()->withPersonalTeam()->create();
        $operator = $this->makeOperator();

        foreach ([$this->ride->driver, $stranger, $owner, $operator] as $user) {
            $this->pay($user)->assertForbidden();
            $this->returnFromPaystack($user)->assertForbidden();
        }

        $this->assertSame(0, RidePayment::count());
        Http::assertNothingSent();
        $this->assertSame('unpaid', $this->ride->fresh()->payment_status);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->post(route('rides.pay', $this->ride))->assertRedirect('/login');
        $this->get(route('rides.pay.callback', $this->ride))->assertRedirect('/login');
    }

    // ─── Coming back from Paystack ───────────────────────────────────────

    public function test_the_callback_verifies_server_side_and_marks_the_ride_paid(): void
    {
        $this->pay();
        $payment = RidePayment::firstOrFail();

        $this->returnFromPaystack()->assertRedirect(route('rides.show', $this->ride))->assertSessionHas('status');

        $payment->refresh();
        $this->assertSame(RidePayment::STATUS_PAID, $payment->status);
        $this->assertSame('4099260516', $payment->provider_transaction_id);
        $this->assertNotNull($payment->paid_at);
        $this->assertSame('paid', $this->ride->fresh()->payment_status);
        $this->assertCount(1, $this->paystackRequests('/transaction/verify/'.$payment->reference));
    }

    public function test_the_callback_never_trusts_its_query_string(): void
    {
        $this->pay();
        $payment = RidePayment::firstOrFail();
        $this->verifyOverrides = ['status' => 'abandoned'];

        $this->returnFromPaystack(null, null, ['reference' => 'forged', 'trxref' => 'forged', 'status' => 'success'])->assertRedirect();

        $this->assertSame('pending', $this->ride->fresh()->payment_status);
        $this->assertSame(RidePayment::STATUS_INITIATED, $payment->fresh()->status);
        $this->assertCount(0, $this->paystackRequests('/transaction/verify/forged'));
        $this->assertCount(1, $this->paystackRequests('/transaction/verify/'.$payment->reference));
    }

    public function test_a_failed_charge_marks_the_attempt_failed_and_the_ride_can_be_paid_again(): void
    {
        $this->pay();
        $this->verifyOverrides = ['status' => 'failed', 'gateway_response' => 'Declined'];

        $this->returnFromPaystack()->assertRedirect()->assertSessionHasErrors('payment');

        $this->assertSame('failed', $this->ride->fresh()->payment_status);
        $this->assertSame(RidePayment::STATUS_FAILED, RidePayment::firstOrFail()->status);

        $this->verifyOverrides = [];
        $this->pay()->assertRedirect();
        $this->assertSame(2, RidePayment::count());
        $this->assertSame('pending', $this->ride->fresh()->payment_status);
        $this->actingAs($this->passenger)->get(route('rides.show', $this->ride))->assertSee('Pay fare');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function mismatches(): array
    {
        return [
            'less than the fare' => [['amount' => 100]],
            'more than the fare' => [['amount' => 99999]],
            'another currency' => [['currency' => 'USD']],
            'another reference' => [['reference' => 'someone-elses-reference']],
        ];
    }

    #[DataProvider('mismatches')]
    public function test_a_successful_charge_that_does_not_match_never_marks_the_ride_paid(array $override): void
    {
        $this->pay();
        $payment = RidePayment::firstOrFail();
        $this->verifyOverrides = $override;

        $this->returnFromPaystack()->assertRedirect()->assertSessionHasErrors('payment');

        $this->assertNotSame('paid', $this->ride->fresh()->payment_status);
        $payment->refresh();
        $this->assertSame(RidePayment::STATUS_UNMATCHED, $payment->status);
        $this->assertNotNull($payment->unmatched_reason);
        $this->assertNull($payment->reviewed_at);
        $this->assertNull($payment->paid_at);
    }

    public function test_a_verify_outage_changes_nothing_and_a_later_callback_succeeds(): void
    {
        $this->pay();
        $this->verifyStatus = 503;

        $this->returnFromPaystack()->assertRedirect()->assertSessionHasErrors('payment');
        $this->assertSame('pending', $this->ride->fresh()->payment_status);
        $this->assertSame(RidePayment::STATUS_INITIATED, RidePayment::firstOrFail()->status);

        $this->verifyStatus = 200;
        $this->returnFromPaystack()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('paid', $this->ride->fresh()->payment_status);
    }

    public function test_the_callback_is_idempotent_and_does_not_notify_twice(): void
    {
        $this->pay();
        $this->returnFromPaystack();
        $this->returnFromPaystack();

        $this->assertSame(1, RidePayment::where('status', RidePayment::STATUS_PAID)->count());
        $this->assertCount(1, $this->notificationsOfType($this->passenger, 'ride_paid'));
        $this->assertCount(1, $this->notificationsOfType($this->ride->driver, 'ride_paid'));
    }

    public function test_payment_notifications_are_in_app_only_and_do_not_promise_driver_payout(): void
    {
        $this->pay();
        $this->returnFromPaystack();

        $driverNote = $this->notificationsOfType($this->ride->driver, 'ride_paid')->first();
        $this->assertStringContainsString('settlement to drivers is manual', $driverNote->data['message']);
        $this->assertStringNotContainsString('paid out', strtolower($driverNote->data['message']));
        $this->assertSame($this->ride->id, $driverNote->data['ride_id']);
    }

    public function test_another_user_cannot_complete_somebody_elses_payment_through_the_callback(): void
    {
        $this->pay();
        $other = User::factory()->withPersonalTeam()->create();
        $otherRide = $this->completedRide($other, '40.00');

        $this->returnFromPaystack($other, $this->ride)->assertForbidden();
        $this->returnFromPaystack($this->passenger, $otherRide)->assertForbidden();

        $this->assertSame('pending', $this->ride->fresh()->payment_status);
        $this->assertSame('unpaid', $otherRide->fresh()->payment_status);
    }

    public function test_a_callback_with_no_attempt_just_redirects(): void
    {
        $this->returnFromPaystack()->assertRedirect(route('rides.show', $this->ride));

        Http::assertNothingSent();
        $this->assertSame('unpaid', $this->ride->fresh()->payment_status);
    }

    public function test_a_second_successful_charge_for_an_already_paid_ride_is_flagged_not_double_counted(): void
    {
        $this->pay();
        $this->returnFromPaystack();

        // A second attempt that was open in another tab and also got charged.
        $second = $this->paymentFor($this->ride->fresh(), RidePayment::STATUS_INITIATED, ['reference' => 'ehail-dup-second']);
        $this->verifyOverrides = ['id' => 5000000001];
        $this->returnFromPaystack();

        $this->assertSame('paid', $this->ride->fresh()->payment_status);
        $this->assertSame(1, RidePayment::where('status', RidePayment::STATUS_PAID)->count());
        $this->assertSame(RidePayment::STATUS_UNMATCHED, $second->fresh()->status);
        $this->assertSame('duplicate_payment', $second->fresh()->unmatched_reason);
    }
}
