# Deploying Dot.Ehail

Production target: `https://ehail.infodot.co.za`, on the shared cPanel account that also runs the other Dot apps. This document is the host checklist and the description of the pipeline in `.github/workflows/`. What to do **after** a deploy (provider keys, tariff, operators, driver documents, backups, monitoring, incidents) is in `docs/R46_OPERATIONS.md`.

| Workflow | Runs | What it does |
|---|---|---|
| `ci.yml` | every PR, pushes to `main`; called by `deploy.yml` | PHPUnit on SQLite and on PostgreSQL 16, the upgrade rehearsal on a populated pre-R-46 database (both engines), the JS suite, the Vite build and manifest check, the signed-in production-like smoke (five roles), the production caches. The release gate. |
| `drift.yml` | manual, **read-only** | Compares every tracked file of `main` with the host's copy (sha256), prints counts and paths. Run it before the takeover. |
| `deploy.yml` | manual: `confirm=adopt` once, then `confirm=deploy` | See "What a deploy does". |

Both manual workflows refuse to start from any branch but `main`, and `deploy.yml` stops in its first job, before any test minute is spent, when a deploy secret is missing. GitHub offers a `workflow_dispatch` workflow in the Actions tab only once the file is on the default branch.

## Prerequisites checklist

A ticked box means the item exists on the host or in GitHub, not that it was decided.

**Domain and host**

- [ ] DNS for `ehail.infodot.co.za` points at the cPanel account and AutoSSL has issued a certificate (the smoke check uses https).
- [ ] cPanel subdomain `ehail.infodot.co.za` with its docroot at `~/ehail.infodot.co.za` (or, better, at `~/ehail.infodot.co.za/ehail/public`: then the app folder is never served and the pipeline skips the docroot copies; it detects which through `uapi`, or a `.docroot-is-public` file in the app folder).
- [ ] MultiPHP: PHP 8.4 for the subdomain (composer.json needs 8.3+, CI runs 8.4), and `php -v` over SSH shows the same version (cron and the pipeline run the CLI `php`).
- [ ] Extensions (`php artisan ehail:preflight` checks them): the PDO driver you use (`pdo_pgsql` or `pdo_sqlite`), `mbstring`, `intl`, `curl`, `fileinfo`, `gd` with JPEG, PNG and WebP (driver document photos are re-encoded, which strips their GPS metadata), `openssl` with P-256 and AES-128-GCM (Web Push), `ctype`, `json`, `tokenizer`, `dom`, `xml`, plus `sqlite3` when the database is SQLite (the pre-deploy database copy uses its online backup). OPcache on.
- [ ] PHP limits for the website **and** the CLI (cPanel, MultiPHP INI Editor): `upload_max_filesize` 12M, `post_max_size` 16M (a driver document may be 10 MB), `memory_limit` 256M (photos up to 40 megapixels are decoded to re-encode them).
- [ ] `composer`, `git`, `rsync` and `flock` exist for the SSH user (the pipeline stops with a clear message when `flock` is missing). `pg_dump` (and `pg_restore`, for the backup script) when the database is PostgreSQL. There is no Node on the host and none is needed.
- [ ] An SSH key for GitHub Actions in the account's `authorized_keys`, and the host's key fingerprints for `DEPLOY_KNOWN_HOSTS`.

**Database**

- [ ] PostgreSQL 16 database and user (preferred: the suite, the load tests and the upgrade rehearsal run on it). SQLite also works and is rehearsed; `docs/R46_OPERATIONS.md` section 6 says what it costs. MySQL is not tested: the partial unique indexes ("one active vehicle per driver") are not enforced there.

**The app folder (first install, by hand)**

The pipeline updates a live install; it does not create one. Layout, as on the other Dot apps on this account:

```
~/ehail.infodot.co.za/            docroot (what the web server serves)
  index.php            shim: <?php require __DIR__.'/ehail/public/index.php';
  .htaccess            copy of public/.htaccess (Laravel rewrite rules)
  build, fonts, icons, images, manifest.webmanifest, sw.js, offline.html, robots.txt, favicons
                       linked (or copied) from ehail/public by every deploy
  ehail/               the app folder = DEPLOY_PATH (artisan is here); never served (the deploy writes a deny .htaccess)
```

- [ ] A copy of the code in `~/ehail.infodot.co.za/ehail/` (cPanel Git clone of `main`, or an upload; `adopt` replaces tracked files) and its `.env` (start from `.env.example`: every variable is listed there with the live value in a comment). `.env` must exist, or the deploy stops before touching anything.
- [ ] `php artisan key:generate --force` once on the host. **Copy `APP_KEY` somewhere safe off the host now**: it encrypts driver licence and ID numbers, saved card tokens and ride PINs, which are unreadable with any other key.
- [ ] The docroot `index.php` shim and `.htaccess`. Every other public file is linked by the deploy itself (a stale copy is moved to `~/dot-ehail-docroot-before-<time>/` and replaced by a link). **`sw.js` must be served from the docroot root**: a service worker's scope is the folder it is served from.
- [ ] Remove any hand-made cron lines for this app in cPanel (the pipeline installs its own and warns about unmarked ones).

