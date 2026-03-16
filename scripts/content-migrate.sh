#!/bin/sh
# ────────────────────────────────────────────────────────────────
# content-migrate.sh — Bidirectional content migration
# ────────────────────────────────────────────────────────────────
#
# Migrates WordPress content (posts, pages, media, menus, terms,
# key options) between local dev and remote production, in either
# direction, with automatic URL search-replace.
#
# Usage:
#   ./scripts/content-migrate.sh push export          # Export local content → content-export/
#   ./scripts/content-migrate.sh push import          # Import content-export/ → remote production
#   ./scripts/content-migrate.sh push sync-uploads    # Sync uploads local → remote
#   ./scripts/content-migrate.sh push full            # All-in-one: export + sync-uploads + import
#
#   ./scripts/content-migrate.sh pull export          # Export remote content → content-export/
#   ./scripts/content-migrate.sh pull import          # Import content-export/ → local dev
#   ./scripts/content-migrate.sh pull sync-uploads    # Sync uploads remote → local
#   ./scripts/content-migrate.sh pull full            # All-in-one: export + sync-uploads + import
#
# Prerequisites:
#   - Local dev stack running (make up)
#   - .env.production with COOLIFY_REMOTE_HOST, COOLIFY_SERVICE_ID,
#     DB_NAME, DB_USER, DB_PASSWORD, and WP_HOME (production URL)
# ────────────────────────────────────────────────────────────────

set -e

SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
PROJECT_DIR="$SCRIPT_DIR/.."
REMOTE_SHELL="$SCRIPT_DIR/remote-shell.sh"
EXPORT_DIR="$PROJECT_DIR/content-export"

COMPOSE="docker compose --env-file .env.development -f docker-compose.dev.yml"

# ── Colours ─────────────────────────────────────────────────────

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

info()  { printf "${CYAN}ℹ %s${NC}\n" "$*"; }
ok()    { printf "${GREEN}✔ %s${NC}\n" "$*"; }
warn()  { printf "${YELLOW}⚠ %s${NC}\n" "$*"; }
error() { printf "${RED}✖ %s${NC}\n" "$*" >&2; }

# ── Load production env ─────────────────────────────────────────

PROD_ENV="$PROJECT_DIR/.env.production"
load_prod_env() {
  if [ -f "$PROD_ENV" ]; then
    . "$PROD_ENV"
  fi
  PROD_DB_NAME="${DB_NAME:-wordpress}"
  PROD_DB_USER="${DB_USER:-wordpress}"
  PROD_DB_PASSWORD="${DB_PASSWORD:-}"
  PROD_WP_HOME="${WP_HOME:-}"

  if [ -z "$PROD_DB_PASSWORD" ]; then
    error "DB_PASSWORD is not set in .env.production"
    exit 1
  fi
  if [ -z "$PROD_WP_HOME" ]; then
    error "WP_HOME is not set in .env.production"
    echo "  Set it to the full production URL (e.g. https://example.com)" >&2
    exit 1
  fi
}

# ── Resolve local dev URL ──────────────────────────────────────

get_local_url() {
  cd "$PROJECT_DIR"
  LOCAL_URL=$($COMPOSE exec -T app wp --allow-root option get home 2>/dev/null || echo "")
  if [ -z "$LOCAL_URL" ]; then
    LOCAL_URL="http://localhost:8080"
    warn "Could not detect local WP_HOME, defaulting to $LOCAL_URL"
  fi
}

# ── Resolve remote uploads volume mount ────────────────────────

resolve_remote_uploads() {
  REMOTE="${COOLIFY_REMOTE_HOST:-}"
  SERVICE_ID="${COOLIFY_SERVICE_ID:-}"

  if [ -z "$REMOTE" ] || [ -z "$SERVICE_ID" ]; then
    error "COOLIFY_REMOTE_HOST and COOLIFY_SERVICE_ID must be set in .env.production"
    exit 1
  fi

  info "Resolving remote app container…"
  REMOTE_CONTAINER=$(ssh "$REMOTE" "sudo docker ps --format '{{.Names}}' | grep '^app-${SERVICE_ID}'" 2>/dev/null | head -1)
  if [ -z "$REMOTE_CONTAINER" ]; then
    error "No 'app' container found for service ID '$SERVICE_ID'"
    exit 1
  fi
  ok "Remote container: $REMOTE_CONTAINER"

  info "Resolving uploads volume mount on remote…"
  REMOTE_UPLOADS_MOUNT=$(ssh "$REMOTE" "sudo docker inspect '$REMOTE_CONTAINER' --format '{{range .Mounts}}{{if eq .Destination \"/var/www/html/web/app/uploads\"}}{{.Source}}{{end}}{{end}}'" 2>/dev/null)

  if [ -z "$REMOTE_UPLOADS_MOUNT" ]; then
    error "Could not find uploads volume mount in remote container"
    exit 1
  fi
  ok "Remote uploads path: $REMOTE_UPLOADS_MOUNT"
}

