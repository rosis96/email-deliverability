#!/usr/bin/env bash
set -e

cd /var/www/html

# When the source is bind-mounted, vendor/ may be empty on first boot.
if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] Installing composer dependencies..."
    composer install --no-interaction --prefer-dist --optimize-autoloader --no-progress
fi

# Ensure an application key exists.
if ! grep -q "^APP_KEY=base64:" .env 2>/dev/null; then
    echo "[entrypoint] Generating application key..."
    php artisan key:generate --force || true
fi

# Make sure writable dirs exist and are owned correctly.
mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache || true

wait_for_db() {
    echo "[entrypoint] Waiting for database..."
    until php -r "exit(@fsockopen(getenv('DB_HOST') ?: 'db', (int)(getenv('DB_PORT') ?: 5432)) ? 0 : 1);" 2>/dev/null; do
        sleep 2
    done
    echo "[entrypoint] Database is reachable."
}

role="${CONTAINER_ROLE:-app}"

case "$role" in
    app)
        wait_for_db
        echo "[entrypoint] Running migrations + seed (idempotent)..."
        php artisan migrate --force || true
        php artisan db:seed --force || true
        php artisan config:clear || true
        echo "[entrypoint] Starting php-fpm."
        exec php-fpm
        ;;
    queue)
        wait_for_db
        echo "[entrypoint] Starting queue worker."
        exec php artisan queue:work --sleep=3 --tries=3 --timeout=90
        ;;
    scheduler)
        wait_for_db
        echo "[entrypoint] Starting scheduler loop."
        exec php artisan schedule:work
        ;;
    *)
        exec "$@"
        ;;
esac
