#!/usr/bin/env sh
#
# MCP STDIO bridge — pipes stdin/stdout to WP-CLI inside the Docker container.
#
# Usage (called automatically by Claude Code via .mcp.json):
#   echo '{"jsonrpc":"2.0","id":1,"method":"initialize",...}' | ./scripts/mcp-stdio.sh
#
# The script resolves its own location so it works regardless of the caller's
# working directory (e.g. when Claude Code spawns it from the project root).

set -e

# Resolve the project root (one level up from this script's directory)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$PROJECT_ROOT"

exec docker compose --env-file .env.development -f docker-compose.dev.yml exec -T app \
  wp --allow-root mcp-adapter serve \
  --server=mcp-adapter-default-server \
  --user=admin
