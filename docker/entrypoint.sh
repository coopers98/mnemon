#!/bin/sh
set -e

# Only the app container runs the bootstrap. If the scheduler ran it too,
# first boot would race: two key:generate (split-brain APP_KEY), two
# migrate --force, two passport:keys, two admin seeds.
if [ "${MNEMON_BOOTSTRAP:-0}" != "1" ]; then
    exec "$@"
fi

# Derive the served address and APP_URL from DOMAIN so Passport's OAuth
# metadata does not advertise localhost while Caddy serves a real hostname.
if [ -n "${DOMAIN}" ]; then
    export CADDY_SITE_ADDRESS="${DOMAIN}"
    export APP_URL="https://${DOMAIN}"
    export SESSION_SECURE_COOKIE=true
else
    export CADDY_SITE_ADDRESS=":80"
fi

echo "[mnemon] waiting for the database at ${DB_HOST:-<unset>}:${DB_PORT:-<unset>}…"
waited=0
until php -r 'exit(@fsockopen(getenv("DB_HOST"), (int) getenv("DB_PORT")) ? 0 : 1);'; do
    sleep 1
    waited=$((waited + 1))
    if [ $((waited % 15)) -eq 0 ]; then
        echo "[mnemon] still waiting for ${DB_HOST:-<unset>}:${DB_PORT:-<unset>} after ${waited}s — check DB_HOST/DB_PORT and that the db service is healthy"
    fi
done

# Stale compiled config surviving an image upgrade is a classic self-hosted
# failure, so clear before anything reads config.
php artisan config:clear
php artisan view:clear
php artisan cache:clear || true

# APP_KEY must survive container restarts. .dockerignore excludes storage/
# from the image, and compose's env_file: only injects environment
# variables — there is no writable .env inside the container for
# `key:generate --force` to persist a key into; it would land in the
# container's ephemeral layer and be gone on the next boot. So Mnemon
# persists the key itself in the storage volume instead. Precedence:
#   1. An operator-supplied APP_KEY in the environment always wins and is
#      never overwritten.
#   2. Otherwise, reuse the key already persisted at storage/app_key.
#   3. Otherwise, generate one with `key:generate --show` (prints, does not
#      write), persist it, and use it.
# Do NOT change this back to `key:generate --force` — without persistence,
# every restart with no operator-supplied key would silently mint a new
# one, invalidating every session and anything encrypted under the old key.
if [ -n "${APP_KEY}" ]; then
    export APP_KEY
elif [ -f storage/app_key ]; then
    export APP_KEY="$(cat storage/app_key)"
else
    echo "[mnemon] generating APP_KEY"
    APP_KEY="$(php artisan key:generate --show)"
    touch storage/app_key
    chmod 600 storage/app_key
    printf '%s\n' "${APP_KEY}" > storage/app_key
    export APP_KEY
fi

php artisan migrate --force

# The vector extension creates itself in a driver-guarded migration.

if [ ! -f storage/oauth-private.key ] || [ ! -f storage/oauth-public.key ]; then
    echo "[mnemon] generating Passport keys"
    php artisan passport:keys --force
    chmod 600 storage/oauth-private.key
fi

# Seed only when there is no user at all. The entrypoint runs on every boot;
# reseeding would rotate the admin password out from under whoever saved it.
if [ "$(php artisan tinker --execute='echo App\Models\User::count();' 2>/dev/null | tail -1)" = "0" ]; then
    echo "[mnemon] seeding the admin user"
    php artisan db:seed --class=AdminUserSeeder --force
fi

exec "$@"
