# R-46 pricing and ride categories

Owner: pricing agent (wave 1). Code: `App\Domain\Pricing\*`, `App\Domain\Catalog\*`, `routes/ehail/pricing.php`, `routes/ehail/pricing-public.php`, `App\Providers\EhailPricingServiceProvider`, `config/ehail_pricing.php`. Decisions D-300 to D-318 in `docs/R46_DECISIONS.md`. Research: `docs/R46_RESEARCH.md`, section "Pricing".

## 1. Principles

- **The server prices; clients never do.** No endpoint reads an amount from a request. A booking names a quote id; the price comes from the signed quote row.
- **Road routes only.** A fare is computed only from a road route returned by the geo routing seam. No routing provider, no price: the result is a typed `QuoteUnavailable` (`routing_unavailable`) that the UI shows as it is. There is no straight-line implementation anywhere (D-4).
- **Integers only.** Every amount is an integer in minor units (cents) with an ISO currency (`Money`). Rates are integers too: per-km is "per 1 000 m", percentages and multipliers are basis points (1 500 bp = 15 %, 12 500 bp = 1.25x). `IntegerMath` refuses overflow instead of letting PHP turn an int into a float.
- **Every rand is on a line.** A fare is a list of components; the total is defined as their sum. Minimum fare, rounding, demand, promotions, caps and support adjustments are all visible lines.
- **Nothing changes silently.** A final fare that differs from the quote carries adjustments (reason code + plain text) that sum exactly to the difference.
- **Versioned and immutable.** A published tariff never changes. Editing drafts version n + 1; quotes and ride fares point at (rule set id, version) so history re-prices exactly.

## 2. Ride categories (`App\Domain\Catalog`)

`ride_categories` (key, name, description, service kind, capacity, luggage, driver rating floor in hundredths, required document types, fleet-only, availability json, policies json, legacy vehicle type, active flag, display order, icon key) and `category_vehicle_rules` (vehicle classes, min year, max age, min doors, min seats, required features). Installed by migration `2026_11_10_500100` (reference data, idempotent, never overwrites operator edits):

| key | name | capacity | vehicles | notes |
|---|---|---|---|---|
| economy | Economy | 4 | economy, standard, max 12 years | continues `vehicle_type = economy` |
| standard | Comfort | 4 | standard, premium, max 8 years | rating floor 4.50; continues `standard` |
| premium | Premium | 4 | premium, max 5 years | rating floor 4.70 |
| xl | XL | 6 | suv, 6+ seats, max 10 years | continues `suv` |
| accessible | Accessible | 4 | any class with `wheelchair_access` | vehicles do not record features yet, so no vehicle qualifies until fleet/verification adds that field (fails closed) |
| business | Business | 4 | premium | **inactive** until business accounts exist (wave 2) |
| airport | Airport transfer | 4 (+4 bags) | standard, premium, suv | |
| long_distance | Long distance | 4 | standard, premium, suv, max 6 years | |
| parcel | Parcel | 0 | economy, standard | **inactive**, `service_kind = parcel`; cannot be activated through the API until that service exists |

- `CategoryEligibility::vehicleFailures/vehicleQualifies/driverFailures/servableCategoryKeys` gives reasons, not just yes/no. A rating floor is not applied to a driver with no ratings yet (D-317). Document verification is NOT decided here; the verification domain gates going online.
- `CategoryAvailability::availableAt(GeoPoint|serviceAreaId, at)` returns `CategoryOffer`s: active ride categories offered in that area and time window, with a published tariff in force, and not counted at zero supply. Supply comes from `EligibleSupplyCounter` (dispatch/geo bind it). Until then the stub returns null and every offer says `supply: "unknown"`; nothing claims drivers are available.

## 3. Rule sets (`pricing_rule_sets`)

