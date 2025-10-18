#!/usr/bin/env bash
set -euo pipefail

APP_DIR=${APP_DIR:-/var/www/api}
cd "$APP_DIR"

BRANCH=$(git rev-parse --abbrev-ref HEAD || echo main)

echo "Pulling latest from origin/$BRANCH..."
git fetch --all --tags
if ! git pull --ff-only origin "$BRANCH"; then
  git reset --hard "origin/$BRANCH"
fi

export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --prefer-dist -n

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate --force

echo "API deploy completed."