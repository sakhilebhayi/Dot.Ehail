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

### Pricing and ride categories (pricing agent, 2026-11-10)

Method: read `App\Services\Ehail\FareCalculator`, `config/ehail.php`, `RideRequestController`, `RideLifecycle`, `RidePayments`, `Ride`, `RidePayment`, `Vehicle`, `DriverProfile`, the ride/payment migrations and every test touching fares; ran the suite.

| Item | Class (before) | Finding | Done in R-46 (branch feat/r46-pricing) |
|---|---|---|---|
| Fare calculation | I/B | Base + haversine km x per-km x tier multiplier, floats, config-only; estimate copied to final fare | Integer `FareEngine` on road routes only, versioned published rule sets, itemised components (docs/R46_PRICING.md) |
| Distance | B | Straight-line distance billed | Removed; road route from the geo seam or no price |
| Currency arithmetic | I | `decimal(8,2)` columns, float maths, cents only for payments | `Money` (integer minor units, overflow-safe, rounding modes); decimals mirrored for history |
| Ride categories | P | `vehicle_type` enum (economy/standard/premium/suv) + config tier map | `ride_categories` + `category_vehicle_rules`, availability by area/time, eligibility with reasons; 9 categories seeded (business, parcel inactive) |
| Quotes | M | None: the estimate was recomputed per request and never bound | Signed, expiring, passenger- and route-bound quotes; tamper/expiry/foreign/route refusals |
| Time/zone/fees | M | No minimum, booking fee, waiting, zones, tolls, tax, rounding | All configurable per rule set; time windows across midnight |
| Demand pricing | M | None | Supported (off, capped, shown, never on tolls); no signal source exists, so no surge |
| Promotions | M | None | Code/automatic, eligibility, budgets, per-user and total limits enforced at ride creation |
| Final fare | I | Estimate copied on completion; operator enters a fare when none | Tolerance-based finalisation with reasons; audited operator adjustments |
| Cancellation fees | M | None | Policy from the ride's tariff version, no-show fee, late-driver waiver |
| Commission / earnings | M | None | Versioned commission plans (market/category/fleet), split shown to drivers before and after |
| Pricing admin | M | Config file only | Operator API: drafts, versions, publish, retire, simulator, promotions, categories |
| Tariff values | n/a | R25 + R8.50/km config defaults | Example tariff labelled "review before launch"; real prices are an owner decision |

Open after this branch: geo bridges need a smoke test once geo merges; dispatch must call `QuoteBinder`, `FareFinaliser`, `CancellationFeePolicy` and bind `EligibleSupplyCounter`; ops UI (wave 2) consumes the admin API; VAT status and real tariffs are owner decisions.
