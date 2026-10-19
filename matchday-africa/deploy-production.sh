#!/usr/bin/env bash
set -Eeuo pipefail

if [[ ! -f artisan || ! -f composer.json ]]; then
    echo "Run this script from the Matchday Africa Laravel root."
    exit 1
fi

if [[ ! -f .env ]]; then
    echo "Missing production .env. Configure it in cPanel before deploying."
    exit 1
fi

# Validate the actual dotenv values without sourcing shell code or displaying secrets.
# Existing vendor is required for this in-place deploy; first installs use the runbook.
php scripts/deployment-settings.php validate
public_root=$(php scripts/deployment-settings.php public)

mkdir -p storage/app/story-cards storage/app/reels public/generated/reels \
    storage/framework/{cache/data,sessions,views} bootstrap/cache
exec 9>storage/framework/deploy.lock
flock -n 9 || { echo "Another deployment is running."; exit 1; }
test -f public/build/manifest.json || { echo "Missing tested Vite build."; exit 1; }
php scripts/backup-deployment.php

echo "Installing locked production dependencies..."
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress
composer check-platform-reqs --no-dev

echo "Clearing stale framework caches..."
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan event:clear

renderer=$(php scripts/deployment-settings.php renderer)
if [[ "$renderer" == server ]]; then
    reel_node=$(php scripts/deployment-settings.php node)
    node_binary=$(command -v "$reel_node") || { echo "REEL_NODE is unavailable."; exit 1; }
    # Node's directory goes first only for npm: on cPanel it can be /usr/bin, whose php is the
    # CGI build, and every later `php artisan` would then fail.
    node_path="$(dirname "$node_binary"):$PATH"
    (PATH="$node_path"; command -v npm >/dev/null) || { echo "npm is missing next to $node_binary."; exit 1; }
    lock_hash=$(sha256sum reel/package-lock.json | cut -d ' ' -f 1)
    installed_hash=$(cat storage/app/reel-lock.sha 2>/dev/null || true)
    if [[ "$lock_hash" != "$installed_hash" || ! -d reel/node_modules ]]; then
        (cd reel && PATH="$node_path" npm ci --omit=dev --no-audit --no-fund)
        # Only record success after proving the installed renderer works.
        rm -f storage/app/reel-check.sha
    fi
    check_hash=$({
        printf '%s\n' "$lock_hash"
        "$node_binary" --version
        sha256sum reel/check.mjs reel/render.mjs reel/audio.mjs
        find reel/src resources/fonts resources/armies -type f -print0 | sort -z | xargs -0 sha256sum
    } | sha256sum | cut -d ' ' -f 1)
    checked_hash=$(cat storage/app/reel-check.sha 2>/dev/null || true)
    if [[ "$check_hash" != "$checked_hash" ]]; then
        (cd reel && "$node_binary" check.mjs)
        printf '%s\n' "$check_hash" > storage/app/reel-check.sha
    fi
    printf '%s\n' "$lock_hash" > storage/app/reel-lock.sha
fi

echo "Applying database migrations..."
php artisan migrate --force

echo "Creating the public storage link..."
if [[ "$public_root" != "$(pwd -P)/public" ]]; then
    # No --delete: retain hashed assets for existing browser sessions and all runtime media.
    rsync -a --exclude=/index.php --exclude=/storage --exclude=/uploads --exclude=/generated \
        --exclude=/hot public/ "$public_root/"
    php scripts/publish-public.php "$(pwd -P)" "$public_root"
    # Carry forward media from an earlier app/public layout without replacing live files.
    for directory in generated uploads; do
        if [[ -d "public/$directory" ]]; then
            mkdir -p "$public_root/$directory"
            rsync -a --ignore-existing "public/$directory/" "$public_root/$directory/"
        fi
    done
fi
mkdir -p "$public_root/generated/reels"
if [[ ! -e "$public_root/storage" && ! -L "$public_root/storage" ]]; then
    php artisan storage:link
fi
[[ -d "$public_root/storage" ]] || { echo "Public storage is not a working directory/link."; exit 1; }

echo "Building production caches..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate:status
php artisan matchday:health
php artisan schedule:list

echo "Restarting queue workers..."
php artisan queue:restart

chmod 600 .env
php artisan up

echo "Server deployment steps passed. The workflow must now verify the public HTTP routes."
