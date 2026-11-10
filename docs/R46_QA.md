# R-46 QA: browser end-to-end acceptance (brief section 24)

Owner: qa agents (wave 3: qa, then qa2), branch `feat/r46-qa`, decisions D-1600 to D-1616. The PHP acceptance layer (`tests/Feature/Acceptance`, integ) proves the journeys over the HTTP API; this layer proves them in real browsers, on the integrated app, with only the providers stubbed.

## 1. How to run

```
node tests/e2e/run.mjs                  # build, boot, every spec, tear down (about 25 min)
node tests/e2e/run.mjs 05               # one spec file (file-name filter)
E2E_GREP='PIN' node tests/e2e/run.mjs 05 # tests whose name matches, inside the chosen files
node tests/e2e/run.mjs --skip-build     # reuse public/build (it must be built without VITE_REVERB_*)
node tests/e2e/run.mjs --serve          # boot only; writes out/serve-env.json for running a spec by hand
node tests/e2e/run.mjs --keep           # keep the throwaway database and storage folder afterwards
E2E_VERBOSE=1 node tests/e2e/run.mjs    # stream the app and queue output
```

Needs PHP 8.4+, Node 22+ (node:sqlite, node:test), Google Chrome installed (driven through `playwright-core` with `channel: 'chrome'`; nothing is downloaded) and `openssl`. Output goes to `tests/e2e/out/` (git-ignored): `fail-<step>.png` for a failed step, `left-<test>-<n>.png` for every page a failed failure-scenario test left open, `route-sweep-<role>-<width>.json` and `route-sweep-failures.json`, `laravel.log`, `app.log`, `queue.log`. The run refuses to start specs unless the web workers write into the suite's own storage folder (D-1608) and fails if anything tried to reach the internet.

## 2. The world

