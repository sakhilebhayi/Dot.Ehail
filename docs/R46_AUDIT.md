# R-46 audit: Dot.Ehail as found on 2026-11-10

Method: read routes, controllers, services, models, enums, migrations, config, views and the deploy/CI workflows; ran the suite (603 tests: 596 pass, 7 skipped, 0 fail); probed the live host (`/`, `/login`, `/up` 200; `/up/realtime` answers `{"status":"degraded"}`). Each wave-1 agent extends this file with a detailed audit of its own domain (section "Domain audits"). Classes: **F** fully functional, **I** functional but needs improvement, **P** partial, **U** UI-only or simulated, **B** broken, **M** missing but essential.

| Area | Class | Finding | Root cause |
|---|---|---|---|
| Auth, SSO, teams | F | InfoDot redeem, team sync, Jetstream, 2FA | n/a |
| Passenger booking | P | A form with address text fields and OPTIONAL raw latitude/longitude number inputs; no map, no search, no geolocation, no route, no category comparison | No location/maps layer |
| Fare | I/B (vs brief) | `FareCalculator`: base + straight-line (haversine) km x per-km x tier multiplier, floats, from config; estimate copied to final fare on completion | No routing engine, no versioned rules, no minor units, no zones/time/fees |
| Dispatch | P | "Available rides" list that any eligible online driver can accept (pull model, first come); locking guards double accept; expiry command; reassignment count | No matching, no offers, no timeouts per driver, no fairness |
| Ride lifecycle | I | Explicit enum state machine (requested, accepted, en_route, arrived, in_progress, completed, cancelled, expired, needs_review) with actor-per-edge rules, row locks, audit log; operator complete/cancel | No searching/assigned split, no no-show, no PIN, no failure state |
| Real-time | P | `RideStatusUpdated` broadcast over Reverb; live host cannot run Reverb (health "degraded"), so pages show stale status until reload | Shared cPanel host has no long-running processes |
| Driver onboarding | P | Apply to a fleet or start one, documents upload, fleet owner approves/rejects; encrypted identifiers with blind indexes | No document types/expiry/rejection reasons/resubmission/ops review queue |
| Vehicles | I | Register, edit, activate/deactivate with rules | No vehicle photos, roadworthy/insurance, category eligibility |
| Payments | P | Paystack initialise/verify/webhook with signature check, payment rows in cents, payment status on ride | No saved cards, no refunds, no receipts, no cash, no ledger, no commission split |
| Driver earnings / payouts | M | Driver stats only | Not built |
| Ratings | I | Two-sided ratings with one-per-trip guard | No moderation, appeals, private reports |
| Safety | M | None: no SOS, no incidents, no trip sharing, no PIN, no deviation detection, no trusted contacts | Not built |
| Support | M | None | Not built |
| Scheduled rides | M | None | Not built |
| Business accounts | M | None | Not built |
| Fleet management | P | Fleet entity, owner approval of drivers, operator approves fleets | No vehicle/driver assignment ops, maintenance, earnings, alerts |
| Notifications | P | Database notifications + bell; Dot.Notify events client | No push/SMS, no email receipts |
| Admin / operations | P | Operator pages for fleets and rides (complete/cancel) | No command centre, metrics, safety ops, finance ops |
| Pricing admin | M | Config file only | Not built |
| Visual design | B (vs brief) | Dark Jetstream pages with inline styles; booking form is a raw form | No product design |
| Security | I | Enforced baseline headers, report-only CSP, policies, audit logs, encrypted IDs | CSP report-only; location privacy not defined |
| Tests | I | 603 tests, strong on lifecycle, payments, encryption | No E2E, no concurrency tests at scale |
| Deploy | F | Manual workflow_dispatch pipeline, CI gate, recent runs green | n/a |

## Preserved
Auth/SSO/teams, the lifecycle's locking and audit approach, Paystack webhook verification, encrypted driver identifiers, fleets, documents, vehicles, ratings, operator actions, deploy pipeline, every existing row (migrations are additive; the status enum is extended, not replaced).

## Domain audits
(appended by the wave-1 agents)

### Geo (maps, routing, geocoding, zones, locations) - geo agent, 2026-11-10

