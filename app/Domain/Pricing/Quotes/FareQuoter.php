<?php

namespace App\Domain\Pricing\Quotes;

use App\Domain\Catalog\Models\RideCategory;
use App\Domain\Pricing\Contracts\DemandSignal;
use App\Domain\Pricing\Contracts\EligibleSupplyCounter;
use App\Domain\Pricing\Contracts\PricingRoutePlanner;
use App\Domain\Pricing\Contracts\PricingZoneLookup;
use App\Domain\Pricing\Contracts\TollEstimator;
use App\Domain\Pricing\Data\Market;
use App\Domain\Pricing\Data\PricedRoute;
use App\Domain\Pricing\Data\ZoneRef;
use App\Domain\Pricing\Engine\DiscountTerms;
use App\Domain\Pricing\Engine\FareEngine;
use App\Domain\Pricing\Engine\FareInputs;
use App\Domain\Pricing\Engine\MarketResolver;
use App\Domain\Pricing\Engine\RuleSetResolver;
use App\Domain\Pricing\Exceptions\RouteUnavailable;
use App\Domain\Pricing\Models\FareQuote;
use App\Domain\Pricing\Models\PricingRuleSet;
use App\Domain\Pricing\Money\Currency;
use App\Domain\Pricing\Promotions\PromotionEvaluator;
use App\Models\AuditLog;
use Carbon\CarbonInterface;

/**
 * Server-side pricing (architecture section 3). quote() prices one category and, for a signed-in passenger, stores a
 * signed FareQuote that expires; estimate() prices without storing anything (public calculator); compare() prices
 * every category offered here now. Every failure is a QuoteUnavailable with a reason the UI shows as it is.
 * A fare is NEVER computed from straight-line distance: no road route, no price.
 */
final class FareQuoter
{
    public function __construct(
        private PricingRoutePlanner $routes,
        private PricingZoneLookup $zones,
        private MarketResolver $markets,
        private RuleSetResolver $ruleSets,
        private FareEngine $engine,
        private PromotionEvaluator $promotions,
        private DemandSignal $demand,
        private TollEstimator $tolls,
        private EligibleSupplyCounter $supply,
        private QuoteSigner $signer,
    ) {}

    /**
     * A bookable quote for one category (persisted and signed when the request has a passenger).
     */
    public function quote(QuoteRequest $request): PricedOption|QuoteUnavailable
    {
        if ($request->categoryKey === null) {
            return new QuoteUnavailable('category_unknown', 'Choose a ride category to get a price.');
        }

        $context = $this->context($request);

        if ($context instanceof QuoteUnavailable) {
            return $context;
        }

        $category = RideCategory::query()->where('key', $request->categoryKey)->first();

        if ($category === null) {
            return new QuoteUnavailable('category_unknown', 'That ride category does not exist.', $request->categoryKey);
        }

        $option = $this->priceCategory($request, $category, ...$context);

        if ($option instanceof PricedOption && $request->passenger !== null) {
            $option->quote = $this->persist($request, $option);
        }

        return $option;
    }

    /**
     * Price without storing anything (public fare calculator; anonymous allowed). Never bookable.
     */
    public function estimate(QuoteRequest $request): PricedOption|QuoteUnavailable
    {
        return $this->quote(new QuoteRequest($request->pickup, $request->dropoff, $request->categoryKey, null, $request->at, null, 'estimate'));
    }

    /**
     * Every category offered at the pickup right now, priced for the same route.
     */
    public function compare(QuoteRequest $request): QuoteComparison|QuoteUnavailable
    {
        $context = $this->context($request);

        if ($context instanceof QuoteUnavailable) {
            return $context;
        }

        [$market, $route, $pickupZones, $dropoffZones, $at] = $context;
        $options = [];
        $unavailable = [];

        foreach (RideCategory::query()->activeRides()->get() as $category) {
            $option = $this->priceCategory($request, $category, $market, $route, $pickupZones, $dropoffZones, $at);

            if ($option instanceof QuoteUnavailable) {
                if ($option->reason !== 'category_not_offered') {
                    $unavailable[] = $option;
                }

                continue;
            }

            if ($request->passenger !== null && $request->purpose !== 'estimate') {
                $option->quote = $this->persist($request, $option);
            }

            $options[] = $option;
        }

        return new QuoteComparison($route, $options, $unavailable);
    }