# ── Content tables ──────────────────────────────────────────────
# These tables contain the actual content. We export their full
# structure and data. wp_options is handled separately (filtered).

CONTENT_TABLES="wp_posts wp_postmeta wp_terms wp_termmeta wp_term_taxonomy wp_term_relationships wp_comments wp_commentmeta wp_links"

# ── Key options to migrate ──────────────────────────────────────
# Only meaningful site options — no transients, crons, sessions,
# nonces, or environment-specific values.

KEY_OPTIONS="
blogname
blogdescription
date_format
time_format
timezone_string
gmt_offset
start_of_week
permalink_structure
category_base
tag_base
show_on_front
page_on_front
page_for_posts
posts_per_page
posts_per_rss
blog_public
current_theme
template
stylesheet
default_category
default_post_format
default_comment_status
default_ping_status
close_comments_for_old_posts
close_comments_days_old
comment_moderation
comment_registration
comments_per_page
comment_order
default_comments_page
thumbnail_size_w
thumbnail_size_h
thumbnail_crop
medium_size_w
medium_size_h
medium_large_size_w
medium_large_size_h
large_size_w
large_size_h
image_default_size
image_default_align
image_default_link_type
uploads_use_yearmonth_folders
wp_page_for_privacy_policy
nav_menu_locations
sidebars_widgets
widget_block
category_children
"

# ── Build OPTIONS_WHERE clause ──────────────────────────────────
# Shared by both push and pull exports. Caller must set
# ACTIVE_STYLESHEET and ACTIVE_TEMPLATE before calling.

build_options_where() {
  OPTIONS_WHERE=""
  for opt in $KEY_OPTIONS; do
    opt=$(echo "$opt" | tr -d '[:space:]')
    [ -z "$opt" ] && continue
    if [ -z "$OPTIONS_WHERE" ]; then
      OPTIONS_WHERE="option_name = '$opt'"
    else
      OPTIONS_WHERE="$OPTIONS_WHERE OR option_name = '$opt'"
    fi
  done

  if [ -n "$ACTIVE_STYLESHEET" ]; then
    OPTIONS_WHERE="$OPTIONS_WHERE OR option_name = 'theme_mods_$ACTIVE_STYLESHEET'"
  fi
  if [ -n "$ACTIVE_TEMPLATE" ] && [ "$ACTIVE_TEMPLATE" != "$ACTIVE_STYLESHEET" ]; then
    OPTIONS_WHERE="$OPTIONS_WHERE OR option_name = 'theme_mods_$ACTIVE_TEMPLATE'"
  fi
}

# ═══════════════════════════════════════════════════════════════
#  PUSH — local dev → remote production
# ═══════════════════════════════════════════════════════════════