Columns: lineage key + version, name, notes, `is_example`, status (draft | published | retired), country, currency, service area id, pickup zone slug, category, time windows + timezone, and every term of the formula: `base_minor`, `per_km_minor` (per 1 000 m), `per_minute_minor`, `minimum_minor`, `booking_fee_minor`, `wait_free_minutes`, `wait_per_minute_minor`, `cancel_free_minutes`, `cancel_fee_minor`, `no_show_fee_minor`, `zone_fees` (`{fee_code: {pickup_minor, dropoff_minor, label}}`, keyed by geo `zones.fee_code`), `toll_mode` (none | pass_through), `demand_enabled`, `demand_cap_bp`, `tax_mode` (none | inclusive | exclusive), `tax_rate_bp`, `tax_label`, `rounding_unit_minor` (1, 5, 10, 50, 100, 500, 1000), `rounding_mode` (half_up | half_even | up | down), finalisation tolerances (`distance_tolerance_bp`, `duration_tolerance_bp`, `overrun_cap_bp`), `effective_from`, `retired_at`, publisher and creator.

**Time windows**: `[{days: [1..7 ISO], start: "HH:MM", end: "HH:MM"}]` in the rule's timezone. A window whose end is not after its start runs past midnight and belongs to the day it starts (Friday 22:00-04:00 covers Saturday 03:00). Start inclusive, end exclusive; `start == end` = whole day; null = always. Africa/Johannesburg has no daylight saving (tested across the year).

**Lifecycle**: draft (editable) -> published (frozen; the model refuses any change except retirement and refuses deletion) -> retired. Publishing version n + 1 retires the lineage's live version at the new version's `effective_from`, so there is never a gap or overlap. A version is "in force at t" when it is published or retired, `effective_from <= t` and (`retired_at` is null or `> t`).

**Resolution** (`RuleSetResolver`): candidates in force at the moment, same country and currency as the market, fitting the trip (area, pickup zone, category, time window; null fits everything). Most specific wins: **pickup zone 8 + service area 4 + category 2 + time window 1**. Ties: latest `effective_from`, then highest version, then highest id (fully deterministic; tested).

**Market**: with the geo zone seam wired, the pickup's service area gives country, currency and timezone, and a pickup outside every area cannot be quoted. Without it (geo not merged), the configured default market applies (ZA, ZAR, Africa/Johannesburg; D-306). A pickup or drop-off in a `restricted` zone cannot be quoted.

## 4. Formula (`FareEngine`, pure and deterministic)

```
trip      = base + per_km x km + per_minute x minutes + waiting (each started minute after the free minutes)
subtotal  = trip + booking_fee + zone fees (pickup and drop-off, once per fee code) + tolls (pass_through only)
minimum   = max(0, minimum - subtotal)                          -> "Minimum fare" line
demand    = (subtotal + minimum - tolls) x (multiplier - 1)      -> only when enabled; capped; shown; never on tolls
discount  = promotion (percent with cap, or fixed), never more than the amount above
tax       = exclusive: rate x discounted amount (added line); inclusive: "Includes VAT of R x" note, not added
rounding  = difference to the rounding unit                      -> "Rounding" line
total     = sum of all lines (>= 0)
```

Deviation from the architecture section 4 sketch (D-302): the demand multiplier never applies to tolls, which are a pass-through cost. Property tests (400 random tariffs and trips) check: total = sum of components, never negative, deterministic, total respects the rounding unit, no negative line except promotion/rounding, minimum holds.

## 5. Quotes

