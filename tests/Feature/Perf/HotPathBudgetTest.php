<?php

namespace Tests\Feature\Perf;

use App\Domain\Catalog\Services\CategoryAvailability;
use App\Domain\Dispatch\Dispatcher;
use App\Domain\Pricing\Data\GeoPoint;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AcceptanceWorld;
use Tests\TestCase;

/**
 * Hot paths must not grow with the number of drivers or categories (docs/R46_PERF.md section 5): budgets as
 * statement counts, measured on the integrated system (AcceptanceWorld: real catalogue, tariff, geo and dispatch).
 */
class HotPathBudgetTest extends TestCase
{
    use AcceptanceWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAcceptanceWorld();
    }

    private function statements(callable $fn, ?array &$log = null): int
    {
        $log = [];
        $recording = true;
        DB::listen(function (QueryExecuted $q) use (&$log, &$recording) {
            if ($recording) {
                $log[] = $q->sql;
            }
        });
        $fn();
        $recording = false;

        if (getenv('EHAIL_PERF_DUMP')) {
            fwrite(STDERR, implode(PHP_EOL, array_map(fn ($s) => substr($s, 0, 140), $log)).PHP_EOL.'----'.PHP_EOL);
        }

        return count($log);
    }

    private function driversOnline(int $n, int $from = 0): void
    {
        for ($i = $from; $i < $from + $n; $i++) {
            $this->goOnlineAt($this->verifiedDriver("Budget Driver {$i}"), [self::DRIVER_START[0] + $i * 0.0004, self::DRIVER_START[1]]);
        }
    }

    public function test_category_availability_reads_the_nearby_supply_once_whatever_the_number_of_categories_or_drivers(): void
    {
        $this->driversOnline(3);
        $point = GeoPoint::from(self::PICKUP[0], self::PICKUP[1]);
        $few = $this->statements(fn () => app(CategoryAvailability::class)->availableAt($point));
        $offers = app(CategoryAvailability::class)->availableAt($point);

        $this->driversOnline(9, 3);
        $many = $this->statements(fn () => app(CategoryAvailability::class)->availableAt($point));

        $this->assertGreaterThan(1, count($offers), 'several categories were compared');
        $standard = collect(app(CategoryAvailability::class)->availableAt($point))->first(fn ($o) => $o->category->key === 'standard');
        $this->assertSame(12, $standard?->eligibleDrivers, 'the grouped count is still exact');
        $this->assertSame($few, $many, 'statements do not grow with drivers');
        // markets/areas/zones, active categories, rules per category, rule-set resolution per category, and the
        // nearby supply (4 statements) once: well under one statement per driver per category.
        $this->assertLessThanOrEqual(40, $many);
    }

    public function test_a_dispatch_tick_does_not_grow_with_the_number_of_candidates(): void
    {
        config(['ehail_dispatch.eta.router_max_candidates' => 5]);
        $passenger = User::factory()->withPersonalTeam()->create();
        $this->savedCard($passenger);
        $this->driversOnline(2);
        $first = $this->requestRide($passenger, $this->quoteFor($passenger)['quote_id'])->assertCreated()->json('ride.id');
        DB::table('ride_offers')->where('ride_id', $first)->delete();
        DB::table('rides')->where('id', $first)->update(['pending_offer_expires_at' => null, 'next_dispatch_at' => null]);
        $few = $this->statements(fn () => app(Dispatcher::class)->tickRide(Ride::find($first)), $fewLog);

        $this->driversOnline(10, 2);
        DB::table('ride_offers')->where('ride_id', $first)->delete();
        DB::table('rides')->where('id', $first)->update(['pending_offer_expires_at' => null, 'next_dispatch_at' => null, 'search_round' => 0]);
        $many = $this->statements(fn () => app(Dispatcher::class)->tickRide(Ride::find($first)), $manyLog);

        // Only the road ETA for the closest candidates may add statements (route cache read, miss, store), and that
        // is capped at router_max_candidates; everything else is one query for all candidates.
        $routes = fn (array $log) => count(array_filter($log, fn ($sql) => str_contains($sql, 'route_cache')));
        $this->assertEqualsWithDelta($few - $routes($fewLog), $many - $routes($manyLog), 1, "tick statements besides routes: {$few} with 2 candidates, {$many} with 12");
        $this->assertLessThanOrEqual(3 * 5, $routes($manyLog), 'road ETAs for at most 5 candidates');
        // 33 for the step itself (lease, state, offer, timeline, notifications) + up to 15 for the road ETAs.
        $this->assertLessThanOrEqual(50, $many);
    }
}