push_export() {
  cd "$PROJECT_DIR"
  get_local_url

  info "Exporting local content to $EXPORT_DIR"
  mkdir -p "$EXPORT_DIR"

  # ── 1. Dump content tables ──────────────────────────────────
  info "Dumping content tables from local database…"
  $COMPOSE exec -T db mariadb-dump \
    -u wordpress -pwordpress wordpress \
    --single-transaction \
    --skip-lock-tables \
    --no-create-db \
    $CONTENT_TABLES \
    > "$EXPORT_DIR/content-tables.sql"
  ok "Content tables dumped ($(wc -c < "$EXPORT_DIR/content-tables.sql" | tr -d ' ') bytes)"

  # ── 2. Export key options ───────────────────────────────────
  info "Exporting key options…"

  ACTIVE_STYLESHEET=$($COMPOSE exec -T app wp --allow-root option get stylesheet 2>/dev/null || echo "")
  ACTIVE_TEMPLATE=$($COMPOSE exec -T app wp --allow-root option get template 2>/dev/null || echo "")
  build_options_where

  $COMPOSE exec -T db mariadb -u wordpress -pwordpress wordpress \
    --batch --raw -e \
    "SELECT option_name, option_value FROM wp_options WHERE $OPTIONS_WHERE ORDER BY option_name;" \
    > "$EXPORT_DIR/options-raw.tsv" 2>/dev/null

  {
    echo "-- Key options exported from local dev"
    echo "-- Generated: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
    echo ""
  } > "$EXPORT_DIR/options.sql"

  LINENUM=0
  while IFS='	' read -r name value; do
    LINENUM=$((LINENUM + 1))
    [ "$LINENUM" -eq 1 ] && continue
    [ -z "$name" ] && continue
    escaped_value=$(printf '%s' "$value" | sed "s/'/''/g")
    echo "REPLACE INTO wp_options (option_name, option_value, autoload) VALUES ('$name', '$escaped_value', 'yes');" >> "$EXPORT_DIR/options.sql"
  done < "$EXPORT_DIR/options-raw.tsv"
  rm -f "$EXPORT_DIR/options-raw.tsv"

  ok "Key options exported ($(grep -c 'REPLACE INTO' "$EXPORT_DIR/options.sql") options)"

  # ── 3. Save metadata ───────────────────────────────────────
  {
    echo "DIRECTION=push"
    echo "SOURCE_URL=$LOCAL_URL"
    echo "EXPORT_DATE=$(date -u '+%Y-%m-%d %H:%M:%S UTC')"
    echo "ACTIVE_STYLESHEET=$ACTIVE_STYLESHEET"
    echo "ACTIVE_TEMPLATE=$ACTIVE_TEMPLATE"
  } > "$EXPORT_DIR/metadata.env"

  ok "Export complete → $EXPORT_DIR/"
  echo ""
  echo "  Files:"
  echo "    content-tables.sql  — posts, pages, media, menus, terms, comments"
  echo "    options.sql         — site options (theme, permalinks, reading, etc.)"
  echo "    metadata.env        — export metadata (source URL, direction, date)"
  echo ""
  echo "  Next steps:"
  echo "    ./scripts/content-migrate.sh push sync-uploads   # copy media files"
  echo "    ./scripts/content-migrate.sh push import          # push to production"
}

push_sync_uploads() {
  load_prod_env
  resolve_remote_uploads
  cd "$PROJECT_DIR"

  LOCAL_UPLOADS_TMP="$EXPORT_DIR/uploads-tmp"
  rm -rf "$LOCAL_UPLOADS_TMP"
  mkdir -p "$LOCAL_UPLOADS_TMP"

  info "Copying uploads from local Docker volume…"
  $COMPOSE exec -T app sh -c 'cd /var/www/html/web/app/uploads && tar cf - .' | tar xf - -C "$LOCAL_UPLOADS_TMP"
  ok "Local uploads copied to temp dir ($(du -sh "$LOCAL_UPLOADS_TMP" | cut -f1))"

  info "Syncing uploads to remote production…"
  rsync -avz --progress \
    "$LOCAL_UPLOADS_TMP/" \
    "$REMOTE:$REMOTE_UPLOADS_MOUNT/"

  ok "Uploads synced to production"

  info "Fixing file ownership on remote…"
  ssh "$REMOTE" "sudo chown -R 82:82 '$REMOTE_UPLOADS_MOUNT/'"
  ok "Ownership fixed (www-data / uid 82)"

  rm -rf "$LOCAL_UPLOADS_TMP"
  ok "Uploads sync complete (local → remote)"
}

