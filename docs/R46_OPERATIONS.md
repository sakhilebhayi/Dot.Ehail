# R-46 owner runbook: Dot.Ehail in production

For the person who owns the platform. Everything here is a command you run on the host (SSH into the cPanel account, `cd` to the app folder, `~/ehail.infodot.co.za/ehail`), a page in the operations centre (`https://ehail.infodot.co.za/ops`), or a setting in a `.env` (this app's or another Dot platform's). The pipeline and the host checklist are in `docs/deployment.md`; this file is what happens **after** a deploy and when something goes wrong. Decisions behind it: D-1800 onward in `docs/R46_DECISIONS.md`.

Two rules for every `.env` change below:

1. **The host runs from a cached configuration.** After editing `<app>/.env` run `php artisan config:cache` (or redeploy). The scheduler and the queue worker start a fresh process every minute, so they pick the change up once the cache is rebuilt.
2. **Check with `php artisan ehail:preflight`.** It reads the configuration the app really uses and prints PASS / INFO / OFF / WARN / FAIL with the fix. A provider that is switched off reads `OFF`, never an error. Its last line, **Can a passenger book?**, names everything that still stops a booking.

## 1. First steps after the first R-46 deploy

Do these once, in this order. Until steps 2 to 5 are done the site is up and safe but **nobody can book a ride**: that is the honest state, not a fault.

1. **Read the deploy log.** Look for `== Database restore point`, `N service areas and M zones created`, `Preflight OK` and `== Smoke check passed.`, then run `php artisan ehail:preflight` yourself and read every WARN line.

2. **Routing provider (the single most important step).** Without a road route there is no price and no booking. Pick one and put it in the host `.env`:
   - Google Routes API (best South African coverage; billed per request): create a server key in Google Cloud restricted to the Routes API (and Places API if you also want Google address search) and to the host's IP, then
     `EHAIL_ROUTING_DRIVER=google`, `EHAIL_GOOGLE_MAPS_SERVER_KEY=...`, optionally `EHAIL_GEOCODING_DRIVER=google` (type-ahead address search).
   - openrouteservice (cheapest real routing; free tier with limits): `EHAIL_ROUTING_DRIVER=ors`, `EHAIL_ORS_KEY=...`, optionally `EHAIL_GEOCODING_DRIVER=ors`.
   - Mapbox, or your own OSRM / Valhalla server: `mapbox` + `EHAIL_MAPBOX_SERVER_TOKEN`, `osrm` + `EHAIL_OSRM_URL`, `valhalla` + `EHAIL_VALHALLA_URL`.
   Then `config:cache` and preflight: `Routing  PASS`. Set a spending cap at the provider. Routes are cached for 10 minutes per ~11 m of start and end, and a circuit breaker stops calls for 60 s after 5 failures, so a provider outage shows "temporarily unavailable" instead of piling up timeouts.
   **Map tiles:** the default OpenStreetMap Foundation server has no SLA and may refuse heavy commercial traffic. Before traffic grows, contract a tile provider (Google Map Tiles, Mapbox, MapTiler, Stadia) and set `EHAIL_TILES_URL`, `EHAIL_TILES_ATTRIBUTION`, `EHAIL_TILES_KIND=` anything but `osm` (which adds the OSM "report a map issue" link); restrict the key to this site by HTTP referrer at the provider (tile URLs are visible in the browser). The enforced Content-Security-Policy takes the tile host from `EHAIL_TILES_URL` (`App\Domain\Geo\Support\GeoCsp`), so a new provider needs no code change; check a map renders after `config:cache`.

3. **Become the platform operator.** Sign in once through InfoDot (that creates your account), then on the host:
   `php artisan ehail:operator you@yourdomain.co.za` (`--revoke` withdraws it; both are audit-logged). In `/ops/team` give each other operator the narrowest role that fits (ops agent, dispatcher, verification reviewer, safety officer, support agent, finance, pricing manager, admin; matrix in `docs/R46_UI_OPS.md` section 2). An operator with no role rows has full access until an admin assigns one.