    /**
     * @return array{0: Market, 1: PricedRoute, 2: list<ZoneRef>, 3: list<ZoneRef>, 4: CarbonInterface}|QuoteUnavailable
     */
    private function context(QuoteRequest $request): array|QuoteUnavailable
    {
        $at = $request->at ?? now();
        $market = $this->markets->forPoint($request->pickup);

        if ($market === null) {
            return new QuoteUnavailable('outside_service_area', 'Dot.Ehail does not operate at this pickup point yet.');
        }

        if (! Currency::isSupported($market->currency)) {
            return new QuoteUnavailable('currency_unsupported', 'Pricing is not set up for this market\'s currency.');
        }

        $pickupZones = $this->zones->zonesFor($request->pickup);
        $dropoffZones = $this->zones->zonesFor($request->dropoff);

        foreach ([...$pickupZones, ...$dropoffZones] as $zone) {
            if ($zone->kind === 'restricted') {
                return new QuoteUnavailable('restricted_zone', 'Rides cannot start or end in this area.', null, $zone->slug);
            }
        }

        try {
            $route = $this->routes->route($request->pickup, $request->dropoff);
        } catch (RouteUnavailable $e) {
            return QuoteUnavailable::routing($e->reason, $e->getMessage());
        }

        return [$market, $route, $pickupZones, $dropoffZones, $at];
    }

    /**
     * @param  list<ZoneRef>  $pickupZones
     * @param  list<ZoneRef>  $dropoffZones
     */
    private function priceCategory(QuoteRequest $request, RideCategory $category, Market $market, PricedRoute $route, array $pickupZones, array $dropoffZones, CarbonInterface $at): PricedOption|QuoteUnavailable
    {
        if (! $category->is_active || $category->service_kind !== 'ride' || ! $category->isOfferedIn($market->serviceAreaId(), $at, $market->timezone)) {
            return new QuoteUnavailable('category_not_offered', $category->name.' is not offered here at this time.', $category->key);
        }

        $rules = $this->ruleSets->resolve($market, $category, $pickupZones, $at);

        if ($rules === null) {
            return new QuoteUnavailable('no_tariff', 'No published tariff covers '.$category->name.' here yet.', $category->key);
        }

        // A public estimate answers "what would this trip cost?": it does not depend on who is driving nearby this
        // second, and it never tells an anonymous visitor where drivers are. Supply is checked when a quote is booked.
        if ($request->purpose === 'estimate') {
            return $this->priceWith($rules, $request, $category, $market, $route, $pickupZones, $dropoffZones, $at, 'not_checked');
        }

        $count = $this->supply->count($category, $request->pickup, $market->serviceAreaId(), $at);

        if ($count === 0) {
            return new QuoteUnavailable('no_supply', 'No '.$category->name.' drivers are available nearby right now.', $category->key);
        }

        return $this->priceWith($rules, $request, $category, $market, $route, $pickupZones, $dropoffZones, $at, $count === null ? 'unknown' : 'available');
    }

