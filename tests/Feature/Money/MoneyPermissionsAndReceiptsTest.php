<?php

namespace Tests\Feature\Money;

use App\Domain\Earnings\Models\DriverEarning;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\Ledger;
use App\Domain\Ledger\Models\FinanceIssue;
use App\Domain\Ledger\Reconciliation;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Receipt;
use App\Domain\Payments\Models\WebhookEvent;
use App\Domain\Payments\PaymentDueGuard;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\Ride;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\Concerns\MoneyFixtures;
use Tests\TestCase;

class MoneyPermissionsAndReceiptsTest extends TestCase
{
    use MoneyFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
        $this->fakeMoneyPaystack();
        config(['money.commission.percent' => 20]);
    }

    private function paidTrip(?User $passenger = null, ?User $driver = null): Ride
    {
        $passenger ??= User::factory()->withPersonalTeam()->create();
        $this->savedCard($passenger);

        return $this->completeTrip($passenger, $driver);
    }

    public function test_drivers_see_only_their_own_earnings(): void
    {
        $driverA = $this->makeApprovedDriver();
        $driverB = $this->makeApprovedDriver();
        $this->paidTrip(null, $driverA);
        $this->paidTrip(null, $driverB);
        $earningB = DriverEarning::where('driver_id', $driverB->id)->sole();

        $statement = $this->actingAs($driverA)->getJson(route('money.driver.earnings', ['period' => 'day']))->assertOk()->json();
        $this->assertSame(1, $statement['totals']['trips']);
        $this->assertSame(10040, $statement['totals']['net_minor']);
        $this->assertSame([$driverA->id], DriverEarning::whereIn('id', array_column($statement['earnings'], 'id'))->pluck('driver_id')->unique()->values()->all());

        $this->actingAs($driverA)->getJson(route('money.driver.earning', $earningB))->assertForbidden();
        $this->actingAs($driverB)->getJson(route('money.driver.earning', $earningB))->assertOk()
            ->assertJsonPath('estimate_vs_actual.estimate_minor', 12550)->assertJsonPath('estimate_vs_actual.difference_minor', 0);

        $csv = $this->actingAs($driverA)->get(route('money.driver.earnings.csv', ['period' => 'day']))->assertOk()->streamedContent();
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1);
        $this->assertStringContainsString('100.40', $csv);
    }

    public function test_fleet_owners_see_only_their_fleets_drivers(): void
    {
        $driver = $this->makeApprovedDriver();
        $fleet = Fleet::find(DriverProfile::withoutGlobalScope('user')->where('user_id', $driver->id)->value('fleet_id'));
        $owner = User::find($fleet->owner_user_id);
        $otherOwner = User::factory()->create();
        $otherFleet = Fleet::factory()->create(['owner_user_id' => $otherOwner->id]);
        $this->paidTrip(null, $driver);

        $this->actingAs($owner)->getJson(route('money.fleets.earnings', $fleet))->assertOk()->assertJsonPath('totals.trips', 1)->assertJsonPath('by_driver.0.driver_id', $driver->id);
        $this->actingAs($otherOwner)->getJson(route('money.fleets.earnings', $fleet))->assertForbidden();
        $this->actingAs($otherOwner)->getJson(route('money.fleets.earnings', $otherFleet))->assertOk()->assertJsonPath('totals.trips', 0);
        $this->actingAs($driver)->getJson(route('money.fleets.earnings', $fleet))->assertForbidden();
        $this->actingAs($owner)->getJson(route('money.driver.earning', DriverEarning::sole()))->assertOk();

        $fleet->forceFill(['status' => 'pending'])->save();
        $this->actingAs($owner)->getJson(route('money.fleets.earnings', $fleet))->assertForbidden();
    }

    public function test_passengers_see_only_their_receipts_and_operators_see_all(): void
    {
        $ride = $this->paidTrip();
        $receipt = Receipt::sole();
        $stranger = User::factory()->create();

        $this->actingAs($ride->passenger)->getJson(route('money.receipts.index'))->assertOk()->assertJsonPath('data.0.number', $receipt->number);
        $this->actingAs($stranger)->getJson(route('money.receipts.index'))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($stranger)->get(route('money.receipts.show', $receipt))->assertForbidden();
        $this->actingAs($ride->driver)->get(route('money.receipts.show', $receipt))->assertForbidden();
        $this->actingAs($this->makeOperator())->get(route('money.receipts.show', $receipt))->assertOk();
    }

    public function test_every_finance_operations_route_refuses_non_operators(): void
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $driver = $this->makeApprovedDriver();

        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'money.ops.'));
        $this->assertGreaterThan(20, $routes->count());

        foreach ($routes as $route) {
            $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');

            foreach ([$passenger, $driver] as $user) {
                // Refused either by the operator gate (403) or, for a record id, before it (404).
                $this->assertContains($this->actingAs($user)->json($method, '/'.$uri)->status(), str_contains($route->uri(), '{') ? [403, 404] : [403], $method.' '.$uri);
            }
        }
    }

    public function test_the_receipt_is_numbered_immutable_and_shows_vat_only_when_registered(): void
    {
        $ride = $this->paidTrip();
        $receipt = Receipt::sole();

        $this->assertMatchesRegularExpression('/^EHR-\d{8}$/', $receipt->number);
        $html = $this->actingAs($ride->passenger)->get(route('money.receipts.show', $receipt))->assertOk()
            ->assertSee($receipt->number)->assertSee('R125.50')->assertSee('Visa •••• 4081')->assertSee('10 Kloof Street')->assertSee('20 Long Street')
            ->getContent();
        $this->assertStringNotContainsString('VAT', $html);
        $this->assertStringNotContainsString('AUTH_', $html);
        $this->assertStringNotContainsString((string) $ride->driver->email, $html);

        $this->actingAs($ride->passenger)->getJson(route('money.receipts.show', $receipt))->assertOk()->assertJsonPath('total_minor', 12550);

        try {
            DB::table('receipts')->update(['total_minor' => 1]);
            $this->fail('receipt changed');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        $receipt->update(['total_minor' => 1]);
    }

    public function test_receipt_numbers_follow_a_sequence(): void
    {
        $this->paidTrip();
        $this->paidTrip();

        $numbers = Receipt::orderBy('id')->pluck('number')->all();
        $this->assertSame(['EHR-00000001', 'EHR-00000002'], $numbers);
    }

    public function test_a_vat_registered_operator_sees_the_vat_number_on_the_receipt(): void
    {
        config(['money.vat.registered' => true, 'money.vat.number' => '4123456789']);
        $ride = $this->paidTrip();

        $this->actingAs($ride->passenger)->get(route('money.receipts.show', Receipt::sole()))->assertOk()->assertSee('VAT 4123456789');
    }

    public function test_the_payment_due_middleware_blocks_and_lets_through(): void
    {
        Route::middleware(['web', 'auth', 'ehail.payment-clear'])->post('/_test/request-ride', fn () => response()->json(['ok' => true]));
        $passenger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($passenger)->postJson('/_test/request-ride')->assertOk();

        $ride = $this->makeRide(['status' => 'completed', 'final_fare' => 80, 'completed_at' => now()->subHour()], $passenger);
        $ride->forceFill(['payment_status' => 'unpaid'])->save();

        $this->actingAs($passenger)->postJson('/_test/request-ride')->assertStatus(402)->assertJsonPath('payment_due.0.ride_id', $ride->id);
        $this->assertNotNull(app(PaymentDueGuard::class)->blockingReason($passenger));
    }

    public function test_webhook_events_are_stored_once_without_personal_data(): void
    {
        $payload = ['event' => 'charge.success', 'data' => ['id' => 77, 'status' => 'success', 'reference' => 'nobody-knows-me', 'amount' => 500, 'currency' => 'ZAR',
            'customer' => ['email' => 'pii@example.test'], 'authorization' => ['authorization_code' => 'AUTH_secret', 'last4' => '9999']]];

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload, 'bad-signature')->assertUnauthorized();

        $event = WebhookEvent::sole();
        $this->assertSame('processed', $event->status);
        $this->assertStringNotContainsString('pii@example.test', json_encode($event->payload));
        $this->assertStringNotContainsString('AUTH_secret', json_encode($event->payload));
    }

    public function test_reconciliation_matches_a_paystack_export_and_lists_mismatches(): void
    {
        $this->paidTrip();
        $payment = Payment::sole();
        $operator = $this->makeOperator();

        $this->actingAs($operator)->getJson(route('money.ops.reconciliation', ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()->assertJsonPath('ledger.balanced', true)->assertJsonPath('mismatches', [])->assertJsonPath('verified_payments.paystack_minor', 12550);

        $csv = "Reference,Amount,Fees\n{$payment->reference},125.50,4.64\nghost-ref,10.00,0\n";
        $file = UploadedFile::fake()->createWithContent('paystack.csv', $csv);
        $this->actingAs($operator)->post(route('money.ops.reconciliation.settlement'), ['file' => $file, 'record' => true], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('matched', 1)->assertJsonPath('mismatches.0.kind', 'settled_but_unknown');

        $this->assertSame(1, FinanceIssue::where('kind', 'reconciliation_mismatch')->count());
        $this->actingAs($operator)->getJson(route('money.ops.issues'))->assertOk()->assertJsonPath('data.0.kind', 'reconciliation_mismatch');
        $issue = FinanceIssue::sole();
        $this->actingAs($operator)->postJson(route('money.ops.issues.resolve', $issue), ['note' => 'Another product on the same Paystack account'])->assertOk();
        $this->assertSame('resolved', $issue->fresh()->status);

        $report = app(Reconciliation::class)->report(CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay());
        $this->assertSame([], $report['mismatches']);
    }

    public function test_operators_post_balanced_adjustments_only(): void
    {
        $operator = $this->makeOperator();

        $this->actingAs($operator)->postJson(route('money.ops.ledger.adjustment'), ['reason' => 'Write off a cash shortfall after review', 'lines' => [
            ['account' => 'waivers_expense', 'debit_minor' => 4000], ['account' => 'passenger_receivable', 'credit_minor' => 3999],
        ]])->assertUnprocessable();

        $this->actingAs($operator)->postJson(route('money.ops.ledger.adjustment'), ['reason' => 'Write off a cash shortfall after review', 'lines' => [
            ['account' => 'waivers_expense', 'debit_minor' => 4000], ['account' => 'nonsense', 'credit_minor' => 4000],
        ]])->assertUnprocessable();

        $this->actingAs($operator)->postJson(route('money.ops.ledger.adjustment'), ['reason' => 'Write off a cash shortfall after review', 'lines' => [
            ['account' => 'waivers_expense', 'debit_minor' => 4000], ['account' => 'passenger_receivable', 'credit_minor' => 4000],
        ]])->assertCreated();

        $this->actingAs($operator)->getJson(route('money.ops.ledger.trial-balance'))->assertOk()->assertJsonCount(2, 'accounts');
        $this->assertDatabaseHas('audit_logs', ['action' => 'money.ledger_adjustment']);
    }

    public function test_a_waived_fare_is_written_off_and_the_driver_still_earns(): void
    {
        $ride = $this->completeTrip();
        $operator = $this->makeOperator();

        $this->actingAs($operator)->post(route('operator.rides.waive', $ride), ['reason' => 'Passenger charged in error'])->assertRedirect();

        $ledger = app(Ledger::class);
        $this->assertSame(0, $ledger->balance(Account::PASSENGER_RECEIVABLE, 'ZAR'));
        $this->assertSame(12550, $ledger->balance(Account::WAIVERS_EXPENSE, 'ZAR'));
        $this->assertSame(DriverEarning::AVAILABLE, DriverEarning::sole()->status);
    }
}
