# Install Story Group 2 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `git clone && cp .env.docker.example .env && docker compose up` gives a stranger a working Mnemon — admin login, reachable MCP endpoint, HTTPS if they have a domain — with no accounts and no API spend.

**Architecture:** One image built in three stages (Node for assets, Composer for vendor, FrankenPHP for runtime), three services (`app`, `db`, `scheduler`), and an idempotent entrypoint that runs in exactly one of them. Everything is verified by actually bringing the stack up, not by reading YAML.

**Tech Stack:** Docker Compose, FrankenPHP (classic mode, Caddy auto-HTTPS), `pgvector/pgvector:pg17`, Laravel 13, PHP 8.4, Node/Vite.

**Spec:** `docs/superpowers/specs/2026-08-28-install-story-design.md`

## Global Constraints

- PHP `^8.4`; Laravel `^13.0`. The runtime image must match `composer.json`'s floor.
- **Docker is available in this environment.** Every task is verified by building and running, never by inspection alone.
- The existing suite must stay green throughout: SQLite `php artisan test --compact` → **424 passed, 1 skipped**; PostgreSQL (container `mnemon-pg`, host port 55432) → **425 passed, 0 failed**.
- Run `./vendor/bin/pint` before every commit that touches PHP.
- Never delete or move `storage/oauth-*.key`.
- Do not change tool signatures or the MCP wire contract.
- **Out of scope, do not touch:** `config/mnemon.php`'s `MNEMON_EMBEDDING_DRIVER` default (native installs keep `openai`), the Ollama compose profile (D10 — the driver cannot store an embedding), and the D12 stored-`tsvector` change.


## Corrections applied during execution

Four things in the task text below were found to be wrong while executing it. The
task text is left as written so the record stays honest; these are the rulings that
superseded it. Anyone re-running this plan should apply them.

