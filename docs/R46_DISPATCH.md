# R-46 dispatch: lifecycle, availability, matching, scheduling, real-time

Owner: dispatch agent (branch `feat/r46-dispatch`, decisions D-400 onward). Code: `App\Domain\Rides\*`, `App\Domain\Dispatch\*`, `App\Http\Controllers\Ehail\Dispatch\*`, `routes/ehail/dispatch.php`, `config/ehail_dispatch.php`, `App\Providers\EhailDispatchServiceProvider`. Research behind the choices: docs/R46_RESEARCH.md "Dispatch and matching".

## 1. State machine v2

One edge table, `App\Domain\Rides\RideTransitions`; one executor, `App\Domain\Rides\RideStateMachine::transition()`. `RideStatus::allowedTransitions()` reads the table, so nothing else decides what may follow what.

Actors: P passenger, D driver, O operator, S system. Guards are explained in the class comment of `RideTransitions`.

| From | To | Actors | Guards | Effects |
|---|---|---|---|---|
| scheduled | requested | S | lead_time | stamp_requested |
| scheduled | cancelled_by_passenger | P | party | stamp_cancelled, withdraw_offers, cancellation_fee, release_driver |
| scheduled | cancelled_by_operator | O | | stamp_cancelled, withdraw_offers, release_driver |
| scheduled | expired | S | slot_missed | stamp_expired |
| scheduled | failed | S | | stamp_failed, withdraw_offers, release_driver |
| requested | searching | S | | start_search |
| requested | driver_assigned | D, O | unassigned, driver_free | assign_driver, withdraw_offers |
| requested | cancelled_by_passenger / cancelled_by_operator / failed | P / O / S | as above | as above |
| requested | expired | S | window_elapsed | stamp_expired, withdraw_offers |
| searching | driver_assigned | D, O | unassigned, driver_free | assign_driver, withdraw_offers |
| searching | cancelled_by_passenger / cancelled_by_operator / failed | P / O / S | | |
| searching | expired | S | window_elapsed | stamp_expired, withdraw_offers |
| searching | needs_review | O, S | | stamp_needs_review, withdraw_offers, release_driver |
| driver_assigned | driver_arriving | D | party | stamp_arriving |
| driver_arriving | driver_arrived | D | party | stamp_arrived |
| driver_arrived | in_progress | D | party, pin | stamp_started |
| driver_arrived | no_show | D | party, no_show_wait | stamp_no_show, cancellation_fee, release_driver |
| driver_assigned / driver_arriving / driver_arrived | searching | D, O | party, can_requeue | release_driver, requeue |
| driver_assigned / driver_arriving / driver_arrived | cancelled_by_driver | D | party, requeue_spent | stamp_cancelled, release_driver, unassign |
| driver_assigned / driver_arriving / driver_arrived | cancelled_by_passenger, cancelled_by_operator, needs_review, failed | P, O, O+S, S | | |
| in_progress | completed | D, O | party, final_fare | complete_trip, release_driver |
| in_progress | needs_review | O, S | | |
| in_progress | cancelled_by_operator | O | | |
| needs_review | completed | O | | complete_trip, release_driver |
| needs_review | cancelled_by_operator | O | | |
| needs_review | searching | O | not_started | release_driver, requeue |
| completed, cancelled_*, expired, no_show, failed | (none) | | | terminal |

Every transition: one DB transaction (3 attempts on lock contention), the ride row locked with `lockForUpdate` and re-read, the idempotency key checked against the timeline, the edge and the actor checked, every guard run, the attributes written, the post-save effects run, one `ride_events` row appended, the pre-R-46 in-app notices and audit rows written (`RideNotices`), and the domain event dispatched **after commit**.

### Pre-v2 values (data migration 2026_11_10_400100)
`accepted -> driver_assigned`, `en_route -> driver_arriving`, `arrived -> driver_arrived`, `cancelled -> cancelled_by_passenger` when `cancelled_by = passenger_id`, else `cancelled_by_operator` (before v2 only the passenger or an operator could reach `cancelled`). Every renamed row is kept and gets a `status_migrated` timeline event. `RideStatus::fromStored()` still reads the old values (`LEGACY_ALIASES`) for history. The down migration folds v2 back (lossy for the v2-only states).

