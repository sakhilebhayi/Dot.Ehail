# R-46 Dot.Ehail: final implementation report

Owner brief: "Dot.Ehail — World-Class Mobility & Ride-Hailing Platform Transformation" (2026-11-10). Branch `feat/r46-ehail`, merged to `main` by the PR named in section 17. This report follows the brief's section 26 and separates what is **complete**, what is **partial**, and what is **future work**. Every claim below points at the document, test or check that shows it. Nothing here is a projection presented as a result.

Detail lives in the area documents: `R46_ARCHITECTURE.md` (contract), `R46_AUDIT.md`, `R46_RESEARCH.md`, `R46_DECISIONS.md` (about 340 logged decisions), `R46_DS.md`, `R46_GEO.md`, `R46_PRICING.md`, `R46_DISPATCH.md`, `R46_SAFETY.md`, `R46_MONEY.md`, `R46_MONEY_POLISH.md`, `R46_INTEGRATION.md`, `R46_COMMS.md`, `R46_FLEET.md`, `R46_BUSINESS.md`, `R46_UI_PASSENGER.md`, `R46_UI_DRIVER.md`, `R46_UI_OPS.md`, `R46_SECURITY.md`, `R46_PRIVACY.md`, `R46_PERF.md`, `R46_A11Y.md`, `R46_QA.md`, `R46_SKILLS.md`, `R46_OPERATIONS.md` (owner runbook), `DESIGN.md`.

## 1. Audit (brief section 1)

`R46_AUDIT.md` classifies every area as found. In short: Dot.Ehail was a booking form with optional raw latitude and longitude fields, straight-line (haversine) float fares from a config file, a pull-based "available rides" list any online driver could take, a sound lifecycle enum with row locks and an audit log, Paystack checkout with a verified webhook, ratings, fleets, documents and vehicles. There was **no** safety layer, support, scheduling, business accounts, driver earnings or payouts, maps or routing, and real-time updates depended on Reverb, which the shared cPanel host cannot run (`/up/realtime` answered "degraded"). Baseline: 603 tests (596 pass, 7 skipped).

Preserved: sign-in through InfoDot, teams, the lifecycle's locking and audit approach, the Paystack webhook verification, encrypted driver identifiers, fleets, documents, vehicles, ratings, operator actions, the deploy pipeline, and every existing row (all migrations are additive; the ride status enum was extended, with a data migration for pre-R-46 values).

## 2. Competitive research (brief section 2)

