<?php

namespace Tests\Feature\Paystack;

use App\Models\Ride;
use App\Models\RidePayment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesPaystack;
use Tests\TestCase;

class PaystackWebhookTest extends TestCase
{
    use FakesPaystack, RefreshDatabase;

    private User $passenger;

    private Ride $ride;

    private RidePayment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enablePaystack();
        Http::preventStrayRequests();
        $this->passenger = User::factory()->withPersonalTeam()->create();
        $this->ride = $this->completedRide($this->passenger, '125.50', 'pending');
        $this->payment = $this->paymentFor($this->ride);
    }

    // ─── Signature matrix ────────────────────────────────────────────────

    public function test_a_valid_signature_marks_the_ride_paid(): void
    {
        $this->postWebhook($this->chargeSuccess($this->payment))->assertOk();

        $this->assertSame('paid', $this->ride->fresh()->payment_status);
        $payment = $this->payment->fresh();
        $this->assertSame(RidePayment::STATUS_PAID, $payment->status);
        $this->assertSame('4099260516', $payment->provider_transaction_id);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_the_webhook_needs_no_csrf_token_or_login_and_makes_no_http_call(): void
    {
        $this->postWebhook($this->chargeSuccess($this->payment))->assertOk();

        Http::assertNothingSent();
    }

    public function test_every_bad_signature_gets_the_same_generic_401_and_changes_nothing(): void
    {
        $body = json_encode($this->chargeSuccess($this->payment));
        $cases = [
            'missing' => [null, false],
            'empty' => ['', true],
            'garbage' => ['not-a-signature', true],
            'sha256 instead of sha512' => [hash_hmac('sha256', $body, self::TEST_KEY), true],
            'wrong secret' => [hash_hmac('sha512', $body, 'sk_test_someone_else'), true],
            'signature of another body' => [hash_hmac('sha512', $body.' ', self::TEST_KEY), true],
            'right bytes, truncated' => [substr(hash_hmac('sha512', $body, self::TEST_KEY), 0, 64), true],
            'right bytes with a prefix' => ['sha512='.hash_hmac('sha512', $body, self::TEST_KEY), true],
        ];

        $bodies = [];
        foreach ($cases as $label => [$signature, $sign]) {
            $response = $this->postWebhook($body, $signature, $sign)->assertStatus(401);
            $bodies[$label] = $response->getContent();
        }

        $this->assertCount(1, array_unique($bodies), 'the 401 body must not reveal which check failed');
        $this->assertSame('pending', $this->ride->fresh()->payment_status);
        $this->assertSame(RidePayment::STATUS_INITIATED, $this->payment->fresh()->status);
        $this->assertSame(1, RidePayment::count());
    }

    public function test_an_uppercase_hex_signature_is_accepted(): void
    {
        $body = json_encode($this->chargeSuccess($this->payment));

        $this->postWebhook($body, strtoupper($this->sign($body)))->assertOk();
        $this->assertSame('paid', $this->ride->fresh()->payment_status);
    }

    public function test_the_signature_is_checked_before_the_body_is_parsed(): void
    {
        $this->postWebhook('{ this is not json', 'bad')->assertStatus(401);
        $this->postWebhook('{ this is not json')->assertStatus(400);
    }

    public function test_an_invalid_signed_body_is_a_400_with_nothing_written(): void
    {
        $this->postWebhook('not json at all')->assertStatus(400);
        $this->postWebhook('"just a string"')->assertStatus(400);

        $this->assertSame(1, RidePayment::count());
    }

    public function test_the_webhook_is_refused_when_no_usable_key_is_configured(): void
    {
        $body = json_encode($this->chargeSuccess($this->payment));

        config(['paystack.secret_key' => '']);
        $this->postWebhook($body, hash_hmac('sha512', $body, ''))->assertStatus(401);

        $this->enablePaystack('sk_live_refused');
        $this->postWebhook($body, hash_hmac('sha512', $body, 'sk_live_refused'))->assertStatus(401);

        $this->assertSame('pending', $this->ride->fresh()->payment_status);
    }

    public function test_the_webhook_is_exempt_from_csrf_while_other_posts_are_not(): void
    {
        // Laravel skips CSRF while running unit tests; switch it back on for this request.
        $this->app['env'] = 'local';

        $this->postWebhook($this->chargeSuccess($this->payment))->assertOk();
        $this->assertSame('paid', $this->ride->fresh()->payment_status);

        $this->actingAs($this->passenger)->post(route('rides.pay', $this->ride))->assertStatus(419);
    }

    public function test_get_is_not_allowed(): void
    {
        $this->get('/webhooks/paystack')->assertStatus(405);
    }

    public function test_the_webhook_is_throttled(): void
    {
        $limited = false;

        for ($i = 0; $i < 90; $i++) {
            if ($this->postWebhook('{}', 'bad')->status() === 429) {
                $limited = true;
                break;
            }
        }

        $this->assertTrue($limited, 'expected a 429 after many requests');
    }

    // ─── Events ──────────────────────────────────────────────────────────

    public function test_other_events_are_acknowledged_and_ignored(): void
    {
        foreach (['charge.failed', 'transfer.success', 'refund.processed', 'customeridentification.success'] as $event) {
            $this->postWebhook(['event' => $event, 'data' => ['reference' => $this->payment->reference]])->assertOk();
        }

        $this->assertSame('pending', $this->ride->fresh()->payment_status);
        $this->assertSame(RidePayment::STATUS_INITIATED, $this->payment->fresh()->status);
    }

    public function test_a_charge_success_that_is_not_successful_does_not_pay(): void
    {
        $this->postWebhook($this->chargeSuccess($this->payment, ['status' => 'failed']))->assertOk();

        $this->assertSame('pending', $this->ride->fresh()->payment_status);
    }

    // ─── Replays and races ───────────────────────────────────────────────

    public function test_a_replayed_webhook_pays_exactly_once(): void
    {
        $payload = $this->chargeSuccess($this->payment);

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertSame(1, RidePayment::where('status', RidePayment::STATUS_PAID)->count());
        $this->assertSame(1, RidePayment::count());
        $this->assertCount(1, $this->notificationsOfType($this->passenger, 'ride_paid'));
        $this->assertCount(1, $this->notificationsOfType($this->ride->driver, 'ride_paid'));
    }

    public function test_the_same_transaction_id_for_a_second_payment_is_not_double_counted(): void
    {
        $this->postWebhook($this->chargeSuccess($this->payment))->assertOk();

        $other = $this->completedRide($this->passenger, '125.50', 'pending');
        $otherPayment = $this->paymentFor($other);
        $this->postWebhook($this->chargeSuccess($otherPayment))->assertOk(); // same transaction id 4099260516

        $this->assertSame('pending', $other->fresh()->payment_status);
        $this->assertSame(RidePayment::STATUS_UNMATCHED, $otherPayment->fresh()->status);
        $this->assertSame('duplicate_transaction', $otherPayment->fresh()->unmatched_reason);
        $this->assertSame(1, RidePayment::where('provider_transaction_id', '4099260516')->count());
    }

    public function test_a_webhook_after_the_callback_changes_nothing(): void
    {
        $this->fakePaystack();
        $this->actingAs($this->passenger)->get(route('rides.pay.callback', $this->ride))->assertRedirect();
        $paidAt = $this->payment->fresh()->paid_at;

        $this->postWebhook($this->chargeSuccess($this->payment))->assertOk();

        $this->assertSame(1, RidePayment::where('status', RidePayment::STATUS_PAID)->count());
        $this->assertEquals($paidAt, $this->payment->fresh()->paid_at);
        $this->assertCount(1, $this->notificationsOfType($this->passenger, 'ride_paid'));
    }

    public function test_a_callback_after_the_webhook_changes_nothing(): void
    {
        $this->postWebhook($this->chargeSuccess($this->payment))->assertOk();
        $this->fakePaystack();

        $this->actingAs($this->passenger)->get(route('rides.pay.callback', $this->ride))->assertRedirect();

        $this->assertSame(1, RidePayment::where('status', RidePayment::STATUS_PAID)->count());
        $this->assertSame([], $this->paystackRequests('/transaction/verify')->all(), 'a settled ride needs no verification');
        $this->assertCount(1, $this->notificationsOfType($this->passenger, 'ride_paid'));
    }

    // ─── Mismatches and unknowns ─────────────────────────────────────────

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function mismatches(): array
    {
        return [
            'short amount' => [['amount' => 12549], 'amount_mismatch'],
            'excess amount' => [['amount' => 12551], 'amount_mismatch'],
            'amount in rand not cents' => [['amount' => 125], 'amount_mismatch'],
            'other currency' => [['currency' => 'NGN'], 'currency_mismatch'],
        ];
    }

    #[DataProvider('mismatches')]
    public function test_a_mismatching_charge_is_flagged_for_operators_and_does_not_pay(array $override, string $reason): void
    {
        $this->postWebhook($this->chargeSuccess($this->payment, $override))->assertOk();

        $this->assertSame('pending', $this->ride->fresh()->payment_status);
        $payment = $this->payment->fresh();
        $this->assertSame(RidePayment::STATUS_UNMATCHED, $payment->status);
        $this->assertSame($reason, $payment->unmatched_reason);
        $this->assertNull($payment->paid_at);
    }

    public function test_an_unknown_reference_is_recorded_as_unmatched_and_acknowledged(): void
    {
        $payload = ['event' => 'charge.success', 'data' => ['id' => 99, 'status' => 'success', 'reference' => 'some-other-system-77', 'amount' => 5000, 'currency' => 'ZAR', 'customer' => ['email' => 'pii@example.test']]];

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $unmatched = RidePayment::where('reference', 'some-other-system-77')->get();
        $this->assertCount(1, $unmatched);
        $this->assertSame(RidePayment::STATUS_UNMATCHED, $unmatched[0]->status);
        $this->assertSame('unknown_reference', $unmatched[0]->unmatched_reason);
        $this->assertNull($unmatched[0]->ride_id);
        $this->assertSame(5000, $unmatched[0]->amount_cents);
        $this->assertStringNotContainsString('pii@example.test', json_encode($unmatched[0]->getAttributes()));
        $this->assertSame('pending', $this->ride->fresh()->payment_status);
    }

    public function test_a_charge_for_a_waived_ride_is_flagged_because_money_arrived(): void
    {
        $this->ride->forceFill(['payment_status' => 'waived'])->save();

        $this->postWebhook($this->chargeSuccess($this->payment))->assertOk();

        $this->assertSame('waived', $this->ride->fresh()->payment_status);
        $this->assertSame('ride_not_payable', $this->payment->fresh()->unmatched_reason);
    }

    public function test_the_stored_summary_holds_no_personal_data(): void
    {
        $this->postWebhook($this->chargeSuccess($this->payment))->assertOk();

        $summary = json_encode($this->payment->fresh()->summary);
        foreach (['someone@example.test', 'customer', 'authorization', '4081'] as $leak) {
            $this->assertStringNotContainsString($leak, $summary);
        }
        $this->assertStringContainsString($this->payment->reference, $summary);
    }

    public function test_losing_the_unique_index_race_is_retried_instead_of_failing(): void
    {
        // Simulate another request recording this transaction between our check and our write: the
        // first write trips the unique index, the transaction rolls back, and one more pass settles.
        $fired = 0;
        RidePayment::updating(function (RidePayment $payment) use (&$fired) {
            if ($payment->status === RidePayment::STATUS_PAID && $fired++ === 0) {
                throw new UniqueConstraintViolationException('sqlite', 'update', [], new \Exception('UNIQUE constraint failed'));
            }
        });

        $this->postWebhook($this->chargeSuccess($this->payment))->assertOk();

        $this->assertSame(2, $fired);
        $this->assertSame('paid', $this->ride->fresh()->payment_status);
        $this->assertSame(1, RidePayment::where('status', RidePayment::STATUS_PAID)->count());
        $this->assertCount(1, $this->notificationsOfType($this->passenger, 'ride_paid'));
    }
}
