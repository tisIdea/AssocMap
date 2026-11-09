#!/bin/sh
set -eu

# Stop clearly if the deployment is missing its encryption key.
: "${APP_KEY:?Set APP_KEY in Railway before deploying}"

PORT="${PORT:-8080}"
case "$PORT" in
    ''|*[!0-9]*) echo "PORT must be a number." >&2; exit 1 ;;
esac
if [ "$PORT" -lt 1 ] || [ "$PORT" -gt 65535 ]; then
    echo "PORT must be between 1 and 65535." >&2
    exit 1
fi

printf 'Listen %s\n' "$PORT" > /etc/apache2/ports.conf
sed "s/\*:8080/*:$PORT/" /app/deploy/apache.conf \
    > /etc/apache2/sites-available/000-default.conf

cd /app/web

# Refresh settings without changing the database.
php artisan config:cache
php artisan view:cache
chown -R www-data:www-data storage bootstrap/cache

. /etc/apache2/envvars
exec apache2 -D FOREGROUND