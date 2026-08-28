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

echo "[mnemon] waiting for the database…"
until php -r 'exit(@fsockopen(getenv("DB_HOST"), (int) getenv("DB_PORT")) ? 0 : 1);'; do
    sleep 1
done

# Stale compiled config surviving an image upgrade is a classic self-hosted
# failure, so clear before anything reads config.
php artisan config:clear
php artisan view:clear
php artisan cache:clear || true

if [ -z "${APP_KEY}" ]; then
    echo "[mnemon] generating APP_KEY"
    php artisan key:generate --force
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
