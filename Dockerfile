# syntax=docker/dockerfile:1

# --- assets -------------------------------------------------------------
# public/build is gitignored and untracked. The OAuth consent view uses
# @vite, so without this stage the consent screen — the one page every
# agent must load — throws ViteManifestNotFoundException.
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
RUN npm ci
COPY resources ./resources
RUN npm run build

# --- vendor -------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction \
        --ignore-platform-req=ext-intl \
        --ignore-platform-req=ext-pdo_pgsql \
        --ignore-platform-req=ext-gd \
        --ignore-platform-req=ext-bcmath

# --- runtime ------------------------------------------------------------
FROM dunglas/frankenphp:php8.4 AS runtime

# The FrankenPHP base ships none of these.
RUN install-php-extensions pdo_pgsql intl zip gd bcmath opcache

WORKDIR /app

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# .dockerignore excludes all of storage/ and bootstrap/cache — both hold
# generated/secret artifacts (Passport keys, admin-password.txt, compiled
# view/config caches) that must never be baked into a shipped image; the
# entrypoint (Task 2) creates them and a volume persists them. But
# package:discover (a composer post-autoload-dump script) boots the app,
# which resolves the Blade compiler and requires storage/framework/views to
# exist — so recreate the empty writable tree Laravel's skeleton expects.
RUN mkdir -p \
        storage/app/public \
        storage/app/private \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache

# frankenphp base ships no composer binary; borrow it from the vendor stage
# just long enough to run dump-autoload, then remove it. A production image
# self-hosters run has no business shipping a general-purpose, network-capable
# package manager. (Removed in the same RUN, not a later one, so the binary
# never appears in a layer the final `docker run` filesystem exposes.)
COPY --from=vendor /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize --no-dev --no-interaction \
        && rm -f /usr/bin/composer

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
# Classic mode, deliberately — NOT worker mode. laravel/octane is absent and
# the app has request-lifetime assumptions (a singleton EmbeddingManager in
# AppServiceProvider, Passport state, Livewire). Stating it here stops a later
# "optimisation" inheriting state-bleed bugs.
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