    /**
     * Price a route under a specific rule set (also used by the admin simulator for drafts).
     *
     * @param  list<ZoneRef>  $pickupZones
     * @param  list<ZoneRef>  $dropoffZones
     */
    public function priceWith(PricingRuleSet $rules, QuoteRequest $request, RideCategory $category, Market $market, PricedRoute $route, array $pickupZones, array $dropoffZones, CarbonInterface $at, string $supply = 'unknown'): PricedOption
    {
        $demandBp = $this->engine->effectiveDemandBp($rules, $this->demand->multiplierBp($market->serviceAreaId(), $category, $at) ?? 10_000);
        $tolls = $rules->toll_mode === 'pass_through' ? $this->tolls->estimateMinor($route, $rules->currency) : null;
        $inputs = fn (?DiscountTerms $discount) => new FareInputs($route->distanceM, $route->durationS, $pickupZones, $dropoffZones, $demandBp, $tolls, false, 0, $discount);

        $undiscounted = $this->engine->compute($rules, $inputs(null));
        $prePromotion = $undiscounted->without(['tax', 'rounding'])->totalMinor();

        $decision = $this->promotions->choose(
            $request->promoCode,
            $request->passenger,
            $category,
            $market->serviceAreaId(),
            $rules->currency,
            $prePromotion,
            $at,
            $market->timezone,
            fn (DiscountTerms $terms) => -$this->engine->compute($rules, $inputs($terms))->amountFor('promotion'),
        );

        $breakdown = $decision->terms === null ? $undiscounted : $this->engine->compute($rules, $inputs($decision->terms));
        $promotion = $breakdown->amountFor('promotion') < 0 ? $decision->promotion : null;

        return new PricedOption($category, $rules, $market, $route, $breakdown, $pickupZones, $dropoffZones, $promotion, $decision->note, $supply, $at);
    }

    private function persist(QuoteRequest $request, PricedOption $option): FareQuote
    {
        $coordinate = fn (float $v) => number_format($v, 7, '.', '');

        $quote = new FareQuote([
            'passenger_id' => $request->passenger?->id,
            'purpose' => $request->purpose === 'legacy_request' ? 'legacy_request' : 'booking',
            'pickup_lat' => $coordinate($request->pickup->lat),
            'pickup_lng' => $coordinate($request->pickup->lng),
            'dropoff_lat' => $coordinate($request->dropoff->lat),
            'dropoff_lng' => $coordinate($request->dropoff->lng),
            'service_area_id' => $option->market->serviceAreaId(),
            'ride_category_id' => $option->category->id,
            'rule_set_id' => $option->ruleSet->id,
            'rule_version' => $option->ruleSet->version,
            'route_distance_m' => $option->route->distanceM,
            'route_duration_s' => $option->route->durationS,
            'route_provider' => substr($option->route->provider, 0, 32),
            'polyline_hash' => $option->route->polylineHash(),
            'zone_codes' => [
                'pickup' => array_map(fn (ZoneRef $z) => $z->feeCode ?? $z->slug, $option->pickupZones),
                'dropoff' => array_map(fn (ZoneRef $z) => $z->feeCode ?? $z->slug, $option->dropoffZones),
            ],
            'components' => $option->breakdown->componentsArray(),
            'demand_multiplier_bp' => $option->breakdown->demandMultiplierBp,
            'total_minor' => $option->breakdown->totalMinor(),
            'currency' => $option->ruleSet->currency,
            'promotion_id' => $option->promotion?->id,
            'promotion_discount_minor' => -$option->breakdown->amountFor('promotion'),
            'is_example' => $option->ruleSet->is_example,
            'expires_at' => now()->addSeconds(max(30, (int) config('ehail_pricing.quotes.ttl_seconds')))->startOfSecond(),
        ]);
        $quote->id = (string) $quote->newUniqueId();
        $quote->signature = $this->signer->sign($quote);
        $quote->save();

        // audit_logs.subject_id is an integer, so the subject is the rule set and the quote's ULID goes in meta.
        AuditLog::record('pricing.quote_issued', $request->passenger, $option->ruleSet, [
            'quote_id' => $quote->id,
            'category' => $option->category->key,
            'rule_set_id' => $option->ruleSet->id,
            'rule_version' => $option->ruleSet->version,
            'total_minor' => $quote->total_minor,
            'currency' => $quote->currency,
            'distance_m' => $quote->route_distance_m,
            'promotion_id' => $quote->promotion_id,
        ]);

        return $quote;
    }
}