4. **Publish a real tariff.** `/ops/tariffs`: create a draft per category (base, per km, per minute, minimum, booking fee, waiting, cancellation and no-show fees, rounding), check it in `/ops/tariffs/simulator`, publish it. Set the commission in `/ops/tariffs/commission` (otherwise `EHAIL_COMMISSION_PERCENT`, default 20 %, applies). Review which categories are on in `/ops/tariffs/catalogue`. **No prices exist until you publish them**: migrations install none. If you ever ran a deploy with `install_example_tariff=install`, retire every "Example tariff: review before launch" rule set once yours is live (passengers see that label).

5. **A way to pay.**
   - Card (Paystack): in the Paystack dashboard (Settings, API Keys and Webhooks) set the webhook URL to `https://ehail.infodot.co.za/webhooks/paystack`. First run a whole ride with the **test** keys (`sk_test_...`, `pk_test_...`). For real money set the live keys **and** `PAYSTACK_ALLOW_LIVE=true` (the second switch; a live key without it is refused and preflight FAILS, which keeps a deploy down). Card checks charge R1.00 and refund it at once.
   - Cash: `EHAIL_CASH_ENABLED=true` (optionally only some categories: `EHAIL_CASH_CATEGORIES=economy,standard`). Drivers then owe the commission on cash trips; it is netted from their payouts.
   - With neither, every ride request is refused ("no way to collect the fare"), except business-account rides.

6. **Review the service areas.** The deploy installed approximate outlines for Greater Johannesburg (with OR Tambo), Pretoria, Cape Town and Durban, and airport zones JNB, CPT, DUR, all flagged *approximate*. Check them in `/ops/areas`; switch off the cities you do not serve yet. Edits are never overwritten by later deploys.

7. **Driver documents: existing drivers are blocked until approved.** With `EHAIL_VERIFICATION_REQUIRE_DOCUMENTS=true` (the default) every driver, including those approved before R-46, needs every required document (ID, licence, PrDP, profile photo; per vehicle: licence disc, roadworthy certificate, insurance, operating licence, front, back and interior photos) **approved** before going online. Legacy licence and ID uploads were carried over as *pending*, never auto-approved. Preflight's `Drivers / Verification` line counts who may go online and why the others may not. Tell drivers to upload in the app (`/drive/documents`), then review in `/ops/verification` (approve with the expiry date, reject or ask again with a reason). Only for a planned transition window, `EHAIL_VERIFICATION_REQUIRE_DOCUMENTS=false` lets approved drivers with an active vehicle online without documents (logged on every go-online decision); switch it back as soon as the review is done.

8. **Email, push and SMS.**
   - Email: a real SMTP transport (the `no-reply@infodot.co.za` account the other Dot apps use): `MAIL_MAILER=smtp`, `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`. With `log`, safety alerts to trusted contacts, receipts, document and password emails are recorded as **not delivered** (screens say so).
   - Web Push: `php artisan ehail:comms-vapid-keys` prints three lines; paste them into the host `.env`. Never change them later (every device would have to switch push on again).
   - SMS (safety alerts to trusted contacts and the on-call team): `EHAIL_SMS_DRIVER=bulksms`, `BULKSMS_TOKEN_ID`, `BULKSMS_TOKEN_SECRET`, optional `BULKSMS_SENDER`. The adapter was tested only against BulkSMS's documented API with faked HTTP: send yourself a test alert first.

9. **Safety escalation contacts.** An SOS goes to every operator in-app at once; if nobody acknowledges within 2 min (critical), 5 (high), 15 (medium) or 60 (low), it escalates to level 1 (on-call) and then level 2 (manager). Set `EHAIL_SAFETY_ONCALL_EMAILS` / `EHAIL_SAFETY_ONCALL_USER_IDS` and `EHAIL_SAFETY_MANAGER_EMAILS` / `EHAIL_SAFETY_MANAGER_USER_IDS` (comma lists). With none set, each level is recorded as "nobody configured". Dot.Ehail does not dispatch emergency help (no armed-response or medical partner is integrated); the SOS screen shows 10111, 10177 and 112.

