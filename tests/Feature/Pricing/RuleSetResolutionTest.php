<?php

namespace Tests\Feature\Pricing;

use App\Domain\Pricing\Data\Market;
use App\Domain\Pricing\Data\ServiceAreaRef;
use App\Domain\Pricing\Data\ZoneRef;
use App\Domain\Pricing\Engine\RuleSetResolver;
use App\Domain\Pricing\Exceptions\ImmutablePricingRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PricingFixtures;
use Tests\TestCase;

class RuleSetResolutionTest extends TestCase
{
    use PricingFixtures, RefreshDatabase;

    private function market(?int $areaId = null): Market
    {
        return new Market('ZA', 'ZAR', 'Africa/Johannesburg', $areaId === null ? null : new ServiceAreaRef($areaId, 'cpt', 'ZA', 'ZAR', 'Africa/Johannesburg'));
    }

    private function resolve(?int $areaId = null, array $zones = [], string $category = 'economy', ?CarbonImmutable $at = null): ?int
    {
        return (new RuleSetResolver)->resolve($this->market($areaId), $this->category($category), $zones, $at ?? CarbonImmutable::now())?->id;
    }

    public function test_most_specific_rule_set_wins(): void
    {
        $country = $this->publishedRuleSet(['name' => 'country']);
        $this->assertSame($country->id, $this->resolve(7));

        $category = $this->publishedRuleSet(['name' => 'category', 'ride_category_id' => $this->category('economy')->id]);
        $this->assertSame($category->id, $this->resolve(7));

        $area = $this->publishedRuleSet(['name' => 'area', 'service_area_id' => 7]);
        $this->assertSame($area->id, $this->resolve(7), 'area (4) beats category (2)');

        $areaCategory = $this->publishedRuleSet(['name' => 'area+cat', 'service_area_id' => 7, 'ride_category_id' => $this->category('economy')->id]);
        $this->assertSame($areaCategory->id, $this->resolve(7));

        $zone = $this->publishedRuleSet(['name' => 'zone', 'zone_slug' => 'cpt-airport']);
        $airport = new ZoneRef(1, 'cpt-airport', 'airport', 'airport');
        $this->assertSame($zone->id, $this->resolve(7, [$airport]), 'zone (8) beats area+category (6)');
        $this->assertSame($areaCategory->id, $this->resolve(7), 'zone rule set needs the pickup zone');

        // Another area's or category's rule sets never apply.
        $this->assertSame($country->id, $this->resolve(8, [], 'standard'));
    }

    public function test_time_window_rule_set_applies_only_inside_its_window_and_beats_an_unwindowed_peer(): void
    {
        // Pin the clock: the rule sets are effective from now() - 1 day and the instants below are fixed on 2026-11-10/11,
        // so an unpinned clock puts them before effective_from on any real date after 2026-11-11.
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', 'Africa/Johannesburg'));

        $always = $this->publishedRuleSet(['name' => 'always']);
        $night = $this->publishedRuleSet(['name' => 'night', 'time_windows' => [['start' => '22:00', 'end' => '05:00']]]);

        $this->assertSame($night->id, $this->resolve(null, [], 'economy', CarbonImmutable::parse('2026-11-10 23:00', 'Africa/Johannesburg')));
        $this->assertSame($night->id, $this->resolve(null, [], 'economy', CarbonImmutable::parse('2026-11-11 04:59', 'Africa/Johannesburg')));
        $this->assertSame($always->id, $this->resolve(null, [], 'economy', CarbonImmutable::parse('2026-11-11 05:00', 'Africa/Johannesburg')));
    }

    public function test_ties_are_broken_by_effective_from_then_version_then_id(): void
    {
        $older = $this->publishedRuleSet(['effective_from' => now()->subDays(3)]);
        $newer = $this->publishedRuleSet(['effective_from' => now()->subDays(2)]);
        $this->assertSame($newer->id, $this->resolve());

        $sameTimeLowVersion = $this->publishedRuleSet(['effective_from' => now()->subDay(), 'version' => 1]);
        $sameTimeHighVersion = $this->publishedRuleSet(['effective_from' => $sameTimeLowVersion->effective_from, 'version' => 2]);
        $this->assertSame($sameTimeHighVersion->id, $this->resolve());

        $twin = $this->publishedRuleSet(['effective_from' => $sameTimeLowVersion->effective_from, 'version' => 2]);
        $this->assertSame($twin->id, $this->resolve(), 'highest id breaks a full tie');
        $this->assertNotNull($older->id);
    }

    public function test_drafts_future_retired_and_other_markets_are_ignored(): void
    {
        $this->publishedRuleSet(['status' => 'draft']);
        $this->publishedRuleSet(['effective_from' => now()->addHour()]);
        $this->publishedRuleSet(['status' => 'retired', 'retired_at' => now()->subMinute()]);
        $this->publishedRuleSet(['currency' => 'USD']);
        $this->publishedRuleSet(['country' => 'BW', 'currency' => 'BWP']);

        $this->assertNull($this->resolve());
    }

    public function test_a_retired_version_still_resolves_for_moments_before_its_retirement(): void
    {
        $old = $this->publishedRuleSet(['effective_from' => now()->subDays(10), 'status' => 'retired', 'retired_at' => now()->subDays(1)]);

        $this->assertSame($old->id, $this->resolve(null, [], 'economy', CarbonImmutable::now()->subDays(2)));
        $this->assertNull($this->resolve());
    }

    public function test_published_rule_sets_are_immutable_but_can_be_retired(): void
    {
        $rules = $this->publishedRuleSet();

        try {
            $rules->update(['per_km_minor' => 1]);
            $this->fail('A published rule set must not change.');
        } catch (ImmutablePricingRecord) {
            $this->assertSame(800, $rules->fresh()->per_km_minor);
        }

        $rules->refresh();
        $rules->update(['status' => 'retired', 'retired_at' => now()]);
        $this->assertSame('retired', $rules->fresh()->status);

        $this->expectException(ImmutablePricingRecord::class);
        $rules->fresh()->update(['status' => 'published']);
    }

    public function test_published_rule_sets_cannot_be_deleted_but_drafts_can(): void
    {
        $draft = $this->publishedRuleSet(['status' => 'draft']);
        $draft->delete();
        $this->assertModelMissing($draft);

        $this->expectException(ImmutablePricingRecord::class);
        $this->publishedRuleSet()->delete();
    }
}
