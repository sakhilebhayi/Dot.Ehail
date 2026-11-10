<?php

namespace Tests\Feature\Business;

use App\Domain\Business\Models\BusinessMember;
use App\Domain\Business\Statements;
use App\Models\Ride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BusinessFixtures;
use Tests\TestCase;

/**
 * The overview's headline spend uses the same month basis as the department budgets (D-1912), and the statement
 * carries the configured operator, EFT details and a precise VAT sentence (D-1905).
 */
class OverviewSpendAndStatementIdentityTest extends TestCase
{
    use BusinessFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBusiness();
        Notification::fake();
    }

    public function test_the_headline_spend_and_the_department_lines_count_the_same_rides(): void
    {
        // A ride taken late in October and settled in November is October's spend on both figures.
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00', 'UTC'));
        [$account, $admin] = $this->businessAccount();
        $sales = $this->department($account, 'Sales', 500_000);
        $traveller = $this->member($account, BusinessMember::TRAVELLER, ['department_id' => $sales->id]);

        $this->requestBusinessRide($traveller)->assertCreated();
        $rideId = Ride::latest('id')->value('id');

        $october = $this->actingAs($admin->user)->get(route('business.home'))->assertOk();
        $this->assertSame(0, $october->viewData('spent'));

        Carbon::setTestNow(Carbon::parse('2026-11-01 09:00:00', 'UTC'));
        $this->completeBusinessRide($rideId, '48.00');

        $november = $this->actingAs($admin->user)->get(route('business.home'))->assertOk();
        $this->assertSame('2026-11', $november->viewData('month'));
        $this->assertSame(0, $november->viewData('budgets')->sum('spent'), 'the department line: nothing ridden in November');
        $this->assertSame($november->viewData('budgets')->sum('spent'), $november->viewData('spent'), 'the headline agrees with the department lines');

        // A November ride counts on both, the same amount.
        $this->requestBusinessRide($traveller)->assertCreated();
        $this->completeBusinessRide(Ride::latest('id')->value('id'), '60.00');

        $after = $this->actingAs($admin->user)->get(route('business.home'))->assertOk();
        $this->assertSame(6000, $after->viewData('spent'));
        $this->assertSame(6000, $after->viewData('budgets')->sum('spent'));
    }

    public function test_the_statement_prints_the_configured_operator_eft_details_and_a_precise_vat_sentence(): void
    {
        config([
            'money.operator.legal_name' => 'Example Mobility (Pty) Ltd',
            'money.operator.registration_number' => '2020/123456/07',
            'money.operator.address' => '1 Test Road | Cape Town | 8001',
            'money.operator.eft' => ['account_name' => 'Example Mobility', 'bank' => 'Test Bank', 'account_number' => '1234567890', 'branch_code' => '250655', 'account_type' => 'Current'],
            'money.receipts.support_email' => 'accounts@example.test',
            'money.vat.registered' => false, 'money.vat.declared' => true,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'UTC'));
        [$account, $admin] = $this->businessAccount();
        $this->requestBusinessRide($this->member($account))->assertCreated();
        $this->completeBusinessRide(Ride::latest('id')->value('id'), '85.00');
        Carbon::setTestNow(Carbon::parse('2026-11-02 08:00:00', 'UTC'));

        $statement = app(Statements::class)->issue($account->fresh(), '2026-10');

        $this->assertSame('Example Mobility (Pty) Ltd', $statement->snapshot['operator']['legal_name']);
        $this->assertSame('The operator is not registered for VAT, so no VAT is charged.', $statement->snapshot['vat_sentence']);

        $html = $this->actingAs($admin->user)->get(route('business.statements.show', $statement))->assertOk()
            ->assertSee('Example Mobility (Pty) Ltd')->assertSee('2020/123456/07')
            ->assertSee('Test Bank')->assertSee('1234567890')->assertSee('250655')
            ->assertSee('The operator is not registered for VAT, so no VAT is charged.')
            ->assertSee('Times are South African Standard Time (SAST).')
            ->getContent();

        foreach (['may include', 'if applicable', 'where applicable', 'no-reply'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
    }

    public function test_a_statement_with_no_configured_operator_invents_nothing(): void
    {
        config(['money.operator' => [], 'money.receipts.support_email' => 'no-reply@infodot.co.za', 'money.vat.registered' => false, 'money.vat.declared' => false]);
        Carbon::setTestNow(Carbon::parse('2026-10-05 08:00:00', 'UTC'));
        [$account, $admin] = $this->businessAccount();
        $this->requestBusinessRide($this->member($account))->assertCreated();
        $this->completeBusinessRide(Ride::latest('id')->value('id'), '85.00');
        Carbon::setTestNow(Carbon::parse('2026-11-02 08:00:00', 'UTC'));

        $statement = app(Statements::class)->issue($account->fresh(), '2026-10');

        $this->assertNull($statement->snapshot['operator']['eft']);
        $this->assertNull($statement->snapshot['vat_sentence']);
        $html = $this->actingAs($admin->user)->get(route('business.statements.show', $statement))->assertOk()->getContent();

        foreach (['Account number', 'Branch code', 'no-reply', 'not configured', 'VAT'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
    }
}
