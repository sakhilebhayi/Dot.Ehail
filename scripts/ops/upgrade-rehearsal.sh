#!/usr/bin/env bash
# Upgrade rehearsal: proves the R-46 migrations are safe on a POPULATED pre-R-46 database, not only on an empty one.
#
#   scripts/ops/upgrade-rehearsal.sh             on a throw-away SQLite file
#   scripts/ops/upgrade-rehearsal.sh --pgsql     on a throw-away PostgreSQL database (PGHOST/PGPORT/PGUSER/PGPASSWORD;
#                                                the role must be allowed to CREATE DATABASE)
#
# 1. Builds the schema exactly as `main` had it before R-46 (every migration up to 2026_10_20_100100, copied to a
#    temp folder and run with --path).
# 2. Fills it with legacy rows the way the old app wrote them (scripts/ops/upgrade-rehearsal.php seed): rides in every
#    old status (requested, accepted, en_route, arrived, in_progress, completed, cancelled by the passenger / by an
#    operator / by nobody recorded, expired, needs_review), a driver holding two accepted rides and a passenger with two
#    open requests (the guards R-46 adds), rides without coordinates, paid / due / waived fares, ride payments in every
#    state, ratings, encrypted licence and ID numbers, approved / pending / suspended drivers, a fleet of one, legacy
#    documents of every type, notifications, audit rows.
# 3. Runs `php artisan migrate --force` (what deploy.yml runs), then verifies (upgrade-rehearsal.php verify): no row
#    lost, every status mapped as docs/R46_ARCHITECTURE.md section 2 says, timeline and guards filled, duplicates listed
#    for operators, identifiers still decrypt, legacy documents carried over as pending, the trunk models read every
#    ride. Then a second migrate is a no-op, the reference installs are idempotent, and ehail:preflight reads the
#    upgraded database (its Drivers row says how many legacy drivers are now blocked until their documents are approved).
#
# Never touches a database it did not create. Exit 0 only when every check passed.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
PHP="${PHP:-php}"
DRIVER=sqlite
[ "${1:-}" = "--pgsql" ] && DRIVER=pgsql
WORK="$(mktemp -d "${TMPDIR:-/tmp}/dot-ehail-rehearsal.XXXXXX")"
PGDB=""

cleanup() {
  if [ -n "$PGDB" ]; then dropdb -h "${PGHOST:-127.0.0.1}" -p "${PGPORT:-5432}" -U "${PGUSER:-$(whoami)}" --if-exists "$PGDB" || echo "could not drop $PGDB; drop it by hand" >&2; fi
  rm -rf "$WORK"
}
trap cleanup EXIT

