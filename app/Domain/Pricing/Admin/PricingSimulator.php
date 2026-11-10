<?php

namespace App\Domain\Pricing\Admin;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Contracts\PricingRoutePlanner;
use App\Domain\Pricing\Contracts\PricingZoneLookup;
use App\Domain\Pricing\Data\GeoPoint;
use App\Domain\Pricing\Data\ZoneRef;
use App\Domain\Pricing\Engine\FareEngine;
use App\Domain\Pricing\Engine\FareInputs;
use App\Domain\Pricing\Engine\MarketResolver;
use App\Domain\Pricing\Engine\RuleSetResolver;
use App\Domain\Pricing\Exceptions\RouteUnavailable;
use App\Domain\Pricing\Models\PricingRuleSet;
use Carbon\CarbonInterface;

/**
 * "What would this trip cost under draft X versus what is live now?" Prices one trip under each requested rule set
 * (drafts included) and, optionally, under the rule set that resolves today. The trip is either a real road route
 * between two points or an explicit distance and duration for what-if questions; results are labelled with which.
 * Nothing is stored and nobody is charged.
 */
final class PricingSimulator
{
    public function __construct(
        private FareEngine $engine,
        private PricingRoutePlanner $routes,
        private PricingZoneLookup $zones,
        private MarketResolver $markets,
        private RuleSetResolver $resolver,
    ) {}

    /**
     * @param  list<int>  $ruleSetIds
     * @param  array{pickup?: GeoPoint, dropoff?: GeoPoint, distance_m?: int, duration_s?: int, waiting_s?: int, tolls_minor?: ?int, demand_bp?: int, pickup_fee_codes?: list<string>, dropoff_fee_codes?: list<string>}  $trip
     * @return array<string, mixed>
     */
    public function simulate(array $ruleSetIds, string $categoryKey, array $trip, bool $includeLive, CarbonInterface $at): array
    {
        $category = RideCategory::query()->where('key', $categoryKey)->firstOrFail();
        $pickupZones = array_map(fn (string $c) => new ZoneRef(0, $c, 'simulated', $c), $trip['pickup_fee_codes'] ?? []);
        $dropoffZones = array_map(fn (string $c) => new ZoneRef(0, $c, 'simulated', $c), $trip['dropoff_fee_codes'] ?? []);
        $source = 'manual';

        if (isset($trip['pickup'], $trip['dropoff'])) {
            try {
                $route = $this->routes->route($trip['pickup'], $trip['dropoff']);
            } catch (RouteUnavailable $e) {
                return ['available' => false, 'reason' => 'routing_unavailable', 'message' => $e->getMessage()];
            }

            $trip['distance_m'] = $route->distanceM;
            $trip['duration_s'] = $route->durationS;
            $pickupZones = $pickupZones ?: $this->zones->zonesFor($trip['pickup']);
            $dropoffZones = $dropoffZones ?: $this->zones->zonesFor($trip['dropoff']);
            $source = 'road_route:'.$route->provider;
        }

        $sets = PricingRuleSet::query()->whereIn('id', $ruleSetIds)->get()->keyBy('id');

        if ($includeLive) {
            $market = isset($trip['pickup']) ? ($this->markets->forPoint($trip['pickup']) ?? null) : $this->markets->forServiceArea(null);
            $live = $market === null ? null : $this->resolver->resolve($market, $category, $pickupZones, $at);

            if ($live !== null) {
                $sets->put($live->id, $live);
            }
        }

        $results = [];

        foreach ($sets as $rules) {
            $breakdown = $this->engine->compute($rules, new FareInputs(
                (int) ($trip['distance_m'] ?? 0),
                (int) ($trip['duration_s'] ?? 0),
                $pickupZones,
                $dropoffZones,
                $this->engine->effectiveDemandBp($rules, (int) ($trip['demand_bp'] ?? 10_000)),
                $trip['tolls_minor'] ?? null,
                false,
                (int) ($trip['waiting_s'] ?? 0),
            ));

            $results[] = [
                'rule_set_id' => $rules->id,
                'version' => $rules->version,
                'status' => $rules->status,
                'name' => $rules->name,
                'is_example' => $rules->is_example,
                'fare' => $breakdown->toArray(),
            ];
        }

        return [
            'available' => true,
            'simulation' => true,
            'trip' => ['source' => $source, 'distance_m' => (int) ($trip['distance_m'] ?? 0), 'duration_s' => (int) ($trip['duration_s'] ?? 0), 'category' => $category->key],
            'results' => $results,
        ];
    }
}
