#!/bin/sh
# Connect to a running container on the remote Coolify host.
# Usage: ./scripts/remote-shell.sh [-i | --logs] <app|db> [command...]
#
# Options:
#   -i      Pass stdin through to the container (for piping data in)
#   --logs  Tail container logs via docker logs -f (instead of docker exec)
#
# Examples:
#   ./scripts/remote-shell.sh app                    # interactive shell in WordPress container
#   ./scripts/remote-shell.sh db                     # interactive shell in MariaDB container
#   ./scripts/remote-shell.sh app wp plugin list     # run a single WP-CLI command
#   ./scripts/remote-shell.sh db mariadb -u wordpress -p wordpress  # open mariadb client
#   ./scripts/remote-shell.sh -i db mariadb -u wordpress -p wordpress < dump.sql  # pipe stdin
#   ./scripts/remote-shell.sh --logs app             # tail WordPress container logs

set -e

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
ENV_FILE="$SCRIPT_DIR/../.env.development"

if [ -f "$ENV_FILE" ]; then
  . "$ENV_FILE"
fi

# ── Parse options ──────────────────────────────────────────────

MODE="exec"
if [ "${1:-}" = "-i" ]; then
  MODE="exec-stdin"
  shift
elif [ "${1:-}" = "--logs" ]; then
  MODE="logs"
  shift
fi

# ── Validate environment ───────────────────────────────────────

REMOTE="${COOLIFY_REMOTE_HOST:-}"
if [ -z "$REMOTE" ]; then
  echo "Error: COOLIFY_REMOTE_HOST is not set." >&2
  echo "Set it in .env.development or export it before running this script." >&2
  exit 1
fi

SERVICE_ID="${COOLIFY_SERVICE_ID:-}"
if [ -z "$SERVICE_ID" ]; then
  echo "Error: COOLIFY_SERVICE_ID is not set." >&2
  echo "Set it in .env.development or export it before running this script." >&2
  echo "You can find it in the Coolify URL or container names (e.g. app-XXXXXX...)." >&2
  exit 1
fi

SERVICE="${1:-}"
if [ -z "$SERVICE" ]; then
  echo "Usage: $0 [-i | --logs] <app|db> [command...]" >&2
  echo "" >&2
  echo "Options:" >&2
  echo "  -i      Pass stdin through to the container" >&2
  echo "  --logs  Tail container logs (docker logs -f)" >&2
  echo "" >&2
  echo "Services:" >&2
  echo "  app   WordPress container (PHP-FPM + Nginx)" >&2
  echo "  db    MariaDB container" >&2
  exit 1
fi
shift

case "$SERVICE" in
  app|db)
    ;;
  *)
    echo "Error: unknown service '$SERVICE'. Use 'app' or 'db'." >&2
    exit 1
    ;;
esac

# ── Resolve remote container ──────────────────────────────────

CONTAINER=$(ssh "$REMOTE" "sudo docker ps --format '{{.Names}}' | grep '^${SERVICE}-${SERVICE_ID}'" 2>/dev/null | head -1)

if [ -z "$CONTAINER" ]; then
  echo "Error: no '$SERVICE' container found for service ID '$SERVICE_ID'." >&2
  echo "Available containers on remote host:" >&2
  ssh "$REMOTE" "sudo docker ps --format '{{.Names}}'" 2>/dev/null | sed 's/^/  /'
  exit 1
fi

# ── Execute ────────────────────────────────────────────────────

case "$MODE" in
  logs)
    exec ssh "$REMOTE" "sudo docker logs -f '$CONTAINER'"
    ;;
  exec-stdin)
    exec ssh "$REMOTE" "sudo docker exec -i '$CONTAINER' $*"
    ;;
  *)
    if [ $# -eq 0 ]; then
      exec ssh -t "$REMOTE" "sudo docker exec -it '$CONTAINER' /bin/sh"
    else
      exec ssh "$REMOTE" "sudo docker exec '$CONTAINER' $*"
    fi
    ;;
esac
