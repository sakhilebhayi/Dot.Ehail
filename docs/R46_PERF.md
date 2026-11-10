# R-46 performance, load and reliability (measured)

Owner: perf agent (wave 3), branch `feat/r46-perf`, decisions D-1500 to D-1515. Code: `App\Domain\Perf\*` (poll policy, lazy session handler, lean poll headers, database-outage rendering, invariants, cost probe), `App\Domain\Health\*` (report, command, alerts), `App\Http\Controllers\Ehail\Dispatch\DriverSyncController`, `App\Http\Controllers\Ehail\Perf\OpsStatusController`, `App\Providers\EhailPerfServiceProvider`, `config/ehail_perf.php`, `routes/ehail/perf.php`, `scripts/load/*`, `tests/Feature/Perf/*`. Fixes in other owners' files are listed in section 8.

Every number here was measured on this machine (Apple M4, 10 cores, 16 GB; PHP 8.5.9 with OPcache; SQLite 3.53 in WAL; PostgreSQL 16 on localhost TCP) with the scripts in `scripts/load`. Section 1.4 says how they translate to the shared host; those translated figures are estimates and are labelled as such.

## 1. Load model for the real host

### 1.1 Set-up (D-1500)

| Piece | What it is |
|---|---|
| Server | `scripts/load/serve.sh <workers>`: PHP's built-in server with `PHP_CLI_SERVER_WORKERS=N`, i.e. N forked single-threaded workers on one socket. The same shape as a PHP-FPM pool with `pm=static` and N children: one request per worker at a time, the rest wait in the listen queue. OPcache on, `APP_DEBUG=false`. nginx/caddy are not installed here; the built-in server avoids adding infrastructure. |
| Drivers | Production ones: `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, real encrypted session cookies (minted into the sessions table of the throwaway world), CSRF tokens, every middleware. |
| World | `scripts/load/world.php setup 500 150`: fresh schema, approximate SA reference areas, the example tariff, 500 verified drivers (all documents approved, cash accepted) online around the Cape Town city bowl, 150 passengers, an operator. Refuses unless `APP_ENV` is local/testing and the database is a `*perf*` SQLite file or a PostgreSQL `ehail_perf_*` database. |
| Phones | `scripts/load/run.mjs`: every driver phone and passenger app following the real protocol (ETags, Retry-After, 30 % of drivers moving, offer answers after 0.8 to 3.5 s, 15 % declines, 20 % double-taps on Accept from two "devices", arrive, PIN from the passenger's feed, start, drive, complete), 100 passengers quoting and requesting at the same second, one operator on the live board every 10 s. Client pool of 128 sockets; latencies include the wait for a free worker. |
| Edges | `scripts/load/stub-osrm.mjs` (OSRM-compatible city-grid routes, switchable to down/slow/garbage), `scripts/load/stub-smtp.mjs` (SMTP sink). No real provider is ever called. |
| Cost probe | `EHAIL_PERF_PROBE=true` (refused outside local/testing) adds `X-Ehail-Queries`, `X-Ehail-Writes`, `X-Ehail-Ms` to every response: statements and writes of the whole request (session, auth, limiter, app). |
| One run | `scripts/load/bench.sh <sqlite|pgsql> <workers> <legacy|sync> <outdir> [run.mjs options]`: builds the world, serves it, runs the load, drains the queue once like the cron minute, checks the invariants (section 3) and prints counts. PostgreSQL databases are created as `ehail_perf_<random>` and dropped on exit. Extras: `TICKS=1` (two `ehail:dispatch-tick` processes at once every 3 s), `DB_OUTAGE_AT/DB_OUTAGE_FOR` (the database refuses connections), `--outage-at/--outage-for` (routing provider down), `CACHE_LIMITER=file`, `OPTIMIZE=1` (config/route/event caches as a deploy builds them). |

### 1.2 The request budget: before and after

Per online driver, as the phone actually behaves (measured, 500 drivers, 4 workers, 90 s, SQLite):

| | Before (first driver app) | After (sync protocol, D-1501..D-1507) |
|---|---|---|
| Requests a minute per driver | ~50 when the server keeps up (feed every 2 s, location every 4 s, heartbeat every 10 s); 39 measured because the server was saturated | **19.4** (one sync every 3 s; 2 s while an offer is on screen) |
| Server at 500 drivers + 100 rides | **saturated**: 351 rps, driver feed p50 433 ms, p95 1.2 s, p99 2.3 s | 184 rps, sync p50 12 ms, p95 95 ms (p99 1.5 s during the 100-request burst) |
| Statements / writes per request | feed 15.6 / 1.27, location 19.9 / 7.9, heartbeat 16 / 4.8 | sync 19.1 / 2.4 (database limiter); **12.9 / 1.2 with `CACHE_LIMITER=file`** |
| Idle driver feed answered 304 | 24 % (every position post moved the driver's own version) | feed `null` unless something changed (D-1504) |
| Driver download / upload (HTTP payload) | 4.9 MB/h / 1.5 MB/h (6.3 MB/h / 1.9 MB/h unsaturated, 20-driver run) | **0.96 MB/h / 0.79 MB/h** |
| Response size | ~2,100 B (two re-sent cookies ~900 B, CSP ~400 B) | 745 B |

Where the before-cost went, per poll on the database stores: session read + **rewrite** (Laravel rewrites the row on every request), user, limiter (read, add, locked increment), the dispatch work, and on every location post: geo latest-row upsert, availability update, a cache delete for the jump counter, a trip point when on a trip. The changes:

- **One request per poll** (`POST /api/ehail/driver/sync`, D-1501): positions up, feed down, same domain code. The old feed/location/heartbeat endpoints remain for compatibility.
- **Adaptive polling** (`PollPolicy`, D-1502): driver with offer 2 s, on trip 3 s, waiting 3 s, saver 6 s, paused 15 s, offline 30 s; passenger searching/approaching 3 s, in the car 5 s, ended 30 s. The passenger app (passenger-owned) already pauses while hidden and polls on becoming visible; the driver app keeps polling in the background (it must, for offers; the browser throttles it anyway, D-907). Parked drivers send a position every 15 s instead of every 4 s (enough to stay inside the 45 s matching window); moving drivers with every sync. Every action answers with the new state, so nobody waits for the next poll after acting.
- **DB writes per location sample**: latest-row upsert + availability mirror only; trail points only during a trip at most every 3 s / 25 m (geo's rule, kept); the jump-counter delete became a read (D-1505); heartbeat-only syncs write at most every 20 s.
- **Sessions** rewritten only when changed or a minute old (D-1506), sweeping lottery 1 in 1,000 (D-1507).
- **Lean poll answers** (D-1506): no XSRF cookie, no unchanged session cookie, no CSP on JSON.
- **ETag effectiveness** (D-1504): a driver's own feed version no longer moves with positions.

### 1.3 Sustainable numbers on this machine (sync protocol)

| Run (90 s, 100 rides requested at once) | rps | sync p50 / p95 | Verdict |
|---|---|---|---|
| SQLite, 4 workers, 500 drivers | 184 | 12 / 95 ms | comfortable |
| SQLite, 4 workers, 500 drivers, `CACHE_LIMITER=file` | 184 | 12 / 53 ms | comfortable, a third fewer statements |
| SQLite, 2 workers, 500 drivers | 180 | 13 / 634 ms | at the knee |
| SQLite, 4 workers, 1,000 drivers | 330 | 31 / 688 ms | at the knee (saturation ~370 rps) |
| PostgreSQL, 4 workers, 500 drivers | 178 | 21 / 866 ms | knee during bursts |
| PostgreSQL, 4 workers, 1,000 drivers | 201 | 2,116 / 3,288 ms | saturated: drivers got 11 polls a minute instead of 20; no errors; 97 of 100 trips finished in the window, invariants hold |
| PostgreSQL, 4 workers, 500 drivers, old driver protocol (feed + location + heartbeat), today's server | 204 | feed 2,219 / 3,801 ms | saturated: **0 of 100 trips completed in 90 s** (all assigned, stuck behind the queue); no errors |

A worker on this machine serves about 90 requests a second on SQLite (10.8 ms per request, bootstrap included) and about 50 on PostgreSQL over TCP (19 ms; 19 statements at ~0.4 ms round trip each).

### 1.4 What the current host supports (estimate)

On cPanel the ceiling is the account's CPU allowance (CloudLinux LVE: 100 % = one core), not the FPM worker count: workers beyond cores only hide database waits. Assumptions, stated so they can be replaced by a measurement on the host (run `bench.sh` against a staging copy from any machine with Node): a shared server core is about 2x slower than an M4 core for PHP, PostgreSQL on the same server over a socket, 70 % target utilisation (headroom for bursts such as many requests at once), one sync every 3 s per online driver plus a passenger poll every 3-5 s for each live ride (~0.36 requests/s per online driver at 50 % of drivers on trips).

| CPU allowance | Requests/s at 70 % (~35 ms each) | Online drivers supported, after | Before (legacy protocol, ~0.9 req/s per driver) |
|---|---|---|---|
| 1 core | ~20 | **~55** | ~22 |
| 2 cores | ~40 | **~110** | ~44 |
| 4 cores | ~80 | **~220** | ~88 |

`CACHE_LIMITER=file` and `php artisan optimize` (section 1.5) add about a quarter on top (measured on PostgreSQL at saturation: 201 -> 255 rps). These are estimates from measured per-request costs, not a measurement on the host.

### 1.5 What would raise it (not built: owner decisions)

| Change | Effect (measured or reasoned) |
|---|---|
| `CACHE_LIMITER=file` (config only) | sync 19.1 -> 12.9 statements, 2.4 -> 1.2 writes (measured). Recommended now. |
| `php artisan optimize` at deploy (config, route, event caches) | SQLite, 4 workers, 1,000 drivers: sync p50 31 -> 9 ms, p95 688 -> 360 ms at the same 330 rps (measured). Confirm the deploy runs it. |
| Both (`optimize` + `CACHE_LIMITER=file`) | PostgreSQL, 4 workers, 1,000 drivers (saturated either way): 201 -> 255 rps, sync statements 19.6 -> 13, writes 2.7 -> 1.2, drivers polled 14 instead of 11 times a minute, p50 2.1 -> 1.0 s (measured). |
| Redis for cache, limiter and sessions (a VPS or a host add-on) | removes the session read, limiter and dispatch-lock statements from the database: ~8 of 13 statements per idle poll; frees the database for rides. |
| Reverb (WebSockets) on a VPS | drivers and passengers stop polling: a driver sends only positions (every 4 s moving, 15 s parked) and receives pushes; the dispatch tick would move to a scheduler loop. Roughly 5x fewer requests per online driver. Broadcast events already exist (`EHAIL_BROADCAST_RIDE_EVENTS`). |
| A VPS with PHP-FPM sized to its cores | removes the LVE ceiling; the M4 numbers above scale with cores. |
| Gzip on JSON at the web server (Apache mod_deflate) | the ops live feed is 110 KB at 500 drivers (212 KB at 1,000), sent every 10 s per operator; compression cuts that by ~85 %. Polls are too small to matter. |

## 2. Protocol reference (for the apps)

`POST /api/ehail/driver/sync` (auth, `throttle:ehail-driver-sync` 90/min): body `{samples?: [{lat, lng, accuracy, speed?, heading?, recorded_at(ms)}] (0..20), saver?: bool}`, header `If-None-Match: "v<version>"`. Answer 200 `{version, poll_after_s, feed: <driver feed>|null, location: {accepted, rejected, on_trip}|{error: not_online}|null}`, headers `ETag`, `Retry-After`, `Cache-Control: private, no-store`. Validation 422 `{error: validation_failed, message, errors}`. The driver console (`resources/js/driver/drive.js`) uses it when the boot data has `urls.sync` and falls back to the old requests otherwise; `LocationThrottler::takeForSync` decides which fixes ride along (tested).

## 3. Concurrency and correctness under load

### 3.1 Invariants (`App\Domain\Perf\SystemInvariants`, `php scripts/load/world.php invariants`)

No driver with two active rides; no ride with more accepted offers than assignments; holding rides agree with `active_driver_id` and with dispatch's availability row (both directions); one live ride per passenger; one pending offer per ride and per driver; at most one paid fare per ride; every ledger journal balances; every ride's last timeline state equals its status; every moved ride has a timeline; every completed ride has started and completed events.

### 3.2 Scenarios and results

| Scenario | Engine | Result |
|---|---|---|
| 500 online drivers, 100 ride requests in the same second, offer races (20 % double-taps from two devices), declines, full trips | SQLite and PostgreSQL | all 100 assigned, started (PIN) and completed; 0 server errors; invariants hold after the queue ran (100 trip journals balanced). Double taps: both taps answered 200 with the same ride (idempotent), never two assignments. |
| 1,000 drivers, same | SQLite, PostgreSQL | SQLite: 99 rides (one passenger's request answered 409 `conflict_retry` under lock contention, as designed) all completed; PostgreSQL (saturated): 100 assigned, 97 completed in the window, the other 3 still on their trips; 0 server errors; invariants hold on both. |
| Double ticks (two `ehail:dispatch-tick` processes at once every 3 s, on top of every poll's tick) + routing provider down for 20 s during the trips + database refusing connections for 10 s (PostgreSQL: `ALLOW_CONNECTIONS false` and in-flight sessions terminated; SQLite: the file unreadable) | SQLite, PostgreSQL | all 100 rides assigned, started and completed; during the 10 s outage the apps got 1,000 (sync) and ~160 (passenger feed) `503 database_unavailable` answers, each backing off for the 5 s Retry-After; no 500, no other error; invariants hold (no double assignment from the double ticks, ledger balanced, timelines complete); the routing outage produced no error (ETAs fell back to the labelled straight-line estimate). |
| Webhook burst with duplicates: six identical `charge.success` deliveries from six processes at the same instant | SQLite file and PostgreSQL (`WebhookBurstConcurrencyTest`) | one event row, one payment transition, ledger balanced, every delivery acknowledged |
| Payment gateway timing out (and its verify endpoint failing) for three completed card trips, then recovering; every success webhook delivered five times; `money:sweep` twice | both (`ReliabilityScenariosTest`) | nothing claimed paid while the gateway was silent; afterwards each ride paid exactly once; 3 webhook events for 15 deliveries; invariants hold |
| Routing provider outage during quoting and during a trip | both | quote refused with the honest reason (no straight-line price); the passenger's driver ETA switches to the labelled straight-line estimate; the trip completes, final fare never above the agreed one, charged once |
| Queue backlog: 1,000 email deliveries (1,000 messages routed with push and email copies) worked by one cron minute | SQLite | `queue:work --stop-when-empty --max-time=50` finished them in **7.0 s** (to a local SMTP sink); `ehail:comms-deliver` now drains up to 45 s of batches per run (D-1509; tested with 130 rows over a 100 batch). With a real SMTP server at ~50 ms a message, 1,000 emails need about one cron minute; nothing is lost if they need two (rows are the queue). |
| Temporary database loss | both | apps get 503 `{"error":"database_unavailable","message":"Dot.Ehail cannot reach its database for a moment. Nothing you did is lost; this screen tries again by itself."}` with `Retry-After: 5`; pages show the 503 page (D-1510). Before: a generic 500. |

Forked-process races already in the suite (dispatch `ConcurrencyTest`: accept vs board claim, double tap, one driver two rides, concurrent ticks, double request with one key) pass on PostgreSQL. The fleet assignment race hung the PostgreSQL run and hid a real PostgreSQL bug; both fixed (D-1513).

## 4. Front end on a throttled mid-range Android profile

{{FRONTEND}}

## 5. Server hot paths at volume

`scripts/load/hotpaths.sh <sqlite|pgsql> <outdir>`: 2,000 drivers (600 online with fresh positions), 3,000 passengers, 10,000 rides over 60 days with 64,000 timeline events and 9,250 offers, 50,000 trip points (half past the 90-day retention), 25,600 ledger entries, 30 trips in progress and 20 rides searching. Medians of 15 runs; endpoints through the full HTTP stack (database session, rate limiter, middleware), the rest at the domain call the page makes.

| Hot path | Statements (writes) | SQLite median | PostgreSQL median | Before the fixes (SQLite) |
|---|---|---|---|---|
| driver sync, idle (no position), full HTTP stack | 16 (2: limiter counter, global tick lock) | 2.0 ms | 6.7 ms | n/a (new) |
| driver sync on a trip, one position | 30 (5: latest row, availability, trip point, limiter, lock) | 4.6 ms | 13.5 ms | |
| driver feed (old GET) | 15 (2) | 1.7 ms | 6.5 ms | |
| passenger feed, trip in progress | 17 (2) | 2.3 ms | 7.0 ms | |
| quote, one category (routing stub, HTTP) | 24 (4) | 53 ms | 53 ms | |
| compare, every category | 45 (10) | 50 ms | 65 ms | 69 statements, 270 ms |
| categories available at a point | 31 (2) | 49 ms | 68 ms | 55 statements, 271 ms |
| nearby drivers, 4 km of 600 online | 1 | 2.4 ms | 6.0 ms | |
| dispatch tick, 20 searching rides (20 offers made) | 748 (180), 37 a ride | 197 ms | 483 ms | 1,946 statements (97 a ride), 226 ms |
| ops live board payload (600 online, 50 live rides) | 9 | 79 ms | 86 ms | |
| ops insights, uncached (30 days, 10k rides; the page caches it) | 16 (1) | 118 ms | 237 ms | PostgreSQL 1,871 ms (revenue join without an index, D-1516) |
| ops status (health report) | 17 | 1.2 ms | 5.0 ms | |
| retention: `ehail:geo-purge`, 25,000 old points of 500 trips | | 2.1 s | 2.4 s | |

Timings in this final pass were taken while the 30-minute browser soak (section 4) ran on the same machine, so they are a little higher than in a quiet run (the first quiet pass: tick 130 ms, compare 118 ms, sync 1.6 ms). Statement counts do not depend on load.

The tick's remaining cost per ride is mostly the road ETA for ranking: dispatch asks the router for the five closest candidates (`EHAIL_ETA_ROUTER_MAX_CANDIDATES`, 400 ms budget); with a paid routing provider that is up to five billable route calls per offer round, because a moving driver's position rarely hits the 10-minute route cache. Set it to 1 or 2 to cut provider cost (the straight-line rank is labelled and already used as the fallback); left at dispatch's default here (owner decision).

Fixes from this table (D-1511, D-1512): the dispatch tick read the category, its vehicle rules and each candidate's profile once per candidate (3 statements per candidate, 60 of a ~97-statement tick); category availability (the booking screen's "which categories have drivers") re-read and re-checked every nearby driver for every category. Budgets pinned by `HotPathBudgetTest` (statements must not grow with drivers or categories; a tick's only per-candidate cost is the road ETA for at most five candidates, already capped by dispatch) and `RequestCostBudgetTest` (an idle sync runs at most 11 statements and writes nothing; a moving one at most 19 statements and 4 writes; the session row is rewritten at most once a minute while polling; poll answers carry no cookie or CSP overhead; the passenger feed asks the router at most twice a minute; feed polls never touch the ride row).

Indexes: every hot query above runs on an existing index (`rides.active_driver_id`/`active_passenger_id` unique, `ride_offers.pending_*` unique and `driver_id`, `driver_locations` lat/lng, `ride_events (ride_id, sequence)` unique, `notification_deliveries (status, next_attempt_at)`, `sessions.last_activity`); no new index was needed at this volume, so none was added. Caching where safe: category + rules (10 s per process, D-1511); rule-set resolution is already a single indexed query per category (7 statements for a full compare, 0.5 ms); the passenger ETA (D-1503). Retention: `ehail:geo-purge` summarised and deleted 25,000 trip points of 500 trips in 1.4 s.

## 6. Monitoring and alerts

| Surface | What |
|---|---|
| `php artisan ehail:health [--json] [--alert]` | Exit 0 ok, 1 degraded, 2 failing. Checks: database (latency), scheduler (last `schedule:run`), dispatch (last `ehail:dispatch-tick` and searching rides nobody moved for a minute), queue (pending jobs and the oldest one's lag; database queue), failed jobs (24 h), drivers (online vs not heard from within 45 s), payment webhooks (unprocessed and their lag, failed in 24 h), notifications (due and failed deliveries), routing and geocoding (provider, circuit open or closed; "off" when not configured). The cron records its own heartbeat (`CommandFinished` for `schedule:run` and `ehail:dispatch-tick`), so a stopped cron is visible. |
| Alerts | Scheduled every five minutes with `--alert`: emails `EHAIL_HEALTH_ALERT_EMAIL` once when a check gets worse, again every 30 minutes while it stays bad, and once when it recovers. No address: says so and sends nothing. Mailer `log`/`array`: says the alert was only logged. |
| `GET /ops/status` | The same report as JSON for any operator (`ops.status`, permission `*`), computed at most every 15 s: the data source for an operations status panel. No panel was built (ops screens are ops-owned). |
| `/up/realtime` | Unauthenticated, `{"status": "ok"|"degraded", "mode": "polling"|"websocket"}`. Polling mode (the shared host): database answers and the dispatch tick ran within 10 minutes. Websocket mode (Reverb configured): the original broadcasting, queue and reverb:start chain. Details only in the log. |
| `/up` | Laravel's boot check, unchanged. |

Thresholds (`config/ehail_perf.php`, env overridable): queue lag 120 s degraded / 600 s failing; failed jobs 1 / 25 a day; dispatch tick 150 s / 600 s; scheduler 150 s / 600 s; silent drivers 25 % / 60 % of at least 5 online; webhook lag 15 min / 1 h, any failed webhook degraded; notification failures 5 / 50 a day, 200 due degraded; database latency over 500 ms degraded.

## 7. Tests

{{TESTS}}

## 8. Changes in other owners' files (small, focused, each tested)

| File | Change | Why |
|---|---|---|
| `app/Domain/Dispatch/RideFeed.php` (dispatch) | poll hints from `PollPolicy`; `forDriver` returns `poll`; reusable passenger ETA | D-1502, D-1503 |
| `app/Domain/Dispatch/Models/DriverAvailability.php` | version moves only for non-presence changes | D-1504 |
| `app/Domain/Dispatch/Dispatcher.php` | candidates' profiles in one query | D-1511 |
| `app/Http/Controllers/Ehail/Dispatch/DriverDispatchController.php` | feed uses the policy hint | D-1502 |
| `app/Domain/Geo/Locations/LocationStore.php` (geo) | jump counter read before delete | D-1505 |
| `app/Domain/Integration/Dispatch/CatalogCategoryEligibility.php`, `Integration/Pricing/DispatchSupplyCounter.php` (integration) | category cache; supply once per point, grouped | D-1511, D-1512 |
| `app/Domain/Comms/Console/DeliverCommand.php` (comms) | time-boxed batches | D-1509 |
| `app/Http/Controllers/RealtimeHealthController.php` | polling mode | D-1508 |
| `app/Domain/Ops/Access/RoutePermissions.php` (ops) | `ops.status => *` | D-1514 |
| `app/Domain/Fleet/VehicleAssignments.php`, `tests/Feature/Fleet/AssignmentConcurrencyTest.php` (fleet) | driver row lock; one connection per contender | D-1513 |
| `app/Http/Controllers/Driver/HomeController.php`, `resources/js/driver/{drive,throttle}.js` (driver) | `urls.sync`, parked interval, sync loop | D-1501, D-1502 |
| `config/cache.php`, `config/session.php`, `config/database.php` | limiter store, lottery, SQLite pragmas from env | D-1507 |
| shared | `bootstrap/providers.php` one line, `routes/web.php` one line | |

## 9. Limits (honest)

- Capacity on the host is estimated from measured per-request costs (section 1.4); it has not been measured on the host.
- The built-in server stands in for PHP-FPM; Apache and FPM add their own small costs (connection handling, FastCGI).
- Data usage counts HTTP bytes (headers and bodies); TLS records and TCP add roughly 5-10 % on top and are not measured here.
- `CACHE_LIMITER=file` is a recommendation, not the default: changing the default store is the deploy owner's call (the file store's counters are not atomic; throttling can only become slightly lenient under races).
- The ops live feed (110 KB per 10 s at 500 drivers) was measured, not changed: it is ops-owned and needs either compression at the web server or a smaller payload.
- No status panel UI was built (data source only); no Redis, Reverb or VPS was set up.
