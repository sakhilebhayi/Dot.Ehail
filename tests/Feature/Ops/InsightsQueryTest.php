<?php

namespace Tests\Feature\Ops;

use App\Domain\Geo\Models\ServiceArea;
use App\Domain\Ledger\Account;
use App\Domain\Ledger\JournalEntry;
use App\Domain\Ledger\Ledger;
use App\Domain\Ops\Insights\ColumnChart;
use App\Domain\Ops\Insights\InsightsQuery;
use App\Models\AuditLog;
use App\Models\Ride;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\OpsFixtures;
use Tests\TestCase;

/**
 * Insights computed on known fixtures (tests only; no fake data ships): every figure checked against a hand count,
 * plus empty states, the CSV and the 60-second cache.
 */
class InsightsQueryTest extends TestCase
{
    use OpsFixtures;
    use RefreshDatabase;

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->from = CarbonImmutable::parse('2026-10-01 00:00:00');
        $this->to = CarbonImmutable::parse('2026-10-07 23:59:59');
    }

    private function ride(array $attrs, User $passenger): Ride
    {
        $ride = $this->makeRide($attrs, $passenger);
        $ride->forceFill(['created_at' => $attrs['created_at']])->saveQuietly();

        return $ride;
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $driver = $this->makeApprovedDriver('standard', false);
        $at = fn (string $t) => CarbonImmutable::parse($t);

        ServiceArea::factory()->create(['name' => 'Test City', 'is_active' => true, 'polygon' => [[-26.0, 28.0], [-26.0, 28.2], [-26.2, 28.2], [-26.2, 28.0]]]);
        $inside = ['pickup_lat' => -26.1, 'pickup_lng' => 28.1];

        // September: A completes a ride (their first).
        $this->ride(['status' => 'completed', 'created_at' => $at('2026-09-10 09:00'), 'completed_at' => $at('2026-09-10 09:30'), 'started_at' => $at('2026-09-10 09:10'), 'driver_id' => $driver->id], $a);
        // 1 October: three requests.
        $r1 = $this->ride($inside + ['status' => 'completed', 'created_at' => $at('2026-10-01 08:00'), 'accepted_at' => $at('2026-10-01 08:01'), 'arrived_at' => $at('2026-10-01 08:06'), 'started_at' => $at('2026-10-01 08:10'), 'completed_at' => $at('2026-10-01 08:30'), 'driver_id' => $driver->id], $a);
        $this->ride($inside + ['status' => 'cancelled_by_passenger', 'created_at' => $at('2026-10-01 10:00'), 'cancelled_at' => $at('2026-10-01 10:02')], User::factory()->create());
        $this->ride($inside + ['status' => 'expired', 'created_at' => $at('2026-10-01 12:00'), 'expired_at' => $at('2026-10-01 12:10')], User::factory()->create());
        // 3 October: two requests, one outside every area.
        $r4 = $this->ride($inside + ['status' => 'completed', 'created_at' => $at('2026-10-03 07:00'), 'accepted_at' => $at('2026-10-03 07:00'), 'arrived_at' => $at('2026-10-03 07:10'), 'started_at' => $at('2026-10-03 07:15'), 'completed_at' => $at('2026-10-03 07:55'), 'driver_id' => $driver->id], $b);
        $this->ride(['pickup_lat' => -33.9, 'pickup_lng' => 18.4, 'status' => 'cancelled_by_driver', 'created_at' => $at('2026-10-03 18:00'), 'cancelled_at' => $at('2026-10-03 18:05')], User::factory()->create());
        // 9 October: outside the window.
        $this->ride(['status' => 'completed', 'created_at' => $at('2026-10-09 07:00'), 'started_at' => $at('2026-10-09 07:05'), 'completed_at' => $at('2026-10-09 07:30')], User::factory()->create());

        foreach ([[$r1, 240], [$r4, 420]] as [$ride, $eta]) {
            DB::table('ride_offers')->insert(['ride_id' => $ride->id, 'driver_id' => $driver->id, 'status' => 'accepted', 'round' => 1, 'pickup_eta_s' => $eta, 'eta_method' => 'straight_line_estimate', 'sent_at' => $ride->accepted_at, 'expires_at' => $ride->accepted_at, 'version' => 1]);
        }

        DB::table('ops_availability_spans')->insert([
            ['driver_id' => $driver->id, 'state' => 'online', 'started_at' => '2026-10-02 08:00:00', 'ended_at' => '2026-10-02 09:00:00'],
            ['driver_id' => $driver->id, 'state' => 'on_trip', 'started_at' => '2026-10-02 09:00:00', 'ended_at' => '2026-10-02 09:20:00'],
            ['driver_id' => $driver->id, 'state' => 'paused', 'started_at' => '2026-10-02 09:20:00', 'ended_at' => '2026-10-02 10:00:00'],
            ['driver_id' => $driver->id, 'state' => 'online', 'started_at' => '2026-09-30 23:00:00', 'ended_at' => '2026-10-01 00:30:00'],
        ]);

        $this->travelTo($at('2026-10-01 08:31'));
        app(Ledger::class)->post((new JournalEntry('trip_revenue', 'trip-revenue:'.$r1->id, 'ZAR', 'Trip', ['ride_id' => $r1->id]))
            ->debit(Account::PASSENGER_RECEIVABLE, 12000)->credit(Account::PLATFORM_REVENUE_COMMISSION, 2400)->credit(Account::PLATFORM_REVENUE_FEES, 300)->credit(Account::of(Account::DRIVER_PAYABLE, $driver->id), 9300));
        $this->travelTo($at('2026-10-03 09:00'));
        app(Ledger::class)->post((new JournalEntry('refund', 'refund:1', 'ZAR', 'Refund', ['ride_id' => $r1->id]))->debit(Account::REFUNDS, 600)->credit(Account::GATEWAY_CLEARING, 600));
        $this->travelTo($at('2026-10-08 12:00'));

        DB::table('safety_incidents')->insert([
            ['reference' => 'INC-1', 'kind' => 'sos', 'severity' => 'critical', 'status' => 'resolved', 'source' => 'sos', 'created_at' => '2026-10-02 10:00:00', 'updated_at' => '2026-10-02 10:00:00'],
            ['reference' => 'INC-2', 'kind' => 'other', 'severity' => 'low', 'status' => 'open', 'source' => 'report', 'created_at' => '2026-10-02 11:00:00', 'updated_at' => '2026-10-02 11:00:00'],
        ]);

        return compact('a', 'b', 'driver');
    }

    public function test_every_metric_matches_a_hand_count(): void
    {
        $this->world();
        $d = app(InsightsQuery::class)->all($this->from, $this->to, 'day');

        $volume = collect($d['ride_volume']['rows'])->pluck('value', 'key');
        $this->assertSame(5, $d['ride_volume']['total']);
        $this->assertSame(3, $volume['2026-10-01']);
        $this->assertSame(2, $volume['2026-10-03']);
        $this->assertSame(0, $volume['2026-10-07']);
        $this->assertSame(7, count($d['ride_volume']['rows']));

        $this->assertSame(2, $d['completed_trips']['total']);

        $c = collect($d['cancellations']['rows'])->keyBy('state');
        $this->assertSame(5, $d['cancellations']['total']);
        $this->assertSame(2, $d['cancellations']['completed']);
        $this->assertSame(2000, $c['cancelled_by_passenger']['rate_bp']);
        $this->assertSame(2000, $c['cancelled_by_driver']['rate_bp']);
        $this->assertSame(2000, $c['expired']['rate_bp']);
        $this->assertSame(0, $c['no_show']['count']);

        $this->assertSame(2, $d['pickup_eta']['count']);
        $this->assertSame(330, $d['pickup_eta']['estimated_s']);
        $this->assertSame(450, $d['pickup_eta']['actual_s']);
        $this->assertSame(2, $d['pickup_eta']['late']);
        $this->assertSame('straight_line_estimate', $d['pickup_eta']['by_method'][0]['method']);

        $this->assertSame(1800, $d['trip_duration']['average_s'], '20 and 40 minutes');
        $this->assertSame(2, $d['trip_duration']['count']);

        // online 3600 + on trip 1200 + the 30 minutes after midnight on 1 October (clipped); paused excluded.
        $this->assertSame(3600 + 1200 + 1800, $d['utilisation']['online_s']);
        $this->assertSame(1200, $d['utilisation']['on_trip_s']);
        $this->assertSame(1818, $d['utilisation']['rate_bp']);

        $ret = collect($d['retention']['rows'])->keyBy('cohort');
        $this->assertSame(1, $ret['2026-09']['size']);
        $this->assertSame(10000, $ret['2026-09']['cells'][0]['rate_bp'], 'A came back in October');
        $this->assertSame(1, $ret['2026-10']['size']);
        $this->assertNull($ret['2026-10']['cells'][0], 'November has not happened');

        $this->assertSame(2400, $d['revenue']['totals']['commission']);
        $this->assertSame(300, $d['revenue']['totals']['fees']);
        $this->assertSame(9300, $d['revenue']['totals']['driver_earnings']);
        $this->assertSame(600, $d['revenue']['totals']['refunds']);
        $this->assertSame(2400, collect($d['revenue']['rows'])->firstWhere('key', '2026-10-01')['commission']);

        $this->assertSame(4, $d['coverage']['rows'][0]['requests']);
        $this->assertSame(2500, $d['coverage']['rows'][0]['rate_bp']);
        $this->assertSame(1, $d['coverage']['outside']['requests']);

        $this->assertSame(2, $d['incidents']['total']);
        $this->assertSame(1, collect($d['incidents']['rows'])->firstWhere('key', '2026-10-02')['critical']);
    }

    public function test_weeks_and_months_roll_up_the_same_rows(): void
    {
        $this->world();
        $week = app(InsightsQuery::class)->all($this->from, $this->to, 'week');
        $this->assertSame(['2026-09-28', '2026-10-05'], array_column($week['ride_volume']['rows'], 'key'));
        $this->assertSame([5, 0], array_column($week['ride_volume']['rows'], 'value'));

        $month = app(InsightsQuery::class)->all($this->from, $this->to, 'month');
        $this->assertSame([['key' => '2026-10', 'label' => 'Oct 2026', 'value' => 5]], $month['ride_volume']['rows']);
    }

    public function test_the_page_shows_definitions_and_honest_empty_states_and_exports_csv(): void
    {
        $this->world();
        $ops = $this->operatorWith(['ops_agent']);

        $this->actingAs($ops)->get(route('ops.insights', ['from' => '2026-10-01', 'to' => '2026-10-07']))->assertOk()
            ->assertSee('5 rides requested')->assertSee(InsightsQuery::DEFINITIONS['utilisation'])->assertSee('18.2%');

        $this->actingAs($ops)->get(route('ops.insights', ['from' => '2026-06-01', 'to' => '2026-06-07']))->assertOk()
            ->assertSee('No completed trips in this period.')->assertSee('No ride was requested in this period.')->assertSee('No driver reached a pickup in this period.');

        $csv = $this->actingAs($ops)->get(route('ops.insights.export', ['from' => '2026-10-01', 'to' => '2026-10-07', 'grain' => 'day']))->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('metric,period,measure,value', $body);
        $this->assertStringContainsString('ride_volume,2026-10-01,count,3', $body);
        $this->assertStringContainsString('revenue,2026-10-01,commission_minor,2400', $body);
        $this->assertTrue(AuditLog::query()->where('action', 'ops.insights_exported')->exists());

        $this->actingAs($this->operatorWith(['verification_reviewer']))->get(route('ops.insights'))->assertForbidden();
    }

    public function test_results_are_cached_for_sixty_seconds(): void
    {
        $this->world();
        $q = app(InsightsQuery::class);
        $this->assertSame(5, $q->all($this->from, $this->to, 'day')['ride_volume']['total']);

        $this->ride(['status' => 'requested', 'created_at' => CarbonImmutable::parse('2026-10-02 10:00')], User::factory()->create());
        $this->assertSame(5, $q->all($this->from, $this->to, 'day')['ride_volume']['total']);

        $this->travel(61)->seconds();
        $this->assertSame(6, $q->all($this->from, $this->to, 'day')['ride_volume']['total']);
    }

    public function test_the_column_scale_puts_the_top_tick_above_the_maximum(): void
    {
        $this->assertSame([8, 2], ColumnChart::niceScale(7));
        $this->assertSame([5, 1], ColumnChart::niceScale(4));
        $this->assertSame([125, 25], ColumnChart::niceScale(100));
        $this->assertSame([4, 1], ColumnChart::niceScale(0));
        $chart = ColumnChart::build([['label' => 'a', 'value' => 3, 'display' => '3'], ['label' => 'b', 'value' => 0, 'display' => '0']], fn ($v) => (string) $v);
        $this->assertFalse($chart['empty']);
        $this->assertNull($chart['bars'][1]['path'], 'a zero draws no column');
    }
}
