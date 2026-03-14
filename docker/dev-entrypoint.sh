#!/bin/sh
set -e

# Install Composer dependencies if vendor/ is missing (first run with bind mount)
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "[dev-entrypoint] vendor/ not found — running composer install..."
    composer install --no-interaction --prefer-dist
fi

# Ensure uploads & cache directories exist and are writable
mkdir -p web/app/uploads web/app/cache
chown -R www-data:www-data web/app/uploads web/app/cache
chmod -R 775 web/app/uploads web/app/cache

# Auto-install WordPress in the background after services start
(
    # Wait for php-fpm and nginx to be ready
    sleep 5

    # Wait for the database using PHP (no mysql client needed)
    echo "[dev-entrypoint] Waiting for database..."
    max_attempts=60
    attempt=0
    until php -r "new mysqli(getenv('DB_HOST') ?: 'db', getenv('DB_USER') ?: 'wordpress', getenv('DB_PASSWORD') ?: 'wordpress', getenv('DB_NAME') ?: 'wordpress', (int)(explode(':', getenv('DB_HOST') ?: 'db:3306')[1] ?? 3306));" 2>/dev/null; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge "$max_attempts" ]; then
            echo "[dev-entrypoint] ✗ Database not reachable after ${max_attempts}s — skipping auto-install"
            exit 1
        fi
        sleep 1
    done
    echo "[dev-entrypoint] Database is ready"

    # Skip if WordPress is already installed
    if wp --allow-root core is-installed 2>/dev/null; then
        echo "[dev-entrypoint] WordPress already installed — skipping"
        exit 0
    fi

    echo "[dev-entrypoint] Installing WordPress..."

    wp --allow-root core install \
        --url="${WP_HOME:-http://localhost:8080}" \
        --title="${WP_TITLE:-WordPress}" \
        --admin_user="${WP_ADMIN_USER:-admin}" \
        --admin_password="${WP_ADMIN_PASSWORD:-admin}" \
        --admin_email="${WP_ADMIN_EMAIL:-admin@localhost.dev}" \
        --skip-email

    echo "[dev-entrypoint] ✔ WordPress installed"
    echo "[dev-entrypoint]   URL:      ${WP_HOME:-http://localhost:8080}"
    echo "[dev-entrypoint]   Admin:    ${WP_HOME:-http://localhost:8080}/wp/wp-admin/"
    echo "[dev-entrypoint]   User:     ${WP_ADMIN_USER:-admin}"
    echo "[dev-entrypoint]   Password: ${WP_ADMIN_PASSWORD:-admin}"
) &

echo "[dev-entrypoint] Ready — starting services..."

# Hand off to CMD (supervisord)
exec "$@"