`FareQuoter`:
- `quote(QuoteRequest): PricedOption|QuoteUnavailable` prices one category. For a signed-in passenger it stores a `fare_quotes` row (ULID id, passenger, pickup/drop-off, service area, category, rule set id + version, road distance/duration, provider, polyline hash, zone codes, components, demand multiplier, total, currency, promotion and discount, `is_example`, `expires_at` = now + 5 min, HMAC signature) and writes an audit row (`pricing.quote_issued`, subject = the rule set, quote id in meta, no coordinates).
- `estimate(QuoteRequest)` prices without storing anything and without promotions (public calculator).
- `compare(QuoteRequest)` prices every active category for one route: `QuoteComparison {route, options[], unavailable[]}` with reasons `no_tariff`, `no_supply`, ...; categories not offered there and then are simply absent.
- Unavailable reasons: `outside_service_area`, `restricted_zone`, `routing_unavailable` (detail: not_configured | timeout | provider_error | circuit_open | no_route), `category_unknown`, `category_not_offered`, `no_tariff`, `no_supply`, `currency_unsupported`.

`QuoteSigner`: HMAC-SHA256 (key derived from APP_KEY) over every promised field. `QuoteBinder::bind(quoteId, passenger, pickup, dropoff, ride, ?category)` (dispatch calls it **inside the ride-creation transaction**) refuses with `QuoteRejected` reason: `not_found` (missing or another passenger's: indistinguishable), `tampered` (any stored field changed; logged), `already_used`, `expired`, `route_mismatch` (pickup or drop-off more than 75 m from the quoted points), `category_mismatch`, `promotion_unavailable`. On success it redeems the promotion, consumes the quote and writes `ride_fares` (the estimated snapshot), audited `pricing.quote_bound`.

Unused quotes are deleted 24 h after expiry by `ehail:pricing:prune-quotes` (hourly, scheduled from the provider; POPIA minimisation). Consumed quotes stay with their ride.

## 6. Promotions

`promotions` (code or automatic; percent with optional cap or fixed; currency; eligibility: first ride only, category keys, service area ids, time windows, minimum fare; start/end; budget and spent; total redemption limit and count; per-user limit; active) and `promotion_redemptions`.
- Evaluated at quote time (`PromotionEvaluator`): a typed code is checked alone and the passenger gets a plain reason when it does not apply; without a code the best eligible automatic promotion applies (largest discount, lowest id on ties). Anonymous estimates get none. A promotion whose remaining budget is smaller than its discount gives only the remainder.
- Redeemed only at ride creation (`PromotionRedeemer`, called by `QuoteBinder`): promotion row locked (`FOR UPDATE`), per-user count re-checked, then ONE conditional UPDATE moves the counters only if `redemptions_count < max` and `spent + discount <= budget`. Either guard alone stops a race; tests simulate the losing side of each. A refused redemption rolls back the booking and asks the passenger to re-quote. A promotion can never make a fare negative.
- Cancellation gives the promotion back (`CancellationFeePolicy::settle` reverses the redemption).

## 7. Finalisation (`FareFinaliser::finalise(Ride, TripActuals): FinalFare`)

`TripActuals`: billable road metres, method, quality and flags from the geo `DistanceMeter` (`TripActuals::fromTripDistance`), duration, waiting seconds at pickup, tolls actually paid, and whether the passenger changed the destination, added a stop or ended early (plus the new drop-off's fee codes).

Rules, all using the SAME rule-set version, demand multiplier, zones and promotion amount as the quote:
1. **Quoted fare stands** while billable distance stays within +-`distance_tolerance_bp` (default 15 %) and duration within +`duration_tolerance_bp` (default 25 %), and the passenger changed nothing.
2. **Recalculated** when the passenger changed the trip (reason `destination_changed`, `stops_added`, `ended_early`; no cap: they asked), or the trip ran longer than tolerated (`route_longer`), or was shorter than tolerated (`route_shorter`: the passenger pays less).
3. **Overrun cap**: without a passenger change, a recalculated fare rises at most `overrun_cap_bp` (default 20 %) over the quote; the excess is a visible negative "Increase capped" line.
4. **Waiting** beyond the free minutes (`waiting_time`) and **tolls** actually paid on pass-through tariffs (`tolls`, replacing any estimate) apply in every case.
5. **Untrustworthy distance** (geo quality `insufficient`, flag `unresolved_gap`, or method `no_data`) never moves the fare: the quote stands, waiting and tolls still apply, and the note says why.

The result writes `ride_fares.final_*`, `basis`, `adjustments`, actuals and `finalised_at`, mirrors the total into `rides.final_fare` (decimal, read by payments and history), snapshots the driver's earnings when a driver is assigned, and audits `pricing.fare_finalised`. Idempotent. A ride without a snapshot (created before R-46) throws `FareNotFinalisable('no_snapshot')`: its recorded fare is kept as it is.

**Operator adjustments** (`FareAdjuster::adjust(ride, operator, newTotalMinor, reasonCode, text)`, API `POST /ops/pricing/rides/{ride}/fare-adjustments`): only on a finalised fare, reason codes `refund_route_issue | refund_service_issue | goodwill | pricing_error | toll_correction | waiting_correction | other`, text the passenger reads; adds an "Adjusted by support" line, appends to the adjustment history, mirrors `rides.final_fare`, re-snapshots earnings, audited.

## 8. Cancellation fees (`CancellationFeePolicy`)

`feeFor(ride, actor, at, ?arrivedAt, ?assignedAt, driverRunningLate): ?Money` and `decide(...)`: `CancellationDecision {fee, code, text}`, from the ride's own rule-set version:
- only a passenger cancellation can cost anything (`no_fee_not_passenger`);
- free before a driver is assigned (`before_driver_assigned`) and for `cancel_free_minutes` after (`within_free_window`);
- free when dispatch reports the driver running late or not heading to the pickup (`driver_late`);
- after the driver arrived and waited the free waiting minutes: `no_show_fee_minor` (`no_show_fee`);
- otherwise `cancel_fee_minor` (`cancellation_fee`).
Assignment/arrival times are read from `driver_assigned_at`/`accepted_at` and `driver_arrived_at`/`arrived_at` unless passed. `settle(ride, decision)` records the fee as the final fare (basis `cancellation_fee`) and returns the promotion. Charging is the money domain's job.

## 9. Commission and driver earnings

`commission_plans` (versioned like rule sets): country, currency, optional service area, category and **fleet** (a fleet plan overrides the market plan), `percent_bp`, `fixed_minor`, min and max commission, `commissionable_codes`. Resolution: fleet 8 + area 4 + category 2, same tie-breaks.

`EarningsSplitter::split(FinalFare, DriverArrangement): Split`, `estimateForQuote`, `estimateForRide` (offer card), `snapshot(ride)`:
- commissionable = base, distance, time, waiting, minimum, demand, cap, rounding and support adjustments (default; configurable per plan). **Not** commissioned: tolls (reimbursed to the driver at cost), booking fee, zone fees, tax. Promotions are platform-funded: the driver earns on the pre-discount fare.
- commission = clamp(commissionable x percent + fixed, min, max), never above the commissionable amount.
- driver gross = commissionable + tolls; driver net = gross - deductions; platform = passenger total - driver net. Property-tested: net + platform = fare; tolls always reach the driver.
- No plan configured: commission and net are **null** with the note "No commission plan is configured for this market yet" (never a guessed zero).

## 10. What each person sees

- **Public calculator** (`POST /fare-estimate`, anonymous, 20/min per IP): itemised estimate per category, labelled "Estimate only. The price you book is confirmed when you request the ride."; `is_example` when the tariff is an example; never stored.
- **Passenger** (`POST /pricing/quotes`, 30/min): a bookable quote per category with expiry, itemised components, notes (tolls, included VAT), the tariff version and whether it is an example; after the trip (`GET /pricing/rides/{ride}/fare`): quoted vs final, every change with its reason.
- **Driver** (same ride endpoint): the fare plus the split: estimate before completion (from the quote), actual after; tolls reimbursed shown separately; the commission plan named.
- **Operator**: everything, plus the admin API.

## 11. Admin API (operator middleware; ops UI in wave 2)

| Method | Path | Purpose |
|---|---|---|
| GET/POST | `/ops/pricing/rule-sets` | list (filters: status, category) / create draft (defaults from config) |
| GET/PATCH | `/ops/pricing/rule-sets/{id}` | show with all versions / edit a draft (409 when not a draft) |
| POST | `/ops/pricing/rule-sets/{id}/versions` | draft version n + 1 from any version, with overrides |
| POST | `/ops/pricing/rule-sets/{id}/publish` | publish (optional future `effective_from`), supersedes the live version |
| POST | `/ops/pricing/rule-sets/{id}/retire` | retire now or at a time |
| POST | `/ops/pricing/rule-sets/simulate` | price a trip (road route between points, or an explicit distance/duration) under draft(s) and the live tariff; nothing stored |
| GET/POST | `/ops/pricing/commission-plans`, `/{id}/versions`, `/{id}/publish`, `/{id}/retire` | commission plans |
| GET/POST | `/ops/pricing/promotions`, `/{id}/deactivate` | promotions |
| GET/PATCH | `/ops/pricing/categories`, `/{id}` | catalogue (parcel cannot be activated) |
| POST | `/ops/pricing/rides/{ride}/fare-adjustments` | audited fare adjustment |

Validation ceilings stop typos reaching passengers (amounts <= R100 000, demand cap <= 3.0x, tax rate <= 50 %, commission <= 50 %); a tax mode other than none needs a rate and a label. Every draft, edit, version, publish and retire is audited with the full terms.

## 12. Example tariff (development and acceptance only)

`php artisan ehail:pricing:install-example-tariff` (and `DatabaseSeeder` in non-production) publishes one country-wide ZA rule set per priced category and a 20 % commission plan, all `is_example = true`, named **"Example tariff: review before launch"**. The numbers are placeholders, not researched or approved prices. VAT is off, there is no airport fee (none is documented for e-hailing; research section), rounding is to whole rand, tolls pass through. The command refuses in production without `--force`. **Migrations install no prices**: a fresh production has no tariff and every quote says `no_tariff` until the owner publishes one.

| cents | base | per km | per min | minimum | booking | wait/min | cancel | no-show |
|---|---|---|---|---|---|---|---|---|
| economy | 1000 | 750 | 70 | 3000 | 300 | 150 | 2500 | 3000 |
| standard | 1500 | 950 | 90 | 4000 | 300 | 150 | 2500 | 3500 |
| premium | 2500 | 1400 | 150 | 7000 | 500 | 250 | 4000 | 5000 |
| xl | 2000 | 1200 | 120 | 5500 | 400 | 200 | 3000 | 4000 |
| accessible | same as economy (no surcharge for needing an accessible vehicle) | | | | | | | |
| business | same as premium | | | | | | | |
| airport | 2000 | 950 | 80 | 15000 | 300 | 150 | 3000 | 5000 |
| long_distance | 3000 | 850 | 30 | 30000 | 500 | 150 | 5000 | 8000 |

## 13. Legacy fares

- `App\Services\Ehail\FareCalculator` keeps only `format()`/`formatCents()` (now on the integer formatter). Its straight-line `estimate()`/`distanceKm()` were removed (D-313).
- The old request form (`RideRequestController::store`) prices through `LegacyRideFareAdapter`: old `vehicle_type` -> category (suv -> xl), a signed quote for the passenger, `estimated_fare` and road `distance_km` written as before, and the quote bound to the ride in the same transaction (so `FareFinaliser` works for it). No coordinates, no route or no tariff = no estimate, as the form already says.
- Existing rides are never re-priced. `GET /pricing/rides/{ride}/fare` shows their recorded decimals marked `legacy`.

## 14. Seams and contracts for other owners

| Seam | Bound to | Owner action |
|---|---|---|
| `PricingRoutePlanner` | `GeoRoutePlannerBridge` when `App\Domain\Geo\Routing\RoutePlanner` and `App\Domain\Geo\LatLng` exist, else `UnconfiguredRoutePlanner` | none; bridge written against geo's current branch (route() -> RouteResult distanceM/durationS/polyline/provider/kind, RoutingUnavailable->reason) |
| `PricingZoneLookup` | `GeoZoneLookupBridge` when `App\Domain\Geo\Zones\ZoneResolver` + `ServiceArea` exist, else `NoZoneLookup` (default market) | geo: keep `ServiceArea` (id, slug, country_code, currency, timezone) and `Zone` (id, slug, kind, fee_code) |
| `EligibleSupplyCounter` | `UnknownSupplyCounter` (bindIf) | dispatch: bind a counter of online, eligible (use `CategoryEligibility`) drivers in range |
| `DemandSignal` | `NoDemandSignal` (bindIf) | none until the owner turns demand pricing on and a signal is built |
| `TollEstimator` | `NoTollEstimator` (bindIf) | optional |
| `QuoteBinder::bind` | | dispatch: call inside ride creation; roll back on `QuoteRejected` |
| `FareFinaliser::finalise` | | dispatch: call at completion with `TripActuals::fromTripDistance(DistanceMeter::actual($ride), ...)` |
| `CancellationFeePolicy::decide/settle` | | dispatch: on passenger cancellation, pass `driverRunningLate`; money charges the fee |
| `EarningsSplitter::estimateForRide` | | dispatch/driver UI: offer card earnings |
| `ride_fares.final_total_minor`, `ride_fares.earnings` | | money: charge and ledger from these |

The geo bridges are not exercised by tests on this branch (geo classes absent); they are a few lines of mapping and must be smoke-tested after merge (wave 3).

Test doubles for everyone: `Tests\Doubles\Pricing\FakeRoutePlanner`, `FakeZoneLookup`, `FakeSupplyCounter`, `FakeDemandSignal`, `FakeTollEstimator`; fixtures in `Tests\Concerns\PricingFixtures`.

## 15. Owner decisions needed

1. **Real tariffs** per category (and per city if wanted): base, per km, per minute, minimum, booking fee, waiting, cancellation and no-show fees. The shipped numbers are placeholders.
2. **VAT status**: whether Dot.Ehail (or each driver/operator) is VAT-registered and whether fares are exempt passenger transport under VAT Act s12(g) while booking/service fees are standard-rated. Question for the accountant; the engine supports none / inclusive / exclusive per tariff and the example uses none.
3. **Demand pricing**: on or off, and the cap (engine maximum 3.0x). Off everywhere today, and no demand signal exists.
4. **Commission**: level per market/category/fleet, fixed fees, min/max, and whether to publish a weekly earnings floor (research: SA norm about 25 %, inDrive about 11 %). Example is 20 %.
5. **Airport and zone fees**: only when a documented charge exists (ACSA). None configured.
6. **Finalisation tolerances** (15 % distance, 25 % time, 20 % overrun cap) and free cancellation (2 min) / free waiting (5 min).
7. **Rounding** to whole rand (example) or to the cent.
8. **Business category** activation once business accounts ship.

## 16. Tests

PHP: `tests/Unit/Pricing` (Money, integer rounding modes, formatting, property tests; engine components, minimums, demand cap, tax modes, zone fees, tolls, waiting, rounding, promotions, 400-case property test; time windows across midnight and the year) and `tests/Feature/Pricing` (resolution order and tie-breaks, immutability, quoting incl. routing failures, outside area, restricted zones, compare, quote tamper/expiry/foreign/route/category refusal, promotion eligibility and redemption races, finalisation scenarios with an adjustment-sum property test, operator adjustments, cancellation fees, earnings split scenarios and conservation property test, catalogue and eligibility, HTTP endpoints, example tariff and pruning commands). The same pricing suite passes on PostgreSQL 16 (run with a scratch phpunit config pointing at a local database; caught and fixed one PG-only bug: `FOR UPDATE` with an aggregate).
