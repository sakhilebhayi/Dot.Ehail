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

- Passengers request rides (addresses, optional coordinates) and drivers claim them from an
  open queue; a ride moves requested → accepted → en_route → arrived → in_progress → completed,
  one stage at a time (`App\Enums\RideStatus` is the only transition table)
- Cancellation with a reason and note; a driver backing out after accepting returns the ride to
  the queue (up to `EHAIL_MAX_REASSIGNMENTS`), a passenger cancelling tells the driver
- `php artisan ehail:expire-rides` (scheduled every minute): requests nobody accepts within
  `EHAIL_RIDE_EXPIRY_MINUTES` (default 10) expire and the passenger is told; trips still in
  progress after `EHAIL_STUCK_RIDE_HOURS` (default 6) are flagged `needs_review`, never
  completed automatically
- Ratings: after completion the passenger rates the driver and the driver rates the passenger,
  once each (1–5, optional comment); the driver's average and ride count are recomputed from rows
- Vehicle management for drivers (`/drive/vehicles`): add, edit, activate, deactivate; one active
  vehicle at a time; a ride is only accepted by an active vehicle whose class serves the ride's
  class (`config/ehail.php`); fleet owners of approved fleets see their drivers' vehicles
- Fleets and an approval gate: a fleet is vetted by a platform operator, drivers by their fleet
  owner or an operator (never by themselves)
- Operator desk (`/operator/rides`): trips needing review, expired requests, pending fleet and
  application counts, and a manual final fare with a mandatory reason for rides that have none
  (audited); plus unpaid fares, unmatched payments and waivers (see "Paystack ride fares")
- Fare payment by card through Paystack, **test mode only**: after a ride completes the passenger
  pays the ride's final fare to the platform's Paystack account; verified server-side, recorded in
  `ride_payments`, never trusted from the browser (see "Paystack ride fares")
- Fare estimate from `config/ehail.php` (base fare, per-km rate, class multipliers). Without
  coordinates the estimate is honestly "not available"
- Driver licence and ID numbers are encrypted at rest, masked in lists, and every open of a
  full profile by someone other than the driver is written to an append-only audit log
- Live operations dashboard, ride search/detail pages, in-app notification bell, ecosystem SSO,
  real-time ride status broadcasts over Reverb (sent after the database commit)

> **Not built:**
> - **Payouts, commissions, tips and KYC.** Fares land in the platform's Paystack balance. Nothing
>   pays a driver or a fleet: driver and fleet views say so ("payout is not available yet — fares
>   paid are recorded, settlement to drivers is manual"). Also not built: cash rides, saved cards,
>   refunds (see below), live Paystack keys (refused unless explicitly allowed twice).
> - Nearest-driver dispatch (there is no driver location data; the queue is first-to-accept),
>   geocoding / map pickers (coordinates are typed in), live driver tracking, and Knowledge Pack
>   publishing to Dot.Brain. See `wiki.md` §6 and §8.

## Domain Models

- **DriverProfile** — a platform `User` who has applied/been approved to drive; licence and ID
  numbers are encrypted, with `rating` and `total_rides` derived from ratings and completed rides
- **Vehicle** — a driver's vehicle (class, make/model, plate, colour, seats); at most one active
- **Ride** — trip record from request to a final state (completed, cancelled, expired,
  needs_review), with a passenger and a driver `User`
- **RideRating** — one rating per (ride, rater): passenger → driver, driver → passenger
- **RidePayment** — one Paystack payment attempt for a ride's fare (integer cents, unique reference,
  status initiated/paid/failed/expired/unmatched); unmatched rows are charges Paystack reported that
  we could not match, kept for the operator desk
- **AuditLog** — append-only record of operator fare entries, fare waivers, payment reviews, driver
  cancellations and profile opens

## Responsive app shell

Signed-in pages render inside `resources/views/layouts/app.blade.php`. From 860px up it is the fixed 260px sidebar and top bar, as before. Below 860px the sidebar becomes an off-canvas drawer that the **Menu** button in the top bar opens (`#app-sidebar`, `[data-shell-open]`, `[data-shell-close]`). `layouts/partials/shell-script.blade.php` moves focus into the drawer, closes it on Escape or a backdrop click, locks page scroll, makes the page behind it inert and returns focus to the button. It is plain JavaScript, no Alpine or build step.

