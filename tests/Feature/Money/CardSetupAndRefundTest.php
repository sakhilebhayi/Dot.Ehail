<?php

namespace Tests\Feature\Money;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\Models\WebhookEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MoneyFixtures;
use Tests\TestCase;

class CardSetupAndRefundTest extends TestCase
{
    use MoneyFixtures, RefreshDatabase;

    private User $passenger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
        $this->fakeMoneyPaystack();
        $this->passenger = User::factory()->withPersonalTeam()->create();
    }

    public function test_adding_a_card_uses_a_card_only_checkout_saves_the_token_and_refunds_the_charge(): void
    {
        $response = $this->actingAs($this->passenger)->postJson(route('money.cards.start'))->assertCreated();
        $this->assertStringStartsWith('https://checkout.paystack.com/', $response->json('authorization_url'));

        $init = $this->paystackRequests('/transaction/initialize')[0]->data();
        $this->assertSame(['card'], $init['channels']);
        $this->assertSame(100, $init['amount']);

        $this->actingAs($this->passenger)->get(route('money.cards.callback', ['reference' => $init['reference']]))->assertRedirect('/dashboard');

        $card = PaymentMethod::where('user_id', $this->passenger->id)->sole();
        $this->assertSame('AUTH_fixture_8dfhjjdt', $card->authorization_code);
        $this->assertNotSame('AUTH_fixture_8dfhjjdt', $card->getRawOriginal('authorization_code'), 'token is encrypted at rest');
        $this->assertSame('4081', $card->last4);
        $this->assertTrue($card->is_default);
        $this->assertArrayNotHasKey('authorization_code', $card->toArray());

        $refund = $this->paystackRequests('/refund');
        $this->assertCount(1, $refund);
        $this->assertSame($init['reference'], $refund[0]['transaction']);
        $this->assertSame(100, $refund[0]['amount']);
        // Paystack said "pending": the refund is not processed until Paystack says so.
        $this->assertSame(Refund::PENDING, Refund::sole()->status);

        // refund.processed webhook -> fetch -> processed, clearing accounts back to zero.
        $this->postWebhook(['event' => 'refund.processed', 'data' => ['id' => Refund::sole()->provider_refund_id, 'status' => 'processed', 'transaction_reference' => $init['reference'], 'amount' => 100]])->assertOk();
        $this->assertSame(Refund::PROCESSED, Refund::sole()->status);
        $ledger = app(Ledger::class);
        $this->assertSame(0, $ledger->balance(Account::GATEWAY_CLEARING, 'ZAR'));
        $this->assertSame(0, $ledger->balance(Account::CARD_VERIFICATION_CLEARING, 'ZAR'));
        $this->assertTrue($this->ledgerSumsToZero());
    }

    public function test_the_same_card_added_twice_is_one_method_and_a_non_reusable_card_is_not_saved(): void
    {
        foreach ([1, 2] as $i) {
            $this->actingAs($this->passenger)->postJson(route('money.cards.start'))->assertCreated();
            $this->actingAs($this->passenger)->get(route('money.cards.callback'))->assertRedirect();
        }

        $this->assertSame(1, PaymentMethod::where('user_id', $this->passenger->id)->count());

        $this->authorizationReusable = false;
        $this->cardSignature = 'SIG_other';
        $this->actingAs($this->passenger)->postJson(route('money.cards.start'))->assertCreated();
        $this->actingAs($this->passenger)->get(route('money.cards.callback'))->assertRedirect()->assertSessionHas('flash.bannerStyle', 'danger');
        $this->assertSame(1, PaymentMethod::where('user_id', $this->passenger->id)->count());
    }

    public function test_card_setup_callback_and_webhook_together_save_once(): void
    {
        $this->actingAs($this->passenger)->postJson(route('money.cards.start'))->assertCreated();
        $payment = Payment::where('purpose', Payment::PURPOSE_CARD_SETUP)->sole();

        $this->postWebhook(['event' => 'charge.success', 'data' => ['id' => 1, 'status' => 'success', 'reference' => $payment->reference, 'amount' => 100, 'currency' => 'ZAR']])->assertOk();
        $this->actingAs($this->passenger)->get(route('money.cards.callback'))->assertRedirect();

        $this->assertSame(1, PaymentMethod::count());
        $this->assertSame(1, Refund::count());
    }

    public function test_removing_a_card_forgets_the_token_and_moves_the_default(): void
    {
        $first = $this->savedCard($this->passenger);
        $second = $this->savedCard($this->passenger, ['signature' => 'SIG_2', 'last4' => '2222', 'is_default' => false]);

        $this->actingAs($this->passenger)->deleteJson(route('money.methods.destroy', $first))->assertOk();

        $this->assertNull($first->fresh()->authorization_code);
        $this->assertSame('removed', $first->fresh()->status);
        $this->assertTrue($second->fresh()->is_default);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->deleteJson(route('money.methods.destroy', $second))->assertNotFound();
        $this->actingAs($stranger)->postJson(route('money.methods.default', $second))->assertNotFound();
    }

    public function test_an_operator_refunds_part_of_a_fare_through_paystack(): void
    {
        $this->savedCard($this->passenger);
        $ride = $this->completeTrip($this->passenger);
        $payment = Payment::where('ride_id', $ride->id)->sole();
        $operator = $this->makeOperator();

        $this->actingAs($this->passenger)->postJson(route('money.ops.refund', $payment), ['amount_minor' => 500, 'reason' => 'goodwill'])->assertForbidden();

        $this->actingAs($operator)->postJson(route('money.ops.refund', $payment), ['amount_minor' => 99999, 'reason' => 'goodwill'])->assertUnprocessable();
        $this->actingAs($operator)->postJson(route('money.ops.refund', $payment), ['amount_minor' => 2550, 'reason' => 'overcharged', 'note' => 'Route detour'])
            ->assertCreated()->assertJsonPath('refund.status', Refund::PENDING);

        $body = $this->paystackRequests('/refund')[0]->data();
        $this->assertSame($payment->reference, $body['transaction']);
        $this->assertSame(2550, $body['amount']);

        // While it is pending, a second refund cannot exceed what is left.
        $this->actingAs($operator)->postJson(route('money.ops.refund', $payment), ['amount_minor' => 10001, 'reason' => 'goodwill'])->assertUnprocessable();

        $refund = Refund::sole();
        $this->postWebhook(['event' => 'refund.processed', 'data' => ['id' => (int) $refund->provider_refund_id, 'status' => 'processed', 'amount' => 2550, 'transaction_reference' => $payment->reference]])->assertOk();
        // A redelivery is stored once and changes nothing.
        $this->postWebhook(['event' => 'refund.processed', 'data' => ['id' => (int) $refund->provider_refund_id, 'status' => 'processed', 'amount' => 2550, 'transaction_reference' => $payment->reference]])->assertOk();

        $this->assertSame(Refund::PROCESSED, $refund->fresh()->status);
        $this->assertSame(2550, $payment->fresh()->refunded_minor);
        $this->assertSame(1, WebhookEvent::where('event', 'refund.processed')->count());
        $ledger = app(Ledger::class);
        $this->assertSame(2550, $ledger->balance(Account::REFUNDS, 'ZAR'));
        $this->assertSame(10000, $ledger->balance(Account::GATEWAY_CLEARING, 'ZAR'));
        $this->assertCount(1, $this->notificationsOfType($this->passenger, 'ride_refunded'));
    }

    public function test_a_refund_can_be_charged_back_to_the_driver(): void
    {
        config(['money.commission.percent' => 20]);
        $this->savedCard($this->passenger);
        $this->refundCreateStatus = 'processed';
        $ride = $this->completeTrip($this->passenger);
        $payment = Payment::where('ride_id', $ride->id)->sole();

        $this->actingAs($this->makeOperator())->postJson(route('money.ops.refund', $payment), ['amount_minor' => 3000, 'reason' => 'driver_issue', 'charge_back_driver' => true])
            ->assertCreated()->assertJsonPath('refund.status', Refund::PROCESSED);

        $this->assertSame(10040 - 3000, app(Ledger::class)->balance(Account::driverPayable($ride->driver_id), 'ZAR'));
        $adjustment = DriverEarning::where('kind', DriverEarning::KIND_ADJUSTMENT)->sole();
        $this->assertSame(-3000, $adjustment->net_minor);
        $this->assertTrue($this->ledgerSumsToZero());
    }

    public function test_a_failed_refund_is_listed_for_operations(): void
    {
        $this->savedCard($this->passenger);
        $ride = $this->completeTrip($this->passenger);
        $payment = Payment::where('ride_id', $ride->id)->sole();
        $this->actingAs($this->makeOperator())->postJson(route('money.ops.refund', $payment), ['amount_minor' => 1000, 'reason' => 'goodwill'])->assertCreated();

        $this->refundFetchStatus = 'failed';
        $refund = Refund::sole();
        $this->postWebhook(['event' => 'refund.failed', 'data' => ['id' => (int) $refund->provider_refund_id, 'status' => 'failed', 'amount' => 1000]])->assertOk();

        $this->assertSame(Refund::FAILED, $refund->fresh()->status);
        $this->assertSame(0, (int) $payment->fresh()->refunded_minor);
        $this->assertDatabaseHas('finance_issues', ['kind' => 'refund_failed', 'subject_id' => $refund->id]);
    }
}
