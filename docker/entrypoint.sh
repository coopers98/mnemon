#!/bin/sh
set -e

# APP_KEY is resolved here, before the bootstrap gate below, so every
# container — not just the one running the bootstrap — has a working key:
# encrypt()/decrypt() (Crypt, encrypted casts, signed URLs) must work in
# `scheduler` too, even though only `app` is allowed to generate a new key.
# Precedence:
#   1. An operator-supplied APP_KEY in the environment always wins and is
#      never overwritten.
#   2. Otherwise, reuse the key already persisted at storage/app_key (shared
#      with `app` via the same `storage` volume — by the time `scheduler`
#      starts, `app` has already written it, since `scheduler` waits on
#      `app`'s healthcheck).
#   3. Otherwise, leave it unresolved here — the bootstrap block below
#      generates and persists one, and only `app` ever reaches that block.
# .dockerignore excludes storage/ from the image, and compose's env_file:
# only injects environment variables — there is no writable .env inside the
# container for `key:generate --force` to persist a key into; it would land
# in the container's ephemeral layer and be gone on the next boot. So
# Mnemon persists the key itself in the storage volume instead.
# Do NOT change this back to `key:generate --force` — without persistence,
# every restart with no operator-supplied key would silently mint a new
# one, invalidating every session and anything encrypted under the old key.
if [ -n "${APP_KEY}" ]; then
    export APP_KEY
elif [ -f storage/app_key ]; then
    export APP_KEY="$(cat storage/app_key)"
fi

# D20 — exporting the key reaches PID 1 and its children, but NOT
# `docker compose exec`: an exec'd process inherits the environment the
# container was created with, which never had APP_KEY. That breaks the workflow
# docs/USERGUIDE.md documents — minting a token with `artisan tinker` — with a
# bare "No application encryption key has been specified".
# Writing it into the container's own .env (ephemeral, rebuilt from the
# persisted key on every boot) is what makes exec'd artisan commands work.
if [ -n "${APP_KEY}" ]; then
    touch .env
    chmod 600 .env
    if grep -q '^APP_KEY=' .env 2>/dev/null; then
        sed -i "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env
    else
        printf 'APP_KEY=%s\n' "${APP_KEY}" >> .env
    fi
fi

# APP_URL is resolved from DOMAIN here too, above the gate, for the same
# reason: any container may need to generate a correct URL, not just the
# one serving Caddy. CADDY_SITE_ADDRESS is Caddy-specific and stays below,
# scoped to the container that actually runs it.
if [ -n "${DOMAIN}" ]; then
    export APP_URL="https://${DOMAIN}"
    export SESSION_SECURE_COOKIE=true
fi

# Only the app container runs the bootstrap. If the scheduler ran it too,
# first boot would race: two key:generate (split-brain APP_KEY), two
# migrate --force, two passport:keys, two admin seeds.
if [ "${MNEMON_BOOTSTRAP:-0}" != "1" ]; then
    exec "$@"
fi

# Derive the served address from DOMAIN so Caddy serves the real hostname
# instead of the bare :80 default. Only meaningful here in the app
# container, which is the only one running Caddy/FrankenPHP.
if [ -n "${DOMAIN}" ]; then
    export CADDY_SITE_ADDRESS="${DOMAIN}"
else
    export CADDY_SITE_ADDRESS=":80"
fi

# DOMAIN set with loopback binds is a silent total outage: ACME's HTTP-01
# challenge needs port 80 reachable from the internet and TLS needs 443
# published, but the container still reports healthy (the healthcheck hits
# the internal :2020 address, not the public one) and every status signal
# stays green while certificate issuance fails and the site is unreachable
# at the real hostname. Warn loudly rather than refuse — an exotic setup
# (something else publishing these ports itself) might be legitimate.
if [ -n "${DOMAIN}" ]; then
    loopback_bind() {
        case "$1" in
            ""|127.*|localhost|localhost:*) return 0 ;;
            *) return 1 ;;
        esac
    }
    if loopback_bind "${HTTP_BIND:-}" || loopback_bind "${HTTPS_BIND:-}"; then
        echo "[mnemon] WARNING: DOMAIN=${DOMAIN} is set, but HTTP_BIND=${HTTP_BIND:-<unset, defaults to 127.0.0.1:8080>} / HTTPS_BIND=${HTTPS_BIND:-<unset, defaults to 127.0.0.1:8443>} bind only to loopback — Let's Encrypt cannot reach port 80 for the HTTP-01 challenge and nothing outside this host can reach 443. Certificate issuance will fail and https://${DOMAIN} will be unreachable. Set HTTP_BIND and HTTPS_BIND to public binds (e.g. 0.0.0.0:80 and 0.0.0.0:443) in your env file."
    fi
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

# A named `storage` volume shadows the image's storage/ skeleton once it
# exists (Docker only auto-populates a volume from the image on that
# volume's very first use), so a directory added to the Dockerfile's
# skeleton in a later release never appears after an upgrade onto an
# existing volume. Recreate it here, in the same spirit as the cache
# clearing below — both exist to survive an image upgrade landing on a
# volume created by an older image. mkdir -p is a no-op when already
# present, so this is safe on every boot, not just an upgrade.
mkdir -p \
    storage/app/public \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs

# Stale compiled config surviving an image upgrade is a classic self-hosted
# failure, so clear before anything reads config.
php artisan config:clear
php artisan view:clear
php artisan cache:clear || true

# Generation only: precedence 1 (operator-supplied) and 2 (persisted) were
# already resolved above the bootstrap gate, for every container. This is
# precedence 3, reached only here, only in `app` — generate with
# `key:generate --show` (prints, does not write), persist it, and use it.
if [ -z "${APP_KEY:-}" ]; then
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

# D17 — User::createToken() needs a personal access client, and nothing else
# creates one. MCP clients register through Dynamic Client Registration and are
# unaffected, which is why this stayed hidden: the documented way to mint a
# token for a script or a custom agent (docs/USERGUIDE.md) failed on every
# fresh install. Guarded on the grant type so a restart does not mint duplicates.
if [ "$(php artisan tinker --execute='echo Laravel\Passport\Client::all()->filter(fn ($c) => $c->hasGrantType("personal_access"))->count();' 2>/dev/null | tail -1)" = "0" ]; then
    echo "[mnemon] creating the personal access client"
    php artisan passport:client --personal --no-interaction --name="Mnemon Personal Access"
fi

# Seed only when there is no user at all. The entrypoint runs on every boot;
# reseeding would rotate the admin password out from under whoever saved it.
if [ "$(php artisan tinker --execute='echo App\Models\User::count();' 2>/dev/null | tail -1)" = "0" ]; then
    echo "[mnemon] seeding the admin user"
    php artisan db:seed --class=AdminUserSeeder --force
fi

exec "$@"