| Area | Class before | Finding | After R-46 geo |
|---|---|---|---|
| Map | M | No map anywhere; booking had raw optional latitude/longitude number inputs | Leaflet map module, lazy chunk 45.6 KB gzip, stations, route line, moving driver marker, accuracy circle, offline/slow states |
| Address search | M | Free-text address fields only, no geocoding | Server-proxied search (country ZA, near-point bias) and reverse; combobox with keyboard support; autocomplete only where the provider permits it |
| Routing | M | `FareCalculator` used haversine straight-line km | `RoutePlanner` with 5 provider drivers, cache, breaker, honest `RoutingUnavailable`; default `none` with the exact unavailable sentence |
| Service areas / zones | M | None; any coordinates accepted | `service_areas` + `zones` with polygon containment; approximate SA metros and airports seeded and flagged |
| Driver location | M | `driver_profiles.is_online` flag only; no positions | Throttled, screened ingest; one latest row per driver; nearby search |
| Trip trail and distance | M | `distance_km` set from the straight-line estimate | Sampled trail during the trip; travelled vs billable distance with jitter, jump and gap handling |
| Route deviation / stops | M | None | Detector + monitor + events for safety |
| Location privacy | M (undefined) | No rules | Passenger sees assigned driver only during the active ride; passenger live position never stored; 90-day trail retention |
| Browser permissions | B | Global `Permissions-Policy: geolocation=()` blocks any location feature; CSP would block tiles once enforced | Seams provided (`AllowGeolocation`, `GeoCsp`); global change is a security-owner item |
### Safety, verification, trust and support (safety agent, 2026-11-10)

| Item | Before | Class before | After this branch |
|---|---|---|---|
| Driver documents | 3 fixed types (licence, id, vehicle_inspection), no status, no expiry, no reviewer, fleet owner could open them | P | Per-market configurable types (11 SA defaults), review queue with reasons, expiry warnings and auto-expiry, history, view audit |
| Go-online gate | Policy checked ownership only; a **pending** driver could toggle `is_online` on | B | `DriverEligibility` with structured reasons; model guard on every path; expired/rejected mandatory document takes the driver offline |
| Upload security | Extension-based `mimes:` rule, original bytes stored (EXIF/GPS kept) | I | Content sniffing, image re-encode, size and pixel caps, private disks, nosniff/sandbox streaming; no virus scanning (stated) |
| Passenger safety | Nothing | M | Safety card (verified driver photo, first name, band, vehicle, registration), trusted contacts, trip share, check-ins, SOS |
| Emergency workflow | Nothing | M | Incidents with timeline, context capture, honest notifications, escalation timers, ops queue, "what we did" |
| Driver safety | Nothing | M | Passenger facts, private reports, driver SOS, cancellation-pattern flags (review only) |
| Ratings | One per trip per side, completed only, no moderation | I | Held comments, hide/release, appeals with exclusion, bands, rate limits |
| Support | Nothing | M | Cases, messages, attachments, SLA, escalation, lost property relay with masking, fare-dispute seam |
| JSON error rendering | Validation errors on web routes redirected JSON clients | B (for APIs) | JSON when the request expects JSON (D-515) |
| Legacy toggle | `drivers.toggle-online` flips without checks | B | Still exists (dispatch/driver-app own its replacement); now guarded |
### Money (money agent, 2026-11-10)

As found (classes as above):

| Area | Class | Finding |
|---|---|---|
| Paystack checkout (initialize, verify on return) | I | Solid: test-mode guard with a second switch for live keys, https-pinned base URL, no redirects, safe error messages, locking order ride -> payment, unmatched charges kept for the desk. Kept |
| Paystack webhook | I | Signature checked in constant time before parsing, but the signed body was trusted as proof of payment and processed inline. Now: stored once, processed by a queued job, every state change confirmed with Paystack's verify/fetch endpoints |
| Saved cards / server-side charging | M | None: the passenger had to open a checkout after every trip |
| Refunds | M | None |
| Cash | M | None |
| Ledger / commission split | M | None: paid fares went to the platform balance with no record of what the driver was owed |
| Driver earnings, payouts | M | None ("Payout is not available yet") |
| Incentives | M | None |
| Receipts | M | None |
| Reconciliation, finance exceptions | P | Only the unmatched-payment list on the operator desk |
| Money in floats | B (vs brief) | `rides.final_fare` decimal(8,2) converted to cents by number_format; amounts in ride_payments were already integer cents |

