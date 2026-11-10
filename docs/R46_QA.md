# R-46 QA: browser end-to-end acceptance (brief section 24)

Owner: qa agent (wave 3), branch `feat/r46-qa`, decisions D-1600 to D-1606. The PHP acceptance layer (`tests/Feature/Acceptance`, integ) proves the journeys over the HTTP API; this layer proves them in real browsers, on the integrated app, with only the providers stubbed.

## 1. How to run

```
tests/e2e/run.sh                 # build, boot, every spec, tear down
tests/e2e/run.sh 05              # one spec file (name filter)
tests/e2e/run.sh --skip-build    # reuse public/build
tests/e2e/run.sh --serve         # boot only, print the URLs (for writing specs)
E2E_VERBOSE=1 tests/e2e/run.sh   # stream the app and queue output
```

Needs PHP 8.4+, Node 22+ (node:sqlite, node:test), Google Chrome installed (driven through `playwright-core` with `channel: 'chrome'`; nothing is downloaded) and `openssl`. Output (screenshots of failures, the route-sweep reports, the app log, the queue log) goes to `tests/e2e/out/` (git-ignored).

## 2. The world

| Piece | What it is |
|---|---|
| App | PHP built-in server with 6 workers from `public/` (as `artisan serve`), APP_ENV local, debug off, assets built as the deploy pipeline builds them (no VITE_REVERB_*), a throwaway SQLite file (WAL, busy timeout, IMMEDIATE transactions; D-1601) and storage folder in the OS temp dir, deleted afterwards |
| Queue | a `queue:work` process (the host's cron runs the same jobs once a minute) |
| Scheduler | not running; a spec runs the command cron would run, after moving timestamps back (D-1605) |
| Reference data | migrations, `ehail:geo-reference-areas`, `ehail:pricing:install-example-tariff` (20 % commission plan) |
| Routing | OSRM-compatible stub: city-grid road routes (north-south, then east-west, 20 m samples, 30 km/h, polyline5), switchable to 503 |
| Geocoding | Nominatim-compatible stub (search, reverse) for four Cape Town places |
| Map tiles | https stub (self-signed; production tile hosts are https and only those enter the CSP) |
| Paystack | https stub with the endpoints the money domain calls, signed webhooks to the app; the hosted checkout is routed inside the browser (checkout.paystack.com is never contacted) |
| SMS | BulkSMS-compatible stub on the same https server |
| Mail | an SMTP server that captures every message (safety reports "sent" honestly) |
| Internet | `HTTP(S)_PROXY` points at a recorder that refuses and lists every attempt; the run fails if the list is not empty |
| People | `support/world.php` (refuses any database but the suite's): users, operators with roles, verified fixture drivers, fleet owners, business admins, time travel, and a reset between specs (drivers offline, rides left waiting cancelled) |

## 3. Journeys

RESULTS_PLACEHOLDER
