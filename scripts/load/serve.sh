#!/usr/bin/env bash
# Multi-worker PHP server for the load tests (DEVELOPMENT ONLY; docs/R46_PERF.md section 1).
#   scripts/load/serve.sh <workers> <port> <sqlite|pgsql> <database> [osrm_url]
# PHP's built-in server with PHP_CLI_SERVER_WORKERS forks N single-threaded workers on one socket: the same
# shape as a PHP-FPM pool with pm=static and N children (one request per worker at a time), with OPcache on.
# Production-like drivers: database sessions, database cache, database queue, the probe headers on.
set -euo pipefail
cd "$(dirname "$0")/../.."

WORKERS="${1:-4}"; PORT="${2:-8621}"; CONN="${3:-sqlite}"; DBNAME="${4:?database}"; OSRM="${5:-http://127.0.0.1:8790}"

export APP_ENV=local APP_DEBUG=false LOG_LEVEL=warning
export SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=database BROADCAST_CONNECTION=log
export EHAIL_PERF_PROBE="${EHAIL_PERF_PROBE:-true}" EHAIL_CASH_ENABLED=true
export EHAIL_ROUTING_DRIVER=osrm EHAIL_OSRM_URL="$OSRM"
export DB_CONNECTION="$CONN" DB_DATABASE="$DBNAME"
if [ "$CONN" = "sqlite" ]; then
  export DB_JOURNAL_MODE=wal DB_TRANSACTION_MODE=IMMEDIATE DB_BUSY_TIMEOUT=10000
fi
export PHP_CLI_SERVER_WORKERS="$WORKERS"

# Laravel's router script resolves the public directory from the working directory (as `artisan serve` does).
cd public
exec php -d opcache.enable=1 -d opcache.validate_timestamps=0 -d memory_limit=256M \
  -S "127.0.0.1:$PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
