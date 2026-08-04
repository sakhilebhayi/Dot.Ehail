---
title: Dot.Ehail — Platform Wiki
version: 0.3.4
status: draft
owners: [Ehail Platform Lead]
platform-id: dot-ehail
last-review: 2026-08-04
---

# Dot.Ehail

Purpose: this is Dot.Ehail's own knowledge home — owned and maintained by the Dot.Ehail team. It describes what this platform is, what's actually built, and how it connects to the wider Dot Ecosystem. Dot.Brain never edits this file; it only reads what we choose to publish.

> **Related:** [Dot.Brain's ingested view of this platform](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-ehail.md)

---

## 1. What Dot.Ehail Is

Dot.Ehail lets an operator — anywhere from a single owner-driver to a multi-vehicle fleet company — run a branded e-hailing business: onboard drivers, register vehicles, take ride requests, dispatch, track trips in real time, settle fares, and collect ratings. It is the entrepreneurship layer of the ecosystem for the movement domain: the product an operator launches, not just the software Dot.Ehail itself runs on.

**Status:** early build. This is a working Laravel/Jetstream/Livewire scaffold with a real ride-hailing schema (drivers, vehicles, rides, ratings) and a live operations dashboard, but no dispatch matching, fare engine, or realtime driver tracking is wired up yet. Sections below describe what is actually implemented today, and call out explicitly where something is still just scaffolding or intent.

## 2. Architecture (as built)

| Layer | Technology | Notes |
|---|---|---|
| Framework | Laravel 12/13, PHP 8.3+ | `composer.json` pins `laravel/framework ^13.8` |
| Auth & teams | Laravel Jetstream 5 (Livewire stack) + Sanctum | Jetstream's `Team`/`Membership` models are the closest existing primitive to "fleet" — see §4 |
| Frontend | Livewire 3, Alpine.js, Tailwind CSS, Vite | |
| Realtime (planned) | Laravel Reverb | Configured in `.env.example` (`REVERB_*`), not yet used by any broadcast event in code |
| Database | PostgreSQL 16, shared `infodot` database per `.env.example` | Ehail's own tables (`driver_profiles`, `vehicles`, `rides`, `ride_ratings`) live in this shared instance |
| AI | Anthropic Claude (`ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL=claude-sonnet-4-6` in `.env.example`) | Wired as config only — no fare-estimation or dispatch logic calls it yet |
| Queue/cache/session | Redis-backed queue, database cache/session (per `.env.example`) | |

Ecosystem auth: `app/Http/Controllers/Auth/EcosystemAuthController.php` handles `/auth/ecosystem`, the SSO handoff route from the InfoDot/Dot hub — a signed-in user elsewhere in the ecosystem lands here already authenticated via Sanctum.

## 3. Domain Entities

Implemented as Eloquent models and migrations (`database/migrations/2026_06_27_000001_create_ehail_tables.php`):

| Entity | Table | Key fields | Notes |
|---|---|---|---|
| `DriverProfile` | `driver_profiles` | `user_id`, `license_number`, `id_number`, `status` (pending/approved/suspended), `is_online`, `rating`, `total_rides` | One-to-one with `User`; a driver is a role on top of a platform user, not a separate identity |
| `Vehicle` | `vehicles` | `driver_profile_id`, `make`, `model`, `year`, `color`, `plate_number`, `type` (economy/standard/premium/suv), `is_active` | Belongs to a driver profile; `DriverProfile::activeVehicle()` picks the active one |
| `Ride` | `rides` | `passenger_id`, `driver_id`, `vehicle_id`, pickup/dropoff address + lat/lng, `status` (requested → accepted → en_route → arrived → in_progress → completed/cancelled), `vehicle_type`, `estimated_fare`, `final_fare`, `distance_km`, timestamps per lifecycle stage | The core trip record; passenger and driver are both `User` references |
| `RideRating` | `ride_ratings` | `ride_id` (unique), `rated_by`, `rating`, `comment` | One rating per ride, rater-agnostic (passenger or driver can be `rated_by`) |
| `Team` / `Membership` | Jetstream defaults | `name`, `personal_team` | Jetstream's stock multi-tenant primitive — not yet remapped to an operator/fleet concept, see §7 |

Fleet/operator as a first-class entity distinct from a Jetstream `Team` does not exist in code yet — see roadmap.

## 4. Dashboard (implemented)

`routes/web.php`'s `/dashboard` route (behind `auth:sanctum` + Jetstream session + `verified`) computes and renders, live from the database:

- Ride counts by lifecycle bucket: total, active (`accepted`/`en_route`/`arrived`/`in_progress`), completed, cancelled, requested
- Driver counts: total, online (`is_online`), approved
- Total revenue (`sum(final_fare)` over completed rides)
- Status breakdown (`group by status`)
- The 10 most recent rides with driver, passenger, and vehicle eager-loaded

This is the only application logic beyond CRUD/auth scaffolding currently in the repo — it is a real operations view, not a mock.

## 5. Events Emitted

Nothing is published to the ecosystem yet — no Knowledge Pack integration, no `dkp:ehail:*` payloads, no outbound event bus wiring exist in code today. The event *shapes* below are the intended contract, derived from the ride lifecycle already modeled in `Ride.status` and from Dot.Brain's platform doc, not yet implemented:

| Event (planned) | Trigger | Status |
|---|---|---|
| `logistics.trip.completed` / `logistics.trip.cancelled` | `Ride.status` reaches `completed` / `cancelled` | outbound ecosystem event still not implemented. A `Ride` observer (`App\Observers\RideObserver`) now exists and fires on the `completed` transition, but it only dispatches the in-app `RideCompletedNotification` — no Knowledge Pack payload, no `cancelled` handling, no outbound bus wiring yet |
| `logistics.corridor.congestion_shift` | Cell-level travel-time regime change | not implemented — no corridor/geohash concept in code yet |
| `logistics.fleet.utilization_cycle` | Fleet reporting cycle | not implemented — no fleet entity to report on yet |

## 6. What's Scaffolding vs. Real

To keep this wiki honest as the repo grows:

- **Real and working:** auth (Jetstream/Sanctum), the four domain tables and models, the dashboard query set, ecosystem SSO handoff route, the in-app `RideCompletedNotification` (fired automatically by `App\Observers\RideObserver` when a ride's `status` transitions to `completed`, to both the passenger and the driver).
- **Written but still dead:** `DriverApplicationSubmittedNotification` exists and is tested via manual dispatch, but has no automatic trigger — there is no "operator" recipient to wire it to until fleet-as-entity (§8) exists, since a `DriverProfile` has no team/operator relation today.
- **Configured but unused:** Reverb (realtime), Anthropic API key/model (AI fare estimation / dispatch).
- **Not started:** fare estimation, dispatch/matching, driver-facing mobile flow, passenger-facing request flow, Knowledge Pack publishing, fleet-as-entity (currently only Jetstream `Team`).

## 7. Connecting to Dot.Brain

Dot.Ehail is a registered platform (`dot-ehail`) in the Dot Ecosystem. Dot.Brain's ingested view of this platform — including the fleet/corridor entity model, spatial-first publication discipline, and the worked Knowledge Pack round-trip example — is maintained at [`platforms/dot-ehail.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-ehail.md). That document currently describes target-state integration (corridor cells, DKP manifest, aggregation floors) ahead of what this repo implements; treat it as the integration contract this platform is building toward, and this wiki as the source of truth for what exists today.

Once trip/dispatch logic lands, Dot.Ehail intends to publish four Knowledge Pack payload types — `observation` (corridor-cell demand/travel-time aggregates), `insight` (corridor-regime findings), `outcome` (recommendation verifications), and `incident` (safety/aggregation-gate events) — gated by the same fleet-tenancy and spatial-aggregation rules Dot.Brain's doc specifies (geohash-5 cells, no origin-destination pairs, minimum vehicle/trip floors per cell-window). None of that publishing pipeline exists in code yet.

## 8. Roadmap

- [ ] Introduce a first-class Fleet/Operator entity (currently conflated with Jetstream `Team`)
- [ ] Dispatch: nearest-driver matching for `requested` rides
- [ ] Fare estimation (the `estimated_fare` field exists; nothing populates it yet)
- [ ] Wire Reverb for live ride-status broadcasts to passenger/driver clients
- [ ] Ride lifecycle observers that emit `logistics.trip.*` events
- [ ] Corridor-cell aggregation and the first `observation` Knowledge Pack
- [ ] Driver document/inspection workflow (license, ID, vehicle inspection records referenced in README but not modeled beyond `license_number`/`id_number`)

## Open Questions

- Should Fleet be modeled as a specialization of Jetstream `Team`, or a new entity referencing it? Affects how single owner-drivers ("fleet of one") are represented.
- Where does fare-estimation AI logic live — a queued job calling Anthropic per ride request, or a synchronous call in the request flow?
- Corridor-cell geohash precision (urban vs. rural) is an open question on the Dot.Brain side too — see that document's Open Questions.

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.3.4 | 2026-08-04 | Platform-loop pass | **Architecture pilot, continued: brought Dot.Ehail's domain models up to the same `HasUserScope` global-scope pattern already piloted on Dot.Finance (`HasUserScope`) and Dot.Notify (`HasTeamScope`).** A prior interrupted pass had already added `app/Models/Concerns/HasUserScope.php` and applied it to `DriverProfile` (the driver's own `driver_profiles.user_id` row) — this pass verified that work and completed the audit of the rest of the domain rather than assuming more models needed it. Checked every real domain table in `2026_06_27_000001_create_ehail_tables.php`: `vehicles` is keyed to `driver_profile_id`, not a direct `user_id`; `rides` has two distinct user parties (`passenger_id`/`driver_id`), not one owning user, and its `/dashboard` and `/rides` views are a documented platform-wide ops surface by design; `ride_ratings` has `rated_by`, not `user_id`, and a rating is legitimately visible to both ride parties, not just the rater. None of these three qualify for the same-column-name global scope the trait implements, and Jetstream's own `Team`/`Membership`/`TeamInvitation` models are the tenancy boundary itself, not tenant-owned domain rows — so `DriverProfile` remains the only genuinely single-owner tenant model in this codebase today (config listing `Features::teams(['invitations' => true])` does not, on inspection, mean the domain data is team-owned). Verified `DriverController@show`'s `Ride::where('driver_id', ...)` filter was correctly left untouched (Ride isn't scoped) and `routes/web.php`'s dashboard closure correctly uses `DriverProfile::withoutGlobalScope('user')` for its platform-wide driver counts. `tests/Feature/Ehail/DriverProfileViewTest.php` already contained `test_scope_alone_blocks_cross_user_access_even_without_a_policy_check`, matching the Dot.Finance/Dot.Notify pattern exactly (plain `DriverProfile::find()`/`::count()` lookups as attacker vs owner, no Policy in the path) — since no other model became newly scoped this pass, no second copy of that test was added elsewhere; the existing one is the load-bearing regression coverage. **Real behavior change, already reflected in the existing tests**: cross-user access to `/drivers/{id}` 404s instead of 403ing, because implicit route-model binding is scoped too. Verified for real against fresh PostgreSQL 16 (`dot_ehail_pilot`): `composer install`, `php artisan migrate`, full test suite both before and after the dependency bump below — 62 total, 55 passed, 7 skipped, 0 failed both times. Added PHPStan + Larastan (`phpstan.neon.dist`, level 5, `includes: [vendor/larastan/larastan/extension.neon]`, `paths: [app]`) — matching Dot.Finance's config; the analysis run itself produced no output in this session's sandbox (known limitation, same as the Finance pilot), so it's flagged as unverified rather than claimed working. `composer audit` found the same guzzlehttp/guzzle family advisories present across every other Dot platform this cycle (host-only cookie scope, cookie-domain/URI-fragment leaks in redirects, noncanonical-host bypass, unbounded response cookies, proxy-header leak) — fixed via a clean transitive bump (`guzzlehttp/guzzle` 7.12.3→7.15.2, `guzzlehttp/psr7` 2.12.3→2.13.0, `guzzlehttp/promises` 2.5.0→2.5.1), re-audited clean (`No security vulnerability advisories found`), and re-ran the full suite afterward to confirm no regression (still 62/55/7/0). |
| 0.3.3 | 2026-08-03 | Sakhile Bhayi | First real-execution verification pass: this codebase had never been run against a real PHP/Postgres toolchain before. `composer install` succeeded with default PHP 8.5 (no `<8.5.0` package ceiling in this platform's lockfile). `php artisan migrate` against an isolated `dot_ehail_verify` Postgres 16 database ran clean on the first try, and `php artisan test` passed clean on the first try too — 61 total, 54 passed, 7 skipped, 0 failed — no real bug found, nothing to fix. Applied the Dot.Brain adr/ADR-0013 idempotent guard (`Schema::hasTable`/`hasColumn` checks) to this platform's six shared Jetstream-core migrations, previously unguarded, so they're safe to run in any order against the shared `infodot` database alongside other Dot platforms. Re-verified on a fresh database after guarding: identical 61/54/7/0 result, confirming the guard is behavior-neutral. |
| 0.3.2 | 2026-08-03 | Sakhile Bhayi | Built a full custom marketing welcome page from scratch — `resources/views/welcome.blade.php` had never received a design pass (unlike sibling Dot platforms) and was still the stock Laravel/Jetstream starter template with no nav, hero, or footer to speak of. New page follows the shared Dot marketing-site pattern: fixed header with the real logo (`public/images/logo.png`) and nav links, a two-column hero (headline/stat-row/CTA on the left, an illustrative operations-dashboard mock on the right), a 6-card features grid and a 6-card capabilities grid describing only entities/workflows that actually exist in code (driver onboarding states, vehicle tiers, the ride status lifecycle, fare/rating fields, Jetstream teams, Postgres/Sanctum/Reverb architecture — no fabricated stats or social proof), a closing CTA section, and a footer with the real logo. Hero background: night city street with car, photo by Luke Miller (@bylukemiller), unsplash.com/photos/a-car-driving-down-a-busy-city-street-at-night-kY1LdHhIcRU. CTA section background: driver navigating with an in-car GPS system, photo by Dan Gold (@danielcgold), unsplash.com/photos/kARZuSYMfrA. Both direct `images.unsplash.com` URLs were verified via `curl -sI` returning `HTTP/2 200` before use. Also corrected frontmatter `version` (was stuck at 0.2.0 despite the changelog already having 0.3.0/0.3.1 entries) to follow the table's actual latest version. |
| 0.3.1 | 2026-08-01 | Ehail Platform Lead | Incremental pass: wired the previously-dead `RideCompletedNotification` (existed, was tested only via manual dispatch, never fired by app code) to a real trigger via a new `App\Observers\RideObserver` that fires on `Ride.status` transitioning to `completed`, notifying both passenger and driver; added `RideObserverTest` covering the completed transition, non-completed transitions, no-op re-saves, and the no-driver-yet case; left `DriverApplicationSubmittedNotification` unwired and documented why (no operator/team relation exists on `DriverProfile` to notify) rather than fabricating one |
| 0.3.0 | 2026-08-01 | Ehail Platform Lead | Platform-loop pass: real logo/favicon wired into nav, auth pages, and browser tab (removed the unreferenced `dot_ehail.png`, `docs/logo.svg`, and the stray root `index.html`/`styles.css`/`dot.logos2.png` "coming soon" template leftovers); added a rides search + detail page, a driver profile page, a database-channel notification bell, and a class-based dark mode toggle; fixed a real authorization gap (any authenticated user could view any driver's ride history and identifiers by ID) via a new `DriverProfilePolicy`; added Feature tests for the dashboard, ride view/search, driver profile access control, and the notification bell |
| 0.2.0 | 2026-08-01 | Ehail Platform Lead | Initial wiki: documented the actual Laravel/Jetstream/Livewire scaffold (driver/vehicle/ride/rating models, live dashboard, ecosystem SSO route), marked event/Knowledge Pack integration as not-yet-implemented against Dot.Brain's platforms/dot-ehail.md target state |
