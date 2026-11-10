<?php

namespace Tests\Feature\Money;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\FinanceIssue;
use App\Domain\Ledger\Models\LedgerJournal;
use App\Domain\Payments\ChargeAttempts;
use App\Domain\Payments\Jobs\SettleCompletedRide;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Receipt;
use App\Domain\Payments\Receipts\ReceiptIssuedNotification;
use App\Domain\Payments\RideSettlement;
use App\Domain\Rides\Events\TripCompleted;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MoneyFixtures;
use Tests\TestCase;

class CardChargingTest extends TestCase
{
    use MoneyFixtures, RefreshDatabase;

    private User $passenger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
        $this->fakeMoneyPaystack();
        config(['money.commission.percent' => 20]);
        $this->passenger = User::factory()->withPersonalTeam()->create(['email' => 'rider@example.test']);
    }

    public function test_completing_a_trip_charges_the_saved_card_with_paystacks_documented_request(): void
    {
        Notification::fake();
        $card = $this->savedCard($this->passenger);

        $ride = $this->completeTrip($this->passenger);

        $charge = $this->paystackRequests('/transaction/charge_authorization');
        $this->assertCount(1, $charge);
        $body = $charge[0]->data();
        $this->assertSame('rider@example.test', $body['email']);
        $this->assertSame(12550, $body['amount']);
        $this->assertSame('ZAR', $body['currency']);
        $this->assertSame('AUTH_fixture_8dfhjjdt', $body['authorization_code']);
        $this->assertMatchesRegularExpression('/^[a-z0-9\-]+$/', $body['reference']);
        $this->assertIsString($body['metadata'], 'metadata is stringified JSON');
        $this->assertSame('Bearer '.self::TEST_KEY, $charge[0]->header('Authorization')[0]);

        // Success is recorded only after the verify endpoint confirmed it.
        $this->assertCount(1, $this->paystackRequests('/transaction/verify/'.$body['reference']));

        $payment = Payment::where('ride_id', $ride->id)->sole();
        $this->assertSame(Payment::PAID, $payment->status);
        $this->assertSame($card->id, $payment->payment_method_id);
        $this->assertNotNull($payment->verified_at);
        $this->assertSame('paid', $ride->fresh()->payment_status);

        // Ledger: fare recognised and settled into gateway clearing; driver owed 80%.
        $ledger = app(Ledger::class);
        $this->assertSame(12550, $ledger->balance(Account::GATEWAY_CLEARING, 'ZAR'));
        $this->assertSame(0, $ledger->balance(Account::PASSENGER_RECEIVABLE, 'ZAR'));
        $this->assertSame(2510, $ledger->balance(Account::PLATFORM_REVENUE_COMMISSION, 'ZAR'));
        $this->assertSame(10040, $ledger->balance(Account::driverPayable($ride->driver_id), 'ZAR'));
        $this->assertTrue($this->ledgerSumsToZero());

        $earning = DriverEarning::where('ride_id', $ride->id)->sole();
        $this->assertSame(DriverEarning::AVAILABLE, $earning->status);
        $this->assertSame(10040, $earning->net_minor);
        $this->assertSame(2510, $earning->commission_minor);

        $receipt = Receipt::where('ride_id', $ride->id)->sole();
        $this->assertSame('Visa •••• 4081', $receipt->snapshot['payment_method']);
        Notification::assertSentTo($this->passenger, ReceiptIssuedNotification::class);
    }

    public function test_a_declined_card_fails_schedules_a_retry_and_tells_the_passenger(): void
    {
        $this->savedCard($this->passenger);
        $this->chargeOutcome = 'failed';

        $ride = $this->completeTrip($this->passenger);

        $payment = Payment::where('ride_id', $ride->id)->sole();
        $this->assertSame(Payment::FAILED, $payment->status);
        $this->assertSame('declined', $payment->failure_code);
        $this->assertNotNull($payment->next_retry_at);
        $this->assertSame('failed', $ride->fresh()->payment_status);
        $this->assertCount(1, $this->notificationsOfType($this->passenger, 'ride_payment_failed'));
        $this->assertSame(DriverEarning::PENDING, DriverEarning::where('ride_id', $ride->id)->sole()->status);

        // Retries pending: the passenger is not blocked yet.
        $this->actingAs($this->passenger)->getJson(route('money.payment-due'))->assertJson(['blocked' => false]);

        // The retry comes due and succeeds.
        $this->chargeOutcome = 'success';
        $this->travel(16)->minutes();
        $this->artisan('money:sweep')->assertSuccessful();

        $this->assertSame(2, Payment::where('ride_id', $ride->id)->count());
        $this->assertSame(Payment::PAID, Payment::where('ride_id', $ride->id)->where('attempt', 2)->sole()->status);
        $this->assertSame('paid', $ride->fresh()->payment_status);
        $this->assertSame(DriverEarning::AVAILABLE, DriverEarning::where('ride_id', $ride->id)->sole()->status);
    }

    public function test_after_the_last_retry_the_passenger_is_blocked_and_ops_see_it(): void
    {
        config(['money.charging.retry_minutes' => [5]]);
        $this->savedCard($this->passenger);
        $this->chargeOutcome = 'failed';

        $ride = $this->completeTrip($this->passenger);
        $this->travel(6)->minutes();
        $this->artisan('money:sweep')->assertSuccessful();

        $last = Payment::where('ride_id', $ride->id)->orderByDesc('attempt')->first();
        $this->assertSame(2, $last->attempt);
        $this->assertNull($last->next_retry_at);
        $this->assertSame(1, FinanceIssue::where('kind', 'payment_retries_exhausted')->count());

        $this->actingAs($this->passenger)->getJson(route('money.payment-due'))
            ->assertJson(['blocked' => true])->assertJsonPath('rides.0.ride_id', $ride->id);

        // The passenger pays now with another card: unblocked.
        $this->chargeOutcome = 'success';
        $other = $this->savedCard($this->passenger, ['last4' => '1111', 'signature' => 'SIG_other', 'is_default' => false]);
        $this->actingAs($this->passenger)->postJson(route('money.rides.pay-now', $ride), ['payment_method_id' => $other->id])
            ->assertOk()->assertJson(['status' => 'paid']);
        $this->actingAs($this->passenger)->getJson(route('money.payment-due'))->assertJson(['blocked' => false]);
    }

    public function test_a_timeout_leaves_the_charge_pending_until_verify_answers(): void
    {
        $this->savedCard($this->passenger);
        $this->chargeOutcome = 'throw';
        $this->verifyStatus = 500;

        $ride = $this->completeTrip($this->passenger);
        $payment = Payment::where('ride_id', $ride->id)->sole();
        $this->assertSame(Payment::PENDING, $payment->status, 'unknown outcome is never failed or paid');
        $this->assertSame('pending', $ride->fresh()->payment_status);

        // Paystack did take the charge; the sweeper verifies it.
        $this->chargeOutcome = 'success';
        $this->verifyStatus = 200;
        $this->travel(2)->minutes();
        $this->artisan('money:sweep')->assertSuccessful();

        $this->assertSame(Payment::PAID, $payment->fresh()->status);
        $this->assertSame(1, Payment::where('ride_id', $ride->id)->count(), 'no second charge was sent');
        $this->assertSame(1, $this->chargeCalls);
    }

    public function test_a_verify_amount_mismatch_never_marks_paid(): void
    {
        $this->savedCard($this->passenger);
        $this->verifyOverrides = ['amount' => 100];

        $ride = $this->completeTrip($this->passenger);

        $payment = Payment::where('ride_id', $ride->id)->sole();
        $this->assertSame(Payment::FAILED, $payment->status);
        $this->assertSame('amount_mismatch', $payment->failure_code);
        $this->assertSame(0, app(Ledger::class)->balance(Account::GATEWAY_CLEARING, 'ZAR'));
    }

    public function test_duplicate_completion_events_charge_once(): void
    {
        $this->savedCard($this->passenger);
        $ride = $this->completeTrip($this->passenger);

        event(new TripCompleted($ride->id, $ride->driver_id, now()));
        event(new TripCompleted($ride->id, $ride->driver_id, now()));
        SettleCompletedRide::dispatchSync($ride->id);

        $this->assertCount(1, $this->paystackRequests('/transaction/charge_authorization'));
        $this->assertSame(1, Payment::where('ride_id', $ride->id)->count());
        $this->assertSame(1, DriverEarning::where('ride_id', $ride->id)->count());
        $this->assertSame(1, Receipt::where('ride_id', $ride->id)->count());
        $this->assertTrue($this->ledgerSumsToZero());
    }

    public function test_concurrent_capture_attempts_send_one_charge(): void
    {
        $card = $this->savedCard($this->passenger);
        $this->chargeOutcome = 'throw'; // the first attempt stays pending, as if still in flight
        $this->verifyStatus = 500;
        $ride = $this->completeTrip($this->passenger);

        $charges = app(ChargeAttempts::class);
        $again = $charges->charge($ride, Payment::PURPOSE_RIDE_FARE, 12550, 'ZAR', 'card', $card);
        $third = $charges->charge($ride, Payment::PURPOSE_RIDE_FARE, 12550, 'ZAR', 'card', $card);

        $this->assertSame($again->id, $third->id);
        $this->assertSame(1, Payment::where('ride_id', $ride->id)->count());
        $this->assertSame(1, $this->chargeCalls);
    }

    public function test_without_a_saved_card_the_checkout_flow_is_kept(): void
    {
        $ride = $this->completeTrip($this->passenger);

        $this->assertSame('unpaid', $ride->fresh()->payment_status);
        $this->assertCount(0, $this->paystackRequests('/transaction/charge_authorization'));
        // The fare is recognised and the driver's earning waits for payment.
        $this->assertSame(12550, app(Ledger::class)->balance(Account::PASSENGER_RECEIVABLE, 'ZAR'));
        $this->assertSame(DriverEarning::PENDING, DriverEarning::where('ride_id', $ride->id)->sole()->status);
    }

    public function test_with_payments_off_nothing_is_collected_or_posted(): void
    {
        config(['paystack.secret_key' => '']);
        $ride = $this->completeTrip($this->passenger);

        $this->assertSame('not_due', $ride->fresh()->payment_status);
        $this->assertSame('not_collected', app(RideSettlement::class)->onTripCompleted($ride->id));
        $this->assertSame(0, DriverEarning::count());
        $this->assertSame(0, LedgerJournal::count());
    }

    public function test_an_expired_card_is_not_charged(): void
    {
        $this->savedCard($this->passenger, ['exp_month' => '01', 'exp_year' => '2020']);

        $ride = $this->completeTrip($this->passenger);

        $this->assertCount(0, $this->paystackRequests('/transaction/charge_authorization'));
        $this->assertSame('unpaid', $ride->fresh()->payment_status, 'falls back to the checkout link');
    }

    public function test_a_legacy_checkout_payment_posts_to_the_ledger_and_issues_a_receipt(): void
    {
        $ride = $this->completeTrip($this->passenger);
        $this->actingAs($this->passenger)->post(route('rides.pay', $ride))->assertRedirect();
        $this->actingAs($this->passenger)->get(route('rides.pay.callback', $ride))->assertRedirect();

        $this->assertSame('paid', $ride->fresh()->payment_status);
        $this->assertSame(12550, app(Ledger::class)->balance(Account::GATEWAY_CLEARING, 'ZAR'));
        $this->assertSame(DriverEarning::AVAILABLE, DriverEarning::where('ride_id', $ride->id)->sole()->status);
        $this->assertSame(1, Receipt::where('ride_id', $ride->id)->count());
    }
}
