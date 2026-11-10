#!/usr/bin/env bash
# Dot.Ehail restore drill: proves the newest backup can actually be restored, and how long it takes. It NEVER touches
# the live database or the live upload folder.
#
#   scripts/ops/restore-drill.sh [--dir DIR] [--db FILE] [--files FILE] [--target-db NAME] [--keep-target]
#
# Restores the newest db-*.dump / db-*.sqlite (or --db) into a scratch database (PostgreSQL) or a scratch file (SQLite),
# and the newest files-*.tar.gz (or --files) into a scratch folder, then checks:
#   - the checksum, when a .sha256 sits beside the file;
#   - every table the product needs exists and `migrations` is not empty;
#   - row counts of the main tables beside the live ones (the live site moves on, so only a missing table, or a restored
#     table that is empty while the live one is not, fails the drill);
#   - every verification document stored before the archive was taken has its file inside it (a sample of 2000).
# Prints the elapsed seconds: that is your realistic recovery time.
#
# PostgreSQL: the scratch database is created when the role may CREATE DATABASE; shared hosting often forbids it. Then
# create an empty database in cPanel (PostgreSQL Databases), grant it to the same role and pass --target-db NAME (it is
# emptied of the dump's objects with --clean and left in place). The real restore steps are in docs/R46_OPERATIONS.md.
set -euo pipefail

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEST="${BACKUP_DIR:-$HOME/dot-ehail-backups}"
PHP="${PHP:-php}"
DB_FILE=""; F_FILE=""; TARGET=""; KEEP_TARGET=0; CREATED=0
while [ $# -gt 0 ]; do
  case "$1" in
    --dir) DEST="$2"; shift ;;
    --db) DB_FILE="$2"; shift ;;
    --files) F_FILE="$2"; shift ;;
    --target-db) TARGET="$2"; KEEP_TARGET=1; shift ;;
    --keep-target) KEEP_TARGET=1 ;;
    -h|--help) sed -n '2,19p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
  shift
done

fail() { echo "RESTORE DRILL FAILED: $*" >&2; exit 1; }
[ -f "$APP/.env" ] || fail "$APP/.env not found"
envval() { { grep -E "^$1=" "$APP/.env" || true; } | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
DBC="$(envval DB_CONNECTION)"
TABLES="migrations users teams fleets driver_profiles vehicles rides ride_events ride_payments verification_documents ledger_entries driver_earnings payout_batches safety_incidents support_cases pricing_rule_sets ride_categories service_areas business_accounts"
COUNTED="users driver_profiles vehicles rides ride_events ride_payments verification_documents ledger_entries driver_earnings safety_incidents pricing_rule_sets service_areas"

case "$DBC" in
  pgsql) [ -n "$DB_FILE" ] || DB_FILE="$(ls -1t "$DEST"/db-*.dump 2>/dev/null | head -1 || true)" ;;
  sqlite) [ -n "$DB_FILE" ] || DB_FILE="$(ls -1t "$DEST"/db-*.sqlite 2>/dev/null | head -1 || true)" ;;
  *) fail "DB_CONNECTION is '${DBC:-unset}'; the drill handles pgsql and sqlite" ;;
esac
[ -n "$DB_FILE" ] && [ -f "$DB_FILE" ] || fail "no database backup found in $DEST (run scripts/ops/backup.sh first)"
[ -n "$F_FILE" ] || F_FILE="$(ls -1t "$DEST"/files-*.tar.gz 2>/dev/null | head -1 || true)"

START=$(date +%s)
WORK="$(mktemp -d "${TMPDIR:-/tmp}/dot-ehail-drill.XXXXXX")"
PSQL=()
cleanup() {
  if [ "$CREATED" -eq 1 ] && [ "$KEEP_TARGET" -eq 0 ]; then "${PSQL[@]}" -d postgres -c "DROP DATABASE IF EXISTS \"$TARGET\"" >/dev/null 2>&1 || echo "could not drop scratch database $TARGET; drop it by hand" >&2; fi
  rm -rf "$WORK"
}
trap cleanup EXIT

echo "== backup: $(basename "$DB_FILE")${F_FILE:+ and $(basename "$F_FILE")}"
verify_sha() {
  local file="$1" expect got
  if [ -f "$file.sha256" ]; then
    expect="$(cut -d' ' -f1 "$file.sha256")"
    if command -v sha256sum >/dev/null 2>&1; then got="$(sha256sum "$file" | cut -d' ' -f1)"; else got="$(shasum -a 256 "$file" | cut -d' ' -f1)"; fi
    [ "$expect" = "$got" ] || fail "checksum of $(basename "$file") does not match its .sha256 (the file is damaged)"
    echo "ok  checksum $(basename "$file")"
  else
    echo "..  no .sha256 beside $(basename "$file"); checksum not checked"
  fi
}
verify_sha "$DB_FILE"

# q DB SQL: one value from the restored (or live) database
if [ "$DBC" = "pgsql" ]; then
  HOST="$(envval DB_HOST)"; PORT="$(envval DB_PORT)"; USER_="$(envval DB_USERNAME)"; LIVE="$(envval DB_DATABASE)"
  PGPASSWORD="$(envval DB_PASSWORD)"; export PGPASSWORD
  PSQL=(psql --host="$HOST" --port="$PORT" --username="$USER_" --no-psqlrc -v ON_ERROR_STOP=1 -At)
  if [ -z "$TARGET" ]; then
    TARGET="${LIVE}_drill_$(date -u +%Y%m%d%H%M%S)"
    "${PSQL[@]}" -d postgres -c "CREATE DATABASE \"$TARGET\"" >/dev/null 2>&1 \
      || fail "this role cannot CREATE DATABASE. Create an empty database in cPanel and pass --target-db NAME."
    CREATED=1
  fi
  [ "$TARGET" != "$LIVE" ] || fail "refusing to restore over the live database ($LIVE)"
  echo "== restoring into scratch database $TARGET"
  pg_restore --clean --if-exists --no-owner --exit-on-error --host="$HOST" --port="$PORT" --username="$USER_" --dbname="$TARGET" "$DB_FILE" \
    || fail "pg_restore failed (see above)"
  echo "ok  pg_restore"
  q_restored() { "${PSQL[@]}" -d "$TARGET" -c "$1"; }
  q_live() { "${PSQL[@]}" -d "$LIVE" -c "$1" 2>/dev/null || echo '?'; }
  has_table() { [ "$(q_restored "SELECT to_regclass('public.$1') IS NOT NULL")" = "t" ]; }
