#!/usr/bin/env bash
# Dot.Ehail backup: the database (PostgreSQL or SQLite) and the private uploads, verified, with retention.
#
#   scripts/ops/backup.sh [--db-only] [--dir DIR] [--keep N]
#
# Writes to $BACKUP_DIR (default ~/dot-ehail-backups, mode 700):
#   db-<UTC>.dump          PostgreSQL: pg_dump custom format, checked with pg_restore --list
#   db-<UTC>.sqlite        SQLite: an online backup (consistent while the site writes), checked with PRAGMA integrity_check
#   files-<UTC>.tar.gz     storage/app/private: driver documents (identity, licence, PrDP, vehicle papers) and support
#                          attachments, checked with tar -t
#   *.sha256               a checksum beside each file
# and keeps the newest N (default 14, or $BACKUP_KEEP) of each kind. Reads DB_* from <app>/.env; no secret is printed.
# Exit 0 only when every file written was verified; any failure prints the cause on stderr and exits non-zero (the last
# line of storage/logs/backup.log is the alarm).
#
# Not covered: .env and APP_KEY (keep a copy OFF the host: without the key, driver licence and ID numbers, saved card
# tokens and ride PINs cannot be decrypted, and nobody stays signed in), the code (git), the build (rebuilt by the
# deploy). It does not leave the hosting account unless BACKUP_OFFSITE is set to an rsync destination (user@host:path).
#
# Cron (install it by hand, docs/R46_OPERATIONS.md section 5; the deploy does NOT manage this line, and its marker must
# not contain "# dot-ehail", which the deploy's crontab step owns):
#   23 2 * * * cd <app folder> && exec scripts/ops/backup.sh >> storage/logs/backup.log 2>&1 # ehail-backup
set -euo pipefail

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DEST="${BACKUP_DIR:-$HOME/dot-ehail-backups}"
KEEP="${BACKUP_KEEP:-14}"
PHP="${PHP:-php}"
DB_ONLY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --db-only) DB_ONLY=1 ;;
    --dir) DEST="$2"; shift ;;
    --keep) KEEP="$2"; shift ;;
    -h|--help) sed -n '2,24p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
  shift
done
case "$KEEP" in ''|*[!0-9]*|0) echo "--keep must be a positive number" >&2; exit 2 ;; esac

fail() { echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) backup FAILED: $*" >&2; exit 1; }
sha() { if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1"; else shasum -a 256 "$1"; fi; }
size() { du -h "$1" | cut -f1; }

[ -f "$APP/.env" ] || fail "$APP/.env not found"
envval() { { grep -E "^$1=" "$APP/.env" || true; } | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }
DBC="$(envval DB_CONNECTION)"

umask 077
mkdir -p "$DEST"
chmod 700 "$DEST"

# One run at a time (mkdir is atomic and works on every host); a lock older than three hours is stale.
LOCK="$DEST/.lock"
if ! mkdir "$LOCK" 2>/dev/null; then
  if [ -n "$(find "$LOCK" -maxdepth 0 -mmin +180 2>/dev/null)" ]; then rmdir "$LOCK" 2>/dev/null && mkdir "$LOCK"; else fail "another backup is running ($LOCK); remove it if that is wrong"; fi
fi
PARTIALS=()
cleanup() { for f in "${PARTIALS[@]:-}"; do [ -n "$f" ] && rm -f "$f"; done; rmdir "$LOCK" 2>/dev/null || true; }
trap cleanup EXIT

FREE_KB="$(df -Pk "$DEST" | awk 'NR==2 {print $4}')"
[ "${FREE_KB:-0}" -gt 512000 ] || fail "less than 500 MB free where $DEST lives"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)"

