#!/usr/bin/env bash
# Production-like signed-in smoke test for Dot.Ehail.
#
#   tests/Support/signed-in-smoke.sh            (needs vendor/ and a built frontend: composer install, npm run build)
#
# Boots the app the way the live host runs it: APP_ENV=production, APP_DEBUG=false, cached config / routes / events /
# views, the BUILT assets, a throw-away SQLite database with the reference areas and the example tariff installed, the
# PHP built-in server in front of public/. Signs in over HTTP (CSRF and all) as a passenger, a driver, an operator, a
# fleet owner and a business admin, and requests every key page and JSON endpoint of each, asserting the status AND a
# landmark in the body. Also checks the signed-out surface (landing, login, fare calculator, health probes, a bogus
# trip-share link: neutral 404, no cookie), the security headers, the installable-app files (manifest, icons, sw.js,
# offline page), the self-hosted fonts and every built CSS/JS file. It fails when the application log gained an ERROR
# line: a page that answers 200 after swallowing an exception is still broken.
#
# Why: CI tests fake Vite and never cache routes; outages that only a signed-in, cached, built app shows (a limiter
# registered in a route file, a view naming an asset that was not built) slipped past them on other Dot platforms.
#
# Everything lives under one temp folder (database, storage, caches) via LARAVEL_STORAGE_PATH and APP_*_CACHE, so the
# working tree, bootstrap/cache and your .env are untouched. Shell environment variables win over .env.
# Optional: PORT (default 8994), PHP (default php), KEEP=1 (keep the temp folder and print its path).
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT" || exit 2
PHP="${PHP:-php}"
PORT="${PORT:-8994}"
BASE="http://127.0.0.1:$PORT"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/dot-ehail-smoke.XXXXXX")"
SERVER_PID=""
FAILS=0
CHECKS=0

cleanup() {
  [ -n "$SERVER_PID" ] && kill "$SERVER_PID" 2>/dev/null && wait "$SERVER_PID" 2>/dev/null
  if [ "${KEEP:-0}" = "1" ]; then echo "kept: $WORK"; else rm -rf "$WORK"; fi
}
trap cleanup EXIT

die() { echo "SMOKE SETUP FAILED: $*" >&2; [ -f "$WORK/setup.log" ] && tail -30 "$WORK/setup.log" >&2; exit 2; }
# Random strings come from PHP, never from `tr ... </dev/urandom | head`: under pipefail that pipe dies of SIGPIPE.
rand_hex() { $PHP -r 'echo bin2hex(random_bytes((int) $argv[1]));' "$1"; }

[ -f public/build/manifest.json ] || die "public/build/manifest.json is missing: run npm run build first."
[ -f vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php ] || die "vendor/ is missing: run composer install."
command -v curl >/dev/null || die "curl is required."

# ---- the production-like environment --------------------------------------------------------------------------------
mkdir -p "$WORK/storage/app/private" "$WORK/storage/framework/cache/data" "$WORK/storage/framework/sessions" \
         "$WORK/storage/framework/views" "$WORK/storage/logs" "$WORK/cache"
: > "$WORK/smoke.sqlite"
export APP_ENV=production APP_DEBUG=false APP_URL="$BASE" APP_NAME="Dot.Ehail"
APP_KEY="base64:$($PHP -r 'echo base64_encode(random_bytes(32));')"
export APP_KEY
export LARAVEL_STORAGE_PATH="$WORK/storage"
export APP_CONFIG_CACHE="$WORK/cache/config.php" APP_ROUTES_CACHE="$WORK/cache/routes-v7.php" \
       APP_EVENTS_CACHE="$WORK/cache/events.php" APP_SERVICES_CACHE="$WORK/cache/services.php" \
       APP_PACKAGES_CACHE="$WORK/cache/packages.php" VIEW_COMPILED_PATH="$WORK/storage/framework/views"
