#!/usr/bin/env bash
# The full PHP suite on PostgreSQL (docs/R46_INTEGRATION.md section 6).
# Creates a throwaway database ehail_integ_<random>, runs phpunit.pgsql.xml against it, and drops it afterwards,
# whatever the outcome. It never touches an existing database. Needs a local PostgreSQL the current user can
# create databases on (PGHOST/PGPORT/PGUSER/PGPASSWORD are honoured).
set -euo pipefail

cd "$(dirname "$0")/.."

DB="ehail_integ_$(php -r 'echo bin2hex(random_bytes(5));')"
HOST="${PGHOST:-127.0.0.1}"
PORT="${PGPORT:-5432}"
USER_NAME="${PGUSER:-$(whoami)}"

if psql -h "$HOST" -p "$PORT" -U "$USER_NAME" -d postgres -Atc "select 1 from pg_database where datname = '$DB'" | grep -q 1; then
  echo "Database $DB already exists; refusing to touch it." >&2
  exit 1
fi

createdb -h "$HOST" -p "$PORT" -U "$USER_NAME" "$DB"
trap 'dropdb -h "$HOST" -p "$PORT" -U "$USER_NAME" --if-exists "$DB"' EXIT

echo "Running the suite on throwaway PostgreSQL database $DB"

APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
DB_HOST="$HOST" DB_PORT="$PORT" DB_DATABASE="$DB" DB_USERNAME="$USER_NAME" DB_PASSWORD="${PGPASSWORD:-}" \
php vendor/bin/phpunit -c phpunit.pgsql.xml "$@"