- Wide tables go in a `<div class="table-scroll" role="region" aria-label="..." tabindex="0">` so only the table scrolls sideways.
- Page markup is mostly inline styles, so the layout carries a few phone-only attribute-selector rules (page padding, grids collapse to one column under 640px, 44px controls, 16px field text so iOS does not zoom). New pages should prefer classes and `minmax()` grids over fixed widths.
- The Jetstream `navigation-menu` bar that sits under the fixed top bar is wrapped in `.shell-legacy-nav` and hidden on phones. Its team switcher and sign-out now live in the drawer footer. The ride links (request a ride, available rides, my vehicles, driver profile, fleet review) were only in that hidden bar; they are in the sidebar now.
- `tests/Feature/ResponsiveShellTest.php` asserts the markup hooks above. It checks the rendered HTML, not pixels, so layout still needs a look in a browser at 360, 375, 768 and 1280px.

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 13 |
| Language | PHP 8.3+ |
| Auth & teams | Jetstream 5 (Livewire stack) + Sanctum |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS |
| Database | PostgreSQL 16 (shared `infodot` database across the ecosystem) |
| Realtime | Laravel Reverb: `RideStatusUpdated` is broadcast to a ride's passenger and driver after the database commit |
| Payments | Paystack (`PAYSTACK_*`), test mode only: hosted checkout redirect, server-side verify, signed webhook |
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

### Configuration

Tariff, currency, vehicle-class mapping and the ride expiry windows live in `config/ehail.php`
(overridable with `EHAIL_*` env vars). Ride expiry and the stuck-trip sweep need the scheduler,
so production must run the standard Laravel cron entry (`* * * * * cd /path/to/app && php
artisan schedule:run >> /dev/null 2>&1`); without it requests never expire.

### Running Tests

```bash
php artisan test
```

Feature tests use an in-memory SQLite connection (see `phpunit.xml`) and Laravel's
`RefreshDatabase` trait — no shared Postgres instance required to run them.

## Paystack ride fares

**Test mode only.** The passenger pays the *platform's* Paystack account the ride's final fare after the
ride is completed, by card on Paystack's hosted checkout. This app never sees card details.

### Set it up (test keys)

1. In the Paystack dashboard switch to **Test mode** and copy the test secret and public keys.
2. Set them in `.env` (never commit them): `PAYSTACK_SECRET_KEY=sk_test_...`, `PAYSTACK_PUBLIC_KEY=pk_test_...`.
3. Under Settings, API Keys & Webhooks, set the **test webhook URL** to `{APP_URL}/webhooks/paystack`
   (must be public https; use a tunnel such as ngrok for local work). The callback URL is sent with
   every payment (`{APP_URL}/rides/{id}/pay/callback`), so you do not configure it in the dashboard.
4. `php artisan config:clear` if configuration was cached. Run a queue worker as before (in-app
   notices are not queued, but Dot.Notify events are).
5. Complete a ride, open it as the passenger, press **Pay fare**, and pay with one of Paystack's
   published test cards.

| Variable | Default | Meaning |
|---|---|---|
| `PAYSTACK_SECRET_KEY` | blank | `sk_test_...`. Blank means online payment is off: rides complete exactly as before and owe nothing ("Payment is not enabled") |
| `PAYSTACK_PUBLIC_KEY` | blank | `pk_test_...`; kept for completeness, the redirect flow never needs it in the browser |
| `PAYSTACK_BASE_URL` | `https://api.paystack.co` | Must be https, otherwise payment is switched off |
| `PAYSTACK_ALLOW_LIVE` | `false` | Second switch a live key needs, see below |
| `PAYSTACK_ATTEMPT_TTL_MINUTES` | `30` | How long "Pay fare" reuses an open checkout before starting a new one |
| `EHAIL_UNPAID_FARE_ALERT_HOURS` | `24` | A completed ride unpaid this long is listed on the operator desk |

