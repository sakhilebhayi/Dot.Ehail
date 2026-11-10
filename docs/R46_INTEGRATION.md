# R-46 integration: five domains as one system

Owner: integ agent (branch `feat/r46-integ`, decisions D-700 to D-714). Code: `App\Domain\Integration\*`, `App\Providers\EhailIntegrationServiceProvider` (registered **last** in `bootstrap/providers.php`), `config/ehail_integration.php`, `tests/Concerns/AcceptanceWorld.php`, `tests/Feature/Acceptance/*`, `phpunit.pgsql.xml`, `bin/test-pgsql.sh`. Small edits in other owners' files are listed in section 7.

## 1. Binding table (seam -> real implementation)

Every owner binds its seams with `bindIf` to an honest default (each domain's own tests run against those). The integration provider binds unconditionally and runs last, so in the running app every seam below is the real thing.

| Consumer | Seam | Bound to (`App\Domain\Integration\...`) | What it does |
|---|---|---|---|
| dispatch | `FareQuoteGateway` | `Dispatch\PricingFareQuoteGateway` | reads the signed `FareQuote`; `bind()` = pricing `QuoteBinder::bind` inside ride creation (refusal rolls the ride back, `quote_<reason>`), then geo `RideRoutePlanner::attach` with the quoted road route (re-read from the route cache, attached only when its polyline hash equals the quote's); `requote()` = `FareQuoter::quote` for scheduled rides; a re-bind (accepted requote) replaces the unfinalised snapshot and returns its promotion |
| dispatch | `TripFareFinaliser` | `Dispatch\PricingTripFareFinaliser` | pricing `FareFinaliser::finalise` with `TripActuals::fromTripDistance(DistanceMeter::actual(ride), duration started->now, waiting arrived->started, passenger changes from the timeline)`; rides without a pricing snapshot keep dispatch's agreed-fare rule |
| dispatch | `CancellationFees` | `Dispatch\PricingCancellationFees` | `CancellationFeePolicy::decide` + `settle` in the cancellation transaction; `driverRunningLate` = not arrived and past the accepted offer's pickup ETA + 300 s; a no-show is decided as the passenger's |
| dispatch | `DriverEarningsEstimator` | `Dispatch\PricingEarningsEstimator` | `EarningsSplitter::estimateForRide(ride, driver)->driverNetMinor` (null without a commission plan) |
| dispatch | `CategoryEligibility` | `Dispatch\CatalogCategoryEligibility` | catalogue vehicle rules (class, age, seats, doors, features) + driver rules (approval, rating floor, fleet-only); legacy vehicle types map to their continuing category |
| dispatch | `DriverLocator` | `Dispatch\GeoDriverLocator` | geo `LocationStore::nearbyDrivers` (screened fixes) intersected with dispatch availability (online, free, fresh heartbeat) |
| dispatch | `PickupEtaEstimator` | `Dispatch\GeoPickupEta` | geo `RoutePlanner` (cached, circuit-broken) labelled `road`; straight-line fallback labelled as such; the scorer keeps its 400 ms / 5-candidate budget |
| dispatch | `ServiceAreaCheck` | `Dispatch\GeoServiceAreaCheck` | `ZoneResolver::isServiced`; null (unknown) while no active service area exists |
| dispatch | `DriverLocationSink` | `Dispatch\GeoLocationSink` | heartbeat positions go through geo screening, trail and deviation monitor |
| dispatch | `DriverEligibility` | `Dispatch\VerificationDriverEligibilityAdapter` | safety's `Verification\DriverEligibility` (documents, approval, own active vehicle) |
| dispatch | `PaymentMethodResolver` | `Dispatch\MoneyPaymentMethodResolver` | card (saved, chargeable) / cash (when enabled) / business (when available) / nothing = default card, else checkout, else cash, else refused; `attach()` writes `ride_payment_choices` in the ride transaction |
| geo | `LocationStore` (class) | `Geo\MirroringLocationStore` | geo's store plus: an accepted fresh fix refreshes dispatch's availability row (position, heartbeat, feed version) unless the driver is offline |
| geo | `DriverPresence` | `Geo\AvailabilityDriverPresence` | online/active ride from `driver_availability` and `rides.active_driver_id` |
| geo | `TrailRetentionHold` | `Geo\OpenIncidentTrailHold` | rides with an open, acknowledged or escalated incident keep their raw trail |
| safety | `SafetyLocationSource` | `Safety\GeoSafetyLocationSource` | geo latest fix and trail (safety's default pointed at a class path that does not exist) |
| safety | `TripProgressSource` | `Safety\DispatchTripProgress` | ride state, geo position, dispatch's passenger-feed ETA with its method |
| safety | `RidePinSource` | `Safety\DispatchRidePin` | the dispatch PIN, same visibility rule as the passenger feed |
| safety (support) | `FareAdjustmentRequest` | `Safety\PricingFareReviewRequest` | dispute submitted to operators with pricing's fare record; never changes a fare by itself |
| money | `FareSource` | `Money\PricingFareSource` | finalised `ride_fares` (payable = final total, promotion, exclusive tax, platform fees = gross - tax - commissionable - tolls); legacy reading otherwise |
| money | `EarningsSplitSource` | `Money\PricingEarningsSplitSource` | pricing commission plan split; money's configured rate only when no plan applies |
| money | `CancellationFeeSource` | `Money\PricingCancellationFeeSource` | the fee pricing settled (`basis = cancellation_fee`) |
| money | `DriverActivitySource` | `Money\DispatchDriverActivity` | acceptance rate from `ride_offers`; online seconds and referrals null (no history / no system) |
| money | `SupportCaseOpener` | `Money\SafetySupportCaseOpener` | support case (payment issue / fare dispute) |
| pricing | `EligibleSupplyCounter` | `Pricing\DispatchSupplyCounter` | fresh geo positions within dispatch's widest ring x online free availability x catalogue eligibility |

Left at their defaults on purpose: money `BusinessBilling` (business wave), money `PayeeResolver` (already reads fleet membership), pricing `DemandSignal` and `TollEstimator` (no signal or toll data exists), pricing's geo bridges (already real when geo is installed).

Also wired: `ehail.payment-clear` on `POST /api/ehail/rides` and the legacy `POST /rides`; navigation hand-off links in the driver feed (`ride.navigation`: Google Maps, Apple Maps, Waze universal links to the next stop); `POST /api/ehail/rides/{ride}/trip-change` (passenger destination change or extra stop, D-712).

## 2. One flow, in event order

Ride creation (one transaction): quote checked -> ride row -> PIN -> **pricing binds the quote** (signature, expiry, route, promotion redeemed, `ride_fares` snapshot, quote consumed) -> **geo attaches the quoted route** -> **money records the payment choice** -> commit -> `RideRequested` -> dispatch offers.

Trip completion (one transaction, `RideStateMachine`): guard `final_fare` -> **pricing finalises** (geo billable distance, tolerance rules, reasons; idempotent, so the guard and the effect agree) and snapshots the driver split -> ride `completed`, `final_fare_minor` -> availability released (online, or offline if verification lapsed meanwhile) -> timeline row -> commit -> `TripCompleted` (after commit). Money: the ride row's `updated` hook and the `TripCompleted` listener both queue `SettleCompletedRide` with `afterCommit`; the job is unique per ride and `RideSettlement` is idempotent (paid -> nothing; a fare charge already attempted -> only earnings recognition, retries belong to `money:sweep`, D-713) -> `recogniseTrip` (ledger `trip_revenue` journal, `driver_earnings` row) -> charge (`charge_authorization`) -> `payment_settled` journal -> receipt -> earning available. Duplicate Paystack webhooks are de-duplicated by money. Safety net (D-1607): the job's unique lock expires after 10 minutes, and `ehail:settlement-sweep` (every five minutes) re-queues any completed ride with a fare due that has no trip earning and no fare charge 15 minutes after completion, raising finance issue `settlement_missing`.

Cancellation / no-show (one transaction): pricing decides and settles the fee on the snapshot (promotion returned) -> `rides.cancellation_fee_minor` -> commit -> `RideCancelled` / `RideNoShow` -> money charges what pricing settled (`ChargeCancellationFee`, idempotent). Driver/operator cancellations, expiry and failure: `SettleFareOnRideEnd` closes the snapshot at zero with the reason after commit.

Verification lapse: `ehail:verification-expiry` -> `DriverForcedOffline` -> dispatch `goOffline` (pending offer withdrawn); a driver on a trip finishes it and is released offline.

## 3. Interface changes (architecture section 3 updated)

- `FareQuoteGateway::bind(LockedQuote, User, Ride): void` and `PaymentMethodResolver::attach(Ride, User, PaymentChoice): void`, both called inside the ride-creation transaction (defaults are no-ops) (D-701).
- `RideRequestService::request(..., array $addresses = [])`; `POST /api/ehail/rides` accepts `pickup_address`, `dropoff_address` (display labels; the quote holds coordinates only). Without them the ride says "Pinned location (lat, lng)", never an invented address.
- `POST /api/ehail/rides/{ride}/trip-change {kind: destination|stop, lat, lng, address?}` (D-712).
- Driver feed `ride.navigation`.
- `App\Domain\Verification\Events\DriverForcedOffline` (D-714).
- Pricing's geo zone bridge is "configured" only once an active service area exists (D-705).

## 4. JSON error policy (D-711)

One policy for every API, `App\Domain\Integration\Http\JsonErrorPolicy`, registered from `bootstrap/app.php` (pricing's own renderers were removed).
- **When:** the request asked for JSON (`Accept: application/json` or XHR) **or** its path is a JSON-only API listed in `config/ehail_integration.php` `json_paths` (`api/*`, `pricing/*`, `ops/pricing/*`, `fare-estimate*`, the geo lookups and driver location). Everything else keeps HTML pages and login redirects (receipts, trip share, legacy forms).
- **Shape:** `{"error": "<code>", "message": "<sentence>"}` + status; validation adds `errors`; 429 keeps `Retry-After`. Codes: `validation_failed`, `unauthenticated` (401), `forbidden`, `not_found`, `method_not_allowed`, `session_expired` (419), `too_many_requests`, `unavailable`. Framework wordings are replaced by sentences for people; a 404 never names a model. Domain refusals (dispatch `DispatchRefused`, safety `DriverNotEligibleToGoOnline`, money `payment_due` 402) use the same shape with their own codes.

## 5. Acceptance tests (`tests/Feature/Acceptance`, real HTTP API, fakes only at the edges)

Edges faked in `tests/Concerns/AcceptanceWorld.php`: an OSRM server answering every route with a city-grid road route (north-south then east-west, 20 m samples, 30 km/h, polyline5); Nominatim search (jsonv2 shape); Paystack (documented shapes, signed webhooks); mail (`Mail::fake` with SMTP configured so "sent" is honest). Real: every domain, database, sync queue, scheduler commands, the approximate Cape Town service area, the example tariff (20 % commission). Time passes with `travel()`; the driver's phone posts a fix every 15 s along the road.

| Test | Proves |
|---|---|
| `PassengerJourneyTest` (P) | register, add a card (checkout + callback), geocode pickup and destination, compare categories (breakdowns sum to totals, supply available), quote, request with an Idempotency-Key (replay returns the same ride), quote bound and route attached, offer to the verified online driver with road ETA and earnings, accept, passenger feed shows driver, position delta and road ETA, arriving/arrived, wrong PIN refused, PIN start, trail recorded, complete, final fare = quote (`quoted_fare_stands`, billable distance from GPS), card charged once, duplicate webhook ignored, ledger balanced, receipt, rating |
| `DriverJourneyTest` (D) | register, apply to a fleet, fleet owner approves, vehicle activated, online refused (`document_missing`, then `document_pending_review`), every document uploaded, operator approves each, eligibility allowed, online, offer with pickup distance/ETA, trip, fare and earnings estimate, navigation links (pickup, then drop-off), trip, back online, earnings = fare - 20 % commission on the trip fare - booking fee, available after payment, weekly payout batch includes it |
| `SafetyJourneyTest` (S) | trusted contact confirmed by emailed link, SOS mid-trip: incident with ride, vehicle, driver's geo position and trail; contact emailed with a live share link; operators alerted in-app; "what we did" built from real notification rows (no SMS claimed); trail held while open; escalation to the on-call lead after the critical timer; acknowledge, resolve, timeline; hold released |
| `PricingJourneyTest` (F) | anonymous estimate not stored; client totals ignored (quote and ride request); components sum; booking binds the quote; a passenger destination change mid-trip is recalculated uncapped with the "You changed the destination" reason and adjustments summing to the difference, card charged the final fare; +20 % time in traffic keeps the quote; every journal balances; earnings = fare - commission - fees; commission and fee revenue in the ledger equal the earnings' totals |
| `FailureScenariosTest` | no routing provider (not configured, and configured but down): nothing priced, honest message; no driver: ride expires with the "No driver found" notice, fare record closed at zero; decline moves on at once, timeout moves on at the next poll; passenger cancellation: free in the window, R25 fee after it (card charged, driver gets 80 %), free when the driver is late; declined card: retry scheduled, then blocked (402 `payment_due`) on the next request; unverified driver refused by the API and by the legacy toggle; expired document takes an online driver offline in dispatch; idempotency key replay and a second key refused; open-board claim beats the offered driver's accept (one assignment) |

True simultaneity (separate processes) stays covered by dispatch's forked `ConcurrencyTest`; the acceptance race is sequential.

## 6. PostgreSQL

`bin/test-pgsql.sh` creates `ehail_integ_<random>`, runs `phpunit.pgsql.xml` against it and drops it on exit (it refuses if the name exists; it never touches another database). Pass `--filter ...` to run a part. Local PostgreSQL 16.14: 1096 tests, 1089 passed, 7 skipped (SQLite: 1096, 1088 passed, 8 skipped; the extra skip is SQLite-only). Divergences found and fixed (D-715): unique-violation inserts without a savepoint aborted the enclosing transaction (idempotency keys, check-ins, rating appeals, user reports); the forked ConcurrencyTest shared the parent's PostgreSQL socket (now one connection per contender, database re-migrated after it), which had also leaked committed rows into InfoDotRedeemTest.

## 7. Edits in other owners' files (all small, all for integration)

dispatch: contracts `FareQuoteGateway::bind`, `PaymentMethodResolver::attach` and their defaults; `RideRequestService` (bind + attach in the transaction, address labels); `ScheduledRides::acceptRequote` (re-binds the new quote); `PassengerRideController` (addresses, trip change); `RideFeed` navigation; `AvailabilityService::releaseFromRide` (offline after a lapse); `routes/ehail/dispatch.php` (payment-clear, trip-change). pricing: `GeoZoneLookupBridge::isConfigured`; provider renderers removed. money: `RideSettlement` (no second charge on a repeated completion), `EnsureNoPaymentDue` (policy shape). safety: `VerificationService` dispatches `DriverForcedOffline`. shared: `bootstrap/app.php`, `bootstrap/providers.php`, `routes/web.php` (payment-clear on the legacy request). Tests: section 8.

## 8. Test changes (behaviour legitimately changed by binding)

Fixtures: `ApprovesDriverDocuments` extracted from the safety fixtures and used by the dispatch fixture (online drivers must be verified); dispatch fixture enables cash (no payment provider in those tests); `PricingFixtures::setUpPricingFixtures` keeps supply unknown for pricing tests; SUV fixtures seat six (XL rule). Assertions: dispatch eligibility code `driver_not_approved` (safety's), catalogue decides categories (an economy car cannot serve Comfort), geo's routing message, geo guest requests answer 401 JSON, support fare dispute is `submitted`, broadcast-after-commit test fakes only broadcast jobs, event constructors use `actorType`, ride fixtures respect one active ride per passenger.

## 9. Remaining gaps

- Online-hours incentives stay unmeasurable: dispatch keeps no history of online sessions.
- Multi-stop routes are not planned: a passenger-added stop is billed with its reason, but deviation checks may prompt a check-in during the detour.
- An operator fare adjustment after settlement does not refund automatically (money refunds stay operator actions).
- Inclusive tax is not split out for the ledger (VAT is off in the example tariff; an owner decision).
- Pickup ETA router calls can take up to the provider timeout (4 s) once inside the scorer's budget.
