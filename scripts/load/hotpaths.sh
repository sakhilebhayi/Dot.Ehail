#!/usr/bin/env bash
# Hot paths at volume (DEVELOPMENT ONLY; docs/R46_PERF.md section 5):  scripts/load/hotpaths.sh <sqlite|pgsql> <outdir>
# SQLite: <outdir>/hot-perf.sqlite; PostgreSQL: a throwaway ehail_perf_<random>, dropped on exit.
set -euo pipefail
cd "$(dirname "$0")/../.."
ENGINE="${1:?sqlite|pgsql}"; OUT="${2:?outdir}"; mkdir -p "$OUT"
if ! curl -s -o /dev/null http://127.0.0.1:8790/__stats; then node scripts/load/stub-osrm.mjs 8790 > /dev/null 2>&1 & OSRM_PID=$!; sleep 0.5; fi
if [ "$ENGINE" = "sqlite" ]; then
  DBNAME="$(cd "$OUT" && pwd)/hot-perf.sqlite"; rm -f "$DBNAME" "$DBNAME-wal" "$DBNAME-shm"; touch "$DBNAME"
else
  DBNAME="ehail_perf_$(php -r 'echo bin2hex(random_bytes(4));')"; createdb -h 127.0.0.1 "$DBNAME"
fi
cleanup() { [ -n "${OSRM_PID:-}" ] && kill "$OSRM_PID" 2>/dev/null; if [ "$ENGINE" = "pgsql" ]; then dropdb -h 127.0.0.1 --if-exists "$DBNAME"; fi; }
trap cleanup EXIT
E=(APP_ENV=local SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=database EHAIL_CASH_ENABLED=true
   EHAIL_ROUTING_DRIVER=osrm EHAIL_OSRM_URL=http://127.0.0.1:8790 DB_CONNECTION="$ENGINE" DB_DATABASE="$DBNAME"
   DB_HOST=127.0.0.1 DB_USERNAME="$(whoami)" DB_PASSWORD="" DB_JOURNAL_MODE=wal)
env "${E[@]}" php -d memory_limit=2G scripts/load/hotpaths.php seed
env "${E[@]}" php -d memory_limit=2G scripts/load/hotpaths.php measure | tee "$OUT/hotpaths-$ENGINE.txt"