**The TEST MODE guard.** A key starting `sk_test_` is test mode and shows a `TEST MODE` badge next to the
pay button and on the operator desk. A key starting `sk_live_` is **refused**: no request is made, the
refusal is logged without the key, and payment stays off, unless `PAYSTACK_ALLOW_LIVE=true` is *also*
set. Any other key (a public key in the secret slot, a typo) is refused too. Do not set
`PAYSTACK_ALLOW_LIVE` until the owner has decided to take real money and the gaps below are closed.

### How it works

- **Completion.** When a ride completes with a final fare above zero while Paystack is usable, its
  `payment_status` becomes `unpaid`. Otherwise it is `not_due`. Every ride that existed before this
  release is `not_due` (the migration adds the column with that default and rewrites nothing), as is any
  ride completed while payment is switched off; those are never retro-charged.
- **Amount.** The ride's stored `final_fare` (`decimal(8,2)`) converted to integer cents with no float
  arithmetic, currency `EHAIL_CURRENCY_CODE` (ZAR). Nothing the browser sends is used.
- **Pay fare.** Only the ride's passenger sees it, on a completed unpaid, pending or failed ride. The
  ride row is locked, one live attempt is allowed at a time (a second click reopens it), Paystack's
  `POST /transaction/initialize` is called, and the passenger is redirected to Paystack. The request
  carries the payer's email, amount, currency, our reference, the callback URL and `metadata` of the ride
  id only: never addresses, coordinates or names. If Paystack is unreachable nothing changes and the
  passenger can retry.
- **Return (callback).** `GET /rides/{ride}/pay/callback` (passenger only) ignores the query string,
  asks `GET /transaction/verify/{reference}` about the ride's latest unsettled attempt, and marks the
  ride paid only if the status is `success` **and** the reference, currency and amount match our attempt
  **and** the ride still owes exactly that fare.
- **Webhook.** `POST /webhooks/paystack` (no login, no CSRF, throttled 60/min) checks the
  `x-paystack-signature` header, an HMAC-SHA512 of the **raw** body under the secret key, with
  `hash_equals` before parsing anything. Wrong or missing signature, or no usable key: one generic 401.
  Unreadable JSON: 400. `charge.success` is settled exactly like the callback (the signed payload is
  trusted, no extra API call, so the acknowledgement is fast). Other events are acknowledged and ignored.
- **Races and replays.** Callback and webhook can arrive together or twice. Each locks the ride row, then
  the payment row, re-reads, and does nothing if the payment is already paid; `unique(reference)` and
  `unique(provider, provider_transaction_id)` are the backstop. A ride is paid once and notified once.
- **Mismatches are never dropped.** A successful charge that does not match (wrong amount, currency or
  reference, a second charge for an already-paid ride, a charge for a waived ride, a reused transaction
  id, an unknown reference) is recorded as `unmatched` with a reason and shown on the operator desk;
  the webhook still answers 200. Somebody may have been charged, so an operator checks it on the Paystack
  dashboard, refunds or reconciles there, and records what they did ("Mark reviewed", audited).
