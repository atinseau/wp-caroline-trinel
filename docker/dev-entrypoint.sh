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

echo "[dev-entrypoint] Ready — starting services..."

# Hand off to CMD (supervisord)
exec "$@"
