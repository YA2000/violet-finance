#!/usr/bin/env bash
# Run inside the site folder as the CloudPanel site user, after .env is filled.
set -e
cd "$(dirname "$0")/.."
chmod -R ug+rwX storage bootstrap/cache
mkdir -p storage/app/private storage/app/tmp storage/framework/{cache,sessions,views} storage/logs
grep -q '^APP_KEY=base64' .env || php artisan key:generate --force
php artisan migrate --force
if [ "$1" = "--first-time" ]; then php artisan db:seed --force; fi
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "Violet Finance is ready."