- **Operator desk (`/operator/rides`).** Unpaid fares older than the threshold (each with a **Waive** form,
  mandatory reason, audited as `ride.fare_waived`), unmatched payments, the latest waivers, and two
  separately labelled figures: *Estimated fare total, not collected* (every recorded fare) and *Fares
  collected through Paystack* (only paid payments' real amounts; labelled test money in test mode).
- **Notifications.** In-app only (the bell). No Dot.Notify event was added: none exists for payments.
  The driver's notice says the fare is recorded and settlement to drivers is manual.

### What is not built

Driver or fleet **payouts** (transfers, split payments), **commissions**, **tips**, **KYC/verification**,
**cash rides** (there is no cash flow), **saved cards / card on file**, **refunds** (a refund is done on
the Paystack dashboard; nothing in this app issues or records one beyond the operator's review note),
subscription or recurring charges, and live keys. Funds sit in the platform's Paystack balance.
Paystack allows one webhook URL per mode per account: if several Dot apps share one Paystack account,
other apps' charges will arrive here as `unmatched` and the reverse; give each app its own Paystack
integration or put a router in front.

## Email via Dot.Notify

Rides and driver applications also reach Dot.Notify (`POST {NOTIFY_INGEST_URL}`, signed with HMAC-SHA256
over `"{timestamp}.{raw body}"` using `NOTIFY_INGEST_SECRET`, headers `X-InfoDot-Timestamp` and
`X-InfoDot-Signature`), so a company can turn them into emails. `ride.cancelled` and `ride.expired` go
out with the existing in-app ride notices (same trigger, same recipient). `ride.accepted` and
`driver.application_decided` have **no in-app notification** in this app; they are sent from the
accept action and the fleet owner's approve/reject action, and no in-app notification was added for them.

| Event | Sent to | `data` keys |
|---|---|---|
| `ride.accepted` | the passenger, when a driver accepts | `driver_name`, `vehicle` (colour make model), `url` |
| `ride.cancelled` | the other party: the driver when the passenger cancels, the passenger when the driver backs out, both when an operator cancels | `cancelled_by_role` (`passenger`/`driver`/`operator`), `reason`, `url` |
| `ride.expired` | the passenger (request timed out, or drivers kept cancelling) | `url` |
| `driver.application_decided` | the applicant, when a fleet owner approves or rejects | `decision` (`approved`/`rejected`), `fleet_name`, `note`, `url` |

A driver backing out of an accepted ride re-queues it (the in-app notice is `ride_requeued`); that is
sent as `ride.cancelled` with `cancelled_by_role: driver`, since from the passenger's side the driver
cancelled. `reason` is a fixed human-readable label, never the free-text cancel note.
`note` is the reviewer's rejection reason, cut to 300 characters (empty on approval). `url` is an
absolute `https` link to the ride or driver page in this app.

**Never sent:** phone numbers, ID or licence numbers, plate numbers, pickup/drop-off addresses,
coordinates, free-text notes (other than the reviewer's rejection note above), or any other person's
email address. The email is only ever in `recipient.email`.

**What it takes for an email to go out:** nothing is sent until `NOTIFY_INGEST_URL` **and**
`NOTIFY_INGEST_SECRET` are both set, **and** the recipient's company has enabled the event in Dot.Notify
(otherwise Notify answers `no_rule` and sends nothing). A **queue worker is required**
(`php artisan queue:work`): events are queued after the surrounding database transaction commits, so a
ride action that rolls back sends nothing, and nothing is ever posted inside the user's request.

**Who is skipped:** Notify routes by company, so the recipient's company is their current team's
`infodot_team_uuid`, or their personal team's if the current team has none. A recipient with no
`infodot_team_uuid` (not provisioned from InfoDot) or no email is skipped, with only an info log line
(event name and user id, never an address). The in-app notification is unaffected.

**Safety:** a Notify outage or misconfiguration cannot break or slow a ride action. The job has a
5 second HTTP timeout, 3 tries with backoff, never throws, logs without secrets or email addresses, and
a small cache-based circuit breaker pauses calls for 60 seconds after 5 consecutive failures (jobs wait
and retry; if Notify stays down they are dropped after their third try). Retries and double dispatches
share an idempotency key, `"{event}:{ride id | driver profile id}:{sha1(recipient email)}:{bucket}"`
(bucket: ride status plus how many times the driver changed, or the decision), so Notify collapses them.

## Daily metrics to Dot.Analytics

Once a day this app posts aggregate numbers, server to server, to Dot.Analytics
(`POST {ANALYTICS_INGEST_URL}`, the full URL ending `/api/ingest/snapshots`). The request is signed
exactly like the Dot.Notify one: HMAC-SHA256 over `"{timestamp}.{raw body}"` with
`ANALYTICS_INGEST_SECRET`, headers `X-InfoDot-Timestamp` (unix seconds) and `X-InfoDot-Signature`
(`sha256=<hex>`), computed when the request is sent, so a retry carries a fresh timestamp. The body is

```json
{"platform": "ehail", "company_uuid": "<uuid>", "taken_at": "2026-11-05T00:00:00Z", "period": "day", "metrics": {"rides_completed_7d": 0, "drivers_online": 0, "avg_rating": 4.5}}
```

`ehail:publish-metrics` collects and queues one `SendAnalyticsSnapshot` job per company. It takes
`--company=<uuid>` (one company only) and `--dry-run` (collect and print, queue and send nothing).

**What is sent: these keys and nothing else** (Dot.Analytics' metrics contract, `config/metrics.php`):

| Key | Meaning | Fleet owner's snapshot | Operating company's snapshot |
|---|---|---|---|
| `rides_requested_7d` | rides requested in the last 7 whole UTC days, counted once each by their original request time (`created_at`; a ride a driver hands back is not a new request) | omitted | yes, platform-wide |
| `rides_completed_7d` | rides with status `completed` whose `completed_at` fell in those 7 days | rides its own drivers completed | platform-wide |
| `rides_expired_7d` | rides with status `expired` whose `expired_at` fell in those 7 days | omitted | yes, platform-wide |
| `drivers_online` | approved drivers marked online right now (the ones who can take a ride) | its own drivers | platform-wide |
| `avg_rating` | mean of the passengers' 1 to 5 ratings of drivers, all time, 2 decimals. Left out when there are none, never `0` | its own drivers | platform-wide |

Aggregates only: no names, emails, addresses, plates, licence or ID numbers, fares, ratings comments
and no row ever appears in the body. Counts are whole numbers; a company with no activity sends true
zeros (zero is a real count).

**Which company.** Ehail has two kinds of owner, so it publishes two kinds of snapshot:

- **Fleet owners.** Drivers belong to a fleet and a fleet to its owner, a user, and Dot.Analytics
  wants a company, so the company is the InfoDot team (`infodot_team_uuid`) the fleet owner currently
  belongs to: their current team's uuid, or their personal team's if the current team has none (the
  rule the Dot.Notify client uses). It is the same "owned **approved** fleets" scope the app's own
  dashboard gives a fleet owner; all fleets of one company are added together. A fleet owner with no
  `infodot_team_uuid`, or a fleet still pending vetting, publishes nothing. A driver is counted with
  the fleet they belong to now. Because **a requested ride has no driver, and an expired ride has had its
  driver cleared**, neither belongs to any fleet: `rides_requested_7d` and `rides_expired_7d` are
  **omitted from fleet snapshots** rather than guessed.
- **The operating company.** The app's own dashboard shows platform operators platform-wide figures,
  and those figures have no fleet owner. They are published **only** under the company named by
  `ANALYTICS_COMPANY_UUID` (the InfoDot company uuid of whoever operates Dot.Ehail), with all five
  keys. **Blank means no platform-wide snapshot is published.** If that company also owns a fleet,
  Dot.Analytics gets the one platform-wide snapshot for it (one snapshot per company and day), and the
  other fleet owners still see only their own.

**Windows are UTC.** `taken_at` is the start of the UTC day the command runs on, and the three "7d"
numbers cover the seven whole UTC days before it, so a re-run on the same day describes the same
snapshot and Dot.Analytics keeps the first one (replays are idempotent per company, platform,
`taken_at` and period). `drivers_online` is read at collection time, shortly after 00:15 UTC.

**What it takes for anything to be sent:** nothing is collected, queued or sent until
`ANALYTICS_INGEST_URL` **and** `ANALYTICS_INGEST_SECRET` are both set. The secret is this app's
`ANALYTICS_INGEST_SECRET_EHAIL` value in Dot.Analytics' `.env` (same value; `ANALYTICS_PLATFORM_KEY`
is `ehail`), and each company must be connected there. The **scheduler cron is required**
(`* * * * * php artisan schedule:run`; the command runs daily at 00:15 UTC) and so is a **queue
worker** (`php artisan queue:work`); with `QUEUE_CONNECTION=sync` the send happens inside the command.

**Safety:** a Dot.Analytics outage or misconfiguration cannot affect this app. The send is a queued
job, never part of a request; it has a 5 second HTTP timeout, 3 tries with 30 s and 120 s backoff,
never throws, logs without secrets or numbers, and a cache-based circuit breaker pauses calls for 60
seconds after 5 consecutive failures (separate from the Dot.Notify breaker). A refusal (401, 404, 422)
is not retried. A collection or queueing error is logged and only ends that run of the command.

## 🚢 Deployment

### Production Checklist

1. **Set environment**
   ```bash
   APP_ENV=production
   APP_DEBUG=false
   ```

2. **Install dependencies (no dev)**
   ```bash
   composer install --optimize-autoloader --no-dev
   npm ci
   ```

3. **Build frontend assets**
   ```bash
   npm run build
   ```

4. **Cache configuration**
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan event:cache
   ```

5. **Run migrations**
   ```bash
   php artisan migrate --force
   ```
   Back up the database first. This release changes `rides.status` (now a string), tightens
   `ride_ratings` and `vehicles` (one active vehicle per driver — drivers with several active
   vehicles keep only their newest), and **encrypts `driver_profiles.license_number` and
   `id_number`**. The encrypted values can only be read with the `APP_KEY` of the environment
   that wrote them: never rotate `APP_KEY` without decrypting first, and keep the key in the same
   backup regime as the database.

   This release also adds `rides.payment_status` (default `not_due`, existing rows untouched; the
   column, a waiver trio and an index on `rides`) and the `ride_payments` table. Back up first as above;
   the column add is a metadata-only change on PostgreSQL 11+, the index build briefly locks `rides`
   writes, so run it off-peak. Rollback drops both (and any recorded payments with them): take a backup
   before rolling back once real or test payments exist.

   Add the scheduler cron entry (see Configuration above).

6. **Start the queue worker** (use `deploy/queue-worker.service` for systemd or `deploy/queue-worker.supervisord.conf` for Supervisor). Requires `QUEUE_CONNECTION=redis`, matching `.env.production.example`.
   ```bash
   php artisan queue:work redis --tries=3 --timeout=90
   ```

7. **Start the Reverb WebSocket server** (use `deploy/reverb.service` for systemd or `deploy/reverb.supervisord.conf` for Supervisor) -- never run this as a bare foreground command in production, it needs the same process supervision as the queue worker.
   ```bash
   php artisan reverb:start
   ```
   Binds to `REVERB_SERVER_HOST`/`REVERB_SERVER_PORT` (loopback-only by default in `.env.production.example`) -- it is not meant to be reachable directly from the internet. See "WebSocket Reverse Proxy" below for how browsers actually reach it over `wss://`.

### Web Server Configuration

#### Nginx

```nginx
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    http2 on;
    server_name your-domain.com;
    root /var/www/ehail/public;

    ssl_certificate     /etc/letsencrypt/live/your-domain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.com/privkey.pem;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    # WebSocket Reverse Proxy -- only Reverb's client-facing path
    # (/app/{key}, what Echo/pusher-js connects to) is proxied here. Its
    # server-to-server publish API (/apps/{id}/events etc.) is deliberately
    # NOT exposed publicly -- config/broadcasting.php's reverb connection
    # talks to it directly over the internal REVERB_SERVER_HOST/PORT
    # instead, so it never needs to be reachable from outside this box.
    # The Upgrade/Connection headers are what turn this from a plain HTTP
    # proxy into a WebSocket one; without them the client's protocol
    # upgrade handshake fails.
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;
    }
}
```

#### Apache

Requires `mod_proxy`, `mod_proxy_wstunnel`, and `mod_ssl` enabled (`a2enmod proxy proxy_wstunnel ssl`).

```apache
<VirtualHost *:80>
    ServerName your-domain.com
    Redirect permanent / https://your-domain.com/
</VirtualHost>

<VirtualHost *:443>
    ServerName your-domain.com
    DocumentRoot /var/www/ehail/public

    SSLEngine on
    SSLCertificateFile      /etc/letsencrypt/live/your-domain.com/fullchain.pem
    SSLCertificateKeyFile   /etc/letsencrypt/live/your-domain.com/privkey.pem

    <Directory /var/www/ehail/public>
        AllowOverride All
        Require all granted
    </Directory>

    ProxyPass        /app ws://127.0.0.1:8080/app
    ProxyPassReverse /app ws://127.0.0.1:8080/app
</VirtualHost>
```

### Real-Time Health Check

`GET /up/realtime` checks broadcasting config, queue connection, and whether `reverb:start` is actually accepting connections -- independently, so it reports which link broke rather than a single healthy/unhealthy bit. See `app/Http/Controllers/RealtimeHealthController.php`.

---

## Ecosystem

**Dot.Ehail** is one of the platforms in the Dot ecosystem, connected via shared PostgreSQL and
Sanctum SSO. Visit [Dot.Brain](https://github.com/sakhilebhayi/Dot.Brain) for the ecosystem-wide
knowledge repo, and [`wiki.md`](wiki.md) for this platform's own source of truth.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
