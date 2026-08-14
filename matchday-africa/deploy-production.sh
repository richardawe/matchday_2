#!/usr/bin/env bash
set -Eeuo pipefail

if [[ ! -f artisan || ! -f composer.json ]]; then
    echo "Run this script from the Matchday Africa Laravel root."
    exit 1
fi

if [[ ! -f .env ]]; then
    cp production.env.example .env
    chmod 600 .env
    echo "A safe .env template was created. Fill in its production values, then run this script again."
    exit 1
fi

if grep -Eq 'REPLACE|CPANELUSER_|pk_test_REPLACE|sk_test_REPLACE|whsec_REPLACE|price_REPLACE' .env; then
    echo "The .env still contains deployment placeholders. Replace them before continuing."
    exit 1
fi

if grep -Eq '^APP_DEBUG=(true|1)$' .env; then
    echo "APP_DEBUG must be false in production."
    exit 1
fi

echo "Installing locked production dependencies..."
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

if ! grep -Eq '^APP_KEY=base64:.+' .env; then
    echo "Generating the initial application key..."
    php artisan key:generate --force
fi

echo "Clearing stale framework caches..."
php artisan optimize:clear

echo "Applying database migrations..."
php artisan migrate --force

echo "Creating the public storage link..."
php artisan storage:link || true

echo "Building production caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "Restarting queue workers..."
php artisan queue:restart

chmod -R 775 storage bootstrap/cache
chmod 600 .env

echo "Deployment complete. Configure the scheduler and queue cron jobs from CPANEL_DEPLOYMENT_RUNBOOK.md."