push_import() {
  load_prod_env

  if [ ! -d "$EXPORT_DIR" ] || [ ! -f "$EXPORT_DIR/content-tables.sql" ]; then
    error "No export found at $EXPORT_DIR/"
    echo "  Run './scripts/content-migrate.sh push export' first." >&2
    exit 1
  fi

  if [ -f "$EXPORT_DIR/metadata.env" ]; then
    . "$EXPORT_DIR/metadata.env"
  fi
  SRC_URL="${SOURCE_URL:-http://localhost:8080}"

  if [ "${SKIP_CONFIRM:-}" != "1" ]; then
    warn "This will OVERWRITE content tables on the ${BOLD}production${NC}${YELLOW} database.${NC}"
    warn "Source: $SRC_URL → Target: $PROD_WP_HOME"
    printf "Are you sure? [y/N] "
    read -r CONFIRM
    case "$CONFIRM" in
      [yY]|[yY][eE][sS]) ;;
      *)
        echo "Aborted."
        exit 0
        ;;
    esac
  fi

  info "Importing content tables into remote database…"
  "$REMOTE_SHELL" -i db mariadb -u "$PROD_DB_USER" -p"$PROD_DB_PASSWORD" "$PROD_DB_NAME" < "$EXPORT_DIR/content-tables.sql"
  ok "Content tables imported"

  info "Importing key options…"
  "$REMOTE_SHELL" -i db mariadb -u "$PROD_DB_USER" -p"$PROD_DB_PASSWORD" "$PROD_DB_NAME" < "$EXPORT_DIR/options.sql"
  ok "Key options imported"

  info "Running search-replace: $SRC_URL → $PROD_WP_HOME"
  "$REMOTE_SHELL" app wp --allow-root search-replace \
    "$SRC_URL" "$PROD_WP_HOME" \
    --all-tables \
    --precise \
    --skip-columns=guid \
    --report-changed-only
  ok "URL search-replace complete"

  info "Flushing caches and rewrite rules on production…"
  "$REMOTE_SHELL" app wp --allow-root cache flush 2>/dev/null || true
  "$REMOTE_SHELL" app wp --allow-root rewrite flush 2>/dev/null || true
  ok "Caches and rewrite rules flushed"

  echo ""
  ok "Push import complete!"
  echo ""
  echo "  Summary:"
  echo "    ✔ Content tables (posts, pages, menus, terms, comments)"
  echo "    ✔ Key options (theme, permalinks, reading settings, etc.)"
  echo "    ✔ URL search-replace ($SRC_URL → $PROD_WP_HOME)"
  echo "    ✔ Caches flushed"
  echo ""
  echo "  Don't forget:"
  echo "    - Check the site at $PROD_WP_HOME"
  echo "    - Verify media files (run 'push sync-uploads' if not done yet)"
  echo "    - Review user accounts (local admin password won't work in prod)"
  echo ""
}

push_full() {
  echo ""
  info "Full content migration: local → production"
  echo "────────────────────────────────────────────"
  echo ""

  load_prod_env
  get_local_url

  warn "This will:"
  echo "  1. Export local content (posts, pages, menus, options)"
  echo "  2. Sync media uploads to production"
  echo "  3. Overwrite production content tables"
  echo "  4. Search-replace URLs: $LOCAL_URL → $PROD_WP_HOME"
  echo ""
  printf "Continue? [y/N] "
  read -r CONFIRM
  case "$CONFIRM" in
    [yY]|[yY][eE][sS]) ;;
    *)
      echo "Aborted."
      exit 0
      ;;
  esac

  echo ""
  echo "═══ Step 1/3: Export ═══"
  push_export

  echo ""
  echo "═══ Step 2/3: Sync Uploads ═══"
  push_sync_uploads

  echo ""
  echo "═══ Step 3/3: Import ═══"
  SKIP_CONFIRM=1 push_import

  echo ""
  ok "Full push complete! Check your site at $PROD_WP_HOME"
}

# ═══════════════════════════════════════════════════════════════
#  PULL — remote production → local dev
# ═══════════════════════════════════════════════════════════════