# ---- database
case "$DBC" in
  pgsql)
    command -v pg_dump >/dev/null 2>&1 || fail "pg_dump is not installed for this account"
    command -v pg_restore >/dev/null 2>&1 || fail "pg_restore is not installed for this account (needed to verify the dump)"
    DB_FILE="$DEST/db-$STAMP.dump"
    PARTIALS+=("$DB_FILE.partial")
    PGPASSWORD="$(envval DB_PASSWORD)" pg_dump --format=custom --no-owner --file="$DB_FILE.partial" \
      --host="$(envval DB_HOST)" --port="$(envval DB_PORT)" --username="$(envval DB_USERNAME)" "$(envval DB_DATABASE)" \
      || fail "pg_dump failed"
    pg_restore --list "$DB_FILE.partial" > /dev/null || fail "the dump was written but pg_restore cannot read it"
    ;;
  sqlite)
    SRC="$(envval DB_DATABASE)"
    [ -n "$SRC" ] || SRC="database/database.sqlite"
    case "$SRC" in /*) ;; *) SRC="$APP/$SRC" ;; esac
    [ -f "$SRC" ] || fail "the SQLite database $SRC does not exist"
    DB_FILE="$DEST/db-$STAMP.sqlite"
    PARTIALS+=("$DB_FILE.partial")
    # SQLite's online backup API: a consistent copy while the site keeps writing (cp of a busy file can be torn).
    $PHP -r '$s = new SQLite3($argv[1], SQLITE3_OPEN_READONLY); $s->busyTimeout(10000); $d = new SQLite3($argv[2]); exit($s->backup($d) ? 0 : 1);' "$SRC" "$DB_FILE.partial" \
      || fail "the SQLite online backup failed (is the sqlite3 PHP extension enabled?)"
    CHECK="$($PHP -r '$d = new SQLite3($argv[1], SQLITE3_OPEN_READONLY); echo $d->querySingle("PRAGMA integrity_check"), "|", $d->querySingle("SELECT count(*) FROM migrations");' "$DB_FILE.partial")" \
      || fail "the SQLite copy cannot be opened"
    [ "${CHECK%%|*}" = "ok" ] || fail "the SQLite copy fails its integrity check: ${CHECK%%|*}"
    [ "${CHECK##*|}" -gt 0 ] 2>/dev/null || fail "the SQLite copy has no migrations rows"
    ;;
  *) fail "DB_CONNECTION is '${DBC:-unset}'; this script backs up pgsql and sqlite" ;;
esac
[ -s "$DB_FILE.partial" ] || fail "the database copy is empty"
mv "$DB_FILE.partial" "$DB_FILE"
( cd "$DEST" && sha "$(basename "$DB_FILE")" > "$(basename "$DB_FILE").sha256" )
SUMMARY="$(basename "$DB_FILE") ($(size "$DB_FILE"))"

# ---- private uploads (driver documents, support attachments)
if [ "$DB_ONLY" -eq 0 ]; then
  if [ -d "$APP/storage/app/private" ]; then
    F_FILE="$DEST/files-$STAMP.tar.gz"
    PARTIALS+=("$F_FILE.partial")
    # tar exits 1 when a file changed while it was read (an upload during the run): the archive is still good, and the
    # read-back below is what decides.
    rc=0
    tar czf "$F_FILE.partial" -C "$APP/storage/app" private || rc=$?
    [ "$rc" -le 1 ] || fail "tar failed with status $rc"
    tar tzf "$F_FILE.partial" > /dev/null || fail "the files archive was written but cannot be read back"
    mv "$F_FILE.partial" "$F_FILE"
    ( cd "$DEST" && sha "$(basename "$F_FILE")" > "$(basename "$F_FILE").sha256" )
    SUMMARY="$SUMMARY, $(basename "$F_FILE") ($(size "$F_FILE"), $(tar tzf "$F_FILE" | grep -vc '/$' || true) files)"
  else
    SUMMARY="$SUMMARY, no storage/app/private yet (nothing uploaded)"
  fi
fi

# ---- retention: the newest $KEEP of each kind
prune() {
  local pattern="$1" n=0 f
  while IFS= read -r f; do
    n=$((n + 1))
    if [ "$n" -gt "$KEEP" ]; then rm -f "$f" "$f.sha256"; fi
  done < <(ls -1t "$DEST"/$pattern 2>/dev/null || true)
}
prune 'db-*.dump'
prune 'db-*.sqlite'
prune 'files-*.tar.gz'

# ---- optional off-account copy (nothing is deleted over there)
if [ -n "${BACKUP_OFFSITE:-}" ]; then
  command -v rsync >/dev/null 2>&1 || fail "BACKUP_OFFSITE is set but rsync is not installed"
  rsync -a "$DEST"/ "$BACKUP_OFFSITE" || fail "the off-account copy to $BACKUP_OFFSITE failed (local backups are fine)"
  SUMMARY="$SUMMARY; copied off the account"
fi

echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) backup ok: $SUMMARY; keeping the newest $KEEP of each in $DEST"