else
  LIVE_FILE="$(envval DB_DATABASE)"; [ -n "$LIVE_FILE" ] || LIVE_FILE="database/database.sqlite"
  case "$LIVE_FILE" in /*) ;; *) LIVE_FILE="$APP/$LIVE_FILE" ;; esac
  TARGET="$WORK/restored.sqlite"
  cp "$DB_FILE" "$TARGET"
  [ "$($PHP -r '$d = new SQLite3($argv[1], SQLITE3_OPEN_READONLY); echo $d->querySingle("PRAGMA integrity_check");' "$TARGET")" = "ok" ] || fail "the restored SQLite file fails its integrity check"
  echo "ok  restored to a scratch file, integrity ok"
  sq() { $PHP -r '$d = new SQLite3($argv[1], SQLITE3_OPEN_READONLY); $d->busyTimeout(5000); $r = @$d->querySingle($argv[2]); if ($r === false) { exit(1); } echo $r === null ? "" : $r;' "$1" "$2"; }
  q_restored() { sq "$TARGET" "$1"; }
  q_live() { sq "$LIVE_FILE" "$1" 2>/dev/null || echo '?'; }
  has_table() { [ "$(q_restored "SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name = '$1'")" = "1" ]; }
fi

# ---- tables and counts
MISSING=""
for T in $TABLES; do has_table "$T" || MISSING="$MISSING $T"; done
[ -z "$MISSING" ] || fail "tables missing after the restore:$MISSING"
[ "$(q_restored "SELECT count(*) FROM migrations")" -gt 0 ] || fail "the restored migrations table is empty"
echo "ok  all product tables exist"
printf '   %-24s %10s %10s\n' table restored live
BAD=""
for T in $COUNTED; do
  R="$(q_restored "SELECT count(*) FROM $T")"; L="$(q_live "SELECT count(*) FROM $T")"
  printf '   %-24s %10s %10s\n' "$T" "$R" "$L"
  if [ "$R" = "0" ] && [ "$L" != "0" ] && [ "$L" != "?" ]; then BAD="$BAD $T"; fi
done
[ -z "$BAD" ] || fail "restored tables are empty while the live ones are not:$BAD"

# ---- private files
if [ -n "$F_FILE" ] && [ -f "$F_FILE" ]; then
  verify_sha "$F_FILE"
  mkdir -p "$WORK/files"
  tar xzf "$F_FILE" -C "$WORK/files" || fail "the files archive cannot be extracted"
  echo "ok  files archive extracted ($(find "$WORK/files" -type f | wc -l | tr -d ' ') files)"
  STAMP="$(basename "$F_FILE" | sed -n 's/^files-\([0-9]\{4\}\)\([0-9]\{2\}\)\([0-9]\{2\}\)T\([0-9]\{2\}\)\([0-9]\{2\}\)\([0-9]\{2\}\)Z.*/\1-\2-\3 \4:\5:\6/p')"
  [ -n "$STAMP" ] || fail "cannot read the time from the archive name $(basename "$F_FILE")"
  GONE=0; CHECKED=0; FIRST_GONE=""
  if [ "$DBC" = "pgsql" ]; then
    ROWS="$("${PSQL[@]}" -d "$TARGET" -F '|' -c "SELECT disk, file_path FROM verification_documents WHERE created_at <= '$STAMP' ORDER BY id DESC LIMIT 2000")"
  else
    ROWS="$($PHP -r '$d = new SQLite3($argv[1], SQLITE3_OPEN_READONLY); $r = $d->query("SELECT disk, file_path FROM verification_documents WHERE created_at <= '"'"'".$argv[2]."'"'"' ORDER BY id DESC LIMIT 2000"); while ($row = $r->fetchArray(SQLITE3_NUM)) { echo $row[0], "|", $row[1], "\n"; }' "$TARGET" "$STAMP")"
  fi
  while IFS='|' read -r disk rel; do
    [ -n "$rel" ] || continue
    CHECKED=$((CHECKED + 1))
    if [ ! -f "$WORK/files/private/$disk/$rel" ]; then GONE=$((GONE + 1)); [ -n "$FIRST_GONE" ] || FIRST_GONE="$disk/$rel"; fi
  done <<< "$ROWS"
  [ "$GONE" -eq 0 ] || fail "$GONE of $CHECKED document rows older than the archive have no file in it (first: $FIRST_GONE). The archive is incomplete or belongs to another database."
  echo "ok  $CHECKED document rows older than the archive all have their file"
else
  echo "..  no files archive found; uploads not checked"
fi

echo "RESTORE DRILL PASSED in $(( $(date +%s) - START )) seconds ($([ "$DBC" = "pgsql" ] && echo "database $TARGET$([ "$CREATED" -eq 1 ] && [ "$KEEP_TARGET" -eq 0 ] && echo ', dropped' || echo ', left in place')" || echo "scratch SQLite file, removed"))."