# The last migration on main before R-46. Everything that sorts before the next name is the legacy schema.
BASELINE_NEXT="2026_10_20_100101"
mkdir -p "$WORK/baseline" "$WORK/storage/framework/cache/data" "$WORK/storage/framework/sessions" "$WORK/storage/framework/views" "$WORK/storage/logs" "$WORK/storage/app/private" "$WORK/cache"
for f in database/migrations/*.php; do
  b="$(basename "$f")"
  if [[ "$b" < "$BASELINE_NEXT" ]]; then cp "$f" "$WORK/baseline/"; fi
done

APP_KEY="base64:$($PHP -r 'echo base64_encode(random_bytes(32));')"
export APP_KEY APP_ENV=production APP_DEBUG=false APP_URL=https://ehail.example.test REHEARSAL=1
export LARAVEL_STORAGE_PATH="$WORK/storage"
export APP_CONFIG_CACHE="$WORK/cache/config.php" APP_ROUTES_CACHE="$WORK/cache/routes-v7.php" \
       APP_EVENTS_CACHE="$WORK/cache/events.php" APP_SERVICES_CACHE="$WORK/cache/services.php" \
       APP_PACKAGES_CACHE="$WORK/cache/packages.php" VIEW_COMPILED_PATH="$WORK/storage/framework/views"
export CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array BROADCAST_CONNECTION=log LOG_CHANNEL=single DB_URL=''

if [ "$DRIVER" = "pgsql" ]; then
  PGDB="ehail_rehearsal_$($PHP -r 'echo bin2hex(random_bytes(5));')"
  createdb -h "${PGHOST:-127.0.0.1}" -p "${PGPORT:-5432}" -U "${PGUSER:-$(whoami)}" "$PGDB"
  export DB_CONNECTION=pgsql DB_HOST="${PGHOST:-127.0.0.1}" DB_PORT="${PGPORT:-5432}" DB_DATABASE="$PGDB" DB_USERNAME="${PGUSER:-$(whoami)}" DB_PASSWORD="${PGPASSWORD:-}"
  echo "== throw-away PostgreSQL database $PGDB"
else
  : > "$WORK/rehearsal.sqlite"
  export DB_CONNECTION=sqlite DB_DATABASE="$WORK/rehearsal.sqlite" DB_FOREIGN_KEYS=true
  echo "== throw-away SQLite file $WORK/rehearsal.sqlite"
fi

echo "== 1. the pre-R-46 schema ($(ls "$WORK/baseline" | wc -l | tr -d ' ') migrations, up to main's 2026_10_20_100100)"
$PHP artisan migrate --force --realpath --path="$WORK/baseline" > "$WORK/baseline.log" 2>&1 || { tail -20 "$WORK/baseline.log"; exit 1; }

echo "== 2. legacy rows"
$PHP scripts/ops/upgrade-rehearsal.php seed > "$WORK/seed.json" || { cat "$WORK/seed.json"; echo "UPGRADE REHEARSAL FAILED: seeding the legacy rows failed"; exit 1; }
$PHP -r '$d=json_decode(file_get_contents($argv[1]),true); foreach($d["counts"] as $t=>$n){ echo "   $t: $n\n"; }' "$WORK/seed.json"

echo "== 3. php artisan migrate --force (the R-46 migrations, as deploy.yml runs them)"
PENDING="$($PHP artisan migrate:status --pending 2>/dev/null | grep -c 'Pending' || true)"
START=$(date +%s)
$PHP artisan migrate --force > "$WORK/migrate.log" 2>&1 || { cat "$WORK/migrate.log"; echo "UPGRADE REHEARSAL FAILED: migrate failed on the populated database"; exit 1; }
echo "   $PENDING migration(s) ran in $(( $(date +%s) - START )) s"

echo "== 4. verify"
$PHP scripts/ops/upgrade-rehearsal.php verify < "$WORK/seed.json"

echo "== 5. a second migrate is a no-op, reference installs are idempotent"
$PHP artisan migrate --force 2>&1 | grep -q 'Nothing to migrate' && echo "  ok    second migrate: nothing to migrate" || { echo "  FAIL  a second migrate did something"; exit 1; }
FIRST="$($PHP artisan ehail:geo-reference-areas)"; SECOND="$($PHP artisan ehail:geo-reference-areas)"
echo "   first:  $FIRST"
echo "   second: $SECOND"
case "$SECOND" in 0\ service\ areas\ and\ 0\ zones*) echo "  ok    ehail:geo-reference-areas is idempotent" ;; *) echo "  FAIL  the second ehail:geo-reference-areas created rows"; exit 1 ;; esac

echo "== 6. ehail:preflight on the upgraded database"
$PHP artisan ehail:preflight --json > "$WORK/preflight.json" || true
$PHP -r '
  $d = json_decode(file_get_contents($argv[1]), true);
  $rows = [];
  foreach ($d["results"] as $r) { $rows[$r["area"]."/".$r["check"]] = $r; }
  foreach (["Database/Migrations", "Reference data/Ride categories", "Reference data/Service areas", "Reference data/Tariff", "Drivers/Verification", "Readiness/Can a passenger book?"] as $k) {
    if (isset($rows[$k])) { printf("   %-4s %s: %s\n", $rows[$k]["status"], $k, $rows[$k]["detail"]); }
  }
  exit(($rows["Database/Migrations"]["status"] ?? "") === "PASS" ? 0 : 1);
' "$WORK/preflight.json" || { echo "  FAIL  preflight does not see a fully migrated database"; exit 1; }

echo
echo "UPGRADE REHEARSAL ($DRIVER) PASSED."