| Where | What is wrong | What replaced it |
|---|---|---|
| Task 2, the Caddyfile | `auto_https off` sits in the global options block. It is a global switch with no per-site override, so setting `DOMAIN` binds `:443` and never provisions a certificate — TLS handshakes fail and plaintext gets `400`. Not degraded HTTPS; a total outage. | The directive is deleted. A bare `:80` has no hostname, so Caddy provisions nothing there regardless. |
| Task 2, Step 5 Command 1 | Sourcing the entrypoint inside an arg-less `sh -c` zeroes `$@`, so `exec "$@"` cannot work and the command hangs no matter what `MNEMON_BOOTSTRAP` is set to. It is a bug in the test command, not the entrypoint. | Invoke the real `ENTRYPOINT` + `CMD` instead. |
| Task 3, the compose file | Publishes only `127.0.0.1:8080:80` and declares no volume for `/data`. ACME needs port 80 reachable and TLS needs 443 published, so the documented `DOMAIN` path could not work; and Caddy's certificates live in `/data/caddy`, so they were destroyed on every `down`. | Interpolated `${HTTP_BIND:-127.0.0.1:8080}` / `${HTTPS_BIND:-127.0.0.1:8443}` binds, plus a `caddy_data` named volume. `env_file` became `${MNEMON_ENV_FILE:-.env}` so the smoke script can point it at a template instead of a developer's real `.env`. |
| Task 4, the asset guard | Requests `/oauth/authorize?client_id=x`, which returns 500, and greps the body for `ViteManifestNotFound`. `curl -f` suppresses the body on an HTTP error, so the grep runs on an empty file and the check passes unconditionally. A valid `client_id` would not help either — that route needs an authenticated session. | Assert inside the container that `public/build/manifest.json` exists **and** every file it references exists. |
| Task 4, Step 3 | The prescribed fault (make the entrypoint's seed unconditional) does not reproduce a failure: `AdminUserSeeder` has its own existence guard keyed on email, so the reseed no-ops. | Wipe the admin user in the entrypoint, then reseed unconditionally — that genuinely exercises the rotated-password branch. |

The common shape is worth naming: every one of these was an assertion or a recipe that
could not observe the thing it claimed to check. None were visible by reading the plan
against itself; all were obvious the moment the code was run.

## File structure

| File | Responsibility |
|---|---|
| `.dockerignore` | Keep the 265MB `benchmark/data/`, `.git`, `node_modules`, and `vendor` out of the build context |
| `Dockerfile` | Three stages: Node assets → Composer vendor → FrankenPHP runtime |
| `docker/Caddyfile` | Serve `:80` plainly when `DOMAIN` is unset; let Caddy provision TLS when it is set |
| `docker/entrypoint.sh` | Idempotent boot: clear caches, key, migrate, Passport keys, seed once, exec |
| `compose.yaml` | `app` + `db` + `scheduler`, healthchecks, volumes, no published `db` port |
| `.env.docker.example` | Production-shaped config — **not** a copy of `.env.example` |
| `.github/workflows/ci.yml` | Gains a third job that boots the stack and asserts against it |
| `README.md`, `docs/USERGUIDE.md`, `CONTRIBUTING.md` | Docker as the primary path |

---

### Task 1: Build context and image

The image must produce `public/build`. `resources/views/mcp/authorize.blade.php:44` uses `@vite`, and `git ls-files public/build` returns **zero files** — so without a Node stage the landing page, wiki, and admin all render fine (they use committed static CSS) and the OAuth consent screen dies with `ViteManifestNotFoundException` mid-redirect inside Claude Code's browser popup. That is the deepest possible place to fail.

**Files:**
- Create: `.dockerignore`
- Create: `Dockerfile`

**Interfaces:**
- Consumes: nothing.
- Produces: an image tagged `mnemon:dev` containing `public/build/manifest.json`, `vendor/`, and the PHP extensions `pdo_pgsql`, `intl`, `zip`, `gd`, `bcmath`, `opcache`. Tasks 2-4 run this image.

- [ ] **Step 1: Prove the build context problem before solving it**

```bash
du -sh benchmark/data .git node_modules 2>/dev/null
```

Expected: `benchmark/data` is ~265MB. Without a `.dockerignore` every `docker build` ships that to the daemon. Record the number.

- [ ] **Step 2: Write `.dockerignore`**

```
.git
.github
node_modules
vendor
benchmark/data
benchmark/results
benchmark/state
storage/logs
storage/framework/cache
storage/framework/sessions
storage/framework/views
.env
.env.*
!.env.docker.example
.superpowers
docs
tests
*.md
```

- [ ] **Step 3: Write the Dockerfile**

```dockerfile
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
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# --- runtime ------------------------------------------------------------
FROM dunglas/frankenphp:php8.4 AS runtime

# The FrankenPHP base ships none of these.
RUN install-php-extensions pdo_pgsql intl zip gd bcmath opcache

WORKDIR /app

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

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
```

Note this references `docker/Caddyfile` and `docker/entrypoint.sh`, created in Task 2. Create minimal placeholders now so the build succeeds — a Caddyfile containing `:80 { root * /app/public; php_server }` and an entrypoint containing `#!/bin/sh` + `exec "$@"` — and Task 2 replaces both with the real thing.

- [ ] **Step 4: Build and verify the image contains what it must**

```bash
docker build -t mnemon:dev .
docker run --rm mnemon:dev php -m | grep -E "^(pdo_pgsql|intl|zip|gd|bcmath|Zend OPcache)$"
docker run --rm mnemon:dev sh -c 'ls public/build/manifest.json && php -r "echo PHP_VERSION;"'
```

Expected: all six extensions listed, `manifest.json` present, PHP 8.4.x. If `manifest.json` is missing the Node stage is wrong — fix it rather than proceeding, because nothing downstream will reveal it.

- [ ] **Step 5: Confirm the context shrank**

```bash
docker build -t mnemon:dev . 2>&1 | head -2
```

Expected: the "transferring context" size is a few MB, not ~270MB. Record it.

- [ ] **Step 6: Commit**

```bash
git add .dockerignore Dockerfile docker/
git commit -m "build: multi-stage image with a Node asset stage

public/build is untracked and the OAuth consent view uses @vite, so an image
without an asset stage renders every other page correctly and then dies with
ViteManifestNotFoundException on the one page every agent must load.

.dockerignore keeps benchmark/data (265MB), .git and node_modules out of the
build context."
```

---

### Task 2: Configuration, Caddyfile, and entrypoint

**Files:**
- Create: `.env.docker.example`
- Replace: `docker/Caddyfile`
- Replace: `docker/entrypoint.sh`

**Interfaces:**
- Consumes: the image from Task 1.
- Produces: an entrypoint that is safe to run on every boot, and `.env.docker.example` as the file `compose.yaml` (Task 3) references via `env_file`.

- [ ] **Step 1: Write `.env.docker.example`**

**This is not a copy of `.env.example`.** That file is a development config — `APP_ENV=local`, `APP_DEBUG=true`, `LOG_LEVEL=debug` — and promoting it to everyone's production config means a public knowledge base serving Laravel debug pages, with stack traces and config, to anonymous visitors on any 500.

```ini
# Copy to .env before `docker compose up`.
APP_NAME=Mnemon
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://localhost:8080

# Set to a hostname you control to get automatic HTTPS from Let's Encrypt.
# Leave blank to serve plain HTTP on 127.0.0.1:8080 for local trial.
DOMAIN=

LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=pgsql
DB_HOST=db
DB_PORT=5432
DB_DATABASE=mnemon
DB_USERNAME=mnemon
DB_PASSWORD=change-me-before-you-deploy

# No embeddings by default: works with no account and no spend. Set to
# "openai" and supply OPENAI_API_KEY for semantic search.
MNEMON_EMBEDDING_DRIVER=none
OPENAI_API_KEY=

# Nothing dispatches jobs today. sync means a future dispatch() fails loudly
# rather than rotting unclaimed in the jobs table.
QUEUE_CONNECTION=sync
SESSION_DRIVER=database
CACHE_STORE=database
```

- [ ] **Step 2: Write `docker/Caddyfile`**

FrankenPHP defaults `SERVER_NAME` to `localhost`, under which Caddy mints a **self-signed internal-CA certificate**, serves HTTPS on 443, and redirects HTTP to it. So `claude mcp add --transport http … http://localhost:8080/mcp` would fail TLS verification after the redirect. The `:80` form must be explicit.

```
{
	frankenphp
	auto_https off
}

{$CADDY_SITE_ADDRESS::80} {
	root * /app/public
	encode zstd gzip
	php_server
}
```

The entrypoint sets `CADDY_SITE_ADDRESS` to `:80` when `DOMAIN` is empty, and to the domain when it is not — turning `auto_https` back on in that case. Implement that in Step 3 and verify both branches in Step 5.

- [ ] **Step 3: Write `docker/entrypoint.sh`**

```sh
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
```

- [ ] **Step 4: Make it executable and rebuild**

```bash
chmod +x docker/entrypoint.sh
docker build -t mnemon:dev .
```

- [ ] **Step 5: Verify both DOMAIN branches without a database**

```bash
docker run --rm -e MNEMON_BOOTSTRAP=0 mnemon:dev sh -c '. /usr/local/bin/entrypoint 2>/dev/null; echo skipped-bootstrap'
docker run --rm -e DOMAIN= mnemon:dev sh -c 'echo "${CADDY_SITE_ADDRESS:-unset}"'
```

The second command shows the variable is only set inside the bootstrap path. That is expected — Task 3 verifies the served address end to end once `db` exists. What Step 5 must establish is that **a non-bootstrap container exits the entrypoint immediately** rather than trying to migrate. Confirm the first command prints `skipped-bootstrap` quickly and does not hang waiting for a database.

- [ ] **Step 6: Commit**

```bash
git add .env.docker.example docker/Caddyfile docker/entrypoint.sh
git commit -m "feat: docker entrypoint, Caddyfile, and production env template

.env.docker.example is deliberately not a copy of .env.example: that file is
APP_ENV=local with APP_DEBUG=true, and shipping it as everyone's production
config means debug pages with stack traces for anonymous visitors.

The Caddyfile sets the site address explicitly because FrankenPHP defaults
SERVER_NAME=localhost, under which Caddy serves a self-signed cert on 443 and
redirects HTTP to it — so a documented plain-HTTP localhost URL would fail TLS
verification.

The entrypoint is gated on MNEMON_BOOTSTRAP so only the app container runs it;
a shared entrypoint would race two key:generate and two seeds on first boot."
```

---

### Task 3: The compose stack

**Files:**
- Create: `compose.yaml`

**Interfaces:**
- Consumes: the image, entrypoint, Caddyfile, and env template from Tasks 1-2.
- Produces: a stack where `docker compose up -d` reaches a healthy `/up` and a rendering OAuth consent page. Task 4 asserts idempotency against it; Task 5 runs it in CI.

- [ ] **Step 1: Write `compose.yaml`**

```yaml
services:
  db:
    image: pgvector/pgvector:pg17
    restart: unless-stopped
    environment:
      POSTGRES_USER: ${DB_USERNAME}
      POSTGRES_PASSWORD: ${DB_PASSWORD}
      POSTGRES_DB: ${DB_DATABASE}
    volumes:
      - pgdata:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U ${DB_USERNAME} -d ${DB_DATABASE}"]
      interval: 5s
      timeout: 5s
      retries: 10
    # Deliberately no `ports:` — the database is reachable only from the
    # compose network.

  app:
    build: .
    restart: unless-stopped
    env_file: .env
    environment:
      MNEMON_BOOTSTRAP: "1"
    # Bound to loopback when DOMAIN is unset. Compose publishes on 0.0.0.0 by
    # default and punches through ufw on Linux, so an unbound "just trying it"
    # run on a VPS would serve the knowledge base and login page in plaintext
    # to the internet.
    ports:
      - "127.0.0.1:8080:80"
    volumes:
      - storage:/app/storage
    depends_on:
      db:
        condition: service_healthy
    healthcheck:
      test: ["CMD-SHELL", "curl -fsS http://localhost/up || exit 1"]
      interval: 10s
      timeout: 5s
      retries: 12
      start_period: 60s

  scheduler:
    build: .
    restart: unless-stopped
    env_file: .env
    environment:
      MNEMON_BOOTSTRAP: "0"
    volumes:
      - storage:/app/storage
    depends_on:
      app:
        condition: service_healthy
    command: ["php", "artisan", "schedule:work"]

volumes:
  pgdata:
  storage:
```

No queue worker: there is no `app/Jobs`, nothing implements `ShouldQueue`, and nothing dispatches.

- [ ] **Step 2: Bring the stack up**

```bash
cp .env.docker.example .env.docker-test
docker compose --env-file .env.docker-test up -d --build
docker compose --env-file .env.docker-test ps
```

Expected: three services, `db` healthy, `app` healthy within the start period.

- [ ] **Step 3: Assert the stack actually serves the pages that matter**

```bash
curl -fsS -o /dev/null -w "up: %{http_code}\n"        http://127.0.0.1:8080/up
curl -fsS -o /dev/null -w "landing: %{http_code}\n"   http://127.0.0.1:8080/
curl -fsS -o /dev/null -w "oauth meta: %{http_code}\n" http://127.0.0.1:8080/.well-known/oauth-authorization-server
curl -fsS "http://127.0.0.1:8080/oauth/authorize?client_id=x&redirect_uri=http://localhost&response_type=code&scope=mcp:use" -o /tmp/authz.html -w "authorize: %{http_code}\n" || true
grep -c "ViteManifestNotFound" /tmp/authz.html || echo "no vite manifest error"
```

Expected: `/up` returns 200; the authorize request may legitimately 4xx on an unknown `client_id`, but it must **not** produce a Vite manifest error. That error is the failure Task 1's asset stage exists to prevent, and this is the only step that proves it.

- [ ] **Step 4: Assert the bootstrap did its work**

```bash
docker compose --env-file .env.docker-test exec -T app sh -c 'ls -l $(php artisan tinker --execute="echo config(\"mnemon.admin_password_path\");" | tail -1)'
docker compose --env-file .env.docker-test exec -T app php artisan tinker --execute='echo App\Models\User::count();'
docker compose --env-file .env.docker-test exec -T db psql -U mnemon -d mnemon -tAc "SELECT count(*) FROM pg_extension WHERE extname='vector';"
```

Expected: the admin password file exists at mode `600`, exactly one user, and the `vector` extension is installed — proving the driver-guarded migration ran.

- [ ] **Step 5: Verify the scheduler is running and did not re-bootstrap**

```bash
docker compose --env-file .env.docker-test logs scheduler | head -20
docker compose --env-file .env.docker-test exec -T app php artisan tinker --execute='echo App\Models\User::count();'
```

Expected: the scheduler is running `schedule:work`, its logs contain **no** migration or seeding output, and the user count is still exactly 1. Two users, or migration output in the scheduler log, means the `MNEMON_BOOTSTRAP` gate is not working.

- [ ] **Step 6: Tear down and commit**

```bash
docker compose --env-file .env.docker-test down -v
rm -f .env.docker-test
git add compose.yaml
git commit -m "feat: compose stack — app, db, scheduler

db publishes no port and app binds to 127.0.0.1 when DOMAIN is unset: compose
publishes on 0.0.0.0 by default and punches through ufw, so an unbound local
trial on a VPS would serve the knowledge base in plaintext to the internet.

Only app carries MNEMON_BOOTSTRAP=1; the scheduler waits on app's /up health
check and runs schedule:work. No queue worker — nothing dispatches."
```

---

### Task 4: Prove the bootstrap is idempotent

The entrypoint runs on **every** boot. A self-hoster restarting the stack, or pulling a new image, must not have their admin password rotated or their migrations half-applied. Nothing so far proves that.

**Files:**
- Create: `docker/smoke.sh`

**Interfaces:**
- Consumes: the stack from Task 3.
- Produces: `docker/smoke.sh`, which Task 5 runs in CI.

- [ ] **Step 1: Write the smoke script**

```sh
#!/bin/sh
# Boots the stack, asserts it works, restarts it, and asserts the restart
# changed nothing it should not have. Run from the repository root.
set -eu

ENVFILE=.env.smoke
cp .env.docker.example "$ENVFILE"

cleanup() {
    docker compose --env-file "$ENVFILE" down -v >/dev/null 2>&1 || true
    rm -f "$ENVFILE"
}
trap cleanup EXIT

docker compose --env-file "$ENVFILE" up -d --build

echo "waiting for app health…"
i=0
until [ "$(docker compose --env-file "$ENVFILE" ps app --format '{{.Health}}')" = "healthy" ]; do
    i=$((i + 1))
    [ "$i" -gt 60 ] && { echo "app never became healthy"; docker compose --env-file "$ENVFILE" logs app; exit 1; }
    sleep 5
done

curl -fsS -o /dev/null http://127.0.0.1:8080/up
echo "health OK"

curl -fsS -o /tmp/smoke-authz.html \
  "http://127.0.0.1:8080/oauth/authorize?client_id=x&redirect_uri=http://localhost&response_type=code&scope=mcp:use" || true
if grep -q "ViteManifestNotFound" /tmp/smoke-authz.html; then
    echo "FAIL: OAuth consent page has no built assets"
    exit 1
fi
echo "consent page renders"

PW1=$(docker compose --env-file "$ENVFILE" exec -T app sh -c 'cat storage/admin-password.txt')
USERS1=$(docker compose --env-file "$ENVFILE" exec -T app php artisan tinker --execute='echo App\Models\User::count();' | tail -1)
[ "$USERS1" = "1" ] || { echo "FAIL: expected 1 user, got $USERS1"; exit 1; }

echo "restarting to prove the bootstrap is idempotent…"
docker compose --env-file "$ENVFILE" restart app
i=0
until [ "$(docker compose --env-file "$ENVFILE" ps app --format '{{.Health}}')" = "healthy" ]; do
    i=$((i + 1))
    [ "$i" -gt 60 ] && { echo "app never became healthy after restart"; exit 1; }
    sleep 5
done

PW2=$(docker compose --env-file "$ENVFILE" exec -T app sh -c 'cat storage/admin-password.txt')
USERS2=$(docker compose --env-file "$ENVFILE" exec -T app php artisan tinker --execute='echo App\Models\User::count();' | tail -1)

[ "$PW1" = "$PW2" ] || { echo "FAIL: admin password rotated on restart"; exit 1; }
[ "$USERS2" = "1" ] || { echo "FAIL: user count changed to $USERS2 on restart"; exit 1; }

echo "OK: stack boots, serves, and restarts without rotating credentials"
```

- [ ] **Step 2: Run it**

```bash
chmod +x docker/smoke.sh
./docker/smoke.sh
```

Expected: ends with `OK: stack boots, serves, and restarts without rotating credentials`. If the password rotates, the seeder's existence guard or the entrypoint's user-count check is wrong — fix the cause, not the assertion.

- [ ] **Step 3: Prove the script can fail**

Temporarily change the entrypoint's seeding condition so it seeds unconditionally, re-run `./docker/smoke.sh`, and confirm it exits non-zero on the rotated-password check. Then restore the entrypoint and confirm it is byte-identical (`git diff --stat docker/entrypoint.sh` shows nothing). A smoke test that cannot fail is decoration.

- [ ] **Step 4: Commit**

```bash
git add docker/smoke.sh
git commit -m "test: smoke script proving the stack boots and restarts cleanly

The entrypoint runs on every boot, so a restart must not rotate the admin
password or re-seed. Verified the script can fail by making the seeder
unconditional and watching it catch the rotation."
```

---

### Task 5: The compose CI job

The spec calls this "the highest-value one and the least obvious": everything this group produces — Dockerfile, entrypoint, compose topology, Caddyfile, bootstrap ordering — is invisible to a CI that only runs PHPUnit.

**Files:**
- Modify: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: `docker/smoke.sh` from Task 4.
- Produces: nothing later depends on it.

- [ ] **Step 1: Add the job**

Append to `.github/workflows/ci.yml`, alongside the existing `tests` and `lint` jobs:

```yaml
  compose:
    name: compose
    runs-on: ubuntu-latest
    timeout-minutes: 20
    steps:
      - uses: actions/checkout@v4

      - name: Boot the stack and smoke it
        run: ./docker/smoke.sh
```

- [ ] **Step 2: Verify the workflow still parses**

```bash
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml')); print('workflow YAML valid')"
```

- [ ] **Step 3: Confirm the job would actually catch a broken image**

Locally, break the asset stage (comment out the `COPY --from=assets` line in the Dockerfile), run `./docker/smoke.sh`, and confirm it fails on the consent-page check. Restore the Dockerfile and confirm `git diff --stat Dockerfile` is empty.

This is the same discipline as Task 4 Step 3: prove the guard catches the thing it exists for, on the specific failure that motivated the asset stage.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: boot the compose stack and smoke it

PHPUnit cannot see the Dockerfile, entrypoint, compose topology, or bootstrap
ordering. Verified the job catches a broken asset stage by removing the
COPY --from=assets line and watching the consent-page check fail."
```

---

### Task 6: Documentation

Shipping an install whose docs describe a different install is the failure this whole piece exists to prevent.

**Files:**
- Modify: `README.md`
- Modify: `docs/USERGUIDE.md`
- Create: `CONTRIBUTING.md`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

- [ ] **Step 1: README — Docker quickstart as the primary path**

Add a quickstart near the top:

````markdown
## Quickstart

```bash
git clone https://github.com/coopers98/mnemon.git
cd mnemon
cp .env.docker.example .env
docker compose up -d
```

Then open `http://localhost:8080`. The admin password is generated on first
boot and written to `storage/admin-password.txt` inside the `app` container:

```bash
docker compose exec app cat storage/admin-password.txt
```

There is no password reset flow — save it somewhere safe.

To serve on a real hostname with automatic HTTPS, set `DOMAIN` in `.env` and
publish ports 80 and 443.
````

Also correct the driver table: it currently names `openai` as the default and advertises `ollama`. Under Docker the default is `none`, and `ollama` **cannot store an embedding** — the column is `vector(1536)` and `nomic-embed-text` is 768-d (defect D10). Say so rather than listing it as an option.

- [ ] **Step 2: USERGUIDE — demote the native install**

Move the existing native instructions under a heading making clear they are the alternative, and cross-link the Docker quickstart. Do not delete them.

- [ ] **Step 3: Write `CONTRIBUTING.md`**

```markdown
# Contributing

## Running the tests

```bash
composer install
php artisan test
```

The suite runs on SQLite by default and needs no setup — Passport signing keys
are generated automatically on first run.

### Running against PostgreSQL

Production runs PostgreSQL, and some defects are invisible on SQLite: it treats
an unresolvable double-quoted identifier as a string literal rather than
erroring, which has hidden real bugs. CI runs both. To run PostgreSQL locally:

```bash
docker run -d --name mnemon-pg -e POSTGRES_USER=mnemon -e POSTGRES_PASSWORD=mnemon \
  -e POSTGRES_DB=mnemon_test -p 55432:5432 pgvector/pgvector:pg17

DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 DB_DATABASE=mnemon_test \
DB_USERNAME=mnemon DB_PASSWORD=mnemon MNEMON_EMBEDDING_DRIVER=none \
php artisan test
```

You will need the `pdo_pgsql` PHP extension.

## Formatting

```bash
./vendor/bin/pint
```

## The Docker stack

`./docker/smoke.sh` boots the full stack, asserts it serves, restarts it, and
checks the bootstrap did not rotate credentials. CI runs it on every PR.
```

- [ ] **Step 4: Verify the quickstart works exactly as written**

Follow your own README from a clean state:

**This repository has a working `.env` used for local development and the test
suite. Do not overwrite or delete it.** Use a scratch env file, exactly as the
smoke script does:

```bash
docker compose --env-file .env.readme-check down -v 2>/dev/null || true
cp .env.docker.example .env.readme-check
docker compose --env-file .env.readme-check up -d --build
# poll rather than sleep
for i in $(seq 1 60); do curl -fsS -o /dev/null http://127.0.0.1:8080/up && break; sleep 5; done
curl -fsS -o /dev/null -w "landing: %{http_code}\n" http://127.0.0.1:8080/
docker compose --env-file .env.readme-check exec -T app cat storage/admin-password.txt
docker compose --env-file .env.readme-check down -v && rm -f .env.readme-check
git status --short   # must show no change to .env
```

Because you are verifying with `--env-file` rather than `.env`, the README's
literal `cp .env.docker.example .env` instruction is not itself exercised. Read
that line critically instead: confirm the variable names it produces match what
`compose.yaml` consumes, and say in your report that the copy step was verified
by inspection rather than execution.

Expected: 200, and a password printed. If any documented command needs a flag the README omits, fix the README — a quickstart that does not work verbatim is worse than none.

- [ ] **Step 5: Commit**

```bash
git add README.md docs/USERGUIDE.md CONTRIBUTING.md
git commit -m "docs: Docker quickstart as the primary install path

Corrects the driver table, which named openai as the default and advertised
ollama — a driver that cannot store an embedding (D10). Adds CONTRIBUTING with
the PostgreSQL instructions, since some defects are structurally invisible on
SQLite."
```

---

### Task 7: Timezone and mail — the spec's "Also fixed here"

**Files:**
- Modify: `routes/console.php:17-42`
- Modify: `.env.docker.example`
- Test: `tests/Feature/ScheduleTimezoneTest.php`

**Interfaces:**
- Consumes: `.env.docker.example` from Task 2.
- Produces: nothing.

`routes/console.php` encodes Central Time as hard-coded UTC hours with comments
like `->dailyAt('08:00') // UTC = 3 AM CT (CDT)`. That is wrong half the year
for its author and wrong all year for every other user — this is about to be
open source. And `.env.example:62` sets `MAIL_MAILER=log` while `routes/web.php`
serves a live contact form, so submissions go silently to a log file.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ScheduleTimezoneTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTimezoneTest extends TestCase
{
    public function test_scheduled_commands_declare_an_explicit_timezone(): void
    {
        $events = app(Schedule::class)->events();

        $this->assertNotEmpty($events, 'expected scheduled commands to be registered');

        foreach ($events as $event) {
            $this->assertNotNull(
                $event->timezone,
                "scheduled command [{$event->command}] has no timezone, so its hard-coded "
                .'hour silently means something different for every operator'
            );
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ScheduleTimezoneTest --compact`
Expected: FAIL — the events carry no timezone.

- [ ] **Step 3: Make the schedule timezone-explicit**

In `routes/console.php`, give every one of the four commands `->timezone(config('app.timezone'))` and rewrite the times as local hours with the misleading UTC-offset comments removed. Keep the intent (early-morning maintenance) rather than preserving the exact UTC instants — those instants were only correct for one operator in one half of the year.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ScheduleTimezoneTest --compact`
Expected: PASS.

- [ ] **Step 5: Expose the timezone and be honest about mail**

Add to `.env.docker.example`:

```ini
# Maintenance tasks run on this timezone.
APP_TIMEZONE=UTC
TZ=UTC

# Mail is written to the log by default — the contact form will not send
# anything until you configure a real mailer.
MAIL_MAILER=log
```

Confirm `config/app.php` reads `APP_TIMEZONE`; if it hard-codes `'UTC'`, wire it to `env('APP_TIMEZONE', 'UTC')` so the variable is not decorative.

- [ ] **Step 6: Run the full suite on both engines**

Run: `php artisan test --compact` → expect **425 passed, 1 skipped** (424 + this test).
Then the PostgreSQL command from the Global Constraints → expect **426 passed, 0 failed**.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint
git add routes/console.php .env.docker.example config/app.php tests/Feature/ScheduleTimezoneTest.php
git commit -m "fix: schedule in an explicit timezone, and document that mail only logs

routes/console.php encoded Central Time as hard-coded UTC hours — wrong half
the year for its author and wrong always for everyone else. Every scheduled
command now declares config('app.timezone'), exposed as APP_TIMEZONE.

MAIL_MAILER=log with a live contact form means submissions go silently to a
log file; the Docker env template now says so."
```

---

## Completion

- [ ] Both engines still green: SQLite **424 passed, 1 skipped**; PostgreSQL **425 passed, 0 failed**
- [ ] `./vendor/bin/pint --test` clean
- [ ] `./docker/smoke.sh` passes from a clean checkout
- [ ] Mark group 2 complete in `docs/superpowers/specs/2026-08-28-install-story-design.md`
- [ ] Note for group 3: the benchmark harness (`benchmark/`) needs to point at the compose stack; `benchmark/config.py` defaults `MNEMON_URL` to the deployed host