Built (wave 1): see `docs/R46_MONEY.md`. Every item above is now F or I at the API level; the passenger, driver and ops screens are wave-2 work on these APIs.

Behaviour changes to existing code, with reasons:
- `PaystackWebhookController` stores and queues instead of settling inline; `RidePayments::handleWebhook` verifies with Paystack before settling (D-601, D-602). `tests/Feature/Paystack/PaystackWebhookTest.php` now fakes the verify endpoint; the mismatch cases drive the verify answer; "makes no HTTP call" became "only calls Paystack verify"; one new test proves a webhook body is never trusted over verify.
- `RidePayments` posts to the ledger after a verified checkout payment and after a waiver (after commit); the driver's notification no longer says payouts do not exist.
- `PaymentStatus` gained `awaiting_cash`.
- `tests/Feature/Paystack/RidePaymentStateTest::test_migrating_legacy_rides_leaves_them_not_due` derives its rollback step count instead of assuming the payment migrations are the last two (R-46 adds later migrations).
- `bootstrap/app.php`: JSON exceptions for `money/*` when the client expects JSON (one line).

Honest limits (not built, or built only as seams): no card pre-authorisation (Paystack has none publicly); online-hours/acceptance/referral incentives are unmeasurable until dispatch data and a referral system exist; cancellation fees need pricing's policy; business-account billing needs the wave-2 business domain; no PDF library (print-to-PDF from the receipt page); Paystack settlement endpoints are undocumented in the OpenAPI file, so reconciliation compares the dashboard CSV export; nothing has been run against a live or test Paystack account (no keys here), only against a contract fake built from Paystack's OpenAPI description; no Dot.Finance/Billing contract exists.
### Dispatch domain audit (dispatch agent, 2026-11-10)
| Area | Before (class) | Finding | After this branch |
|---|---|---|---|
| Lifecycle | I | Enum with actor-per-edge rules and row locks; no searching/assigned split, no no-show, PIN, failure, scheduled states; `transition()` returned null on a lost race; audit only in audit_logs | F: v2 edge table (15 states), guards and effects per edge, append-only `ride_events` timeline, idempotent transition keys, domain events after commit, data migration of old values |
| Concurrency | I | Accept guarded by a row lock and `driver_id IS NULL`, but nothing stopped one driver holding two accepted rides; SQLite ignores `lockForUpdate` | F: database-level guards (nullable unique columns) for driver, passenger, offers; CAS offer acceptance; tick lease; tested with forked processes |
| Dispatch | P | Pull list, first to accept wins, no location, no ranking, no timeouts per driver | F (with stated limits): sequential ETA-ranked offers with 15 s timeout, widening rings, fairness terms logged per offer, requeue with priority, expiry; the pull list kept as a delayed open board |
| Availability | P | `DriverProfile::is_online` boolean only; no heartbeat, no position, no on-trip state | F: offline/online/on_trip/paused, heartbeat staleness, eligibility seam with reasons, offline blocked on trip |
| Ride request | B (vs brief) | Fare computed from client-entered coordinates with straight-line distance; no idempotency; duplicate active rides possible | F behind the pricing seam: quote-only requests, Idempotency-Key, one active ride per passenger; honest 503 until pricing is bound. The old form route still exists for the old page (wave 2 replaces it) |
| Scheduled rides | M | None | F: book ahead, reminder, allocation at lead time, price guarantee with re-quote acceptance, reschedule/cancel rules, missed-slot expiry |
| Real-time | P | Reverb broadcast that the live host cannot run | F: versioned delta polling with ETag/304, Retry-After, stale flags, per-role payloads; broadcast variants kept for Reverb hosts |
| Operations | P | Complete/cancel stuck trips from a page | F (API): live list, timeline with offer score terms, force-cancel, reassign (search or named driver), flag, resolve; all audited |
| Not done here | | UI for any of it (wave 2), batched matching, PostgreSQL concurrency run (wave 3 perf), comms delivery of reminder/requote events (wave 2) | |
