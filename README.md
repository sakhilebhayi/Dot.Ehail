<div align="center">

<img src="public/images/logo.png" alt="Dot.Ehail" width="200" />

<br /><br />

**Onboard drivers, register vehicles, and run a live ride-hailing operations dashboard.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.3+-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the [Dot Ecosystem](https://github.com/sakhileb/InfoDot)** &nbsp;·&nbsp; `ehail.infodot.app`

</div>

---

## What is Dot.Ehail?

Dot.Ehail is the ride-hailing platform in the Dot ecosystem. It's the entrepreneurship layer for
the movement domain — the product an operator (from a single owner-driver to a multi-vehicle
fleet) launches to onboard drivers, register vehicles, take ride requests, and settle fares.

**Status:** early build. See [`wiki.md`](wiki.md) for the full, kept-honest breakdown of what's
actually implemented vs. still scaffolding/intent.

## Core Features (implemented today)

- Real ride-hailing schema: driver profiles, vehicles, rides, and ride ratings
- Live operations dashboard — ride counts by status, driver availability, revenue, recent rides
- Ride search & detail pages (`/rides`, `/rides/{ride}`)
- Driver profile page with ride history, restricted to the driver themselves (`/drivers/{driver}`)
- In-app notification bell (Laravel's `database` notification channel) for ride/driver events
- Dark / light mode toggle (Tailwind class-based strategy, persisted per browser)
- Ecosystem SSO from the Dot hub

> **Not yet built:** dispatch/nearest-driver matching, fare estimation, realtime driver tracking
> (Reverb is configured but unused), fleet-as-a-first-class-entity (currently only Jetstream
> `Team`), and Knowledge Pack publishing to Dot.Brain. See `wiki.md` §6 and §8 for the full
> scaffolding-vs-real breakdown and roadmap. Earlier drafts of this README described that target
> state as if it already shipped — it doesn't yet.

## Domain Models

- **DriverProfile** — a platform `User` who has applied/been approved to drive
- **Vehicle** — registered vehicle linked to a driver profile
- **Ride** — trip record from request through to completion, with a passenger and driver `User`
- **RideRating** — one rating per ride, from either the passenger or the driver

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 13 |
| Language | PHP 8.3+ |
| Auth & teams | Jetstream 5 (Livewire stack) + Sanctum |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS |
| Database | PostgreSQL 16 (shared `infodot` database across the ecosystem) |
| Realtime | Laravel Reverb (configured in `.env.example`, not yet wired to any broadcast) |
| AI | Anthropic Claude (`ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`) — config only, no fare/dispatch logic calls it yet |
| Queue/cache/session | Redis queue; database cache & session |

## Quick Start

```bash
git clone https://github.com/sakhileb/Dot.Ehail.git
cd Dot.Ehail
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan serve
```

> **Ecosystem SSO:** Set `DB_*` env vars to the shared Dot PostgreSQL instance and
> `APP_URL=https://ehail.infodot.app`. Users authenticated elsewhere in the ecosystem gain access
> automatically via the Sanctum handoff at `/auth/ecosystem`.

### Running Tests

```bash
php artisan test
```

Feature tests use an in-memory SQLite connection (see `phpunit.xml`) and Laravel's
`RefreshDatabase` trait — no shared Postgres instance required to run them.

## Ecosystem

**Dot.Ehail** is one of the platforms in the Dot ecosystem, connected via shared PostgreSQL and
Sanctum SSO. Visit [Dot.Brain](https://github.com/sakhilebhayi/Dot.Brain) for the ecosystem-wide
knowledge repo, and [`wiki.md`](wiki.md) for this platform's own source of truth.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
