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
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs

# --- runtime ------------------------------------------------------------
FROM dunglas/frankenphp:php8.4 AS runtime

# The FrankenPHP base ships none of these.
RUN install-php-extensions pdo_pgsql intl zip gd bcmath opcache

WORKDIR /app

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# .dockerignore excludes these (dev logs/cache/sessions), so they don't exist
# after COPY . . — but package:discover (a composer post-autoload-dump script)
# boots the app, which resolves the Blade compiler and requires
# storage/framework/views to exist. Recreate the writable tree Laravel expects.
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# frankenphp base ships no composer binary; borrow it from the vendor stage.
COPY --from=vendor /usr/bin/composer /usr/bin/composer
RUN composer dump-autoload --optimize --no-dev --no-interaction

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

ENTRYPOINT ["entrypoint"]
# Classic mode, deliberately — NOT worker mode. laravel/octane is absent and
# the app has request-lifetime assumptions (a singleton EmbeddingManager in
# AppServiceProvider, Passport state, Livewire). Stating it here stops a later
# "optimisation" inheriting state-bleed bugs.
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