export DB_CONNECTION=sqlite DB_DATABASE="$WORK/smoke.sqlite" DB_FOREIGN_KEYS=true DB_URL=''
export SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync MAIL_MAILER=log BROADCAST_CONNECTION=log
export LOG_CHANNEL=single LOG_LEVEL=warning SESSION_SECURE_COOKIE=false
export INFODOT_SERVICE_SECRET='' ALLOW_REGISTRATION=false NOTIFY_INGEST_URL='' ANALYTICS_INGEST_URL=''
export PAYSTACK_SECRET_KEY='' PAYSTACK_PUBLIC_KEY='' EHAIL_ROUTING_DRIVER=none EHAIL_CASH_ENABLED=true
export EHAIL_SMS_DRIVER=null EHAIL_VAPID_PUBLIC_KEY='' EHAIL_VAPID_PRIVATE_KEY=''
SMOKE_PASSWORD="$(rand_hex 16)"
export SMOKE_PASSWORD SMOKE_SEED=1

step() { echo "== $*"; }
run() { "$@" >> "$WORK/setup.log" 2>&1 || die "'$*' failed"; }

step "preparing a throw-away database and caches ($WORK)"
run $PHP artisan migrate --force
run $PHP artisan ehail:geo-reference-areas
run $PHP artisan ehail:pricing:install-example-tariff --force
$PHP tests/Support/smoke-seed.php > "$WORK/seed.json" 2>> "$WORK/setup.log" || die "seeding the smoke people failed"
run $PHP artisan config:cache
run $PHP artisan route:cache
run $PHP artisan view:cache
run $PHP artisan event:cache

seed() { $PHP -r '$d=json_decode(file_get_contents($argv[1]),true); echo $d[$argv[2]] ?? "";' "$WORK/seed.json" "$1"; }

step "ehail:preflight (warnings are expected on a laptop; failures are not)"
# Everything is real here except the host: a production boot on 127.0.0.1 makes the APP_URL check fail by design, so
# that single failure is the one accepted.
$PHP artisan ehail:preflight --json > "$WORK/preflight.json" 2>> "$WORK/setup.log"
BADPRE="$($PHP -r '$d=json_decode(file_get_contents($argv[1]),true); if(!is_array($d)){ echo "  preflight printed no JSON\n"; exit; } foreach($d["results"] as $r){ if($r["status"]==="FAIL" && $r["check"]!=="APP_URL"){ echo "  FAIL ".$r["area"]."/".$r["check"].": ".$r["detail"]."\n"; } }' "$WORK/preflight.json")"
[ -z "$BADPRE" ] || { echo "$BADPRE"; die "ehail:preflight failed on a production-like boot"; }

step "starting the server on $BASE"
curl -s -o /dev/null --max-time 1 "$BASE/up" && die "something already answers on $BASE; set PORT to a free port."
( cd public && exec $PHP -S "127.0.0.1:$PORT" "$ROOT/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php" ) > "$WORK/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 50); do
  curl -s -o /dev/null --max-time 2 "$BASE/up" && break
  kill -0 "$SERVER_PID" 2>/dev/null || die "the server stopped: $(tail -5 "$WORK/server.log")"
  sleep 0.2
done

# ---- assertion helpers ----------------------------------------------------------------------------------------------
JAR="$WORK/guest.jar"
BODY="$WORK/body.out"
HEAD="$WORK/head.out"

pass() { CHECKS=$((CHECKS + 1)); printf '  ok    %s\n' "$1"; }
fail() { CHECKS=$((CHECKS + 1)); FAILS=$((FAILS + 1)); printf '  FAIL  %s\n' "$1"; }
title() { grep -o '<title>[^<]*' "$BODY" | head -1 | sed 's/<title>//'; }

