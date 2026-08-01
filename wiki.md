---
title: Dot.Ehail — Platform Wiki
version: 0.2.0
status: draft
owners: [Ehail Platform Lead]
platform-id: dot-ehail
last-review: 2026-08-01
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
| `logistics.trip.completed` / `logistics.trip.cancelled` | `Ride.status` reaches `completed` / `cancelled` | not implemented — no listener/observer on `Ride` yet |
| `logistics.corridor.congestion_shift` | Cell-level travel-time regime change | not implemented — no corridor/geohash concept in code yet |
| `logistics.fleet.utilization_cycle` | Fleet reporting cycle | not implemented — no fleet entity to report on yet |

## 6. What's Scaffolding vs. Real

To keep this wiki honest as the repo grows:

- **Real and working:** auth (Jetstream/Sanctum), the four domain tables and models, the dashboard query set, ecosystem SSO handoff route.
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
| 0.2.0 | 2026-08-01 | Ehail Platform Lead | Initial wiki: documented the actual Laravel/Jetstream/Livewire scaffold (driver/vehicle/ride/rating models, live dashboard, ecosystem SSO route), marked event/Knowledge Pack integration as not-yet-implemented against Dot.Brain's platforms/dot-ehail.md target state |
