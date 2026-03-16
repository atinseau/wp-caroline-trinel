#!/usr/bin/env sh
#
# MCP HTTP Remote bridge — reads production credentials from .env.development
# and launches the @automattic/mcp-wordpress-remote proxy.
#
# Usage (called automatically by Claude Code via .mcp.json):
#   ./scripts/mcp-remote.sh
#
# Required variables in .env.development:
#   MCP_REMOTE_URL       — e.g. https://carolinetrinel.fr/wp-json/mcp/mcp-adapter-default-server
#   MCP_REMOTE_USERNAME  — WordPress username on the production site
#   MCP_REMOTE_PASSWORD  — Application Password generated in Users → Profile → Application Passwords
#

set -e

# Resolve the project root (one level up from this script's directory)
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

ENV_FILE="$PROJECT_ROOT/.env.development"

if [ -f "$ENV_FILE" ]; then
  . "$ENV_FILE"
fi

if [ -z "$MCP_REMOTE_URL" ]; then
  echo "Error: MCP_REMOTE_URL is not set in $ENV_FILE" >&2
  exit 1
fi

if [ -z "$MCP_REMOTE_USERNAME" ]; then
  echo "Error: MCP_REMOTE_USERNAME is not set in $ENV_FILE" >&2
  exit 1
fi

if [ -z "$MCP_REMOTE_PASSWORD" ]; then
  echo "Error: MCP_REMOTE_PASSWORD is not set in $ENV_FILE" >&2
  exit 1
fi

export WP_API_URL="$MCP_REMOTE_URL"
export WP_API_USERNAME="$MCP_REMOTE_USERNAME"
export WP_API_PASSWORD="$MCP_REMOTE_PASSWORD"

exec npx -y @automattic/mcp-wordpress-remote@latest
