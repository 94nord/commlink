#!/bin/sh
set -e

# Clean slate on the mounted volume: stale caches and hot-reload markers from
# the dev stack must not leak into the test run.
rm -f public/hot
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan clear-compiled

# Ephemeral key when none was injected, so no secret is ever baked into the
# image or required to run the test stack.
if [ -z "$APP_KEY" ]; then
    APP_KEY="$(php artisan key:generate --show)"
    export APP_KEY
fi

# Fresh, empty database on every boot; retry while PostgreSQL starts up.
tries=0
until php artisan migrate:fresh --force; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
        echo "Database never became ready" >&2
        exit 1
    fi
    echo "Waiting for database ($tries)..."
    sleep 1
done

exec php-fpm