**Host `.env`, the values that differ from `.env.example`**

```
APP_ENV=production                 # forced by every deploy anyway
APP_DEBUG=false                    # forced by every deploy anyway
APP_URL=https://ehail.infodot.co.za
APP_KEY=                           # key:generate on the host, once; back it up off the host
LOG_LEVEL=warning
DB_CONNECTION=pgsql                # + DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
SESSION_SECURE_COOKIE=true
BROADCAST_CONNECTION=log           # no Reverb on shared hosting; the screens poll
EHAIL_BROADCAST_RIDE_EVENTS=false
MAIL_MAILER=smtp                   # + MAIL_SCHEME, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD
INFODOT_SERVICE_SECRET=            # = ECOSYSTEM_SECRET_EHAIL in InfoDot's .env
DOT_INFODOT_URL=https://infodot.co.za
# then the providers (routing, Paystack, cash, SMS, VAPID, escalation contacts): docs/R46_OPERATIONS.md section 1
```

`QUEUE_CONNECTION`, `CACHE_STORE` and `SESSION_DRIVER` stay `database` (there is no Redis; the deploy warns on `redis` or `sync`).

**InfoDot**

- [ ] InfoDot's `.env`: `ECOSYSTEM_SECRET_EHAIL` = a new random value (`openssl rand -hex 32`); the same value as `INFODOT_SERVICE_SECRET` here.
- [ ] `ehail` marked live in InfoDot's platform registry and `ehail.infodot.co.za` in its `ECOSYSTEM_TRUSTED_DOMAINS`; otherwise every sign-in is refused (public sign-up is closed).

**GitHub**

- [ ] Repository secrets: `DEPLOY_HOST` (`ehail.infodot.co.za`), `DEPLOY_USER`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`. `DEPLOY_PATH` is optional (`/home/<user>/ehail.infodot.co.za/ehail`; without it the app is the single `artisan` within two levels of `~/ehail.infodot.co.za`, and zero or several is an error, not a guess).

## First deploy, in order

1. Tick the checklist. Take your own database backup in cPanel and copy `.env` (with `APP_KEY`) off the host.
2. If the folder already holds a running copy, run **Drift Report** from `main` and read it.
3. **Deploy to Production** with `confirm=adopt`: rollback copy, database copy, the folder becomes a checkout of `origin/main` without deleting anything untracked (`.env`, `storage/`, uploads), dependencies, build, invariants, static files, caches, cron lines, smoke. Pending migrations are only **listed** (add `migrate=migrate` to run them in the same run).
4. **Deploy to Production** with `confirm=deploy`: the CI gate, then everything above **with** migrations, the reference service areas and `ehail:preflight`.
5. Follow `docs/R46_OPERATIONS.md` section 1 (routing key, tariff, operator, payments, driver documents, email, contacts, backups).

## Every release after that

**Deploy to Production**, `confirm=deploy`, from `main`. Optional inputs: `skip_db_dump=skip` (no database copy: only when the host cannot make one and you took your own), `install_example_tariff=install` (also install the placeholder example tariff: never for real passengers).

## What a deploy does

1. **Inputs and secrets**: `confirm` is `deploy` or `adopt`; the run is on `main`; the four deploy secrets exist.
2. **CI gate**: the whole of `ci.yml`. Red means nothing was touched.
3. **Build on the runner** (`npm ci && npm run build`, Node 22) and `node scripts/ops/check-vite-manifest.mjs` (every `@vite` entry is built, every manifest file exists, no view that uses `@vite` names a `resources/` path that is not an entry). Staged on the host as `public/build.incoming`.
4. **On the host, before maintenance mode**: a rollback copy `~/dot-ehail-<mode>-<time>.tgz` (newest three kept) and a database copy `~/dot-ehail-db-<mode>-<time>.dump` (pg_dump, custom format) or `.sqlite` (online backup, integrity-checked); newest three kept. A failure here stops the run with the site untouched. The app-folder deny `.htaccess` (skipped when the docroot is `<app>/public`).
5. **Maintenance mode**, code to `origin/main` (an explicit refspec; host-only migration files are moved to `~/dot-ehail-quarantine-<time>/`), `composer install --no-dev`, `.env` invariants (`APP_DEBUG=false`, `APP_ENV=production`; warnings for an http `APP_URL`, reverb, redis, sync, ride-event broadcasts without Reverb), the staged build swapped in, the docroot's `build/` and every other public file kept in step (folders: linked or `rsync -a` without deleting; files: linked, a different copy moved aside and replaced by a link), private storage folders created.
6. **Database**: pending migrations listed, `migrate --force` (always on `deploy`), `ehail:geo-reference-areas` (approximate SA service areas and airport zones, created when missing, never overwriting edits), the example tariff only on request. The ride categories are installed by their migration; no prices are.
7. **Caches** (targeted clears, then config, route, view and event caches), `schedule:list`, **`ehail:preflight`**: a FAIL leaves the site in maintenance mode with the reason; WARN/INFO/OFF never stop a release. Then `php artisan up`.
8. **Cron lines** in the shared crontab, marked `# dot-ehail`, under the account-wide `flock`, with a retried read (an empty `crontab -l` is never trusted), only this app's lines replaced and the read-back verified (otherwise the previous crontab is restored); runner mode when the account runs the bounded cron runner. Then `queue:restart`.

   ```
   * * * * * cd <app> && exec <php> artisan schedule:run > storage/logs/cron-scheduler.log 2>&1 # dot-ehail
   <parity>-59/2 * * * * cd <app> && exec <php> artisan queue:work --stop-when-empty --tries=3 --timeout=60 --max-time=50 > storage/logs/cron-queue.log 2>&1 # dot-ehail
   ```

   `schedule:run` drives 17 tasks (dispatch tick, ride expiry, safety, money, business and comms sweeps every minute or few; retention purges; verification expiry 05:00; payout drafts; statements; the Analytics snapshot). `ehail:preflight` lists them and fails if one is not registered.