| Piece | What it is |
|---|---|
| App | PHP built-in server with 6 workers from `public/` (as `artisan serve`), APP_ENV local, debug off, assets built as the deploy pipeline builds them (no VITE_REVERB_*), a throwaway SQLite file (WAL, busy timeout, IMMEDIATE transactions; D-1601) and storage folder in the OS temp dir, deleted afterwards |
| Queue | a `queue:work` process (the host's cron runs the same jobs once a minute) |
| Scheduler | not running; a spec runs the command cron would run, after moving timestamps back (D-1605) |
| Reference data | migrations, `ehail:geo-reference-areas`, `ehail:pricing:install-example-tariff` (20 % commission plan) |
| Routing | OSRM-compatible stub: city-grid road routes (north-south, then east-west, 20 m samples, 30 km/h, polyline5), switchable to 503 |
| Geocoding | Nominatim-compatible stub (search, reverse) for four Cape Town places |
| Map tiles | https stub (self-signed; production tile hosts are https and only those enter the CSP) |
| Paystack | https stub with the endpoints the money domain calls, signed webhooks to the app; the hosted checkout is routed inside the browser (checkout.paystack.com is never contacted) |
| SMS | BulkSMS-compatible stub on the same https server |
| Mail | an SMTP server that captures every message (safety reports "sent" honestly) |
| Internet | `HTTP(S)_PROXY` points at a recorder that refuses and lists every attempt; the run fails if the list is not empty |
| People | `support/world.php` (refuses any database but the suite's): users, operators with roles, verified fixture drivers, fleet owners, business admins, time travel, and a reset between specs (drivers offline, rides left waiting cancelled) |

## 3. Journeys

Every row is asserted by the spec named, in Chrome, against the integrated app; "Evidence" names what the spec checks beyond the screen (database rows read straight from the suite's SQLite file, stub logs). Results: section 6.

### 3.1 Passenger (spec 01, 390 px phone; the driver in a second browser with emulated GPS)

| # | Step | Result | Evidence |
|---|---|---|---|
| 1 | Register through the real form | Pass | `users` row; signed in after the form |
| - | Add a card (Paystack card-only checkout, R1.00 check) | Pass | `payment_methods` active default, Visa 4081; setup payment `paid`; `/refund` call for the R1.00 on the stub |
| 2 | Pickup from the phone's location | Pass | pickup field filled from geolocation |
| 3 | Destination by search | Pass | Nominatim stub result chosen with the keyboard |
| 4 | Estimate from the road route | Pass | every `fare_quotes` row: road distance = the stub's grid route (±40 m), provider `osrm`; sheet shows 2.4 km |
| 5 | Transparent breakdown | Pass | the visible lines add up to the visible total, which is a stored quote total; components sum to `total_minor` |
| 6 | Choose and request (card default) | Pass | button reads "Request Economy, R…"; ride bound to the quote (`consumed_at`), `quoted_total_minor` = shown total, `payment_method_kind` card |
| 7 | A real driver is assigned | Pass | offer in the driver app shows the fare and "You earn"; accepted there; passenger sees "on the way", car and colour |
| 8 | Track the driver | Pass | passenger feed shows the driver at the pickup after the emulated drive; one `driver_locations` row (latest only) |
| 9 | Pickup and PIN | Pass | passenger sees "is here" and a 4-digit PIN; driver types it on the keypad; trip starts |
| 10 | Follow the trip | Pass | at least 10 `trip_points` recorded |
| 11 | Complete | Pass | driver confirms; summary view |
| 12 | Paid by card | Pass | one `ride_fare` payment `paid` = `final_fare_minor`; `ride_fares.basis` quoted_fare_stands; exactly one `charge_authorization`; signed `charge.success` webhook accepted (200) and de-duplicated; ledger balanced |
| 13 | Receipt | Pass | `receipts` row = total; receipt link on the trip page; receipt page shows number, total, card; receipt email captured by the SMTP stub |
| 14 | Rate | Pass | 5 stars, a tag, a comment; `ride_ratings` row for the driver |
| - | Hygiene | Pass | no 5xx, no console errors, no third-party hosts, no CSP violations on either phone |

### 3.2 Driver (spec 02; owner, reviewer and finance at 1440 in their own browsers)

| # | Step | Result | Evidence |
|---|---|---|---|
| 1 | Register | Pass | through the real form |
| 2 | Apply: fleet, licence, ID, vehicle | Pass | `driver_profiles` pending in the chosen fleet, licence number stored encrypted, vehicle row; the fleet owner approves in `/fleet/drivers` |
| - | Online refused before documents | Pass | console says documents are missing; API answers `document_missing` |
| 3 | Documents through the app's picker | Pass | 11+ `verification_documents` pending; vehicle documents tied to the vehicle |
| 4 | Verification in `/ops` | Pass | reviewer opens and approves each document; every document approved; one `verification_reviews` row each |
| 5 | Vehicle approved and active | Pass | `/drive/vehicles` lists it; `is_active` |
| 6 | Online | Pass | first position post reaches `driver_availability` |
| 7 | A valid request | Pass | the offer is this driver's (`ride_offers` pending) |
| 8 | Pickup and earnings before accepting | Pass | "Pickup 4xx m", "Trip 2.4 km"; the card's "You earn" = the earnings estimate endpoint |
| 9 | Accept | Pass | trip view |
| 10 | Navigate | Pass | hand-off link to the pickup, then to the drop-off after the start |
| 11 | Pickup: arrive, PIN | Pass | as passenger step 9 |
| 12 | Complete | Pass | earning `available`; net = the estimate before accepting; fare - commission - deductions = net; the summary shows net and commission |
| 13 | Earnings = ledger | Pass | `driver_payable:<id>` = net; commission in the ledger = the earning's; earnings page shows net and gross |
| 14 | Payout | Pass | bank account added; earning a day old (D-1605); finance drafts the batch and marks it paid in `/ops`; payout = net; nothing left owed; the driver sees the payout and its trip |

### 3.3 Safety (spec 03)

| # | Step | Result | Evidence |
|---|---|---|---|
| 1 | Trusted contact confirmed by the emailed link | Pass | GET shows a button and confirms nothing; POST confirms (`confirmed_at`) |
| 2 | SOS during a trip | Pass | one tap, the 3-second window, then "What we did"; `safety_incidents` critical, open, tied to the ride |
| 3 | Context captured | Pass | ride state, registration and driver position in `context` |
| 4 | Contact alerted | Pass | email (SMTP stub) with a live share link and 10111; SMS (BulkSMS stub); the share page shows the car; notification rows `sent` for mail and SMS, in-app alert to operations `sent` |
| 5 | Ops console and escalation | Pass | the incident in `/ops/safety`; after the critical timer (time moved back) `ehail:safety-sweep` escalates to level 1, the on-call email arrives, the timeline says so |
| 6 | Acknowledge, resolve, "what we did" | Pass | acknowledged from the escalated incident's page (D-1617), resolved with `user_safe` and a note; the passenger page says resolved, lists only what happened, has a "What we did not do" section and never claims police were called |

### 3.4 Pricing (spec 04)

| # | Step | Result | Evidence |
|---|---|---|---|
| 1 | Public estimate with nobody online | Pass | `/fares` prices from the map, says a driver is not guaranteed, stores nothing for the guest, lines add up |
| 2 | Server recomputation | Pass | a quote request and a booking that send `total_minor: 100` get the server's price |
| 3 | Breakdown bound to the ride | Pass | `ride_fares` estimated components sum to the total |
| 4 | Booking | Pass | ride created by the API with the server price, default card |
| 5 | Destination change mid-trip | Pass | fare `recalculated`, higher, `destination_changed` reason, adjustments = the difference, final lines add up; the passenger page and the receipt say why (D-1616); the card charged the final fare |
| 6 | Delay within tolerance (+20 %, tolerance 25 %) | Pass | `quoted_fare_stands`, final = estimate (second trip accepted from the summary screen, D-1614) |
| 7 | Ledger, receipts, statements | Pass | per ride: commission, fees, driver payable and tax split the fare exactly; journals balance; receipts = payments; the driver's CSV statement and earnings page agree |

## 4. Failure scenarios (spec 05)

| Scenario | Result | Evidence |
|---|---|---|
| Routing provider down | Pass | route cache expired and stub switched to 503: "could not be worked out", no option, no quote stored, nothing requestable; the breaker's cooldown is then passed (cache cleared) |
| No driver accepts | Pass | a driver online (pricing needs supply) whose phone goes away; search window passed: ride `expired`, "No driver found", "not charged", fare closed at zero, no accepted offer, passenger free to book |
| Driver declines; offer times out | Pass | decline with a reason moves the offer to the next driver at once; an unanswered offer expires (`timeout`) to the next driver |
| Passenger cancels after assignment | Pass | free inside the window; after it (time moved) the dialog says "R25.00" first, fee charged by card, driver gets 80 %, ledger balanced |
| Payment declined | Pass | charge fails, retries scheduled and run out (`money:sweep`), `payment_due` blocks: the sheet shows the amount with Request disabled and the API refuses; Pay now clears it and the next request goes through |
| Unverified driver | Pass | console says documents are missing; Go online absent; API `document_missing` |
| Expired document | Pass | `ehail:verification-expiry` expires it and takes the online driver offline; the console says why |
| Idempotency and double tap | Pass | one Idempotency-Key twice = one ride; replay header; same key with another body refused; a double tap on Request makes one ride (every request followed to its answer) |
| Two drivers accept at once | Pass | offer accept and open-board claim race: exactly one driver holds the ride (D-1610 made the board list it) |
| Connection loss; PIN brute force | Pass | driver offline: "No connection" on the driver, "Last position N s ago" on the passenger (D-1613), both recover; five wrong PINs lock (the fifth says so, D-1611), the right PIN is refused while locked and works after |

## 5. Route sweep (spec 06)

Every GET route (197, 189 swept; the rest need a parameter nothing in the run creates, or are endpoints/one-time links listed in `SKIP`) as guest, passenger, driver, every operator role, fleet owner and business admin, at 390 and 1440: no 5xx, exactly one h1, no horizontal overflow (the report names the element that sticks out), no console errors, no host but the app and the tile server, no CSP violation. Found and fixed: legal pages without an h1 (D-1612), payout batch 47 px overflow at 390 (D-1615).

## 6. Results

RESULTS_PLACEHOLDER

## 7. Bugs found and fixed

| Severity | Bug | Fix (commit) | Regression test |
|---|---|---|---|
| High | A completed ride could stay unpaid with no settlement job: the job's unique lock never expired, and a lock outliving its job skipped every later settlement trigger for that ride (D-1607) | `9db5656`: lock expires after 10 min; `ehail:settlement-sweep` every 5 min re-queues never-settled completed rides, releases a stale lock, raises a critical `settlement_missing` finance issue | `tests/Feature/Money/SettlementDispatchTest.php` (6) |
| High (test harness) | The E2E web workers ignored `LARAVEL_STORAGE_PATH` (`$_ENV` empty under cli-server) and used the repository's storage: cache, sessions and logs split from the queue worker (D-1608) | `92345ba` | run.mjs refuses to start specs otherwise |
| Medium | Driver actions answered 500 for a driver whose feed version was 0 (`RideFeed::forDriver` returned no body) (D-1609) | `cd422dd` | SettlementDispatchTest (driver HTTP path) |
| Medium | The passenger never saw the driver's position go stale while the driver's phone was silent (versioned feed, no new body) (D-1613) | `c1a901a` | RideFeedTest (`stale_after_s`), E2E connection-loss |
| Medium | The open board hid R-46 rides a vehicle may serve (legacy tiers vs category rules) and the accept policy refused the claim (D-1610) | `0b76101` | DispatcherTest `test_the_board_lists_exactly_the_rides_the_drivers_vehicle_may_claim` |
| Medium | On the driver console, a trip accepted while the last trip's summary was up stayed hidden behind the summary (D-1614) | `44bcf31` | E2E pricing journey (second trip) |
| Medium | An escalated safety incident could not be acknowledged from its ops page (D-1617) | `e96ca1f` | OpsPagesTest `test_an_escalated_incident_can_still_be_acknowledged_from_its_page` |
| Low | The wrong PIN that locks entry said only "not right" (D-1611) | `2a9579a` | RideStateMachineTest |
| Low | Receipts did not say why a recalculated fare differs from the quote (D-1616) | `42201ed` | Acceptance PricingJourneyTest |
| Low | Legal pages had no h1 (D-1612) | `46df89c` | LegalPagesHeadingTest |
| Low | Payout batch page 47 px wider than a 390 px phone (sr-only labels escaped the table scroller) (D-1615) | `9f1cf88` | E2E route sweep |

Spec bugs fixed along the way (not product defects): fixed sleeps replaced by polling the database, the DOM or the stubs; a title regex lost its `i` flag; selectors and channel names out of date (`/mark-paid`, `mail`, `operations`); cancel cost read before the dialog filled it; booking API bodies that named `card` without a card reference; the routing-down test answered from the route cache; tests that needed supply for bookable prices; the routed Paystack checkout host counted as third-party.

## 8. Not fixed

None open from this QA pass. Notes for the owner:
- The settlement sweep re-queues only rides with a fare due (`payment_status` unpaid) and neither a trip earning nor a fare charge; rides completed while payments were off (`not_due`) are correctly left alone.
- The suite reaches time-dependent rules by moving timestamps back (D-1605); the 15-minute settlement threshold itself is covered by the PHP tests, not the browser suite.