10. **Ecosystem links.** InfoDot: `INFODOT_SERVICE_SECRET` equal to `ECOSYSTEM_SECRET_EHAIL` in InfoDot's `.env`, and `ehail` marked live in InfoDot's registry (until then nobody can sign in, because public sign-up is closed). Optional: Dot.Notify (`NOTIFY_INGEST_URL`, `NOTIFY_INGEST_SECRET`; Notify must know the new event names `safety.incident_alert`, `driver.document_reviewed`, `driver.document_expiring`, `driver.document_expired`), Dot.Analytics (`ANALYTICS_INGEST_URL`, `ANALYTICS_INGEST_SECRET`, `ANALYTICS_COMPANY_UUID`).

11. **Backups, on day one** (section 2), and an uptime monitor on `/up` (section 3).

12. **Last check.** `php artisan ehail:preflight` shows `Can a passenger book?  PASS`. Then do one complete ride with a real phone as driver and another as passenger (test keys first): book, accept, arrive, PIN, complete, pay, receipt, rating, and the driver's earnings line.

## 2. Backups and the restore drill

What must survive: the **database**, **`storage/app/private`** (driver documents and support attachments), and **`APP_KEY`** with the `.env`. Without the key, driver licence and ID numbers, saved card tokens and ride PINs cannot be decrypted. Keep a copy of the `.env` off the host (a password manager), never in the backup folder alone. The code is in git; the build is rebuilt by every deploy.

**What the pipeline does:** every deploy takes a copy of the files (`~/dot-ehail-<mode>-<time>.tgz`) and of the database (`~/dot-ehail-db-<mode>-<time>.dump` for PostgreSQL, `.sqlite` for SQLite, an online copy checked with `PRAGMA integrity_check`) **before** maintenance mode, keeping the newest three of each. That is a restore point per release, not a backup schedule.

**Daily backup.** `scripts/ops/backup.sh` writes `db-<UTC>.dump` (PostgreSQL, verified readable with `pg_restore --list`) or `db-<UTC>.sqlite` (online backup, integrity-checked), `files-<UTC>.tar.gz` (verified readable) and a `.sha256` beside each into `~/dot-ehail-backups` (mode 700), keeps the newest 14 of each, and exits non-zero with the reason on any failure. Run it once by hand:

```
cd ~/ehail.infodot.co.za/ehail && scripts/ops/backup.sh
```

Then add its cron line. **The marker must be `# ehail-backup`**: the deploy's crontab step replaces every line containing `# dot-ehail`, so a backup line marked `# dot-ehail-backup` would be wiped by the next deploy. The crontab is shared by every Dot app; write it under the same lock the deploys use (or, if the account runs the cron runner, put the command without the schedule in `~/.dot-cron.d/ehail-backup.cron`):

```
( flock 9; crontab -l > ~/.crontab.before-ehail-backup && { cat ~/.crontab.before-ehail-backup; echo '23 2 * * * cd ~/ehail.infodot.co.za/ehail && exec scripts/ops/backup.sh >> storage/logs/backup.log 2>&1 # ehail-backup'; } | crontab - ) 9>~/.dot-crontab.lock
crontab -l | grep -c .      # exactly one more line than before
```

`crontab -l` has been seen answering empty for a moment: if `~/.crontab.before-ehail-backup` is empty and you know the crontab is not, stop and do not write. 02:23 sits away from the other apps' minute-zero jobs and before this app's 02:40 trail purge.

- **Off the account.** A backup on the same account does not survive losing the account. Set `BACKUP_OFFSITE=user@otherhost:/path/` in the cron line (`BACKUP_OFFSITE=... scripts/ops/backup.sh`) to rsync the folder out after each run, or fetch `~/dot-ehail-backups` from your own machine weekly.
- **Disk.** The script refuses to run with under 500 MB free; `ehail:preflight` warns under 1 GB. Lower the count with `BACKUP_KEEP=7` if needed.
- **POPIA.** Backups contain identity documents and trip data: the folder is mode 700, and erased data stays in backups until they age out (14 days by default). Say so in the privacy notice.

