#!/bin/sh
set -eu
cd /var/www/html
mkdir -p /var/lib/sh-nolife /backups \
    storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs
if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is required. Configure the private .env before starting NoLife." >&2
    exit 1
fi
touch /var/lib/sh-nolife/database.sqlite storage/framework/nolife-library.lock
chown -R www-data:www-data /var/lib/sh-nolife /backups storage bootstrap/cache
chmod 770 /var/lib/sh-nolife
php artisan migrate --force --no-interaction
php artisan config:cache --no-interaction
exec "$@"
