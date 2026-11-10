<?php

namespace Tests\Feature\Business;

use App\Domain\Business\BusinessClock;
use App\Domain\Business\Collections;
use App\Domain\Business\Models\BusinessAccount;
use App\Domain\Business\Models\BusinessCharge;
use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Models\BusinessPayment;
use App\Domain\Business\Models\BusinessStatement;
use App\Domain\Business\Models\BusinessTrip;
use App\Domain\Business\Notifications\StatementIssuedNotification;
use App\Domain\Business\SpendLimits;
use App\Domain\Business\Statements;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\LedgerEntry;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentMethod;
use App\Domain\Payments\PaymentDueGuard;
use App\Domain\Payments\Seams\BusinessBilling;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BusinessFixtures;
use Tests\TestCase;

/**
 * Money: a completed business ride is billed through money's business_account gateway to the ledger
 * (business_receivable:{account}); statements are the ledger's figures; payments, dunning and the
 * company card behave as documented.
 */
class BillingAndStatementsTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
        Notification::fake();
    }

    private function ledger(): Ledger
    {
        return app(Ledger::class);
    }

    private function receivable(BusinessAccount $account): int
    {
        return $this->ledger()->balance(Account::of(Account::BUSINESS_RECEIVABLE, $account->id), 'ZAR');
    }

    public function test_a_completed_ride_is_charged_to_the_account_and_the_reservation_becomes_spend(): void
    {
        [$account] = $this->businessAccount();
        $sales = $this->department($account, 'Sales', 100_000);
        $traveller = $this->member($account, BusinessMember::TRAVELLER, ['department_id' => $sales->id, 'monthly_allowance_minor' => 50_000]);
        $this->requestBusinessRide($traveller, ['totalMinor' => 8500], ['purpose' => 'Site visit'])->assertCreated();
        $rideId = Ride::latest('id')->value('id');

        $this->completeBusinessRide($rideId, '92.00');

        $payment = Payment::where('ride_id', $rideId)->sole();
        $this->assertSame(Payment::PAID, $payment->status);
        $this->assertSame('business_account', $payment->provider);
        $charge = BusinessCharge::sole();
        $this->assertSame(9200, $charge->amount_minor);
        $this->assertSame($payment->id, $charge->payment_id);
        $this->assertSame($charge->reference, $payment->provider_transaction_id);
        $this->assertSame(9200, $this->receivable($account));

        $trip = BusinessTrip::where('ride_id', $rideId)->sole();
        $this->assertSame(BusinessTrip::CHARGED, $trip->status);
        $this->assertSame(0, $trip->reserved_minor);
        $limits = app(SpendLimits::class);
        $month = BusinessClock::monthKey();
        $this->assertSame(9200, $limits->spent('member', $traveller->id, $month), 'the real fare, not the estimate');
        $this->assertSame(9200, $limits->spent('department', $sales->id, $month));
        $this->assertSame(1_000_000 - 9200, $limits->creditAvailable($account->fresh()));
        $this->assertTrue($this->ledgerBalancesToZero());
    }

    public function test_a_cancelled_ride_releases_its_reservation(): void
    {
        [$account] = $this->businessAccount();
        $traveller = $this->member($account, BusinessMember::TRAVELLER, ['monthly_allowance_minor' => 10_000]);
        $this->requestBusinessRide($traveller, ['totalMinor' => 8000])->assertCreated();
        $ride = Ride::latest('id')->first();

        $this->actingAs($traveller->user)->postJson(route('ehail.dispatch.rides.cancel', $ride), ['reason' => 'changed_plans'])->assertSuccessful();

        $trip = BusinessTrip::where('ride_id', $ride->id)->sole();
        $this->assertSame(BusinessTrip::RELEASED, $trip->status);
        $this->assertSame(0, app(SpendLimits::class)->used('member', $traveller->id, BusinessClock::monthKey()));
        $this->requestBusinessRide($traveller, ['totalMinor' => 9000])->assertCreated();
    }

    public function test_suspension_never_strands_a_trip_already_booked(): void
    {
        [$account] = $this->businessAccount();
        $traveller = $this->member($account);
        $this->requestBusinessRide($traveller, ['totalMinor' => 8000])->assertCreated();
        $rideId = Ride::latest('id')->value('id');

        $account->forceFill(['status' => BusinessAccount::SUSPENDED, 'status_reason' => 'overdue'])->save();
        $this->completeBusinessRide($rideId, '80.00');

        $this->assertSame(Payment::PAID, Payment::where('ride_id', $rideId)->sole()->status);
        $this->assertSame(8000, $this->receivable($account));
        $this->assertFalse(app(PaymentDueGuard::class)->statusFor($traveller->user)['blocked']);
    }

    public function test_a_ride_not_booked_on_the_account_is_never_billed_to_it(): void
    {
        [$account] = $this->businessAccount();
        $traveller = $this->member($account);
        $decision = app(BusinessBilling::class)->canCharge($account->id, $traveller->user, 5000, 'ZAR');

        $this->assertFalse($decision->allowed);
    }

    public function test_statement_totals_reconcile_with_the_ledger(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'UTC'));
        [$account, $admin] = $this->businessAccount();
        $a = $this->member($account);
        $b = $this->member($account, BusinessMember::TRAVELLER, ['department_id' => $this->department($account, 'Ops')->id]);

        foreach ([[$a, '85.00'], [$b, '120.50'], [$a, '42.10']] as [$member, $fare]) {
            $this->requestBusinessRide($member)->assertCreated();
            $this->completeBusinessRide(Ride::latest('id')->value('id'), $fare);
            Carbon::setTestNow(now()->addDay());
        }

        $operator = User::where('is_platform_operator', true)->first();
        app(Collections::class)->recordEft($account, $operator, 10_000, 'EFT-OCT-1', null);

        // November: one more ride, which belongs to the next statement.
        Carbon::setTestNow(Carbon::parse('2026-11-02 08:00:00', 'UTC'));
        $this->requestBusinessRide($b)->assertCreated();
        $this->completeBusinessRide(Ride::latest('id')->value('id'), '60.00');

        $statement = app(Statements::class)->issue($account->fresh(), '2026-10');

        $this->assertSame(0, $statement->opening_minor);
        $this->assertSame(8500 + 12050 + 4210, $statement->charges_minor);
        $this->assertSame(10_000, $statement->payments_minor);
        $this->assertSame(24760 - 10_000, $statement->closing_minor);
        $this->assertCount(3, $statement->snapshot['lines']);
        $this->assertSame($statement->charges_minor, array_sum(array_column($statement->snapshot['lines'], 'amount_minor')));
        $this->assertSame(3, BusinessCharge::where('statement_id', $statement->id)->count());

        // The ledger's own balance at the period end is the closing balance.
        [, $end] = BusinessClock::monthBounds('2026-10');
        $ledgerAtEnd = (int) LedgerEntry::where('account', 'business_receivable:'.$account->id)->where('created_at', '<', $end)->sum(DB::raw('debit_minor - credit_minor'));
        $this->assertSame($ledgerAtEnd, $statement->closing_minor);
        $this->assertSame($statement->closing_minor + 6000, $this->receivable($account));

        // No VAT line unless the operator is VAT registered and fares carry tax.
        $this->assertFalse($statement->snapshot['vat_shown']);
        $this->assertSame(0, $statement->tax_minor);
        $this->assertSame(Carbon::parse('2026-11-02 08:00:00', 'UTC')->addDays(30)->toDateString(), $statement->due_at->toDateString());

        // Issuing twice changes nothing.
        $this->assertSame($statement->id, app(Statements::class)->issue($account->fresh(), '2026-10')->id);

        // Viewing and exporting: admin yes; CSV rows match.
        $this->actingAs($admin->user)->get(route('business.statements.show', $statement))->assertOk()->assertSee($statement->number);
        $csv = $this->actingAs($admin->user)->get(route('business.statements.csv', $statement))->assertOk()->getContent();
        $this->assertSame(4, count(array_filter(explode("\n", trim($csv)))));
        $this->assertStringContainsString('120.50', $csv);
    }

    public function test_dunning_marks_overdue_suspends_after_grace_and_payment_reactivates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'UTC'));
        [$account] = $this->businessAccount();
        $traveller = $this->member($account);
        $this->requestBusinessRide($traveller)->assertCreated();
        $this->completeBusinessRide(Ride::latest('id')->value('id'), '85.00');

        Carbon::setTestNow(Carbon::parse('2026-11-01 04:00:00', 'UTC'));
        $this->artisan('business:statements')->assertSuccessful();
        $statement = BusinessStatement::sole();
        $this->assertSame(BusinessStatement::ISSUED, $statement->status);
        Notification::assertSentOnDemand(StatementIssuedNotification::class);

        Carbon::setTestNow($statement->due_at->copy()->addDay());
        $collections = app(Collections::class);
        $collections->dunning();
        $this->assertSame(BusinessStatement::OVERDUE, $statement->fresh()->status);
        $this->assertSame(BusinessAccount::ACTIVE, $account->fresh()->status);

        Carbon::setTestNow($statement->due_at->copy()->addDays(8));
        $collections->dunning();
        $this->assertSame(BusinessAccount::SUSPENDED, $account->fresh()->status);
        $this->assertSame('overdue', $account->fresh()->status_reason);
        $this->requestBusinessRide($traveller)->assertStatus(422)->assertJsonPath('error', 'business_account_suspended');

        $collections->recordEft($account->fresh(), User::where('is_platform_operator', true)->first(), 8500, 'EFT-123', 'Paid late');
        $this->assertSame(BusinessStatement::PAID, $statement->fresh()->status);
        $this->assertSame(BusinessAccount::ACTIVE, $account->fresh()->status);
        $this->assertSame(0, $this->receivable($account));
        $this->assertTrue($this->ledgerBalancesToZero());
    }

    public function test_card_mode_statements_are_charged_to_the_company_card_only_on_a_verified_success(): void
    {
        config(['paystack.secret_key' => 'sk_test_business', 'paystack.public_key' => 'pk_test_business']);
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'UTC'));
        [$account, $admin] = $this->businessAccount(['billing_mode' => 'card']);
        $card = PaymentMethod::create([
            'user_id' => $admin->user_id, 'type' => PaymentMethod::CARD, 'provider' => 'paystack', 'authorization_code' => 'AUTH_company',
            'brand' => 'visa', 'last4' => '4081', 'exp_month' => '12', 'exp_year' => '2030', 'reusable' => true, 'status' => 'active',
        ]);
        $this->actingAs($admin->user)->put(route('business.settings.billing'), ['billing_mode' => 'card', 'card_payment_method_id' => $card->id])->assertRedirect();
        $this->assertSame($card->id, $account->fresh()->card_payment_method_id);

        $traveller = $this->member($account);
        $this->requestBusinessRide($traveller)->assertCreated();
        $this->completeBusinessRide(Ride::latest('id')->value('id'), '85.00');

        $outcome = 'failed';
        $charged = [];
        Http::preventStrayRequests();
        Http::fake([
            'api.paystack.co/transaction/charge_authorization' => function (Request $request) use (&$outcome, &$charged) {
                $charged[] = $request['reference'];

                return Http::response(['status' => true, 'message' => 'Charge attempted', 'data' => ['id' => 77, 'reference' => $request['reference'], 'status' => $outcome, 'amount' => $request['amount'], 'currency' => 'ZAR', 'gateway_response' => $outcome === 'success' ? 'Approved' : 'Insufficient funds']]);
            },
            'api.paystack.co/transaction/verify/*' => function (Request $request) use (&$outcome) {
                $reference = rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)));
                $payment = BusinessPayment::where('reference', $reference)->first();

                return Http::response(['status' => true, 'message' => 'Verification successful', 'data' => ['id' => 77, 'reference' => $reference, 'status' => $outcome, 'amount' => $payment?->amount_minor, 'currency' => 'ZAR', 'gateway_response' => $outcome === 'success' ? 'Approved' : 'Insufficient funds']]);
            },
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-01 04:00:00', 'UTC'));
        $this->artisan('business:statements')->assertSuccessful();
        $statement = BusinessStatement::sole();
        $this->assertSame(BusinessPayment::FAILED, BusinessPayment::sole()->status);
        $this->assertSame(8500, $this->receivable($account), 'a declined card moves nothing');

        // Next day: one new attempt, this time approved.
        $outcome = 'success';
        Carbon::setTestNow(now()->addDay());
        app(Collections::class)->dunning();
        $this->assertCount(2, $charged);
        $paid = BusinessPayment::where('status', BusinessPayment::PAID)->sole();
        $this->assertSame(8500, $paid->amount_minor);
        $this->assertSame(0, $this->receivable($account));
        $this->assertSame(BusinessStatement::PAID, $statement->fresh()->status);
        $this->assertTrue($this->ledgerBalancesToZero());

        // Running again charges nothing.
        app(Collections::class)->dunning();
        $this->assertCount(2, $charged);
    }

    private function ledgerBalancesToZero(): bool
    {
        $row = DB::table('ledger_entries')->selectRaw('COALESCE(SUM(debit_minor),0) d, COALESCE(SUM(credit_minor),0) c')->first();

        return (int) $row->d === (int) $row->c;
    }
}