**Restore drill, monthly.** `scripts/ops/restore-drill.sh` restores the newest database backup into a scratch database (PostgreSQL) or scratch file (SQLite), never the live one, unpacks the newest files archive into a temp folder, checks the checksums, that every product table exists, the row counts beside the live ones, and that every verification document older than the archive has its file, then prints the seconds it took (your real recovery time). Shared hosts often refuse `CREATE DATABASE`: create an empty database in cPanel, grant it to the same role, pass `--target-db NAME`. A drill was run against a fake host for both engines (PASS) and fails, as it should, when a document file is missing from the archive.

### Restore for real

1. `php artisan down`.
2. Choose the copy: `ls -lt ~/dot-ehail-backups` (or `~/dot-ehail-db-deploy-*` for a bad release). Verify it from inside its folder: `sha256sum -c db-<stamp>.<ext>.sha256`.
3. PostgreSQL: `pg_restore --clean --if-exists --no-owner --exit-on-error -h <DB_HOST> -U <DB_USERNAME> -d <DB_DATABASE> db-<stamp>.dump`. SQLite: copy `db-<stamp>.sqlite` over the `DB_DATABASE` file and delete its `-wal` and `-shm` files. Everything written after the backup is lost.
4. Files, only if they were lost: `tar xzf files-<stamp>.tar.gz -C storage/app`.
5. The same `APP_KEY` as when the backup was taken. `php artisan config:cache && php artisan up`, then `php artisan ehail:preflight`.

Never run `php artisan migrate:rollback` on the live database: several R-46 `down()` methods are lossy (the ride-status data migration folds new states back into old ones).

## 3. Monitoring and thresholds

The host has no monitoring stack. These are the signals that exist, with the threshold that means act.

| Signal | How | Act when |
|---|---|---|
| Uptime | an external monitor on `https://ehail.infodot.co.za/up` (200 = the app boots and the database answers) | 2 misses in a row. A failed deploy leaves maintenance mode on purpose (503): read the run log. |
| `/up/realtime` | answers 503 `degraded` on shared hosting by design (no Reverb) | **do not** alert on it here; it matters only on a host that runs Reverb |
| Scheduler | `storage/logs/cron-scheduler.log` is rewritten every minute; preflight's `Cron seen` | older than 2 min: offers stall without polling, rides never expire, failed charges are never retried. `crontab -l | grep -c '# dot-ehail'` must be 2. |
| Queue | `php artisan queue:failed`; preflight's `Backlog` | any job older than 15 min, or any failed job: settlement, receipts and notifications are not leaving (`queue:retry all` after fixing the cause) |
| Safety | `/ops/safety`: incidents by severity with timers | an unacknowledged critical incident after 2 min escalates automatically; check the on-call contacts actually received it |
| Money | `/ops/finance` (issues), `/ops/finance/reconciliation`; `money:reconcile` runs 03:30 daily | any open finance issue (`webhook_failed`, `card_setup_refund`, a mismatch); a completed ride unpaid for over `EHAIL_UNPAID_FARE_ALERT_HOURS` (24 h) is listed |
| Payouts | `/ops/finance/payouts`: a batch is drafted on `EHAIL_PAYOUT_DAY` (Monday) at 06:00 | a draft not exported and paid by Wednesday; a transfer stuck in processing for a day |
| Verification | `/ops/verification` queue with waiting time; `/ops/verification/expiry` | documents waiting over 24 h (drivers cannot earn); expiries in the next 7 days |
| Errors | `storage/logs/laravel.log` (with `LOG_LEVEL=warning` it holds real problems only) | any `production.ERROR`: send it with the time to the developer |
| Providers | preflight; `grep -E 'routing_failed|geocoding' storage/logs/laravel.log` | routing failures several times an hour: the provider key, quota or outage |
| Backups | last line of `storage/logs/backup.log`; monthly drill | not today's `backup ok` |
| Disk | preflight `Free disk space` | under 1 GB: prune old rollback copies in `~` first |
| CSP | the policy is enforced; `grep csp.violation storage/logs/laravel*.log` | a violation from a real page (something was blocked). `SECURITY_CSP_REPORT_ONLY=true` + `config:cache` is the escape hatch while it is fixed (docs/R46_SECURITY.md) |

Weekly: `php artisan ehail:preflight --strict` (exits 1 on any warning; useful in a scheduled check).

## 4. Incidents and escalation