pull_export() {
  cd "$PROJECT_DIR"
  load_prod_env

  info "Exporting remote production content to $EXPORT_DIR"
  mkdir -p "$EXPORT_DIR"

  # ── 1. Dump content tables from remote ──────────────────────
  info "Dumping content tables from remote database…"
  "$REMOTE_SHELL" db mariadb-dump \
    -u "$PROD_DB_USER" -p"$PROD_DB_PASSWORD" "$PROD_DB_NAME" \
    --single-transaction \
    --skip-lock-tables \
    --no-create-db \
    $CONTENT_TABLES \
    > "$EXPORT_DIR/content-tables.sql"
  ok "Content tables dumped ($(wc -c < "$EXPORT_DIR/content-tables.sql" | tr -d ' ') bytes)"

  # ── 2. Export key options from remote ───────────────────────
  info "Exporting key options from remote…"

  ACTIVE_STYLESHEET=$("$REMOTE_SHELL" app wp --allow-root option get stylesheet 2>/dev/null || echo "")
  ACTIVE_TEMPLATE=$("$REMOTE_SHELL" app wp --allow-root option get template 2>/dev/null || echo "")
  build_options_where

  "$REMOTE_SHELL" db mariadb -u "$PROD_DB_USER" -p"$PROD_DB_PASSWORD" "$PROD_DB_NAME" \
    --batch --raw -e \
    "SELECT option_name, option_value FROM wp_options WHERE $OPTIONS_WHERE ORDER BY option_name;" \
    > "$EXPORT_DIR/options-raw.tsv" 2>/dev/null

  {
    echo "-- Key options exported from remote production"
    echo "-- Generated: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
    echo ""
  } > "$EXPORT_DIR/options.sql"

  LINENUM=0
  while IFS='	' read -r name value; do
    LINENUM=$((LINENUM + 1))
    [ "$LINENUM" -eq 1 ] && continue
    [ -z "$name" ] && continue
    escaped_value=$(printf '%s' "$value" | sed "s/'/''/g")
    echo "REPLACE INTO wp_options (option_name, option_value, autoload) VALUES ('$name', '$escaped_value', 'yes');" >> "$EXPORT_DIR/options.sql"
  done < "$EXPORT_DIR/options-raw.tsv"
  rm -f "$EXPORT_DIR/options-raw.tsv"

  ok "Key options exported ($(grep -c 'REPLACE INTO' "$EXPORT_DIR/options.sql") options)"

  # ── 3. Save metadata ───────────────────────────────────────
  {
    echo "DIRECTION=pull"
    echo "SOURCE_URL=$PROD_WP_HOME"
    echo "EXPORT_DATE=$(date -u '+%Y-%m-%d %H:%M:%S UTC')"
    echo "ACTIVE_STYLESHEET=$ACTIVE_STYLESHEET"
    echo "ACTIVE_TEMPLATE=$ACTIVE_TEMPLATE"
  } > "$EXPORT_DIR/metadata.env"

  ok "Export complete → $EXPORT_DIR/"
  echo ""
  echo "  Files:"
  echo "    content-tables.sql  — posts, pages, media, menus, terms, comments"
  echo "    options.sql         — site options (theme, permalinks, reading, etc.)"
  echo "    metadata.env        — export metadata (source URL, direction, date)"
  echo ""
  echo "  Next steps:"
  echo "    ./scripts/content-migrate.sh pull sync-uploads   # pull media files"
  echo "    ./scripts/content-migrate.sh pull import          # import into local"
}

pull_sync_uploads() {
  load_prod_env
  resolve_remote_uploads
  cd "$PROJECT_DIR"

  LOCAL_UPLOADS_TMP="$EXPORT_DIR/uploads-tmp"
  rm -rf "$LOCAL_UPLOADS_TMP"
  mkdir -p "$LOCAL_UPLOADS_TMP"

  info "Downloading uploads from remote production…"
  rsync -avz --progress \
    "$REMOTE:$REMOTE_UPLOADS_MOUNT/" \
    "$LOCAL_UPLOADS_TMP/"
  ok "Remote uploads downloaded ($(du -sh "$LOCAL_UPLOADS_TMP" | cut -f1))"

  info "Injecting uploads into local Docker volume…"
  tar cf - -C "$LOCAL_UPLOADS_TMP" . | $COMPOSE exec -T app sh -c 'cd /var/www/html/web/app/uploads && tar xf -'
  ok "Uploads injected into local volume"

  info "Fixing file ownership in local container…"
  $COMPOSE exec -T app sh -c 'chown -R 82:82 /var/www/html/web/app/uploads/'
  ok "Ownership fixed (www-data / uid 82)"

  rm -rf "$LOCAL_UPLOADS_TMP"
  ok "Uploads sync complete (remote → local)"
}

