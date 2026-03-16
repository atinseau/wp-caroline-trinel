#!/bin/sh
# Export or import the remote (Coolify) database over SSH.
# Delegates all remote execution to remote-shell.sh.
#
# Usage:
#   ./scripts/remote-db.sh export [file]   # dump remote DB → local file (default: remote-db-dump.sql)
#   ./scripts/remote-db.sh import [file]   # push local file → remote DB  (default: remote-db-dump.sql)
#
# Environment files:
#   .env.production    Production DB credentials + Coolify config
#                      (DB_NAME, DB_USER, DB_PASSWORD, COOLIFY_REMOTE_HOST, COOLIFY_SERVICE_ID)

set -e

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
REMOTE_SHELL="$SCRIPT_DIR/remote-shell.sh"
PROJECT_DIR="$SCRIPT_DIR/.."

# ── Load production credentials ────────────────────────────────

PROD_ENV="$PROJECT_DIR/.env.production"
if [ -f "$PROD_ENV" ]; then
  . "$PROD_ENV"
fi

if [ -z "${DB_PASSWORD:-}" ]; then
  echo "Error: DB_PASSWORD is not set." >&2
  echo "Make sure .env.production exists and contains the production DB credentials." >&2
  exit 1
fi

DB_NAME="${DB_NAME:-wordpress}"
DB_USER="${DB_USER:-wordpress}"

# ── Parse arguments ────────────────────────────────────────────

ACTION="${1:-}"
DEFAULT_FILE="remote-db-dump.sql"
DUMP_FILE="${2:-$DEFAULT_FILE}"

if [ -z "$ACTION" ]; then
  echo "Usage: $0 <export|import> [file]" >&2
  echo "" >&2
  echo "Commands:" >&2
  echo "  export [file]   Dump remote database to a local file (default: $DEFAULT_FILE)" >&2
  echo "  import [file]   Import a local file into the remote database (default: $DEFAULT_FILE)" >&2
  exit 1
fi

# ── Commands ───────────────────────────────────────────────────

case "$ACTION" in
  export)
    echo "Exporting remote database → $DUMP_FILE …"
    "$REMOTE_SHELL" db mariadb-dump -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" > "$DUMP_FILE"
    echo "✔ Remote database exported to $DUMP_FILE ($(wc -c < "$DUMP_FILE" | tr -d ' ') bytes)"
    ;;

  import)
    if [ ! -f "$DUMP_FILE" ]; then
      echo "Error: file '$DUMP_FILE' not found." >&2
      exit 1
    fi

    echo "⚠ This will OVERWRITE the remote database '$DB_NAME'."
    printf "Are you sure? [y/N] "
    read -r CONFIRM
    case "$CONFIRM" in
      [yY]|[yY][eE][sS]) ;;
      *)
        echo "Aborted."
        exit 0
        ;;
    esac

    echo "Importing $DUMP_FILE → remote database …"
    "$REMOTE_SHELL" -i db mariadb -u "$DB_USER" -p"$DB_PASSWORD" "$DB_NAME" < "$DUMP_FILE"
    echo "✔ Remote database imported from $DUMP_FILE"
    ;;

  *)
    echo "Error: unknown action '$ACTION'. Use 'export' or 'import'." >&2
    exit 1
    ;;
esac
