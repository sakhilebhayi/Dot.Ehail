# R-46 operations command centre (`/ops`)

Owner: ops agent, branch `feat/r46-ops`, decisions D-1000 to D-1014. Code: `App\Domain\Ops\*` (access, live board, drivers, insights, audit, availability), `App\Http\Controllers\Ops\*`, `resources/views/ops/**`, `resources/js/ops/**`, `resources/css/ops.css`, `routes/ehail/ops.php`, `App\Providers\EhailOpsServiceProvider`, migration `2026_11_10_700000_create_ops_tables`. Brief section 18.

Reading of the brief (no question channel): a dense, desktop-first tool for people who work in it all day; usable on a 768 tablet; every number real and defined; every change goes through the domain that owns it.

## 1. Screens

| Screen | Route | What it answers | Actions (API they post to) |
|---|---|---|---|
| Live | `ops.live` (+ `ops.live.feed`, 5 s poll with ETag, 30 s hidden) | What needs me now: map of open rides and online drivers, figures, queues (needs review, stuck, waiting for a driver) | links into rides |
| Rides desk | `ops.rides` | Rides needing review, stuck, expired; fare payments needing an operator (unpaid, unmatched, waived); the pre-R-46 desk figures | waive (`operator.rides.waive`), payment reviewed (`operator.payments.review`) |
| All rides | `ops.rides?view=all` | Search by number, person, address, status, dates | |
| Ride | `ops.rides.show` | Route map (pickup, drop-off, planned route, driver's latest fix), the full `ride_events` timeline drawn as a route line, offers with ETA method and score, fare snapshot (quoted vs final, adjustments), payments, incidents, cases | resolve, reassign, flag for review, cancel (`ehail.dispatch.ops.*`); fare adjustment (`pricing.ops.rides.fare-adjustments.store`) |
| Drivers | `ops.drivers`, `ops.drivers.show` | Account and dispatch state, trips, factual stats (offers accepted, cancellations after accepting, rating band), earnings and payouts (money roles only), documents (reviewers only) | suspend, lift, decide appeal (ops) |
| Verification | `ops.verification` (driver, vehicle, applications), `ops.verification.show`, `ops.verification.expiry` | The review queue with waiting time, the document viewer (file streamed by the verification domain, every open logged), expiry dashboard | assign, approve (with expiry), reject, ask for resubmission (`safety.ops.verification.*`) |
| Safety | `ops.safety`, `.show`, `.check-ins`, `.reports`, `.patterns`, `.access` | Incidents by severity with escalation timers and ownership; one incident with trip context, "what we did" (every notification and its real outcome) and timeline; check-ins; reports; cancellation-pattern flags; who viewed what | acknowledge, assign, escalate, note, resolve, close, review report (`safety.ops.*`) |
| Support | `ops.support` (open, nobody yet, mine, SLA missed, lost property, closed), `ops.support.show` | Queue with first-reply and resolution timers; the thread with internal notes; linked ride, payment, incident | take, reply (with visibility, attachment), status with resolution code, assign, priority, escalate (`support.ops.cases.*`) |
| Pricing | `ops.pricing` (tariffs), `.show` (versions, draft editor), `.simulator`, `.promotions`, `.catalogue`, `.commission` | Live and draft tariffs per lineage, example-tariff warnings, version history | new draft, save draft, publish (confirmed, optional start), retire; simulate; promotions create/switch off; categories on/off; commission plans draft/publish/retire (`pricing.ops.*`) |
| Service areas | `ops.zones` | Areas and zones on the map | draw, edit corners, add zone (kind, fee code), switch on/off (ops, writing geo models) |
| Finance | `ops.finance` (payments), `.payment`, `.payouts`, `.batch`, `.reconciliation`, `.ledger` | Payments by status and reference; one payment with refunds and ledger postings; payout batches; reconciliation exceptions and finance issues; balances by account; revenue, commission and driver earnings by period from the ledger | refund (`money.ops.refund`); draft batch, bank CSV, mark paid, transfers, fail, reverse; resolve issue; ledger CSV |
| Insights | `ops.insights`, `ops.insights.export` | Section 3 below | CSV export (audited) |
| Audit log | `ops.audit` | Every audit row, filtered by area, record, person, dates | |
| Team and roles | `ops.roles` | Operators, their roles, the role matrix | grant, change, remove (audited) |
| Fleets | `ops.fleets` | Fleets awaiting approval, approved fleets with driver counts | approve (`operator.fleets.approve`) |
| Notifications | `ops.notifications` (comms) | The operator's own inbox; bell in the shell bar | |

Old addresses `/operator/rides` and `/operator/fleets` redirect here. `/ops` sends each operator to the first screen their role opens.

## 2. Roles and least privilege

| Permission | ops agent | dispatcher | reviewer | safety | support | finance | pricing | admin |
|---|---|---|---|---|---|---|---|---|
| Live map and queues | yes | yes | | yes | | | | yes |
| Rides, timelines, fares | yes | yes | | yes | yes | yes | | yes |
| Intervene on rides | | yes | | | | | | yes |
| Adjust a fare | | | | | yes | yes | | yes |
| Drivers and stats | yes | yes | yes | yes | yes | yes | | yes |
| Driver positions | | yes | | yes | | | | yes |
| Suspend, appeals | | | | yes | | | | yes |
| Earnings and payouts of a driver | | | | | | yes | | yes |
| Identity and vehicle documents | | | yes | | | | | yes |
| Fleets | | | yes | | | | | yes |
| Safety (read / act) | read | | | both | read | | | both |
| Support (read / act) | read | | | read | both | | | both |
| Pricing (read / manage), zones | | | | | | | both | both |
| Finance (view, refund, payouts, manage) | | | | | | all | | all |
| Business accounts (read / manage) | read | | | | | both | | both |
| Insights | yes | yes | | | | yes | yes | yes |
| Audit log, who viewed what | | | | yes | | | | yes |
| Roles | | | | | | | | yes |

Enforcement: the `operator` middleware checks every operator route against `RoutePermissions` (this centre's pages and the dispatch, money, pricing, safety, support and legacy APIs). Outside it, `Gate::before` and `OpsPrivilegeGuard` narrow the domains' flag-only shortcuts (D-1002). Navigation shows only what the role opens. Operators with no roles keep full access until an admin assigns roles (D-1001).

## 3. Insights: definitions (printed on the page next to each figure)

| Measure | Definition |
|---|---|
| Ride volume | count of rides by the day they were created |
| Completed trips | rides with status completed, by the day of `completed_at` |
| How rides ended | rides created in the period ending in each state / all rides created in the period |
| Pickup ETA vs actual | rides that reached the pickup in the period: estimated = `ride_offers.pickup_eta_s` of the accepted offer (method shown: straight-line estimates rank drivers only), actual = `arrived_at - accepted_at` |
| Trip duration | average `completed_at - started_at` of rides completed in the period |
| Driver utilisation | on_trip time / (online + on_trip) time from measured availability spans, clipped to the period (D-1004) |
| Retention | passengers grouped by month of first completed ride; later months = share with a completed ride in that month |
| Revenue and driver earnings | from the ledger: commission and fee accounts (credits minus debits); driver and fleet payable excluding payouts |
| Coverage | rides by active service area (polygon test) and their "no driver found" rate; rides outside every area; address-only rides counted apart |
| Safety incidents | incidents by the day opened, by severity |

Empty periods say so ("No completed trips in this period"); averages with no rows print a dash; CSV export has one row per metric, period and measure (formula-neutralised); results cached 60 s. Queries use plain SQL with an engine-specific day bucket (SQLite, PostgreSQL, MySQL), indexes added on `rides(created_at)`, `rides(status, completed_at)`, `safety_incidents(created_at)`, `audit_logs(created_at)`, `audit_logs(actor_user_id)`.

## 4. Verification

- PHP: before the merge of trunk, my branch point ran 1112 tests with 134 failing (the unbound integration seams, fixed by integ). After merging trunk (integration and comms): **1187 tests, 1179 passed, 8 skipped, 0 failed** (21 new ops tests, 6 updated legacy tests). New: `tests/Feature/Ops/{RolePermissionMatrixTest, LeastPrivilegeTest, OpsWorkflowsTest, InsightsQueryTest, OpsPagesTest}`; fixtures in `tests/Concerns/OpsFixtures.php` (tests only). The matrix test checks the real middleware for every operator route (122) x 7 roles + admin + legacy operator + non-operator against a specification written independently in the test.
- JS: `node --test "tests/js/*.test.mjs"`: 28 before, 35 after the trunk merge (4 mine in `tests/js/ops.test.mjs`: durations, timers, refusal messages, rand to cents without floats).
- `npm run build`; `view:cache` and `route:cache` pass.
- Browser (dev server 8612, local data built by a scratch script that is not committed): signed in as an admin; Live at 1440 light (map with drivers, pickups and the review marker, queues, figures, polling), resolved ride 115 from needs review with a fare (the invalid amount "1.234" was refused in the form first), acknowledged and owned an SOS incident, created a new draft of the economy tariff, edited its base fare and saved it, simulated the draft against the live tariff (R123.00 vs R121.00), drew a new service area with three corners (created switched off), read insights and the ledger, the team page. Captures (headless Chrome, signed in, no horizontal overflow at any size) at 1440 light and night and 768 light (tablet) for every screen, 390 light for five: `docs/R46_screens/ops/<page>-<width>-<scheme>.jpg`.

## 5. Limits and follow-ups (honest)

- Domain actions need JavaScript (they post JSON to the owning APIs); pages render and read without it.
- Driver positions on the live board come from `driver_availability` (dispatch's latest fix); the ride page reads geo's per-ride endpoint.
- Online time is measured only from this release on; earlier utilisation is not available.
- Requests refused before a ride exists are not recorded by any domain, so they cannot be counted (coverage says so). Pricing would need to log refused quotes.
- Polygon editing has no self-intersection check; time windows and zone fees of a tariff are shown but edited only through the pricing API.
- `taste` lint warnings kept: map marker rings and the floating map status (real overlays), and 1 px zero-blur dividers on the figure strip.
- The report-only CSP floods `/csp-report` (429) with map-tile reports in development (geo D-214, owner of SecurityHeaders to act).
- For the driver app: a suspended driver can appeal with `POST /drive/suspensions/{id}/appeal {text}`; `App\Domain\Ops\Drivers\SuspensionService::activeFor($user)` gives the active suspension and its reason.
- For the business agent: an `ops.business` page, if added under the `operator` middleware, must be mapped in `RoutePermissions` (unmapped means admin only, and the matrix test fails).

## 6. Jury polish (pops, 2026-11-10)

What changed, by jury item (decisions D-2200 to D-2211):

- **SOS (8):** live-board takeover rail, red map marker, opt-in alarm sound; incident page leads with owner and escalation, one audited ownership action, a map with trail, and place names with GPS kept as a secondary line.
- **Places (6, 12):** `PlaceNames` on business approvals, business overview and the incident page; small maps on approvals and overview.
- **Identity (13):** route-line header mark, plate chips, night safety banner.
- **Fleet (72, 73):** stacked rows and total row at 390; the navigation rule runs the full page height.
- **Ops (14):** "Service areas" header, tariff title on one line, native date inputs in kit style.
- **Copy (17):** sign-in, 404 (three destinations), virus-scan note for reviewers only, plurals.

Checked in a real browser (Chromium, local server, seeded jury data): live board 1440 light and night, 768, 390 night; incident 1440 light and night, 390; approvals 1440 light, 390 night; business overview 1440 night; fleet drivers 390, 768 night, 1440 night; fleet earnings and overview 390; tariff detail 1440; service areas 1440; sign-in 1440 and 390; 404 1440.

Not checked or not done: business overview at 768 and insights were captured but not reviewed; the impeccable detector was not run; the empty-state line mark is on ops empty states only, not yet placed in business or fleet views; items in D-2211 wait on pmoney. Four small after images are in `docs/R46_screens/polish-ops/` (incident 1440, live takeover 768, business approvals 1440, fleet drivers 390); the before set is the jury's own and is not copied here.