pull_import() {
  cd "$PROJECT_DIR"
  load_prod_env
  get_local_url

  if [ ! -d "$EXPORT_DIR" ] || [ ! -f "$EXPORT_DIR/content-tables.sql" ]; then
    error "No export found at $EXPORT_DIR/"
    echo "  Run './scripts/content-migrate.sh pull export' first." >&2
    exit 1
  fi

  if [ -f "$EXPORT_DIR/metadata.env" ]; then
    . "$EXPORT_DIR/metadata.env"
  fi
  SRC_URL="${SOURCE_URL:-$PROD_WP_HOME}"

  if [ "${SKIP_CONFIRM:-}" != "1" ]; then
    warn "This will OVERWRITE content tables on the ${BOLD}local dev${NC}${YELLOW} database.${NC}"
    warn "Source: $SRC_URL → Target: $LOCAL_URL"
    printf "Are you sure? [y/N] "
    read -r CONFIRM
    case "$CONFIRM" in
      [yY]|[yY][eE][sS]) ;;
      *)
        echo "Aborted."
        exit 0
        ;;
    esac
  fi

  info "Importing content tables into local database…"
  $COMPOSE exec -T db mariadb -u wordpress -pwordpress wordpress < "$EXPORT_DIR/content-tables.sql"
  ok "Content tables imported"

  info "Importing key options…"
  $COMPOSE exec -T db mariadb -u wordpress -pwordpress wordpress < "$EXPORT_DIR/options.sql"
  ok "Key options imported"

  info "Running search-replace: $SRC_URL → $LOCAL_URL"
  $COMPOSE exec -T app wp --allow-root search-replace \
    "$SRC_URL" "$LOCAL_URL" \
    --all-tables \
    --precise \
    --skip-columns=guid \
    --report-changed-only
  ok "URL search-replace complete"

  info "Flushing caches and rewrite rules on local…"
  $COMPOSE exec -T app wp --allow-root cache flush 2>/dev/null || true
  $COMPOSE exec -T app wp --allow-root rewrite flush 2>/dev/null || true
  ok "Caches and rewrite rules flushed"

  echo ""
  ok "Pull import complete!"
  echo ""
  echo "  Summary:"
  echo "    ✔ Content tables (posts, pages, menus, terms, comments)"
  echo "    ✔ Key options (theme, permalinks, reading settings, etc.)"
  echo "    ✔ URL search-replace ($SRC_URL → $LOCAL_URL)"
  echo "    ✔ Caches flushed"
  echo ""
  echo "  Don't forget:"
  echo "    - Check the site at $LOCAL_URL"
  echo "    - Verify media files (run 'pull sync-uploads' if not done yet)"
  echo ""
}

pull_full() {
  echo ""
  info "Full content migration: production → local"
  echo "────────────────────────────────────────────"
  echo ""

  load_prod_env
  get_local_url

  warn "This will:"
  echo "  1. Export remote content (posts, pages, menus, options)"
  echo "  2. Download media uploads from production"
  echo "  3. Overwrite local content tables"
  echo "  4. Search-replace URLs: $PROD_WP_HOME → $LOCAL_URL"
  echo ""
  printf "Continue? [y/N] "
  read -r CONFIRM
  case "$CONFIRM" in
    [yY]|[yY][eE][sS]) ;;
    *)
      echo "Aborted."
      exit 0
      ;;
  esac

  echo ""
  echo "═══ Step 1/3: Export ═══"
  pull_export

  echo ""
  echo "═══ Step 2/3: Sync Uploads ═══"
  pull_sync_uploads

  echo ""
  echo "═══ Step 3/3: Import ═══"
  SKIP_CONFIRM=1 pull_import

  echo ""
  ok "Full pull complete! Check your site at $LOCAL_URL"
}

# ═══════════════════════════════════════════════════════════════
#  MAIN
# ═══════════════════════════════════════════════════════════════

DIRECTION="${1:-}"
ACTION="${2:-}"

show_usage() {
  echo "Usage: $0 <push|pull> <command>"
  echo ""
  echo "Directions:"
  echo "  push    Local dev → remote production"
  echo "  pull    Remote production → local dev"
  echo ""
  echo "Commands:"
  echo "  export          Export content from source → content-export/"
  echo "  import          Import content-export/ → target (with URL search-replace)"
  echo "  sync-uploads    Sync media files between source and target"
  echo "  full            All-in-one: export + sync-uploads + import"
  echo ""
  echo "Examples:"
  echo "  $0 push full            # Deploy local content to production"
  echo "  $0 pull full            # Pull production content to local"
  echo "  $0 push export          # Export local content only"
  echo "  $0 pull sync-uploads    # Download production media only"
  echo ""
  echo "Prerequisites:"
  echo "  - Local dev stack running (make up)"
  echo "  - .env.production with production credentials"
}

case "$DIRECTION" in
  push)
    case "$ACTION" in
      export)       push_export ;;
      import)       push_import ;;
      sync-uploads) push_sync_uploads ;;
      full)         push_full ;;
      *)
        error "Unknown command '$ACTION' for push"
        echo ""
        show_usage
        exit 1
        ;;
    esac
    ;;
  pull)
    case "$ACTION" in
      export)       pull_export ;;
      import)       pull_import ;;
      sync-uploads) pull_sync_uploads ;;
      full)         pull_full ;;
      *)
        error "Unknown command '$ACTION' for pull"
        echo ""
        show_usage
        exit 1
        ;;
    esac
    ;;
  *)
    show_usage
    exit 1
    ;;
esac
