<?php

namespace Tests\Feature\Money;

use App\Domain\Payments\Models\Receipt;
use App\Domain\Payments\OperatorIdentity;
use App\Domain\Payments\Receipts\ReceiptIssuer;
use App\Domain\Pricing\Quotes\FareQuoter;
use App\Domain\Pricing\Quotes\QuoteBinder;
use App\Domain\Pricing\Quotes\QuoteRequest;
use App\Models\Ride;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\MoneyFixtures;
use Tests\Concerns\PricingFixtures;
use Tests\TestCase;

/**
 * Operator identity on money documents (D-1905), billed quantities on the receipt (D-1904) and receipt times in
 * South African time (D-1906). Nothing is invented: a value that is not configured leaves its line off.
 */
class OperatorIdentityTest extends TestCase
{
    use MoneyFixtures, PricingFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enablePaystack();
        $this->fakeMoneyPaystack();
        config(['money.commission.percent' => 20]);
    }

    private function configureOperator(): void
    {
        config([
            'money.operator.legal_name' => 'Example Mobility (Pty) Ltd',
            'money.operator.trading_name' => 'Dot.Ehail',
            'money.operator.registration_number' => '2020/123456/07',
            'money.operator.address' => '1 Test Road | Cape Town | 8001',
            'money.operator.support_phone' => '+27 21 555 0100',
            'money.operator.eft' => ['account_name' => 'Example Mobility', 'bank' => 'Test Bank', 'account_number' => '1234567890', 'branch_code' => '250655', 'account_type' => 'Current'],
            'money.receipts.support_email' => 'help@example.test',
            'money.vat.registered' => false,
            'money.vat.declared' => true,
        ]);
    }

    private function paidTrip(?callable $beforeComplete = null): Ride
    {
        $passenger = User::factory()->withPersonalTeam()->create();
        $this->savedCard($passenger);

        return $this->completeTrip($passenger, null, '125.50', $beforeComplete);
    }

    public function test_nothing_is_invented_when_nothing_is_configured(): void
    {
        config(['money.operator' => [], 'money.receipts.support_email' => null, 'money.receipts.support_url' => null, 'money.vat.registered' => false, 'money.vat.declared' => false]);

        $doc = OperatorIdentity::forDocument();

        $this->assertNull($doc['legal_name']);
        $this->assertNull($doc['registration_number']);
        $this->assertSame([], $doc['address_lines']);
        $this->assertSame(OperatorIdentity::VAT_NOT_CONFIGURED, $doc['vat_status']);
        $this->assertNull($doc['eft']);
        $this->assertNull(OperatorIdentity::vatSentence($doc), 'no VAT statement at all until the operator states its position');
        $this->assertCount(6, OperatorIdentity::missing());
    }

    public function test_vat_wording_is_precise_for_each_position(): void
    {
        config(['money.vat.registered' => true, 'money.vat.number' => '4123456789']);
        $this->assertSame(OperatorIdentity::VAT_REGISTERED, OperatorIdentity::vatStatus());
        $this->assertSame('VAT registration number 4123456789. VAT is shown on its own line.', OperatorIdentity::vatSentence(null, 1370));
        $this->assertSame('VAT registration number 4123456789. These fares carry no VAT.', OperatorIdentity::vatSentence(null, 0));

        config(['money.vat.registered' => true, 'money.vat.number' => null]);
        $this->assertSame(OperatorIdentity::VAT_NOT_CONFIGURED, OperatorIdentity::vatStatus(), 'registered without a number is not a position');

        config(['money.vat.registered' => false, 'money.vat.declared' => true]);
        $this->assertSame('The operator is not registered for VAT, so no VAT is charged.', OperatorIdentity::vatSentence());

        foreach (['may', 'might', 'if applicable', 'where applicable'] as $hedge) {
            $this->assertStringNotContainsString($hedge, (string) OperatorIdentity::vatSentence());
        }
    }

    public function test_a_no_reply_address_is_never_a_support_contact(): void
    {
        foreach (['no-reply@infodot.co.za', 'noreply@example.test', 'do-not-reply@example.test', 'not an email'] as $bad) {
            config(['money.receipts.support_email' => $bad]);
            $this->assertNull(OperatorIdentity::supportEmail(), $bad);
        }

        config(['money.receipts.support_email' => 'help@example.test']);
        $this->assertSame('help@example.test', OperatorIdentity::supportEmail());
    }

    public function test_the_receipt_prints_the_configured_operator_and_a_reachable_support_contact(): void
    {
        $this->configureOperator();
        $ride = $this->paidTrip();
        $receipt = Receipt::sole();

        $this->assertSame('Example Mobility (Pty) Ltd', $receipt->snapshot['operator']['legal_name']);
        $this->assertSame('The operator is not registered for VAT, so no VAT is charged.', $receipt->snapshot['vat_sentence']);

        $this->actingAs($ride->passenger)->get(route('money.receipts.show', $receipt))->assertOk()
            ->assertSee('Example Mobility (Pty) Ltd')->assertSee('2020/123456/07')->assertSee('1 Test Road')->assertSee('8001')
            ->assertSee('The operator is not registered for VAT, so no VAT is charged.')
            ->assertSee('mailto:help@example.test', false)->assertSee('+27 21 555 0100')
            ->assertDontSee('no-reply')->assertDontSee('noreply');
    }

    public function test_an_unconfigured_operator_leaves_the_lines_off_and_never_prints_a_no_reply_contact(): void
    {
        config(['money.operator' => [], 'money.receipts.support_email' => 'no-reply@infodot.co.za', 'money.receipts.support_url' => null, 'money.vat.registered' => false, 'money.vat.declared' => false]);
        $ride = $this->paidTrip();
        $receipt = Receipt::sole();

        $this->assertNull($receipt->snapshot['operator']['legal_name']);
        $this->assertNull($receipt->snapshot['support_email'] ?? null);

        $html = $this->actingAs($ride->passenger)->get(route('money.receipts.show', $receipt))->assertOk()->getContent();

        foreach (['no-reply', 'not configured', 'Registration', 'VAT', 'mailto:'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
        // A passenger can still reach someone: the in-app help case.
        $this->assertStringContainsString('Open a help case in the app', $html);
    }

    public function test_preflight_tells_operations_what_is_missing(): void
    {
        config(['money.operator' => [], 'money.receipts.support_email' => 'no-reply@infodot.co.za', 'money.vat.declared' => false, 'money.vat.registered' => false]);
        Artisan::call('ehail:preflight', ['--json' => true]);
        $row = collect(json_decode(Artisan::output(), true)['results'])->firstWhere('check', 'Operator details');

        $this->assertNotNull($row);
        $this->assertSame('WARN', strtoupper((string) $row['status']));
        $this->assertStringStartsWith('Operator details not configured: ', $row['detail']);
        $this->assertStringContainsString('EHAIL_SUPPORT_EMAIL is a no-reply address', $row['detail']);

        $this->configureOperator();
        $this->assertSame([], OperatorIdentity::missing());
    }

    public function test_the_receipt_states_the_billed_distance_and_time_its_lines_were_charged_on(): void
    {
        $this->assertSame(['distance' => '5.7 km', 'time' => '11.4 min'], ReceiptIssuer::billed([
            ['code' => 'base', 'amount_minor' => 1000],
            ['code' => 'distance', 'amount_minor' => 4275, 'quantity' => '5.7', 'quantity_tenths' => 57, 'unit' => 'km', 'rate_minor' => 750],
            ['code' => 'time', 'amount_minor' => 798, 'quantity' => '11.4', 'quantity_tenths' => 114, 'unit' => 'min', 'rate_minor' => 70],
        ]));
        $this->assertSame(['distance' => null, 'time' => null], ReceiptIssuer::billed([['code' => 'base', 'amount_minor' => 1000]]), 'a fare with no metered lines claims no billed quantity');

        // Through the real lifecycle: a ride booked on a 5.714 km / 686 s quote.
        $this->publishedRuleSet(['per_km_minor' => 750, 'per_minute_minor' => 70, 'booking_fee_minor' => 300, 'rounding_unit_minor' => 100]);
        $this->fakeRoute(5714, 686);

        $ride = $this->paidTrip(function (Ride $ride): void {
            $quote = $this->app->make(FareQuoter::class)->quote(new QuoteRequest($this->capeTown(), $this->seaPoint(), 'economy', $ride->passenger))->quote;
            $this->app->make(QuoteBinder::class)->bind($quote->id, $ride->passenger, $this->capeTown(), $this->seaPoint(), $ride);
        });
        $receipt = Receipt::sole();
        $billed = $receipt->snapshot['billed'];
        $lines = collect($receipt->snapshot['components'])->keyBy('code');

        $this->assertNotNull($billed['distance']);
        $this->assertNotNull($billed['time']);
        // The header quantity is the quantity on the line, and the line is rate x that quantity.
        $this->assertSame($billed['distance'], $lines['distance']['quantity'].' km');
        $this->assertSame($billed['time'], $lines['time']['quantity'].' min');
        $this->assertSame($receipt->total_minor, array_sum(array_column($receipt->snapshot['components'], 'amount_minor')));

        $this->actingAs($ride->passenger)->get(route('money.receipts.show', $receipt))->assertOk()
            ->assertSee('Billed distance')->assertSee($billed['distance'])->assertSee('Billed time')->assertSee($billed['time'])
            ->assertSee($lines['distance']['detail']);
    }

    public function test_receipt_times_are_south_african_time_and_say_so(): void
    {
        // 05:30 UTC is 07:30 in Johannesburg: the receipt must not print the stored UTC hour.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-10 05:30:00', 'UTC'));
        Carbon::setTestNow('2026-11-10 05:30:00');
        $ride = $this->paidTrip();
        $receipt = Receipt::sole();

        $html = $this->actingAs($ride->passenger)->get(route('money.receipts.show', $receipt))->assertOk()->getContent();

        $this->assertStringContainsString('07:30', $html);
        $this->assertStringNotContainsString('05:30', $html);
        $this->assertStringContainsString('SAST', $html);
        $this->assertStringContainsString('Times are South African Standard Time (SAST).', $html);
    }
}
