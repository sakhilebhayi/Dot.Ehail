<div align="center">

<img src="public/images/logo.png" alt="Dot.Ehail" width="200" />

<br /><br />

**Ride-hailing for South Africa: passengers, drivers, fleets, business accounts and an operations centre.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?style=flat-square&logo=php&logoColor=white) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white) ![SQLite](https://img.shields.io/badge/SQLite-tested-003B57?style=flat-square&logo=sqlite&logoColor=white)

<br /><br />

**Part of the [Dot Ecosystem](https://github.com/sakhileb/InfoDot)** &nbsp;·&nbsp; `ehail.infodot.co.za`

</div>

---

## What it is

Dot.Ehail is the Dot ecosystem's mobility platform. The R-46 rebuild turned a booking form and a pull-based ride list into five connected surfaces on one Laravel application:

| Surface | Address | Who |
|---|---|---|
| Passenger app | `/ride` | book with the full fare breakdown before requesting, card or cash, live trip with the ride PIN, receipt, rating, trips, scheduled rides, saved places, safety centre (trusted contacts, trip sharing, SOS), help |
| Driver app | `/drive` | onboarding and document upload, vehicles, go online, offer card with pickup distance, trip estimate and earnings before accepting, navigation hand-off, arrived / PIN start / complete, earnings and payouts, safety |
| Operations centre | `/ops` | live board, rides and timelines, interventions, driver verification, safety incidents with escalation, support cases, tariffs and promotions, finance (payments, refunds, payouts, ledger, reconciliation), insights, service areas, team roles, audit |
| Business accounts | `/business` | booking for staff and guests under policies (categories, hours, areas, limits, approvals), monthly statements |
| Fleet portal | `/fleet` | drivers, vehicles and assignments, maintenance, documents status, trips, earnings that match the ledger |
| Public | `/`, `/fares`, `/t/{token}` | landing, fare calculator, trip-share link for trusted contacts (no cookie, expires) |

It is an installable web app (manifest, service worker for the app shell only; no API response or personal data is cached).

## How it works (honestly)

- **Real time is short polling, not websockets.** Shared cPanel hosting cannot run a websocket server, so the passenger screen polls a versioned feed every 3 s during a ride, the driver every 2 s while online, and drivers post their position every 4 s. Dispatch advances on every poll and on the every-minute scheduler: an offer that is not answered moves on within seconds while anyone is polling, within a minute otherwise. Laravel broadcast events are kept for a host that can run Reverb.
- **Prices are the operator's.** Fares are quoted server-side from a published, versioned tariff over a road route from the configured routing provider, in integer cents. No routing provider, no tariff, no quote: the screens say so. The repository ships an *example* tariff only for development (labelled "Example tariff: review before launch"); migrations install no prices.
- **Every provider has an honest "not configured" state**: routing (Google Routes, ORS, Mapbox, OSRM, Valhalla), address search (Nominatim by default), map tiles (OpenStreetMap by default), card payments (Paystack, test keys unless the live key is allowed twice), payout transfers (manual bank-file batches by default), SMS (BulkSMS adapter), Web Push (VAPID), email, Dot.Notify, Dot.Analytics. `php artisan ehail:preflight` lists each one's state.
- **Not built, on purpose:** in-house emergency dispatch (SOS raises an incident, alerts trusted contacts and operators, shows 10111 / 10177 / 112 with one tap, and says exactly what was done); turn-by-turn navigation (handed to Google Maps, Apple Maps or Waze); automated background checks (documents are uploaded and reviewed by people); virus scanning of uploads (none on the host: images are re-encoded, PDFs only streamed as attachments).

Each domain is documented where its code is decided: [`docs/R46_ARCHITECTURE.md`](docs/R46_ARCHITECTURE.md) (the contract), [`R46_DISPATCH`](docs/R46_DISPATCH.md), [`R46_PRICING`](docs/R46_PRICING.md), [`R46_GEO`](docs/R46_GEO.md), [`R46_SAFETY`](docs/R46_SAFETY.md), [`R46_MONEY`](docs/R46_MONEY.md), [`R46_BUSINESS`](docs/R46_BUSINESS.md), [`R46_FLEET`](docs/R46_FLEET.md), [`R46_COMMS`](docs/R46_COMMS.md), [`R46_INTEGRATION`](docs/R46_INTEGRATION.md), the UI surfaces in `R46_UI_*.md` and the design system in [`DESIGN.md`](DESIGN.md) and [`R46_DS.md`](docs/R46_DS.md). Every decision is logged in [`docs/R46_DECISIONS.md`](docs/R46_DECISIONS.md).

## Before real passengers

A fresh production install is safe but cannot take a booking until the owner does the steps in [`docs/R46_OPERATIONS.md`](docs/R46_OPERATIONS.md), section 1: a routing provider key, a published tariff, a way to pay (Paystack live keys and/or cash), a first platform operator, approval of every driver's documents (existing drivers included), email, and the escalation contacts. `php artisan ehail:preflight` ends with one line, **"Can a passenger book?"**, that names whatever is still missing.

## Tech stack

Laravel 13 (PHP 8.3+, CI on 8.4), Jetstream + Fortify (sign-in through InfoDot), Blade components (`<x-eh.*>`) with small vanilla JS modules built by Vite, Leaflet for maps, self-hosted fonts and icons (nothing loads from a CDN at runtime). PostgreSQL 16 in production (SQLite works and is tested), database queue and cache, cron-driven scheduler and queue worker.

## Run it locally

```bash
composer install
cp .env.example .env            # environment-independent: SQLite, no provider keys, nothing paid is called
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan db:seed             # dev only: test@example.com (password "password") and the EXAMPLE tariff; refuses in production
php artisan ehail:geo-reference-areas
npm ci && npm run build
php artisan serve
```

Booking needs a road route. For development point `EHAIL_ROUTING_DRIVER=osrm` and `EHAIL_OSRM_URL` at a local OSRM-compatible stub (the acceptance tests fake one in `tests/Concerns/AcceptanceWorld.php`); never call a paid API from a laptop. `php artisan db:seed --class=DemoFleetSeeder` and `DemoBusinessSeeder` add clearly labelled demo data (they refuse production). The queue and scheduler run from cron on the host; locally run `php artisan schedule:work` and `php artisan queue:work`.

## Tests and checks

| Command | What it proves |
|---|---|
| `php artisan test --compact` | the PHP suite on in-memory SQLite (unit, feature, acceptance journeys with fakes only at the provider edges) |
| `bin/test-pgsql.sh` | the same suite on a throw-away PostgreSQL database |
| `npm run test:js` | the JS modules (geo, driver, passenger, ops, comms, the deploy's manifest check) |
| `npm run build && node scripts/ops/check-vite-manifest.mjs` | every `@vite` entry a view asks for is built |
| `tests/Support/signed-in-smoke.sh` | a production-like boot (cached config, routes, views, built assets) signed in as passenger, driver, operator, fleet owner and business admin, every key page and JSON endpoint, fonts, manifest, service worker |
| `scripts/ops/upgrade-rehearsal.sh [--pgsql]` | the R-46 migrations on a populated pre-R-46 database: every row kept, every old ride status mapped |
| `php artisan ehail:preflight [--json] [--strict]` | this machine and `.env`: PHP, limits, database, storage, build, schedule, providers, reference data, drivers' verification, booking readiness |

CI (`.github/workflows/ci.yml`) runs all of these except the browser walkthroughs, which drive a real Chrome against a local routing stub on a developer machine (`docs/R46_UI_*.md` list what was clicked through).

## Deployment

Manual, from GitHub Actions: **Deploy to Production** (`deploy.yml`, `confirm=deploy`) on `main`. It runs the whole CI as a gate, builds the frontend on the runner, copies the database before maintenance mode, migrates, installs the reference service areas, runs `ehail:preflight` (a FAIL keeps the site down with the reason), installs the cron lines in the account's shared crontab, and smoke-checks the live site. Host checklist: [`docs/deployment.md`](docs/deployment.md). Owner runbook (first steps, backups and the restore drill, monitoring, incidents): [`docs/R46_OPERATIONS.md`](docs/R46_OPERATIONS.md).

## Ecosystem

Sign-in, companies and the platform switcher come from InfoDot (signed SSO redeem). Optional: email events through Dot.Notify, a daily aggregate snapshot to Dot.Analytics. See [`wiki.md`](wiki.md) for the platform's history before R-46.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
