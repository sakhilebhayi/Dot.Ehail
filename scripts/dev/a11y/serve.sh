#!/bin/sh
# R-46 a11y (dev only): the dev server the accessibility scripts run against, with the local geo stand-in and cash
# trips on. Settings live in this process's environment, never in .env, so the test suite never sees them.
#   sh scripts/dev/a11y/serve.sh [port=8623] [stub_port=8693]
cd "$(dirname "$0")/../../.." || exit 1
PORT="${1:-8623}"; STUB="${2:-8693}"
node scripts/dev/a11y/geo-stub.mjs "$STUB" &
export EHAIL_ROUTING_DRIVER=osrm EHAIL_OSRM_URL="http://127.0.0.1:$STUB" EHAIL_GEOCODING_DRIVER=nominatim \
  EHAIL_NOMINATIM_URL="http://127.0.0.1:$STUB" EHAIL_NOMINATIM_RPS=50 EHAIL_CASH_ENABLED=true CACHE_STORE=file \
  APP_URL="http://127.0.0.1:$PORT" PHP_CLI_SERVER_WORKERS=4
cd public && exec php -S "127.0.0.1:$PORT" ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
