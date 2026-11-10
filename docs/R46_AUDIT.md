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

### ds (design system, shells, landing, auth, PWA), 2026-11-10

| Area | Class before | Finding | Done on feat/r46-ds |
|---|---|---|---|
| Visual identity | B | Three unrelated looks: dark app shell (Syne, Inter, sky blue), cream guest pages (Space Grotesk, Manrope, gold), Jetstream grey defaults | One system, "Route Line" (DESIGN.md, tokens `--eh-*`) |
| Runtime hosts | B | Tailwind CDN script, Google Fonts, Material Symbols, Unsplash backgrounds, ui-avatars images, a Laravel logo in mail | All removed; fonts and icons self-hosted; tested |
| App shells | M | One dark sidebar shell for every role | Passenger, driver, ops, business, fleet shells; role-aware navigation; /home role routing |
| Landing | U | Generic marketing page with stock photography from a CDN | Rebuilt: fare breakdown first, honest safety copy, credited South African photographs |
| Auth and errors | I | Working but off-brand; error pages loaded Google Fonts and Material Symbols | Restyled with the kit; no external hosts |
| PWA | M | None | Manifest, shell-only service worker, offline page, icons from the logo |
| Accessibility | I | 44px drawer targets existed; no skip link; mixed focus styles | Skip link, one focus ring, 44/56px targets, measured AA contrast both themes |