Fill in this table and keep a printed copy with the operations team. Nothing in the app can do this for you.

| Role | Name | Phone | Email |
|---|---|---|---|
| Platform owner (final decisions, money, legal) | | | |
| Safety on-call (first escalation for an SOS) | | | |
| Safety manager (second escalation) | | | |
| Developer on call (outages, deploys) | | | |
| Hosting provider support (cPanel account) | | | |
| Paystack support (payments, payouts) | | | |
| Routing / maps provider support | | | |

- **A rider or driver in danger (SOS).** Emergency services first: 10111 (police), 10177 (ambulance), 112 from a mobile. Then in `/ops/safety`: acknowledge (stops the escalation timer), take ownership, call the person, note every action on the incident timeline, resolve with the outcome. The incident keeps the trip's location trail until it is closed.
- **The site is down.** Check `/up` and the last deploy run. In maintenance mode after a failed deploy: the log's `::error::` line names the cause; fix it and re-run the workflow (it brings the site up itself), or restore (section 5). Not a deploy: hosting provider status, disk, PHP errors in the log.
- **Booking fails for everyone.** `php artisan ehail:preflight`: the `Can a passenger book?` line names the missing piece (routing provider down or out of quota, tariff retired, Paystack key refused, cash off).
- **Payments wrong** (double charge, money not received): Paystack dashboard first, then `/ops/finance`. Refunds go through `/ops/finance` (never directly in Paystack, or the ledger and the driver's earnings will not match).
- **Personal data exposed** (a leaked export, a wrong person seeing a trip): stop the exposure (revoke trip shares, suspend the account), record what was exposed, to whom and when. POPIA section 22 requires notifying the Information Regulator and the people affected as soon as reasonably possible: get legal advice the same day.

## 5. Rollback

**A bad release (the site is up but wrong).** Prefer a forward fix: revert the change on `main` and run the deploy again (the log prints the release before and after). The R-46 migrations are additive except the ride-status rename (section 2: never `migrate:rollback`).

**A failed release (the site is in maintenance mode).** Read the `::error::` line: usually `ehail:preflight` FAIL (it names the setting), a migration error, or a missing PHP extension. Fix and re-run; or restore the previous release: stop writes, `tar xzf ~/dot-ehail-deploy-<time>.tgz` over the app folder, `composer install --no-dev`, re-deploy the previous commit to rebuild the frontend, `php artisan up`. The database stays as migrated unless you restore it (section 2, with the release's `~/dot-ehail-db-deploy-<time>.*`); everything written since is then lost.

## 6. Running on SQLite

The platform is built and load-tested for PostgreSQL. If the host's `.env` says `DB_CONNECTION=sqlite` (Dot.Design's host turned out to), it works and the deploy, backups and drill all handle it, but there is one writer at a time while every online driver writes a position every 4 s and every screen polls. Preflight's `Database / Driver` line prints the connection's transaction mode, busy timeout and journal mode; dispatch recommends `IMMEDIATE`, a 10000 ms busy timeout and `wal` (docs/R46_DISPATCH.md), and `CACHE_STORE=file` keeps the rate limiters' writes off the database file (D-818). Move to PostgreSQL before real traffic: create the database in cPanel, point `DB_*` at it, deploy (the migrations build the schema), then copy the data across with a one-off export planned with the developer. There is no automated SQLite-to-PostgreSQL converter in this repository.

## 7. Routine

| When | What |
|---|---|
| After any `.env` edit | `php artisan config:cache`, `php artisan ehail:preflight` |
| Daily | `/ops/live` and `/ops/safety`; the verification queue; finance issues |
| Weekly (Monday) | export and pay the payout batch (`/ops/finance/payouts`), mark it paid; `ehail:preflight --strict`; failed jobs |
| Monthly | restore drill; provider bills (routing, tiles, SMS) against expectations; prune old rollback copies in `~`; review approximate service areas and tariffs |
| When prices change | a new tariff version in `/ops/tariffs` (versions are immutable; quotes record which version they used) |
| When a driver's document expires | the daily 05:00 job warns at 30 and 7 days and takes the driver offline at expiry; renewals keep the old document valid until the new one is approved |