# get PATH EXPECTED_STATUS LANDMARK_REGEX [curl args...]  (current cookie jar; an empty LANDMARK skips the body check)
get() {
  local path="$1" want="$2" landmark="$3"; shift 3
  local code
  code=$(curl -s --max-time 30 -b "$JAR" -c "$JAR" -D "$HEAD" -o "$BODY" -w '%{http_code}' "$@" "$BASE$path")
  if [ "$code" != "$want" ]; then fail "GET $path answered $code, expected $want ($(title))"; return 1; fi
  if [ -n "$landmark" ] && ! grep -Eqi -- "$landmark" "$BODY"; then fail "GET $path answered $want but the body lacks /$landmark/ (title: $(title))"; return 1; fi
  if grep -qiE 'Whoops|stack trace|vendor/laravel/framework' "$BODY"; then fail "GET $path shows a debug page"; return 1; fi
  pass "GET $path $code"
}
page() { get "$1" 200 "${2:-<main}"; }
json() { get "$1" 200 "${2:-^[[:space:]]*[\{\[]}" -H "Accept: application/json"; }
redirects_to() {
  local path="$1" target="$2" code
  code=$(curl -s --max-time 30 -b "$JAR" -c "$JAR" -D "$HEAD" -o /dev/null -w '%{http_code}' "$BASE$path")
  if [ "$code" = "302" ] && grep -qiE "^location: *[^ ]*$target" "$HEAD"; then pass "GET $path redirects to $target"; else fail "GET $path answered $code, expected a redirect to $target ($(grep -i '^location:' "$HEAD" | tr -d '\r'))"; fi
}

