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