9. **Smoke check from the runner** (each request retried on a firewall 403 / timeout): `/`, `/login`, `/fares`, `/up` 200; `/fare-estimate/categories` JSON; `/up/realtime` honest (503 `degraded` without Reverb, never a 500); `/ride` redirects a guest to the login; a bogus `/t/<token>` gives the neutral 404 with no cookie and `noindex`; security headers present and the CSP mode reported (a warning while report-only); every built CSS/JS file, every font (woff2 magic bytes, not HTML), the web manifest (JSON with icons), `/sw.js` (this release's file, a JavaScript type, at the root) and one file of every static folder from the **docroot**; private storage and `.env` not served; `POST /up` is not a debug page; the app folder's `.env`, `artisan`, SQLite file, logs, `vendor/autoload.php` and `composer.*` not served.

Never touched: `.env` (except forcing `APP_DEBUG` and `APP_ENV`), `storage/`, the database file, uploads, other apps' cron lines.

## If a run fails

- **Before maintenance mode** (inputs, the CI gate, build, SSH, upload, rollback copy, database copy): the site still serves the previous release; the log says so.
- **After maintenance mode started**: the site is left **down on purpose** with the rollback copy and database copy named in the log. Fix the cause (usually what `ehail:preflight` printed as FAIL) and run the workflow again; a clean run brings the site up. Or restore by hand (`docs/R46_OPERATIONS.md` section 5).
- **After `php artisan up`** (cron lines or smoke check): the new release is serving; read the message. A failed crontab verification restores the previous crontab.

## How to read the log

`==` lines are the host script's progress (copies, release before and after, invariants, docroot static files, pending migrations, reference installs, the preflight table, crontab result). `::warning::` lines are things seen and not changed. On the host: `storage/logs/laravel.log`, `storage/logs/cron-scheduler.log`, `storage/logs/cron-queue.log`, `php artisan queue:failed`, `php artisan ehail:preflight`.

## What was verified, and what only the real host can show

Verified locally on the merged trunk (2026-11-10), details in `docs/R46_DECISIONS.md` D-1800 onward:

- The host scripts extracted from `deploy.yml` were run end to end against a **fake host**: an install of the pre-R-46 `main` with legacy rows (rides in every old status, encrypted identifiers, legacy documents), its docroot holding a stale `sw.js` and a real `fonts/` folder, a shared crontab with two other apps' lines. Result: SQLite online copy with integrity check, migrations and data migrations, reference installs (a second run creates nothing), the stale `sw.js` moved aside and linked, `ehail:preflight` FAIL stopping the release with the site down (a `.test` APP_URL) and a re-run bringing it up, cron lines installed twice with the other lines untouched, then the smoke step against the served docroot: all checks passed. One scheduler run and one queue run on the upgraded data logged no error.
- `scripts/ops/upgrade-rehearsal.sh` on SQLite and PostgreSQL 16 (it found and fixed a SQLite-only migration failure, D-1803).
- The signed-in smoke (163 checks), the PHP suite on SQLite and PostgreSQL, the JS suite, the build and manifest check, `actionlint` and `shellcheck` on every workflow and script.

Needs the real host to prove: `uapi`'s docroot answer, LiteSpeed/Apache honouring the app-folder deny file and serving symlinked static files, `flock` and the real `crontab`, `pg_dump` availability for the account, the PHP limits of the website (not the CLI), AutoSSL and HSTS on the real domain, the smoke from a GitHub runner through the host's firewall, the providers with real keys (routing, Paystack live webhooks, BulkSMS, Web Push to a real phone, SMTP).