sign_in() {
  local who="$1" email token code
  email="$(seed "$who")"
  JAR="$WORK/$who.jar"
  : > "$JAR"
  get "/login" 200 'name="_token"' >/dev/null || { fail "no login form for $who"; return 1; }
  token="$(grep -o 'name="_token" value="[^"]*"' "$BODY" | head -1 | sed 's/.*value="//; s/"$//')"
  code=$(curl -s --max-time 30 -b "$JAR" -c "$JAR" -D "$HEAD" -o "$BODY" -w '%{http_code}' -X POST \
    --data-urlencode "_token=$token" --data-urlencode "email=$email" --data-urlencode "password=$SMOKE_PASSWORD" "$BASE/login")
  if [ "$code" = "302" ] && ! grep -qi '^location:.*/login' "$HEAD"; then
    pass "$who signed in ($(grep -i '^location:' "$HEAD" | tr -d '\r' | sed 's/^[Ll]ocation: *//'))"
  else
    fail "$who could not sign in (POST /login answered $code)"; return 1
  fi
}

# ---- 1. public surface, signed out ----------------------------------------------------------------------------------
step "signed out"
get "/up" 200 ""
get "/" 200 "<html"
get "/login" 200 'name="_token"'
get "/fares" 200 "<main"
json "/fare-estimate/categories"
get "/cookies" 200 "<html"
get "/privacy-policy" 200 "<html"
get "/terms-of-service" 200 "<html"
redirects_to "/ride" "/login"
redirects_to "/drive" "/login"
redirects_to "/ops" "/login"
# /up/realtime is honest: there is no Reverb on this host (or here), so it answers 503 "degraded" in JSON, not a 500.
code=$(curl -s --max-time 30 -o "$BODY" -w '%{http_code}' "$BASE/up/realtime")
if { [ "$code" = "503" ] && grep -q '"degraded"' "$BODY"; } || { [ "$code" = "200" ] && grep -q '"ok"' "$BODY"; }; then pass "GET /up/realtime $code $(cat "$BODY")"; else fail "GET /up/realtime answered $code: $(head -c 200 "$BODY")"; fi
# A trip-share link that does not exist: the same neutral 404 as an expired one, no cookie, nothing leaked.
code=$(curl -s --max-time 30 -D "$HEAD" -o "$BODY" -w '%{http_code}' "$BASE/t/$(rand_hex 24)")
[ "$code" = "404" ] && pass "GET /t/<bogus> 404" || fail "GET /t/<bogus> answered $code, expected 404"
grep -qi '^set-cookie:' "$HEAD" && fail "a bogus trip-share link set a cookie" || pass "a bogus trip-share link sets no cookie"
grep -qi '^x-robots-tag:.*noindex' "$HEAD" && pass "trip-share 404 is noindex" || fail "trip-share 404 lacks X-Robots-Tag noindex"
grep -qiE 'stack trace|vendor/laravel|Illuminate\\' "$BODY" && fail "the trip-share 404 leaks internals" || pass "the trip-share 404 leaks nothing"
curl -s -D "$HEAD" -o /dev/null "$BASE/login"
for H in x-content-type-options x-frame-options referrer-policy permissions-policy "content-security-policy(-report-only)?"; do
  grep -Eqi "^$H:" "$HEAD" && pass "header $H on /login" || fail "header $H missing on /login"
done

# ---- 2. passenger ---------------------------------------------------------------------------------------------------
step "passenger"
if sign_in passenger; then
  redirects_to "/home" "/ride"
  page "/ride"
  page "/ride/trips"
  page "/ride/account"
  page "/ride/safety"
  page "/ride/help"
  page "/ride/help/new"
  page "/ride/notifications"
  page "/ride/notifications/settings"
  page "/dashboard"
  page "/user/profile"
  json "/geo/config" '"routing"'
  json "/ride/api/places"
  json "/api/ehail/comms/inbox"
  json "/api/ehail/comms/inbox/summary"
  json "/api/ehail/comms/preferences"
  json "/api/ehail/comms/push/key"
  json "/safety/contacts"
  json "/support/cases"
  json "/support/categories"
  json "/money/receipts"
  json "/business/profiles" '"profiles"'
  get "/ops/live" 403 ""
fi

# ---- 3. driver ------------------------------------------------------------------------------------------------------
step "driver"
if sign_in driver; then
  redirects_to "/home" "/drive"
  page "/drive"
  page "/drive/onboarding"
  page "/drive/documents"
  page "/drive/vehicles"
  page "/drive/trips"
  page "/drive/earnings"
  page "/drive/earnings/payouts"
  page "/drive/account"
  page "/drive/safety"
  page "/drive/notifications"
  # The feed answers 304 + ETag when nothing changed since the version the client holds (a new driver: version 0).
  code=$(curl -s --max-time 30 -b "$JAR" -c "$JAR" -D "$HEAD" -o "$BODY" -w '%{http_code}' -H "Accept: application/json" "$BASE/api/ehail/driver/feed")
  if { [ "$code" = "200" ] || [ "$code" = "304" ]; } && grep -qi '^etag: *"v[0-9]' "$HEAD"; then pass "GET /api/ehail/driver/feed $code with a version ETag"; else fail "GET /api/ehail/driver/feed answered $code without a version ETag"; fi
  json "/verification/status"
  json "/verification/eligibility?vehicle_id=$(seed vehicle_id)" '"allowed" *: *true'
  json "/verification/requirements"
  json "/money/driver/earnings"
  json "/money/driver/payouts"
fi

# ---- 4. operator ----------------------------------------------------------------------------------------------------
step "operator"
if sign_in operator; then
  for P in /ops/live /ops/rides /ops/drivers /ops/verification /ops/verification/expiry /ops/safety /ops/support /ops/finance \
           /ops/finance/payouts /ops/finance/ledger /ops/finance/reconciliation /ops/insights /ops/areas /ops/tariffs \
           /ops/tariffs/catalogue /ops/tariffs/commission /ops/tariffs/promotions /ops/tariffs/simulator /ops/team /ops/audit \
           /ops/fleets /ops/business /ops/notifications; do
    page "$P"
  done
  json "/ops/live/feed"
  json "/api/ehail/ops/rides"
  json "/money/ops/payments"
  json "/money/ops/ledger/trial-balance"
  json "/money/ops/payout-batches"
  json "/ops/pricing/rule-sets" 'Example tariff'
  json "/safety/ops/incidents"
  json "/support/ops/cases"
fi

# ---- 5. fleet owner -------------------------------------------------------------------------------------------------
step "fleet owner"
if sign_in fleet; then
  for P in /fleet /fleet/drivers /fleet/vehicles /fleet/trips /fleet/earnings /fleet/maintenance /fleet/documents /fleet/alerts /fleet/settings; do
    page "$P"
  done
  get "/fleet/drivers" 200 "Thabo"
fi

# ---- 6. business admin ----------------------------------------------------------------------------------------------
step "business admin"
if sign_in business; then
  for P in /business /business/book /business/trips /business/people /business/policies /business/approvals /business/invoices /business/settings; do
    page "$P"
  done
  json "/business/profiles" 'Smoke Logistics'
fi

# ---- 7. installable app, fonts and built assets (what the docroot must serve) ---------------------------------------
step "installable app, fonts and built assets"
JAR="$WORK/guest.jar"
code=$(curl -s --max-time 30 -D "$HEAD" -o "$BODY" -w '%{http_code}' "$BASE/manifest.webmanifest")
[ "$code" = "200" ] && $PHP -r 'exit(is_array(json_decode(file_get_contents($argv[1]), true)) ? 0 : 1);' "$BODY" && pass "/manifest.webmanifest is JSON" || fail "/manifest.webmanifest answered $code or is not JSON"
code=$(curl -s --max-time 30 -D "$HEAD" -o "$BODY" -w '%{http_code}' "$BASE/sw.js")
[ "$code" = "200" ] && grep -q "addEventListener('fetch'" "$BODY" && pass "/sw.js is the service worker, served from the site root (scope /)" || fail "/sw.js answered $code or is not the service worker"
grep -qiE '^content-type: *(application|text)/javascript' "$HEAD" && pass "/sw.js has a JavaScript content type" || fail "/sw.js content type is $(grep -i '^content-type:' "$HEAD" | tr -d '\r')"
for F in offline.html robots.txt apple-touch-icon.png $(cd public && ls icons/*.png); do
  code=$(curl -s --max-time 30 -o "$BODY" -w '%{http_code}' "$BASE/$F")
  [ "$code" = "200" ] && [ -s "$BODY" ] && pass "/$F $code" || fail "/$F answered $code"
done
for F in $(cd public && ls fonts/*.woff2); do
  code=$(curl -s --max-time 30 -o "$BODY" -w '%{http_code}' "$BASE/$F")
  [ "$code" = "200" ] && [ "$(head -c4 "$BODY")" = "wOF2" ] && pass "/$F is a woff2 font" || fail "/$F answered $code or is not a woff2 file"
done
ASSETS="$($PHP -r '$m=json_decode(file_get_contents("public/build/manifest.json"),true); $f=[]; foreach($m as $v){ if(!empty($v["isEntry"])){ $f[$v["file"]]=1; foreach($v["css"]??[] as $c){ $f[$c]=1; } } } ksort($f); echo implode(" ", array_keys($f));')"
for A in $ASSETS; do
  code=$(curl -s --max-time 30 -o "$BODY" -w '%{http_code}' "$BASE/build/$A")
  [ "$code" = "200" ] && [ -s "$BODY" ] && pass "/build/$A $code" || fail "/build/$A answered $code"
done
# Private uploads are never reachable from public/.
for P in storage/app/private/driver-documents storage/logs/laravel.log .env; do
  code=$(curl -s --max-time 15 -o /dev/null -w '%{http_code}' "$BASE/$P")
  [ "$code" != "200" ] && pass "/$P not served ($code)" || fail "/$P answered 200"
done

# ---- 8. nothing was logged as an error ------------------------------------------------------------------------------
step "application log"
if grep -hE '^\[[0-9-]+ [0-9:]+\] [a-z]+\.(ERROR|CRITICAL|ALERT|EMERGENCY)' "$WORK"/storage/logs/*.log 2>/dev/null | grep -v 'csp.report' > "$WORK/errors.txt" && [ -s "$WORK/errors.txt" ]; then
  fail "the application logged errors during the run:"
  cut -c1-240 "$WORK/errors.txt" | head -10 | sed 's/^/        /'
else
  pass "no ERROR or worse in the application log"
fi
grep -qE 'Fatal error|Uncaught' "$WORK/server.log" && fail "the PHP server log has a fatal error: $(grep -E 'Fatal error|Uncaught' "$WORK/server.log" | head -2)" || pass "no PHP fatal in the server log"

echo
if [ "$FAILS" -eq 0 ]; then
  echo "SIGNED-IN SMOKE PASSED: $CHECKS checks."
  exit 0
fi
echo "SIGNED-IN SMOKE FAILED: $FAILS of $CHECKS checks."
exit 1
