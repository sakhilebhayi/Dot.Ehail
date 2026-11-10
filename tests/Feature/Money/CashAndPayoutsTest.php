<?php

namespace Tests\Feature\Money;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Earnings\Models\Payout;
use App\Domain\Earnings\Models\PayoutAccount;
use App\Domain\Earnings\Models\PayoutBatch;
use App\Domain\Earnings\Payouts\PayoutAccounts;
use App\Domain\Earnings\Payouts\PayoutService;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\FinanceIssue;
use App\Domain\Payments\Models\DriverMoneySetting;
use App\Domain\Payments\Models\RidePaymentChoice;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MoneyFixtures;
use Tests\TestCase;

class CashAndPayoutsTest extends TestCase
{
    use MoneyFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
        $this->fakeMoneyPaystack();
        config(['money.commission.percent' => 20, 'money.cash.enabled' => true, 'money.payouts.minimum_minor' => 1000]);
    }

    private function cashTrip(User $driver, string $fare = '100.00', ?User $passenger = null): Ride
    {
        DriverMoneySetting::updateOrCreate(['user_id' => $driver->id], ['accepts_cash' => true]);

        return $this->completeTrip($passenger, $driver, $fare, function ($ride) {
            $this->actingAs($ride->passenger)->putJson(route('money.rides.choice', $ride), ['method' => 'cash'])->assertOk();
        });
    }

    private function bankAccount(User $user, string $number = '62000011112'): PayoutAccount
    {
        return app(PayoutAccounts::class)->add($user, ['payee_type' => 'driver', 'fleet_id' => null, 'account_holder' => $user->name, 'account_number' => $number, 'bank_code' => '250655', 'bank_name' => 'First National Bank', 'account_type' => 'personal']);
    }

    public function test_cash_is_refused_when_off_or_the_driver_has_not_opted_in(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->makeRide(['status' => 'accepted', 'driver_id' => $driver->id]);

        $this->actingAs($ride->passenger)->putJson(route('money.rides.choice', $ride), ['method' => 'cash'])->assertUnprocessable();

        DriverMoneySetting::create(['user_id' => $driver->id, 'accepts_cash' => true]);
        config(['money.cash.enabled' => false]);
        $this->actingAs($ride->passenger)->putJson(route('money.rides.choice', $ride), ['method' => 'cash'])->assertUnprocessable();

        config(['money.cash.enabled' => true, 'money.cash.categories' => ['economy']]);
        $this->actingAs($ride->passenger)->putJson(route('money.rides.choice', $ride), ['method' => 'cash'])->assertUnprocessable();

        config(['money.cash.categories' => []]);
        $this->actingAs($ride->passenger)->putJson(route('money.rides.choice', $ride), ['method' => 'cash'])->assertOk();
        $this->assertSame(RidePaymentChoice::CASH, RidePaymentChoice::sole()->method);
    }

    public function test_a_cash_trip_waits_for_the_driver_and_puts_the_commission_on_the_drivers_cash_account(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->cashTrip($driver);

        $this->assertSame('awaiting_cash', $ride->fresh()->payment_status);
        $this->assertCount(0, $this->paystackRequests('/transaction/charge_authorization'));
        $this->assertSame(DriverEarning::PENDING, DriverEarning::sole()->status);

        $other = $this->makeApprovedDriver();
        $this->actingAs($other)->postJson(route('money.driver.cash-received', $ride), ['amount_minor' => 10000])->assertUnprocessable();

        $this->actingAs($driver)->postJson(route('money.driver.cash-received', $ride), ['amount_minor' => 10000])->assertOk();
        $this->actingAs($driver)->postJson(route('money.driver.cash-received', $ride), ['amount_minor' => 10000])->assertOk(); // repeat: no-op

        $ledger = app(Ledger::class);
        $this->assertSame('paid', $ride->fresh()->payment_status);
        $this->assertSame(10000, $ledger->balance(Account::cashWithDriver($driver->id), 'ZAR'));
        $this->assertSame(8000, $ledger->balance(Account::driverPayable($driver->id), 'ZAR'));
        $this->assertSame(DriverEarning::AVAILABLE, DriverEarning::sole()->status);
        $this->assertSame(10000, DriverEarning::sole()->cash_collected_minor);
        $this->assertSame(0, FinanceIssue::count());

        // The driver holds R100 and is owed R80: they owe the platform R20, nothing is paid out.
        $balances = $this->actingAs($driver)->getJson(route('money.driver.earnings'))->assertOk()->json('balances');
        $this->assertSame(10000, $balances['cash_held_minor']);
        $this->assertSame(2000, $balances['cash_commission_owed_minor']);
        $this->assertSame(0, $balances['next_payout_estimate_minor']);
    }

    public function test_a_cash_shortfall_opens_a_discrepancy_without_blocking_the_passenger(): void
    {
        $driver = $this->makeApprovedDriver();
        $ride = $this->cashTrip($driver);

        $this->actingAs($driver)->postJson(route('money.driver.cash-received', $ride), ['amount_minor' => 6000])->assertOk();

        $issue = FinanceIssue::where('kind', 'cash_discrepancy')->sole();
        $this->assertSame(4000, $issue->amount_minor);
        $this->assertSame(4000, app(Ledger::class)->balance(Account::PASSENGER_RECEIVABLE, 'ZAR'));
        $this->actingAs($ride->passenger)->getJson(route('money.payment-due'))->assertJson(['blocked' => false]);
    }

    public function test_a_weekly_batch_nets_cash_owed_and_pays_the_rest(): void
    {
        $driver = $this->makeApprovedDriver();
        $this->bankAccount($driver);
        $this->savedCard($passenger = User::factory()->create());

        // Card trip: driver earns R200 net of R250. Cash trip: driver holds R100, earns R80.
        $this->completeTrip($passenger, $driver, '250.00');
        $cash = $this->cashTrip($driver, '100.00');
        $this->actingAs($driver)->postJson(route('money.driver.cash-received', $cash), ['amount_minor' => 10000])->assertOk();

        $this->travel(1)->days();
        $operator = $this->makeOperator();
        $batch = $this->actingAs($operator)->postJson(route('money.ops.batches.draft'), ['period_end' => now()->subDay()->toDateString()])->assertCreated()->json('batch');

        $payout = Payout::sole();
        $this->assertSame(20000 + 8000, $payout->earnings_minor);
        $this->assertSame(10000, $payout->cash_offset_minor);
        $this->assertSame(18000, $payout->amount_minor);
        $this->assertSame(Payout::SCHEDULED, $payout->status);

        // Export is the bank file; it carries the full account number and is audited.
        $csv = $this->actingAs($operator)->get(route('money.ops.batch.export', $batch['id']))->assertOk()->streamedContent();
        $this->assertStringContainsString('62000011112', $csv);
        $this->assertStringContainsString('180.00', $csv);
        $this->assertDatabaseHas('audit_logs', ['action' => 'money.payout_batch_exported']);

        $this->actingAs($operator)->postJson(route('money.ops.batch.paid', $batch['id']))->assertOk()->assertJson(['paid' => 1]);

        $ledger = app(Ledger::class);
        $this->assertSame(0, $ledger->balance(Account::driverPayable($driver->id), 'ZAR'));
        $this->assertSame(0, $ledger->balance(Account::cashWithDriver($driver->id), 'ZAR'));
        $this->assertSame(-18000, $ledger->balance(Account::PLATFORM_BANK, 'ZAR'));
        $this->assertSame(2, DriverEarning::where('status', DriverEarning::PAID)->count());
        $this->assertSame('completed', PayoutBatch::sole()->status);
        $this->assertTrue($this->ledgerSumsToZero());

        // The driver sees the exact composition.
        $this->actingAs($driver)->getJson(route('money.driver.payout', $payout))->assertOk()
            ->assertJsonPath('cash_offset_minor', 10000)->assertJsonCount(2, 'earnings');
        $this->actingAs($operator)->getJson(route('money.driver.payout', $payout))->assertOk();
        $this->actingAs($this->makeApprovedDriver())->getJson(route('money.driver.payout', $payout))->assertForbidden();
    }

    public function test_below_minimum_or_without_a_bank_account_is_carried_forward(): void
    {
        $poor = $this->makeApprovedDriver();
        $noBank = $this->makeApprovedDriver();
        $this->bankAccount($poor);
        $passenger = User::factory()->create();
        $this->savedCard($passenger);
        $this->completeTrip($passenger, $poor, '10.00');   // R8 net, below R10 minimum
        $this->completeTrip($passenger, $noBank, '50.00');

        $batch = app(PayoutService::class)->draftBatch(CarbonImmutable::today());

        $this->assertSame(0, $batch->payout_count);
        $reasons = collect($batch->summary['carried_forward'])->pluck('reason')->sort()->values()->all();
        $this->assertSame(['below_minimum', 'no_bank_account'], $reasons);
        $this->assertSame(2, DriverEarning::where('status', DriverEarning::AVAILABLE)->whereNull('payout_id')->count());
    }

    public function test_a_failed_payout_releases_the_earnings_and_a_reversal_reposts_them(): void
    {
        $driver = $this->makeApprovedDriver();
        $this->bankAccount($driver);
        $passenger = User::factory()->create();
        $this->savedCard($passenger);
        $this->completeTrip($passenger, $driver, '100.00');
        $service = app(PayoutService::class);
        $operator = $this->makeOperator();

        $payout = $service->draftBatch(CarbonImmutable::today())->payouts()->sole();
        $service->markFailed($payout, $operator, 'Account closed');
        $this->assertNull(DriverEarning::sole()->payout_id);
        $this->assertDatabaseHas('finance_issues', ['kind' => 'payout_failed']);

        $second = $service->draftBatch(CarbonImmutable::tomorrow())->payouts()->sole();
        $service->markPaid($second, $operator);
        $this->assertSame(0, app(Ledger::class)->balance(Account::driverPayable($driver->id), 'ZAR'));

        $service->reverse($second->fresh(), $operator, 'Bank returned the payment');
        $this->assertSame(8000, app(Ledger::class)->balance(Account::driverPayable($driver->id), 'ZAR'));
        $this->assertSame(DriverEarning::AVAILABLE, DriverEarning::sole()->status);
        $this->assertTrue($this->ledgerSumsToZero());
    }

    public function test_paystack_transfers_are_off_by_default_and_work_when_enabled(): void
    {
        $driver = $this->makeApprovedDriver();
        $this->bankAccount($driver);
        $passenger = User::factory()->create();
        $this->savedCard($passenger);
        $this->completeTrip($passenger, $driver, '100.00');
        $operator = $this->makeOperator();
        $batch = app(PayoutService::class)->draftBatch(CarbonImmutable::today());

        $this->actingAs($operator)->postJson(route('money.ops.batch.transfers', $batch))->assertUnprocessable();
        $this->assertCount(0, $this->paystackRequests('/transfer'));

        config(['money.payouts.paystack_transfers' => true]);
        $this->actingAs($operator)->postJson(route('money.ops.batch.transfers', $batch))->assertOk()->assertJson(['sent' => 1]);

        $recipient = $this->paystackRequests('/transferrecipient')[0]->data();
        $this->assertSame('basa', $recipient['type']);
        $this->assertSame('ZAR', $recipient['currency']);
        $transfer = $this->paystackRequests('api.paystack.co/transfer')->filter(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/transfer'))->first()->data();
        $payout = Payout::sole();
        $this->assertSame($payout->reference, $transfer['reference']);
        $this->assertSame(8000, $transfer['amount']);
        $this->assertSame('balance', $transfer['source']);
        $this->assertSame(Payout::PROCESSING, $payout->status, 'queued at Paystack is not paid');

        $this->postWebhook(['event' => 'transfer.success', 'data' => ['reference' => $payout->reference, 'status' => 'success', 'amount' => 8000, 'transfer_code' => 'TRF_x']])->assertOk();

        $this->assertSame(Payout::PAID, $payout->fresh()->status);
        $this->assertSame(0, app(Ledger::class)->balance(Account::driverPayable($driver->id), 'ZAR'));
        $this->assertSame(10000 - 8000, app(Ledger::class)->balance(Account::GATEWAY_CLEARING, 'ZAR'));
    }

    public function test_fleet_owner_payee_receives_their_drivers_earnings(): void
    {
        config(['money.payouts.payee_for_fleet_drivers' => 'fleet_owner']);
        $driver = $this->makeApprovedDriver();
        $fleet = Fleet::find(DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->value('fleet_id'));
        $owner = User::find($fleet->owner_user_id);
        app(PayoutAccounts::class)->add($owner, ['payee_type' => 'fleet_owner', 'fleet_id' => $fleet->id, 'account_holder' => 'Fleet Co', 'account_number' => '1234567890', 'bank_code' => '470010', 'bank_name' => 'Capitec', 'account_type' => 'business']);
        $passenger = User::factory()->create();
        $this->savedCard($passenger);
        $this->completeTrip($passenger, $driver, '100.00');

        $this->assertSame(8000, app(Ledger::class)->balance(Account::fleetPayable($fleet->id), 'ZAR'));
        $payout = app(PayoutService::class)->draftBatch(CarbonImmutable::today())->payouts()->sole();
        $this->assertSame($owner->id, $payout->payee_user_id);
        $this->assertSame('fleet_owner', $payout->payee_type);
    }

    public function test_one_bank_account_used_by_two_payees_is_flagged(): void
    {
        $this->bankAccount($this->makeApprovedDriver(), '62000099999');
        $this->bankAccount($this->makeApprovedDriver(), '6200 0099 999');

        $this->assertSame(1, FinanceIssue::where('kind', 'duplicate_payout_account')->count());
        $this->assertStringNotContainsString('62000099999', json_encode(PayoutAccount::first()->getAttributes()));
    }

    public function test_bank_account_verification_uses_paystack_validate_and_never_stores_the_id_number(): void
    {
        $driver = $this->makeApprovedDriver();
        $account = $this->bankAccount($driver);

        $this->actingAs($driver)->postJson(route('money.payout-accounts.verify', $account), ['document_type' => 'identityNumber', 'document_number' => '9001015009087'])
            ->assertOk()->assertJsonPath('account.verification_status', 'verified');

        $body = $this->paystackRequests('/bank/validate')[0]->data();
        $this->assertSame('ZA', $body['country_code']);
        $this->assertSame('62000011112', $body['account_number']);
        $this->assertDatabaseMissing('payout_accounts', ['verification_message' => '9001015009087']);
        $this->assertStringNotContainsString('9001015009087', json_encode(PayoutAccount::sole()->getAttributes()));

        $this->actingAs($this->makeApprovedDriver())->postJson(route('money.payout-accounts.verify', $account), ['document_type' => 'identityNumber', 'document_number' => '1'])->assertNotFound();
    }
}
