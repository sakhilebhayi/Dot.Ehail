<?php

namespace Tests\Feature\Money;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\Models\Receipt;
use App\Domain\Payments\Seams\BusinessBilling;
use App\Domain\Payments\Seams\BusinessChargeDecision;
use App\Domain\Payments\Seams\CancellationFeeSource;
use App\Domain\Rides\Events\RideCancelled;
use App\Enums\CancelReason;
use App\Models\Ride;
use App\Models\User;
use App\Services\Ehail\RideLifecycle;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MoneyFixtures;
use Tests\TestCase;

class WalletAndSeamsTest extends TestCase
{
    use MoneyFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
        $this->fakeMoneyPaystack();
        config(['money.commission.percent' => 20]);
    }

    public function test_the_wallet_reports_methods_and_honest_availability(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $this->savedCard($passenger);

        $this->actingAs($passenger)->getJson(route('money.wallet'))->assertOk()
            ->assertJsonPath('methods.0.label', 'Visa •••• 4081')
            ->assertJsonMissingPath('methods.0.authorization_code')
            ->assertJsonPath('card_payments.available', true)
            ->assertJsonPath('card_payments.test_mode', true)
            ->assertJsonPath('cash.enabled', false)
            ->assertJsonPath('business_accounts.available', false)
            ->assertJsonPath('payment_due.blocked', false);

        config(['paystack.secret_key' => '']);
        $this->actingAs($passenger)->getJson(route('money.wallet'))->assertJsonPath('card_payments.available', false);
        $this->actingAs($passenger)->postJson(route('money.cards.start'))->assertUnprocessable();
    }

    public function test_a_passenger_chooses_a_card_for_a_ride_and_only_their_own(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $card = $this->savedCard($passenger, ['is_default' => false]);
        $default = $this->savedCard($passenger, ['signature' => 'SIG_d', 'last4' => '5555', 'is_default' => true]);
        $stranger = User::factory()->create();
        $strangerCard = $this->savedCard($stranger, ['signature' => 'SIG_s']);

        $ride = $this->completeTrip($passenger, null, '125.50', function (Ride $ride) use ($passenger, $card, $strangerCard, $stranger) {
            $this->actingAs($passenger)->putJson(route('money.rides.choice', $ride), ['method' => 'card', 'payment_method_id' => $strangerCard->id])->assertUnprocessable();
            $this->actingAs($stranger)->putJson(route('money.rides.choice', $ride), ['method' => 'card', 'payment_method_id' => $strangerCard->id])->assertForbidden();
            $this->actingAs($passenger)->putJson(route('money.rides.choice', $ride), ['method' => 'business_account', 'payment_method_id' => $card->id])->assertUnprocessable();
            $this->actingAs($passenger)->putJson(route('money.rides.choice', $ride), ['method' => 'card', 'payment_method_id' => $card->id])->assertOk();
        });

        $this->assertSame($card->id, Payment::where('ride_id', $ride->id)->sole()->payment_method_id, 'the chosen card, not the default');
        $this->assertNotSame($default->id, $card->id);
    }

    public function test_a_cancellation_fee_is_charged_only_when_a_policy_is_bound(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $this->savedCard($passenger);
        $driver = $this->makeApprovedDriver();

        // No fee decided for this ride (no pricing fare record): nothing is charged.
        $ride = $this->makeRide(['status' => 'accepted', 'driver_id' => $driver->id, 'accepted_at' => now()], $passenger);
        app(RideLifecycle::class)->cancel($ride, $passenger, CancelReason::cases()[0]);
        $this->assertSame(0, Payment::count());

        $this->app->instance(CancellationFeeSource::class, new class implements CancellationFeeSource
        {
            public function feeFor(Ride $ride, string $actor, CarbonInterface $at): ?int
            {
                return $actor === 'passenger' ? 2500 : null;
            }
        });

        $second = $this->makeRide(['status' => 'accepted', 'driver_id' => $driver->id, 'accepted_at' => now()], $passenger);
        app(RideLifecycle::class)->cancel($second, $passenger, CancelReason::cases()[0]);
        event(new RideCancelled($second->id, $passenger->id, 'passenger', now(), 'passenger_cancelled')); // duplicate signal

        $fee = Payment::where('purpose', Payment::PURPOSE_CANCELLATION_FEE)->sole();
        $this->assertSame(2500, $fee->amountMinor());
        $this->assertSame(Payment::PAID, $fee->status);
        $this->assertSame(2000, DriverEarning::where('kind', DriverEarning::KIND_CANCELLATION_FEE)->sole()->net_minor);
        $this->assertSame(DriverEarning::AVAILABLE, DriverEarning::sole()->status);
        $this->assertSame(500, app(Ledger::class)->balance(Account::PLATFORM_REVENUE_COMMISSION, 'ZAR'));
        $this->assertSame('cancellation_fee', Receipt::sole()->kind);
        $this->assertTrue($this->ledgerSumsToZero());
    }

    public function test_a_business_account_trip_settles_to_the_business_receivable_when_the_seam_is_bound(): void
    {
        $this->app->instance(BusinessBilling::class, new class implements BusinessBilling
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function canCharge(int $businessAccountId, User $traveller, int $amountMinor, string $currency): BusinessChargeDecision
            {
                return $amountMinor <= 50000 ? BusinessChargeDecision::allow() : BusinessChargeDecision::deny('Over the trip limit');
            }

            public function record(int $businessAccountId, User $traveller, int $amountMinor, string $currency, int $rideId): string
            {
                return "BIZ-{$businessAccountId}-{$rideId}";
            }
        });

        $passenger = User::factory()->withPersonalTeam()->create();
        $method = PaymentMethod::create(['user_id' => $passenger->id, 'type' => PaymentMethod::BUSINESS_ACCOUNT, 'business_account_id' => 7, 'status' => 'active']);

        $ride = $this->completeTrip($passenger, null, '125.50', function (Ride $ride) use ($passenger, $method) {
            $this->actingAs($passenger)->putJson(route('money.rides.choice', $ride), ['method' => 'business_account', 'payment_method_id' => $method->id])->assertOk();
        });

        $payment = Payment::where('ride_id', $ride->id)->sole();
        $this->assertSame(Payment::PAID, $payment->status);
        $this->assertSame('BIZ-7-'.$ride->id, $payment->provider_transaction_id);
        $this->assertSame(12550, app(Ledger::class)->balance('business_receivable:7', 'ZAR'));
        $this->assertCount(0, $this->paystackRequests('/transaction/charge_authorization'));
    }
}
