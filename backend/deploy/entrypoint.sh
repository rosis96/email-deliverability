#!/usr/bin/env bash
set -e
cd /var/www/html

export PORT="${PORT:-8080}"

# Render nginx config with Railway's $PORT (ONLY PORT is substituted).
mkdir -p /tmp/nginx-client
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# APP_KEY must be provided via env in production. Fall back to an ephemeral key
# so the app still boots, but warn — encrypted data won't survive a redeploy.
if [ -z "${APP_KEY:-}" ]; then
    echo "[entrypoint] WARNING: APP_KEY is not set. Generating an EPHEMERAL key."
    echo "[entrypoint]          Set APP_KEY in Railway (php artisan key:generate --show) so"
    echo "[entrypoint]          stored Instantly credentials persist across deploys."
    export APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
fi

# Decide whether to seed BEFORE migrating: seed only when the DB is brand new
# (users table missing or empty), so redeploys never duplicate seed data.
FRESH=$(php -r '
    $h=getenv("DB_HOST"); $p=getenv("DB_PORT") ?: "5432"; $d=getenv("DB_DATABASE");
    $u=getenv("DB_USERNAME"); $pw=getenv("DB_PASSWORD");
    if (!$h || !$d) { echo "unknown"; exit; }
    try {
        $pdo = new PDO("pgsql:host=$h;port=$p;dbname=$d", $u, $pw, [PDO::ATTR_TIMEOUT => 5]);
        $n = $pdo->query("select count(*) from users")->fetchColumn();
        echo ($n > 0 ? "no" : "yes");
    } catch (Throwable $e) {
        echo "yes";
    }
')

echo "[entrypoint] Running migrations..."
php artisan migrate --force

if [ "$FRESH" = "yes" ]; then
    echo "[entrypoint] Fresh database detected — seeding (Oct-30 audit snapshot + roles + logins)."
    php artisan db:seed --force || true
else
    echo "[entrypoint] Existing database — skipping seed."
fi

# Cache config/routes for performance (safe: routes are controller-based).
php artisan config:cache || true
php artisan route:cache || true

echo "[entrypoint] Starting php-fpm + nginx on :$PORT"
php-fpm -D
exec nginx -g 'daemon off;'