### Existing code and tests changed, with reasons
| Change | Reason |
|---|---|
| Status strings renamed across policies, controllers, `VehicleManager`, dashboard and status views; tests updated by the same mapping | the enum values changed (architecture section 2) |
| A driver backing out returns the ride to `searching` (was `requested`) | v2 has a separate searching state |
| Exhausted reassignments end as `cancelled_by_driver` (was `expired`) and clear the driver | architecture section 2 names cancelled_by_driver as the terminal outcome; the passenger notice and the Dot.Notify `ride.expired` email are unchanged |
| Tests that claim from the board set `open_board_after_seconds = 0` | sequential offers come first; those tests are about the claim, not the delay (the delay is tested in DispatcherTest) |
| `RideViewTest`/`RideRatingTest` fixtures no longer give one passenger several active rides | the database now enforces one active ride per passenger |
| `RidePaymentStateTest` rolls back by counting migrations after the payment ones | it rolled back a fixed 2 steps, which became the dispatch migrations |

## 2. Domain events

`App\Domain\Rides\Events\*`, plain Laravel events, `ShouldDispatchAfterCommit`, payload `ride_id, actor_id, actor_type, occurred_at, reason_code, from, to` (ids only, never names or locations): `RideRequested`, `DriverAssigned`, `DriverArriving`, `DriverArrived`, `TripStarted`, `TripCompleted`, `RideCancelled` (to says which side), `RideExpired`, `RideNoShow`, `RideFailed`, `RideNeedsReview`, plus `RideRequeued`, `ScheduledRideReminderDue`, `ScheduledRideRequoteRequired` for wave-2 comms. Broadcastable variant: `RideEventBroadcast` on `private-ride.{id}` (the existing channel authorisation), sent only when `EHAIL_BROADCAST_RIDE_EVENTS=true` (hosts that run Reverb). The existing `RideStatusUpdated` broadcast from `RideObserver` is unchanged.

## 3. Driver availability

