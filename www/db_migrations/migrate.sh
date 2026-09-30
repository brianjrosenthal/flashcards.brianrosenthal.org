#!/usr/bin/env bash
#
# Migration runner for Mastery.
#
# Applies every db_migrations/*.sql (in version order) that hasn't been applied
# yet to the app database named in www/config.local.php, recording each file in
# the `schema_migrations` table — the same table Admin -> Migrations uses, so
# the two stay in sync no matter which one applied a migration. Re-running is
# safe: recorded files are skipped, and a file that fails only with
# "already exists" style errors is recorded as applied (reconciled).
#
# Usage:
#   bash www/db_migrations/migrate.sh            # apply pending migrations
#   bash www/db_migrations/migrate.sh --dry-run  # show what would happen
#   CONF=/path/to/config.local.php bash www/db_migrations/migrate.sh
#
set -uo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

CONF="${CONF:-$DIR/../config.local.php}"
if [ ! -f "$CONF" ]; then
  echo "Cannot find config.local.php at $CONF — set CONF=/path/to/config.local.php" >&2
  exit 1
fi
echo "Using config: $CONF"

read_conf() { php -r 'require $argv[1]; echo defined($argv[2]) ? constant($argv[2]) : "";' "$CONF" "$1"; }
DB_HOST="$(read_conf DB_HOST)"
DB_NAME="$(read_conf DB_NAME)"
DB_USER="$(read_conf DB_USER)"
DB_PASS="$(read_conf DB_PASS)"

if [ -z "$DB_NAME" ]; then
  echo "Could not read DB_NAME from $CONF" >&2
  exit 1
fi

MYSQL=(mysql -h "${DB_HOST:-localhost}" -u "$DB_USER")
[ -n "$DB_PASS" ] && MYSQL+=("-p${DB_PASS}")
MYSQL+=("$DB_NAME")

echo "Target database: $DB_NAME  (host: ${DB_HOST:-localhost})"
[ "$DRY_RUN" = "1" ] && echo "(dry run — no changes will be made)"
echo

if [ "$DRY_RUN" = "0" ]; then
  "${MYSQL[@]}" -e "CREATE TABLE IF NOT EXISTS schema_migrations (
      filename   VARCHAR(255) NOT NULL PRIMARY KEY,
      applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB;" || { echo "Could not connect to $DB_NAME or create schema_migrations." >&2; exit 1; }
fi

is_recorded() {
  "${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM schema_migrations WHERE filename='$1';" 2>/dev/null || echo 0
}
record() { "${MYSQL[@]}" -e "INSERT IGNORE INTO schema_migrations (filename) VALUES ('$1');"; }

applied=0; reconciled=0; skipped=0
for f in $(ls "$DIR"/*.sql | sort -V); do
  base="$(basename "$f")"

  if [ "$(is_recorded "$base")" = "1" ]; then
    skipped=$((skipped+1)); continue
  fi

  if [ "$DRY_RUN" = "1" ]; then
    echo "WOULD APPLY   $base"
    applied=$((applied+1)); continue
  fi

  err="$("${MYSQL[@]}" < "$f" 2>&1)"
  if [ $? -eq 0 ]; then
    record "$base"; echo "APPLIED       $base"; applied=$((applied+1)); continue
  fi

  if echo "$err" | grep -qiE "1050|1060|1061|1062|1091|1826|errno: ?121|already exists|Duplicate (column|key|entry)"; then
    record "$base"; echo "ALREADY DONE  $base  (reconciled)"; reconciled=$((reconciled+1))
  else
    echo "FAILED        $base" >&2
    echo "  $err" >&2
    echo >&2
    echo "Stopped. Fix the above, then re-run — completed migrations are recorded and won't repeat." >&2
    exit 1
  fi
done

echo
echo "Done. applied=$applied  reconciled(already-applied)=$reconciled  skipped(recorded)=$skipped"
[ "$DRY_RUN" = "1" ] && echo "(dry run — nothing was changed)"
exit 0
