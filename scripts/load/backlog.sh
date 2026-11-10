#!/usr/bin/env bash
# Notification backlog drill (DEVELOPMENT ONLY; docs/R46_PERF.md section 3):
#   scripts/load/backlog.sh <sqlite-file|ehail_perf_db> <messages>
# Needs a perf world (bench.sh leaves the SQLite one) and the SMTP sink (started here if absent). Routes <messages>
# notifications (each: in-app + push + email copy), then times one cron minute of `queue:work` and one
# `ehail:comms-deliver`, and prints the delivery rows by channel and status.
set -euo pipefail
cd "$(dirname "$0")/../.."
DBNAME="${1:?database}"; MESSAGES="${2:-500}"
if [[ "$DBNAME" == ehail_perf_* ]]; then CONN=pgsql; else CONN=sqlite; fi
if ! nc -z 127.0.0.1 2525 2>/dev/null; then node scripts/load/stub-smtp.mjs 2525 > /dev/null 2>&1 & SMTP_PID=$!; sleep 0.5; fi
trap '[ -n "${SMTP_PID:-}" ] && kill "$SMTP_PID" 2>/dev/null || true' EXIT
E=(APP_ENV=local DB_CONNECTION="$CONN" DB_DATABASE="$DBNAME" DB_HOST=127.0.0.1 DB_USERNAME="$(whoami)" DB_PASSWORD=""
   DB_JOURNAL_MODE=wal DB_TRANSACTION_MODE=IMMEDIATE QUEUE_CONNECTION=database CACHE_STORE=database
   MAIL_MAILER=smtp MAIL_HOST=127.0.0.1 MAIL_PORT=2525 MAIL_SCHEME=smtp)
env "${E[@]}" php scripts/load/backlog.php "$MESSAGES"
S=$(date +%s.%N)
env "${E[@]}" php artisan queue:work --stop-when-empty --max-time=50 --quiet || true
echo "queue:work (one cron minute) took $(echo "$(date +%s.%N) - $S" | bc) s"
env "${E[@]}" php scripts/load/backlog.php counts
S=$(date +%s.%N)
env "${E[@]}" php artisan ehail:comms-deliver
echo "ehail:comms-deliver took $(echo "$(date +%s.%N) - $S" | bc) s"
env "${E[@]}" php scripts/load/backlog.php counts