`driver_availability`: `state` offline | online | on_trip | paused, `vehicle_id`, `since`, `online_since`, `last_heartbeat_at`, latest position (`last_lat`, `last_lng`, `heading`, `last_location_at`; one row per driver, overwritten), `current_ride_id` (unique), `last_trip_ended_at`, feed versions.
- Going online asks `DriverEligibility::canGoOnline(User, Vehicle)`: the safety domain's verification seam. The default applies the rules that exist today (approved profile, own active vehicle) and returns reasons.
- Heartbeat: position is stored only while not offline (POPIA minimisation) and forwarded to `DriverLocationSink` (geo's `LocationStore::record` when bound). No heartbeat or position for 45 s = stale: not offered rides, not switched offline.
- Offline is refused while on a trip; pause/offline withdraw a pending offer without counting it against the driver.
- `DriverProfile::is_online` is kept in step for the pre-R-46 pages.

## 4. Dispatch algorithm (`App\Domain\Dispatch\Dispatcher`)

On demand, no daemon (D-2): `tick()` runs on every passenger poll (that ride), every driver poll (the ride the driver has an offer for), at most every 2 s for up to 5 other due rides from any poll, and every minute from `php artisan ehail:dispatch-tick` (all due rides, priority first).

One step for one ride, under the ride's lease:
1. `requested` moves to `searching`.
2. A pending offer past its 15 s is marked `expired` (reason timeout); a live one ends the step.
3. Past the search window (`ehail.ride_expiry_minutes`, default 10) the ride expires.
4. No pickup coordinates (pre-R-46 address-only requests): the ride waits on the open board.
5. Candidates ring by ring (2, 4, 7, 10 km; configurable) from `DriverLocator::nearbyDrivers` (default: bounding-box query on `driver_availability`, then exact great-circle filter): online, fresh heartbeat and position, not on a trip, not the passenger, approved profile, active vehicle that `CategoryEligibility` allows for the ride's category, inside the service area when `ServiceAreaCheck` knows. Excluded: drivers who declined or backed out of this ride, and drivers whose offer for it lapsed in the last minute.
6. `CandidateScorer` ranks them (section 5); the best is offered. The offer row is created in a transaction that re-reads the ride under lock and re-checks the driver; `pending_driver_id` is unique, so a driver who just got another ride's offer is skipped for the next candidate.
7. Nobody anywhere: retry in 3 s.

Answers: accept is one transaction: compare-and-swap the offer (still pending, unexpired, this driver), then the state machine (`searching -> driver_assigned`); a repeated tap on an offer already won returns the ride. Decline records the reason and offers the next driver at once. Driver backs out: `searching` with `dispatch_priority + 1` and `reassignment_count + 1` while `ehail.max_reassignments` allows, then `cancelled_by_driver`. Passenger cancel during search withdraws the offer. No-show: driver at pickup, 5 minutes waited, driver confirms; fee from the pricing `CancellationFees` seam (none until pricing binds it).

Open board (the pre-R-46 claim list): a searching ride is listed to eligible drivers after `open_board_after_seconds` (45; `0` immediately; negative switches it off). Claiming withdraws the pending offer in the same transaction. It is a page the driver opens; nothing pushes it.

### Concurrency
| Guarantee | Mechanism | Engine notes |
|---|---|---|
| a ride never has two drivers | state machine `unassigned` guard under the row lock; offer CAS; one `driver_assigned` per assignment | |
| a driver never holds two active rides | `rides.active_driver_id` unique (set only in driver-holding states) + `driver_availability.current_ride_id` unique + `driver_free` guard | nullable-unique columns behave as a partial unique index on SQLite, PostgreSQL and MySQL alike (any number of NULLs allowed); no engine-specific DDL |
| one active ride per passenger | `rides.active_passenger_id` unique + request-time check | |
| one offer in flight per ride, one per driver | `ride_offers.pending_ride_id` / `pending_driver_id` unique | |
| concurrent ticks | lease CAS on `rides.dispatch_lease_until` (5 s, taken over when stale) | atomic everywhere, including SQLite where `lockForUpdate` is a no-op |
| lock contention | transactions retried 3 times on concurrency errors; the API maps a leftover one to `409 conflict_retry` with `Retry-After: 1` | |

`tests/Feature/Dispatch/ConcurrencyTest` runs contenders in **separate forked processes** with separate connections to one SQLite file (WAL, busy timeout 10 s), released together by a time barrier: offer accept vs board claim, double tap from two devices, one driver accepting one offer while claiming another ride, three concurrent ticks, a double request with one Idempotency-Key. With `transaction_mode = IMMEDIATE` every loser gets a clean refusal; with the default `DEFERRED` mode correctness still holds but a loser may get "database is locked" (mapped to 409). **Recommendation for any SQLite deployment:** `transaction_mode => IMMEDIATE`, `busy_timeout => 10000`, `journal_mode => wal`. **PostgreSQL run (for the wave-3 performance agent):** run the same class with `DB_CONNECTION=pgsql` pointing at a disposable database, replacing the file setup with `migrate:fresh` on that connection; there `lockForUpdate` takes real row locks and the contenders serialise on the ride row.

## 5. Scoring, weights and fairness

`cost (s) = w_eta x eta_s - idle_weight x min(idle_s, idle_cap_s) + clamp(s_per_trip x (trips_today - median), +-cap) + cooldown_s`; lowest cost wins; ties: longer idle, fewer trips today, lower user id.

| Weight (env) | Default | Meaning |
|---|---|---|
| `EHAIL_W_ETA` | 1.0 | pickup ETA dominates |
| `EHAIL_W_IDLE` / `EHAIL_W_IDLE_CAP_S` | 0.1 / 1800 | 10 min idle = 60 s of ETA credit, at most 180 s |
| `EHAIL_W_TRIP_BALANCE_S` / `_CAP_S` | 30 / 120 | each trip above the candidates' median costs 30 s, at most 120 s |
| `EHAIL_W_COOLDOWN_S` | 60 | applies for 5 min after 3 declines/timeouts in 10 min; never exclusion |

Rationale: ETA keeps passengers' waits short; idle credit and trip balance spread work so the same drivers do not take everything (two equally placed drivers alternate exactly, FairnessTest); the cooldown discourages cherry-picking a little and temporarily, and drivers see the destination and earnings before accepting, so declining is a legitimate choice, not a sin. ETA for ranking comes from `PickupEtaEstimator` (geo's router for the closest 5 candidates within 400 ms when bound) or the bounded straight-line estimate (x1.4 detour, 25 km/h, capped at 1 h), labelled `straight_line_estimate`; it is never used for fares (D-4).

### Metrics logged (measurable)
Per offer (`ride_offers`): sent, seen, expires, responded, status (accepted, declined, expired, withdrawn), reason, round, radius, pickup distance and ETA with method, score and every term and weight. Per ride: `offers_sent`, `search_round`, `reassignment_count`, `dispatch_priority`, search start, every stamp, and the full timeline (`ride_events`). Available to operators through `GET /api/ehail/ops/rides/{ride}/timeline`.

