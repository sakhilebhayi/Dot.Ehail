#!/usr/bin/env bash
# One reproducible load run (DEVELOPMENT ONLY; docs/R46_PERF.md):
#   scripts/load/bench.sh <sqlite|pgsql> <workers> <protocol legacy|sync> <outdir> [run.mjs options...]
# SQLite: a throwaway file <outdir>/ehail-perf-load.sqlite. PostgreSQL: creates ehail_perf_<random>, drops it on exit
# (never touches another database). Starts the OSRM stub (8790) if it is not running, builds the world (500 online
# drivers, 150 passengers), serves it with <workers> PHP workers on 8621, runs the load, then checks the invariants
# and runs the cron queue worker once to measure the backlog the run left.
set -euo pipefail
cd "$(dirname "$0")/../.."

ENGINE="${1:?sqlite|pgsql}"; WORKERS="${2:?workers}"; PROTOCOL="${3:?legacy|sync}"; OUT="${4:?outdir}"; shift 4
DRIVERS="${DRIVERS:-500}"; PASSENGERS="${PASSENGERS:-150}"; PORT="${PORT:-8621}"
mkdir -p "$OUT"
TAG="${ENGINE}-w${WORKERS}-${PROTOCOL}"

if ! curl -s -o /dev/null http://127.0.0.1:8790/__stats; then
  node scripts/load/stub-osrm.mjs 8790 > "$OUT/osrm.log" 2>&1 &
  OSRM_PID=$!
  sleep 0.5
fi
curl -s "http://127.0.0.1:8790/__mode?m=ok" > /dev/null

if [ "$ENGINE" = "sqlite" ]; then
  DBNAME="$(cd "$OUT" && pwd)/ehail-perf-load.sqlite"
  rm -f "$DBNAME" "$DBNAME-wal" "$DBNAME-shm"; touch "$DBNAME"
  export DB_JOURNAL_MODE=wal DB_TRANSACTION_MODE=IMMEDIATE DB_BUSY_TIMEOUT=10000
else
  DBNAME="ehail_perf_$(php -r 'echo bin2hex(random_bytes(4));')"
  createdb -h 127.0.0.1 "$DBNAME"
  export DB_HOST=127.0.0.1 DB_PORT=5432 DB_USERNAME="$(whoami)" DB_PASSWORD=""
fi

cleanup() {
  [ -n "${SERVER_PID:-}" ] && kill "$SERVER_PID" 2>/dev/null || true
  pkill -f "127.0.0.1:$PORT" 2>/dev/null || true
  [ -n "${OSRM_PID:-}" ] && kill "$OSRM_PID" 2>/dev/null || true
  if [ "$ENGINE" = "pgsql" ]; then sleep 0.5; dropdb -h 127.0.0.1 --if-exists "$DBNAME"; fi
}
trap cleanup EXIT

ENVV=(APP_ENV=local SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=database EHAIL_CASH_ENABLED=true
      EHAIL_ROUTING_DRIVER=osrm EHAIL_OSRM_URL=http://127.0.0.1:8790 DB_CONNECTION="$ENGINE" DB_DATABASE="$DBNAME")

env "${ENVV[@]}" php -d memory_limit=1G scripts/load/world.php setup "$DRIVERS" "$PASSENGERS" "$OUT/world-$ENGINE.json"

scripts/load/serve.sh "$WORKERS" "$PORT" "$ENGINE" "$DBNAME" > "$OUT/server-$TAG.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT/up" && break; sleep 0.2; done

node scripts/load/run.mjs --world "$OUT/world-$ENGINE.json" --base "http://127.0.0.1:$PORT" --protocol "$PROTOCOL" \
  --osrm http://127.0.0.1:8790 --out "$OUT/result-$TAG.json" "$@" | tee "$OUT/run-$TAG.txt"

echo "--- queue backlog after the run (one cron minute: queue:work --stop-when-empty --max-time=50)"
START=$(date +%s)
env "${ENVV[@]}" php artisan queue:work --stop-when-empty --max-time=50 --quiet || true
echo "queue worker took $(( $(date +%s) - START )) s"
echo "--- invariants (after the queue: settlement, ledger, notifications)"
env "${ENVV[@]}" php scripts/load/world.php invariants | tee "$OUT/invariants-$TAG.json"
echo "--- stats"
env "${ENVV[@]}" php scripts/load/world.php stats | tee "$OUT/stats-$TAG.json"