`R46_RESEARCH.md`, desk research only (no access to any platform's code or data; unverified claims are marked). It covers Uber, Bolt, inDrive, DiDi, Lyft and Grab on matching, pricing, commission, safety and UX, plus South African specifics (ZAR, tolls, airport pickup rules, VAT section 12(g), the National Land Transport Amendment Act). What the build took from it:

- **Pricing:** upfront itemised quotes, re-pricing only for passenger changes or large overruns, demand pricing off by default and always its own line, and no airport fee until a documented airport charge exists.
- **Drivers:** before accepting, the driver sees the destination, the fare, the commission and their net. Commission is published (20 % by default, set by the owner).
- **Dispatch:** sequential offers with a 15-second timer, explicit and capped fairness terms, no hidden acceptance-rate punishment, and no fastest-finger broadcast while driving.
- **Safety:** an SOS that never claims an emergency dispatch it cannot make, a "what we did / what we did not do" record, and the South African emergency numbers (10111, 10177, 112).

## 3. Passenger experience (brief section 4)

**Complete** (`R46_UI_PASSENGER.md`; browser journey in `R46_QA.md` section 3.1):

- **Booking:** a map-first booking home with one "Where to?" field, a pickup chip from geolocation, recent places and keyboard-accessible address search. Category rows show only the categories that fit the trip, and each has a line-by-line fare breakdown that adds up to the stored quote. The day-and-time picker works on the service area's clock (SAST) whatever zone the phone is set to.
- **Live trip:** card, cash where enabled, or a business account. Driver identity (photo, car, plate chip) comes before the PIN, followed by live tracking with an honest "last position N s ago" when the driver's phone goes quiet.
- **After the trip:** a trip summary, a branded receipt with the operator's identity and a link back to the trip, ratings, a trip list, help and support cases, and an account page with "Download my data".
- **Public pages:** the fare calculator at `/fares` prices with nobody online and says a driver is not guaranteed.
- **Safety centre:** trusted contacts, trip sharing (a Route Line share page with a live map, area-level only, plus a WhatsApp link) and SOS.

**Partial:** checked in desktop Chrome with an emulated phone and GPS, not on a real phone. Native apps are not built: the passenger and driver apps are installable web apps (manifest, service worker, offline page).

## 4. Driver experience (brief section 4)

**Complete** (`R46_UI_DRIVER.md`; `R46_QA.md` section 3.2):

- **Onboarding:** apply to a fleet, then upload documents through the app's picker (11 South African document types with expiry). A reviewer approves each document in `/ops`, and the vehicle is approved. Going online is refused with the reason until every required document is current.
- **The offer card:** Accept, Decline and the countdown stay on screen at 360 to 430 px wide and in landscape. The card shows the pickup distance and ETA (and the method), the trip distance, the destination, the payment method, the passenger's first name and rating band, and an earnings walk that adds up: fare, each line, commission with its base, then net.
- **The trip:** a night console, navigation handed off to the phone's maps app, the PIN keypad and a trip summary.
- **Earnings and payouts:** earnings tie to the ledger, there are statements, and payouts show with their trips.
- **Offer alerts:** sound, vibrate, a test sound, a wake lock, and honest wording about background limits.

**Partial:** the wake lock is refused by headless Chrome (the screen says so); there has been no real-phone test. Turn-by-turn navigation is deliberately handed to Google Maps or Waze, not built.

## 5. Pricing (brief sections 5 and 6)

**Complete** (`R46_PRICING.md`, `R46_MONEY_POLISH.md`):

- **Money and rule sets:** money is held as integer minor units (a `Money` value object). Pricing rule sets are versioned and immutable once published, edited in `/ops/tariffs`, and tried in a simulator before publishing.
- **Quotes:** quotes are signed and expire after 5 minutes. The server recomputes every price; a client-sent total is ignored, and a test proves it.
- **Fare lines:** every metered line bills the quantity it prints (distance in tenths of a kilometre, time in tenths of a minute).
- **Final fare:** the quoted fare stands within a tolerance (25 % by default). A destination change re-prices with its reason shown on the trip and on the receipt. Waiting, cancellation and no-show fees are rule-set values.
- **Commission and supply:** commission is configurable, and the driver sees it before accepting. A price never depends on who is online; asking for a ride now with nobody nearby answers 409 `no_supply`, while scheduled rides are not blocked.
- **Categories:** Economy, Standard, Premium, XL, Airport, Long distance and Accessible, each offered only where the trip fits it.

**Not shipped live, by design:** a real tariff. The example tariff is a placeholder, installed only on request (`install_example_tariff=install`), and the owner publishes the real prices (section 16). **Distance is never billed in a straight line:** without a configured road-routing provider the booking says "Route pricing is unavailable" and nothing can be booked (section 7).

## 6. Safety (brief section 7)

**Complete** (`R46_SAFETY.md`; browser journey in `R46_QA.md` section 3.3):

- **Driver verification:** per-market document types, a review queue with reasons, expiry warnings and automatic expiry, which takes the driver offline.
- **Trip safety:** the trip PIN locks after five wrong tries. Trip sharing works with trusted contacts confirmed by an emailed link, and check-ins prompt on long stops and route deviation.
- **SOS:** a 3-second window, then an incident with the ride state, registration and driver position. Trusted contacts get email and SMS (when a provider is set up), operations get an in-app alert, and an SOS takes over the live board with a red marker and an opt-in alarm.
- **Escalation:** unacknowledged incidents escalate on timers to on-call and then manager. The incident page leads with owner and escalation, and has one audited "Acknowledge and take ownership" action.
- **What the passenger sees afterwards:** what was done, what was not done, the emergency numbers, and never a claim that police were called.
- **Driver safety:** private reports, driver SOS, and cancellation-pattern flags for review only.

**Partial:** the SMS adapter (BulkSMS) was tested only against its documented API with faked HTTP. Uploaded files are not virus-scanned, because the host has no scanner; reviewers see that note.

## 7. Dispatch, maps and real-time (brief sections 8 and 9)

**Complete** (`R46_DISPATCH.md`, `R46_GEO.md`, `R46_PERF.md`):

- **Ride state machine:** 15 states with row locks, an append-only `ride_events` table, database unique guards against double assignment, and idempotency keys on ride requests. The E2E suite proves "two drivers accept at once, exactly one holds the ride" and "double tap makes one ride".
- **Offers:** sequential offers ranked by pickup ETA, with capped fairness terms (idle credit, trip balance, decline cooldown), each logged per offer. An open board is a fallback after 45 s, without sound.
- **Dispatch timing:** dispatch advances on every poll and from a per-minute `ehail:dispatch-tick`, because the host allows no daemon.
- **Real-time:** short polling of versioned feeds with ETag/304 answers. The driver sends one combined sync request (about 19 a minute instead of about 50, measured).
- **Maps:** Leaflet with OpenStreetMap tiles, and server-side address search (Nominatim) with country and position bias.
- **Routing:** behind five provider drivers (Google, openrouteservice, Mapbox, OSRM, Valhalla), with a cache and a circuit breaker. Service areas and airport zones are installed as approximate outlines for the owner to correct.

**Owner-dependent:** routing is **off** until the owner sets a provider key, so no ride can be priced or booked until then. That is the honest state, and preflight says so. OpenStreetMap's tile server has no SLA, so a tile provider should be contracted before traffic grows.

**Future:** batched (global) matching, WebSockets on a VPS, and Redis. `R46_PERF.md` section 1.5 measures what each would buy.

## 8. Payments (brief section 10)

**Complete** (`R46_MONEY.md`):

- **Card capture and charging:** Paystack card setup with an R1 check that is refunded, then a charge after the trip from the saved authorisation.
- **Webhooks:** stored once, processed by a queued job, confirmed with Paystack's verify endpoint, and de-duplicated.
- **Failed charges and refunds:** failed charges are retried, then a "payment due" state blocks new requests until paid. Refunds are supported.
- **Cash:** optional, with commission owed netted from payouts.
- **Ledger:** a double-entry immutable ledger, where every journal balances and is checked by system invariants under load.
- **Documents:** receipts and business statements carry the operator's identity from configuration.
- **Settlement safety net:** QA found a High defect where a completed ride could stay unpaid (D-1607). It is fixed, and the `ehail:settlement-sweep` re-queues any never-settled ride every 5 minutes and raises a critical finance issue.

**Owner-dependent:**
- Live Paystack keys need a second switch (`PAYSTACK_ALLOW_LIVE=true`).
- Payouts are a manual CSV batch marked paid in `/ops`; Paystack Transfers is built but off by default.
- VAT treatment needs the owner's accountant (section 12(g)).

## 9. Fleet and business (brief sections 13 to 15)

**Complete** (`R46_FLEET.md`, `R46_BUSINESS.md`):

- **Fleet portal:** drivers, vehicles, driver-vehicle custody, maintenance that blocks going online, earnings from the ledger, alerts, and permission scopes.
- **Business accounts:**
  - Roles, departments, budgets and approvals enforced when a ride is requested.
  - Guests ride on the account.
  - Monthly statements with EFT details from configuration.
  - Spend that matches the department budget lines.

**Partial:** Dot.Finance is not integrated (documented seams only).

## 10. Operations command centre (brief section 18)

**Complete** (`R46_UI_OPS.md`): live board (rides, drivers, SOS takeover), rides and timelines in people's names and SAST, verification queue, safety incidents, support cases, finance (ledger, payouts, finance issues, reconciliation), tariffs and simulator, commission, promotions, service areas, insights (real data only, metric definitions in plain words), team and roles. Eight operator roles with a route-by-route permission matrix; a test fails if any new route is not classified.

## 11. Notifications and support (brief sections 16 and 17)

**Complete** (`R46_COMMS.md`): the in-app inbox (unread count per ride, badge stops at "9+"), email, Web Push implemented without dependencies, SMS for safety alerts, a delivery log, and honest "not delivered" states when a channel is not configured. Support cases come with messages, attachments, service levels, escalation, lost-property relay with masked contact, and fare disputes.

## 12. Design and the skills actually used (brief section 3)

Identity "Route Line": Barlow, sign green and road-marking yellow, a night theme for drivers, the `--eh-*` tokens and the `<x-eh.*>` component kit (`DESIGN.md`, linted with `@google/design.md`: 0 errors). `R46_SKILLS.md` records every agent's skill use. Invoked and applied:

| Skill | Applied |
|---|---|
| `impeccable` | Every UI agent, inside the locked system |
| `design-taste-frontend` | Every UI agent; the ds agent ran its page and app draws |
| `hallmark` | The landing page |
| `design-md` | `DESIGN.md` |
| `dataviz` | Ops insights and ledger charts |
| `security-audit` | The security review |

`awesome-design-md` and `open-design` were read as reference only. Motion is plain CSS and 1 KB of JS, so no `gsap-*` skill was needed.

**Unavailable and not claimed:** `ui-ux-pro-max` and `emil-design-eng` are dangling symlinks on this machine.

**Jury:** two blind critics scored 178 screenshots 6.1 and 6.0 and listed 18 defects (money that did not add up, unlabelled UTC times, the offer card cut off, coordinates shown to people, unbranded receipt and share page, the SOS buried on the live board, and more). All 18 were addressed in the polish round (driver, ops, money and passenger branches; decisions D-1900 to D-2211). **No second jury was run** after the polish, so the improvement is not scored.

## 13. Backend and Laravel Boost (brief section 3)

Laravel 13 with domain folders under `app/Domain/*`. Cross-domain seams are interfaces bound with safe defaults, and `EhailIntegrationServiceProvider` (registered last) binds the real implementations. Errors follow one JSON policy (`{error, message}`), the API lives under `/api/ehail/`, and 43 migrations, all additive, cover the change. Laravel Boost is installed (`laravel/boost` 2.5), and its project guidelines in `CLAUDE.md` were followed by every agent: sibling conventions, Pint, feature tests. No agent recorded using the Boost MCP tools, so this report does not claim them.

## 14. Security and privacy (brief sections 20 and 21)

**Complete:**

- **Review:** `R46_SECURITY.md` records 34 findings. Every finding of medium severity or higher is fixed with a test; the High one was an email change that kept verification and could take over a business invitation.
- **Access control:** operator access was narrowed to roles. ID and licence numbers are masked except for the driver and the verification reviewer.
- **CSP:** an enforced nonce CSP, with geolocation allowed only on the pages that need it.
- **Rate limits** are registered in providers, never in route files.

Low findings accepted or left to the owner are listed in section 2 of that file, for example fee avoidance by switching to cash before a late cancellation, and a cap on scheduled rides.

**POPIA:** `R46_PRIVACY.md` records the personal-information inventory, purposes, a retention schedule implemented as purge jobs, account closure, data subject access ("Download my data"), operator access logging and cross-border processors. The owner must name the information officer.

## 15. Performance, accessibility and tests (brief sections 22 to 24)

- **Performance** (`R46_PERF.md`, measured on this machine, estimated for the host):
  - The driver sync protocol cut requests per online driver from about 50 to about 19 a minute, and data per hour from 6.3 MB to about 1 MB.
  - At 500 drivers plus 100 rides, sync p50 was 12 ms and p95 95 ms (the old protocol saturated).
  - System invariants held under load on SQLite and PostgreSQL.
  - The estimated host capacity is about 55 online drivers per CPU core. This is an estimate, not a measurement on the host.
- **Accessibility** (`R46_A11Y.md`): page states with a serious or critical axe violation went from 552 to 0. Keyboard, mobile and reduced-motion checks pass, and the driver light theme meets AAA contrast.
- **Tests, on the merged trunk:**

| Suite | Result |
|---|---|
| PHP on SQLite | 1,501 tests, 1,493 passed, 8 skipped, 0 failed |
| PHP on PostgreSQL 16 (throwaway database, `bin/test-pgsql.sh`) | 1,501 tests, 1,494 passed, 7 skipped, 0 failed |
| JavaScript | 122 of 122 |
| Build | Vite build and `view:cache` clean |
| Browser end-to-end (`R46_QA.md`, real Chrome, local stubs for routing, search, Paystack, SMS and SMTP, and a proxy that fails the run if anything reaches the internet) | **16 of 16 pass** on the merged code (final full run, 2026-11-10): every journey, every failure scenario, and the route sweep (191 GET routes as 15 roles at 390 and 1440 px, 0 problems) |

The browser suite covers passenger steps 1 to 14, driver steps 1 to 14, safety 1 to 6, pricing 1 to 7, ten failure scenarios, and a sweep of 189 routes for every role at 390 and 1440 px.

## 16. Limitations, risks and owner steps

Until the owner completes steps 1 to 5 of `R46_OPERATIONS.md` section 1, the live site is up and safe but **nobody can book a ride**. That is intended, and `php artisan ehail:preflight` lists what is missing. In order:

1. **Routing provider key** (Google Routes or openrouteservice). This is the most important step: no route, no price.
2. **Tile provider** before traffic grows (`EHAIL_TILES_URL`).
3. **Operator account and roles:** `php artisan ehail:operator you@...`, then give each person the narrowest role in `/ops/team`.
4. **Publish a real tariff** in `/ops/tariffs`, and set the commission.
5. **A way to pay:**
   - Set the Paystack webhook URL to `https://ehail.infodot.co.za/webhooks/paystack`.
   - Run one full ride on the test keys first, then switch to live keys with `PAYSTACK_ALLOW_LIVE=true`. Cash is optional.
6. **Approve existing drivers' documents.** The verification gate blocks every driver, including those approved before R-46, until their documents are approved.
7. **Mail, SMS and push:**
   - A real SMTP transport.
   - BulkSMS tokens.
   - VAPID keys (`php artisan ehail:comms-vapid-keys`; never change them later).
8. **Safety escalation contacts:** `EHAIL_SAFETY_ONCALL_*` and `EHAIL_SAFETY_MANAGER_*`.
9. **Operator identity on money documents:**
   - `EHAIL_OPERATOR_*`, `EHAIL_VAT_REGISTERED`, `EHAIL_SUPPORT_EMAIL` and the EFT details.
   - Until set, documents show the app name only.
10. **Host settings:** `CACHE_LIMITER=file`, `EHAIL_HEALTH_ALERT_EMAIL`, and backups with a restore drill (section 2 of the runbook).
11. **Business decisions:**
    - Name the information officer (POPIA).
    - Have your accountant settle VAT status.
    - Confirm the commission rate.
    - Correct the approximate service areas in `/ops/areas`.

**Risks:**

| Risk | Detail |
|---|---|
| Shared host capacity | About 55 online drivers per core (estimate). A VPS with Redis and WebSockets is the path past that (`R46_PERF.md` 1.5). |
| Untested on real phones | GPS, background tabs, the wake lock and push have only been tried in desktop Chrome with emulation. |
| SMS untested end to end | The SMS adapter has only been tested against its documented API. |
| Approximate service areas | The installed outlines are approximate until the owner corrects them. |
| Regulation | Operating-licence requirements under the National Land Transport Amendment Act are the owner's legal step. |

## 17. Deployment

Merged to `main` as `38fd725` ([PR #33](https://github.com/sakhilebhayi/Dot.Ehail/pull/33)) and deployed on 2026-11-10 by `deploy.yml` (`confirm=deploy`), run [42861943395](https://github.com/sakhilebhayi/Dot.Ehail/actions/runs/42861943395): **success**.

**What the run did:**

1. **CI gate:** green on SQLite and PostgreSQL 16, JS, the upgrade rehearsal on a populated pre-R-46 database, and the signed-in production-like smoke check.
2. **Before maintenance mode:** a rollback copy (`dot-ehail-deploy-20261110T171403Z.tgz`) and a database restore point (`dot-ehail-db-deploy-20261110T171403Z.sqlite`, integrity ok).
3. **Release:** code moved from `d596a97` to `38fd725`; all migrations ran (43 applied, none pending), including the ride status migration to state machine v2.
4. **Reference data:** 4 approximate service areas and 3 airport zones created.
5. **Caches and schedule:** caches rebuilt and the 18 scheduled tasks registered.
6. **Preflight:** `Preflight OK: 36 pass, 5 info, 4 off, 13 warning(s), 0 failure(s)`. The warnings are the owner steps in section 16.
7. **Go live:** site up, cron lines installed through the shared-crontab runner, smoke check passed, and nothing in the app folder is readable over HTTP.

**Checked live afterwards (from outside the host):**

- **Pages:** `/`, `/login`, `/up`, `/fares` and `/offline.html` answer 200.
- **Realtime:** `/up/realtime` answers `{"status":"ok","mode":"polling"}`. Before R-46 it answered "degraded".
- **Access:** `/ride` sends a guest to the login, and a bogus `/t/<token>` share link answers 404.
- **App shell:** `manifest.webmanifest` and `/sw.js` (as JavaScript) are served, the built CSS and JS answer 200, and the self-hosted Barlow fonts answer 200 as `font/woff2`.
- **Security headers:** HSTS, an enforced nonce CSP, nosniff, frame options, referrer policy and permissions policy are present.
- **In a browser:** `/fares` renders in the Route Line design with OpenStreetMap tiles and no console errors.

**Live state, honestly:**

- The site is up and safe, and preflight answers **"Can a passenger book? NO"**: there is no routing provider, no way to pay and no published tariff. That is intended until the owner completes section 16.
- The live database is **SQLite** (preflight warns: one writer at a time). PostgreSQL is what the platform is load-tested on; move before real traffic (`R46_OPERATIONS.md` section 6).
- Mail goes to the log, so no email is delivered yet.


## 18. Summary: complete, partial, future

| | Items |
|---|---|
| **Complete** | Every area in sections 3 to 15, tested as stated there; the jury's 18 defects addressed |
| **Partial** | Real-phone testing; SMS end to end; virus scanning (no scanner on the host); Dot.Finance integration (seams only); no second jury score |
| **Future** | Batched dispatch; WebSockets on a VPS; native apps; Paystack Transfers turned on; airport fees (when a documented charge exists); demand pricing (built, off) |