## 6. Ride requests, PIN, idempotency
`POST /api/ehail/rides {quote_id, payment_method?, payment_ref?, scheduled_for?}` with optional `Idempotency-Key` (8-100 chars). The quote comes from the pricing seam `FareQuoteGateway` (must exist, belong to the passenger, be unexpired and unused); the client never sends a fare. Until pricing is bound the default answers `503 pricing_unavailable`. Payment method via the money seam (default: cash only). The ride stores the quote id and snapshot, total in minor units (decimal mirror for the old pages), category, route distance and duration. A 4-digit PIN: HMAC for checking, encrypted copy shown to the passenger only from assignment until the trip starts; 5 wrong entries lock it for 10 minutes; every wrong entry is audited. Idempotency: same key and body replays the stored response (`Idempotent-Replayed: true`) for 24 h; same key with a different body is refused; a retry while the first runs gets `409 request_in_progress`.

## 7. Scheduled rides
Booking 30 minutes to 30 days ahead creates `scheduled` ("awaiting driver"). Reminder event 60 minutes before. At 15 minutes before: re-quote; within 10% of the booked fare (or when pricing cannot quote) the booked fare stands; above it the passenger must accept the new fare (`POST .../requote/accept`) before dispatch, otherwise the slot is missed (`expired` 10 minutes after pickup time). Then `requested`, and dispatch like any ride; "driver confirmed" once assigned. Reschedule until 60 minutes before pickup; cancel any time before the trip (fee decided by the pricing seam). A passenger still on another ride delays allocation until that ride ends or the slot is missed. Nobody is reserved hours ahead: an honest "awaiting driver" until allocation.

## 8. Polling protocol (real-time without a socket)
| Endpoint | Who | Poll | Contents |
|---|---|---|---|
| `GET /api/ehail/rides/{ride}/feed` | that ride's passenger only | `Retry-After` 3 s while active, 30 s otherwise | ride (status, places, fare in minor units, requote, payment, search window, PIN while assigned, driver first name, rating and vehicle), driver position with heading, age, stale flag and ETA (to pickup, or to drop-off in the trip) only while the driver holds the ride |
| `GET /api/ehail/driver/feed` | the driver | 2 s online, 30 s offline | availability (state, heartbeat staleness), pending offer (pickup and distance/ETA, destination address, trip distance/duration, fare, earnings estimate or null, passenger first name and rating band), active ride (next stage, PIN required, no-show available at) |

Versions are epoch microseconds, strictly increasing per source (`FeedVersion`); a feed's version is the maximum of its sources. Send `If-None-Match: "v<version>"` (or `?since=<version>`): `304` when nothing changed, otherwise only the changed sections. Responses are `Cache-Control: private, no-store`. The driver never receives the passenger's location (it is never collected). Measured on SQLite in memory with 500 online drivers (RideFeedTest, medians of 50): passenger feed 0.44 ms, driver feed 0.42 ms, a dispatch tick (search over 500 drivers, scoring, offer) about 7 ms.

Driver actions: `POST /api/ehail/driver/{online,offline,pause,resume,heartbeat}`, `POST /api/ehail/offers/{offer}/{seen,accept,decline}`, `POST /api/ehail/driver/rides/{ride}/{arriving,arrived,start,complete,no-show,cancel}`. Passenger: `POST /api/ehail/rides/{ride}/cancel`, `PATCH /api/ehail/rides/{ride}/schedule`. Operations (operator middleware): `GET /api/ehail/ops/rides`, `GET .../{ride}/timeline`, `POST .../{ride}/{force-cancel,reassign,needs-review,resolve}`, each needing a written reason and audited. Errors: `{error, message}`; invalid transition 409; refusal codes listed in the controllers. The API lives under `/api/` so validation and auth failures render as JSON (bootstrap/app.php).

## 9. Limits (honest)
- Sequential offers, not batched global matching (research section); fine at launch volume, revisit per zone.
- Without a daemon, an offer moves on at the next poll from anyone (2-3 s) or at the next scheduler minute when nobody polls. A driver whose app is closed holds an offer at most 15 s plus that delay.
- Straight-line ETA until the geo router is bound; labelled everywhere it appears.
- Until pricing is bound, quote-based requests answer 503 and there is no earnings estimate or cancellation fee; until money is bound, cash only; until safety is bound, eligibility is profile approval only (no document expiry).
- Feed versions from different sources can only tie within one microsecond; then a change would wait for the next change to be seen.
- The PIN is 4 digits; the lock and audit trail protect it, not the hash.
