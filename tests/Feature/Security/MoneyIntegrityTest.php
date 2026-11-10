<?php

namespace Tests\Feature\Security;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Earnings\Models\PayoutAccount;
use App\Domain\Earnings\Payouts\PayoutAccounts;
use App\Domain\Earnings\Payouts\PayoutService;
use App\Domain\Ledger\FinanceIssues;
use App\Domain\Ledger\Models\FinanceIssue;
use App\Domain\Payments\ChargeAttempts;
use App\Domain\Payments\GatewayResult;
use App\Domain\Payments\Models\DriverMoneySetting;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\PaymentDueGuard;
use App\Enums\PaymentStatus;
use App\Http\Middleware\RequireRecentSignIn;
use App\Models\Ride;
use App\Models\User;
use App\Notifications\SecurityNoticeNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\MoneyFixtures;
use Tests\TestCase;

/**
 * R-46 security S-11, S-13, S-15, S-16: money cannot be dodged, double-taken or redirected.
 */
class MoneyIntegrityTest extends TestCase
{
    use MoneyFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
        $this->fakeMoneyPaystack();
        config(['money.commission.percent' => 20, 'money.cash.enabled' => true, 'money.payouts.minimum_minor' => 1000]);
    }

    private function bankAccount(User $user, string $number = '62000011112'): PayoutAccount
    {
        return app(PayoutAccounts::class)->add($user, ['payee_type' => 'driver', 'fleet_id' => null, 'account_holder' => $user->name, 'account_number' => $number, 'bank_code' => '250655', 'bank_name' => 'First National Bank', 'account_type' => 'personal']);
    }

    private function completedRide(User $passenger, PaymentStatus $status): Ride
    {
        $ride = $this->makeRide(['status' => 'completed', 'completed_at' => now()->subHour(), 'final_fare' => '90.00'], $passenger);
        $ride->forceFill(['payment_status' => $status->value])->save();

        return $ride->fresh();
    }

    public function test_an_abandoned_checkout_does_not_clear_the_payment_due_block(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->completedRide($passenger, PaymentStatus::Unpaid);
        $this->assertTrue(app(PaymentDueGuard::class)->statusFor($passenger)['blocked']);

        // The passenger opens a checkout and walks away: the ride now says "pending".
        $this->actingAs($passenger)->post(route('rides.pay', $ride));
        $this->assertSame(PaymentStatus::Pending->value, $ride->fresh()->payment_status);

        $this->assertTrue(app(PaymentDueGuard::class)->statusFor($passenger)['blocked']);
        $this->actingAs($passenger)->postJson(route('ehail.dispatch.rides.store'), ['quote_id' => 'x'])->assertStatus(402);
    }

    public function test_a_server_charge_in_flight_is_not_a_payment_due(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->completedRide($passenger, PaymentStatus::Pending);
        Payment::create(['ride_id' => $ride->id, 'user_id' => $passenger->id, 'provider' => 'paystack', 'purpose' => Payment::PURPOSE_RIDE_FARE, 'reference' => 'eh-pending-1', 'amount_cents' => 9000, 'currency' => 'ZAR', 'status' => Payment::PENDING]);

        $this->assertFalse(app(PaymentDueGuard::class)->statusFor($passenger)['blocked']);
        // And no second (checkout) payment can start while it is in flight.
        $this->actingAs($passenger)->post(route('rides.pay', $ride))->assertSessionHasErrors();
    }

    public function test_a_late_card_success_after_the_fare_was_paid_is_held_as_a_duplicate(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $ride = $this->completedRide($passenger, PaymentStatus::Paid);
        Payment::create(['ride_id' => $ride->id, 'user_id' => $passenger->id, 'provider' => 'paystack', 'purpose' => Payment::PURPOSE_RIDE_FARE, 'reference' => 'eh-paid-1', 'amount_cents' => 9000, 'currency' => 'ZAR', 'status' => Payment::PAID, 'paid_at' => now()]);
        $late = Payment::create(['ride_id' => $ride->id, 'user_id' => $passenger->id, 'provider' => 'paystack', 'purpose' => Payment::PURPOSE_RIDE_FARE, 'reference' => 'eh-late-1', 'amount_cents' => 9000, 'currency' => 'ZAR', 'status' => Payment::PENDING]);
        $journalsBefore = \DB::table('ledger_journals')->count();

        app(ChargeAttempts::class)->apply($late->id, new GatewayResult(GatewayResult::SUCCEEDED, 'TRX_late'));

        $this->assertSame(Payment::UNMATCHED, $late->fresh()->status);
        $this->assertSame('duplicate_payment', $late->fresh()->unmatched_reason);
        $this->assertSame($journalsBefore, \DB::table('ledger_journals')->count(), 'no second settlement journal');
        $this->assertTrue(FinanceIssue::where('kind', 'duplicate_payment')->exists());
    }

    public function test_a_cash_shortfall_holds_the_trip_earning_until_an_operator_resolves_it(): void
    {
        $driver = $this->makeApprovedDriver();
        $this->bankAccount($driver);
        DriverMoneySetting::updateOrCreate(['user_id' => $driver->id], ['accepts_cash' => true]);
        $ride = $this->completeTrip(null, $driver, '100.00', function ($ride) {
            $this->actingAs($ride->passenger)->putJson(route('money.rides.choice', $ride), ['method' => 'cash'])->assertOk();
        });

        $this->actingAs($driver)->postJson(route('money.driver.cash-received', $ride), ['amount_minor' => 0])->assertOk();
        $this->assertSame(DriverEarning::PENDING, DriverEarning::where('source_key', "trip:{$ride->id}")->value('status'));

        $this->travel(1)->days();
        $batch = app(PayoutService::class)->draftBatch(CarbonImmutable::today());
        $this->assertSame(0, $batch->payouts()->where('payee_user_id', $driver->id)->sum('amount_minor'), 'no bank payout of the held earning');

        app(FinanceIssues::class)->resolve(FinanceIssue::where('kind', 'cash_discrepancy')->sole(), $this->makeOperator(), 'Driver repaid the shortfall in person.');
        $this->assertSame(DriverEarning::AVAILABLE, DriverEarning::where('source_key', "trip:{$ride->id}")->value('status'));
    }

    public function test_a_changed_bank_account_waits_out_the_cooling_off_and_the_holder_is_told(): void
    {
        Notification::fake();
        $driver = $this->makeApprovedDriver();
        $this->bankAccount($driver);
        $this->savedCard($passenger = User::factory()->create());
        $this->completeTrip($passenger, $driver, '250.00');

        $changed = $this->bankAccount($driver, '99990000111');
        $this->assertNotNull($changed->payable_from);
        Notification::assertSentTo($driver, SecurityNoticeNotification::class, fn ($n) => $n->kind === 'payout_account' && str_contains($n->title, 'changed'));

        $this->travel(1)->days();
        $batch = app(PayoutService::class)->draftBatch(CarbonImmutable::today());
        $this->assertSame(0, $batch->payouts()->count());
        $this->assertSame('account_cooling_off', $batch->summary['carried_forward'][0]['reason']);

        $this->travel(3)->days();
        $this->assertSame(1, app(PayoutService::class)->draftBatch(CarbonImmutable::today())->payouts()->count());
    }

    public function test_changing_bank_details_or_trusted_contacts_needs_a_recent_sign_in(): void
    {
        $driver = $this->makeApprovedDriver();
        $form = ['account_holder' => 'T Mokoena', 'account_number' => '62 1234 5678', 'bank_code' => '250655', 'bank_name' => 'FNB', 'account_type' => 'personal'];

        $this->actingAs($driver)->postJson(route('money.payout-accounts.store'), $form + ['payee_type' => 'driver'])->assertStatus(423)->assertJsonPath('error', 'reauthentication_required');
        $this->actingAs($driver)->post(route('drive.earnings.bank-account'), $form)->assertRedirect(route('password.confirm'));
        $this->actingAs($driver)->postJson(route('safety.contacts.store'), ['name' => 'Sipho', 'email' => 'sipho@example.com'])->assertStatus(423);
        $this->assertSame(0, PayoutAccount::count());

        $this->actingAs($driver)->withSession([RequireRecentSignIn::SIGNED_IN_AT => time() - 3600])->postJson(route('money.payout-accounts.store'), $form + ['payee_type' => 'driver'])->assertStatus(423);
        $this->actingAs($driver)->withSession(['auth.password_confirmed_at' => time()])->postJson(route('money.payout-accounts.store'), $form + ['payee_type' => 'driver'])->assertCreated();
    }

    public function test_a_real_sign_in_counts_as_recent(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['password' => bcrypt('secret-pass-123')]);
        $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass-123']);

        $this->assertIsInt(session(RequireRecentSignIn::SIGNED_IN_AT));
    }

    public function test_automatic_transfers_skip_unverified_accounts(): void
    {
        config(['money.payouts.paystack_transfers' => true]);
        $driver = $this->makeApprovedDriver();
        $this->bankAccount($driver);
        $this->savedCard($passenger = User::factory()->create());
        $this->completeTrip($passenger, $driver, '100.00');
        $batch = app(PayoutService::class)->draftBatch(CarbonImmutable::today());

        $this->actingAs($this->makeOperator())->postJson(route('money.ops.batch.transfers', $batch))->assertOk()->assertJson(['sent' => 0, 'skipped' => 1]);
    }

    public function test_an_oversized_webhook_body_is_refused_before_it_is_hashed(): void
    {
        $this->call('POST', route('paystack.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'x'], str_repeat('a', 300 * 1024))
            ->assertStatus(413);
    }
}
