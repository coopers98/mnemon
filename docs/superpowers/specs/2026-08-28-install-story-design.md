# Design: Install Story (Piece 2)

**Date:** 2026-08-28
**Roadmap:** `2026-08-28-release-roadmap.md`, Piece 2

## Goal

A stranger runs `docker compose up` and gets a working Mnemon: HTTPS if they
have a domain, an admin login, a reachable MCP endpoint, and no accounts or API
spend required. And the test suite executes against PostgreSQL, so the class of
defect that has repeatedly reached `main` stops being invisible.

**Done when** a fresh clone reaches a working OAuth consent screen — the page
every agent must load — and CI runs the suite on both PostgreSQL and SQLite.

## Locked decisions

| Decision | Choice |
|---|---|
| Compose's role | **Primary install path.** Native install demoted to an alternative, not deleted. |
| Default embedding driver | **`none`** — full-text + temporal only. Zero accounts, zero spend on first run. |
| Web runtime | **FrankenPHP**, automatic HTTPS via Caddy when `DOMAIN` is set. |
| Ollama profile | **Cut from this piece.** See D10 below — it cannot work against the current schema. |

## Services

| Service | Image | Role |
|---|---|---|
| `app` | built from the repo, FrankenPHP base | Laravel, MCP endpoint, TLS termination. **Runs the bootstrap.** |
| `db` | `pgvector/pgvector:pg17` | Postgres with the extension compiled in. **No published port.** |
| `scheduler` | same image as `app` | `php artisan schedule:work`. **Does not run the bootstrap.** |

No queue worker: there is no `app/Jobs`, nothing implements `ShouldQueue`, and
nothing dispatches. The Docker env sets `QUEUE_CONNECTION=sync` so that a future
`dispatch()` fails loudly rather than rotting unclaimed in the `jobs` table.

## Bootstrap

Runs in **exactly one container** (`app`). This is load-bearing: if `scheduler`
shared the entrypoint, first boot would run two concurrent `key:generate` calls
(split-brain `APP_KEY`), two `migrate --force`, two `passport:keys`, and two
admin seeds. That is a first-boot-only, timing-dependent failure — the worst
kind to debug and the easiest to prevent.

`scheduler` instead waits on `app` via `depends_on: condition: service_healthy`.
The app already exposes `/up` (`bootstrap/app.php:13`), so healthchecks replace
a hand-rolled wait-for-db loop. `db` gets `pg_isready`. Everything gets
`restart: unless-stopped`.

Entrypoint, idempotent on every boot:

1. `config:clear`, `view:clear`, `cache:clear` — stale compiled config surviving
   an image upgrade is a classic self-hosted failure.
2. `key:generate` if `APP_KEY` is empty.
3. `migrate --force` — the vector extension creates itself
   (`2022_08_03_000000_create_vector_extension.php`, driver-guarded).
4. `passport:keys` if `storage/oauth-private.key` is absent, then `chmod 600`.
5. Seed the admin user **only if the users table is empty**.
6. `exec` FrankenPHP.

Named volumes: `pgdata` and `storage`. The Passport signing key lives in
`storage`; losing it invalidates every issued token.

## Configuration

**`.env` is not bind-mounted.** A bind mount of a file that does not exist makes
Docker create a *directory* at that path on the host, and the first thing a
stranger runs then fails in a way that looks like their Docker is broken.
Instead: a checked-in `.env.docker.example`, compose `env_file: .env`, and a
documented `cp .env.docker.example .env` as step one.

`.env.docker.example` is **not** a copy of `.env.example`. That file is a
development config — `APP_ENV=local`, `APP_DEBUG=true`, `LOG_LEVEL=debug` — and
promoting it to everyone's production config means a public knowledge base
serving Laravel debug pages, with stack traces and config to anonymous visitors,
on any 500. The Docker template sets `APP_ENV=production`, `APP_DEBUG=false`,
`LOG_LEVEL=info`, `LOG_CHANNEL=stderr`, `DB_HOST=db`,
`MNEMON_EMBEDDING_DRIVER=none`, `QUEUE_CONNECTION=sync`.

**`APP_URL` must be derived from `DOMAIN`.** Caddy would serve
`https://mnemon.example.com` while Passport's OAuth metadata and every
config-built URL still advertised `http://localhost`. The entrypoint sets
`APP_URL` from `DOMAIN`, and `SESSION_SECURE_COOKIE=true` when `DOMAIN` is set.

**The localhost fallback must be explicit.** FrankenPHP defaults to
`SERVER_NAME=localhost`, under which Caddy mints a self-signed internal-CA
certificate, serves HTTPS on 443, and redirects HTTP to it — so `--transport
http … http://localhost/mcp` fails TLS verification after the redirect. The
Caddyfile sets `:80` explicitly when `DOMAIN` is unset, and the no-domain case
publishes on **`127.0.0.1:8080:80`**, not `0.0.0.0`. Compose publishes on all
interfaces by default and punches through ufw on Linux; without this bind, a
user who runs the "just trying it" path on a VPS serves their knowledge base and
login page in plaintext to the internet with nothing telling them.

`db` gets no `ports:` entry at all, and a generated password rather than a
constant shared by every install.

## Image

Multi-stage:

1. **Node stage** — `npm ci && npm run build`, producing `public/build`.
   **This is not optional.** `resources/views/mcp/authorize.blade.php:44` uses
   `@vite`, and `public/build` has zero tracked files. Without it the landing
   page, wiki, and admin all render (they use committed static CSS) so the
   install *looks* fine — and then the first `claude mcp add` dies with
   `ViteManifestNotFoundException` mid-redirect inside the OAuth popup. Filament's
   own assets are already committed and need no publishing step.
2. **Composer stage** — `composer install --no-dev --optimize-autoloader`.
3. **Runtime** — FrankenPHP with `pdo_pgsql`, `intl`, `zip`, `gd`, `bcmath`,
   `opcache`. The base image ships none of these.

**Classic mode, explicitly — not worker mode.** `laravel/octane` is absent and
the app has request-lifetime assumptions (a singleton `EmbeddingManager` in
`AppServiceProvider.php:30`, Passport state, Livewire). Stating this prevents a
later "optimisation" inheriting state-bleed bugs.

A `.dockerignore` is required, not a nicety: `benchmark/data/` is **265MB** and
would otherwise ship in every build context, alongside `.git` and `node_modules`.

## Product bugs this piece must fix

These are live defects, not Docker concerns, but each one breaks the install
story so they land here.

**The admin seeder ships a known password.** `AdminUserSeeder:17` hard-codes
`Hash::make('password')` and prints it; `USERGUIDE.md` already claims a
generated password, so the docs are lying today. Every Mnemon on the internet
would share one admin login — and `canAccessPanel()` returns `true` for any user
(`User.php:23-26`), while the panel manages OAuth clients and tokens. Fix:
generate a random password, write it to `storage/admin-password.txt` mode 600,
and document that **there is no recovery path** — the panel is `->login()` only
with no `->passwordReset()` (`AdminPanelProvider.php:28`), so a lost password
means `artisan tinker`. Printing to stdout is not sufficient: nobody watches
`docker compose up -d`, and the credential then lives forever in `docker logs`.

**`brain_status` always reports `dimensions: null`.** `BrainStatusTool:81` reads
`config('mnemon.embedding.dimensions')`; the real key is
`mnemon.embedding.drivers.{driver}.dimensions`. Verified by execution. Fix the
key and add `embedded_drawers` / `unembedded_drawers` alongside it, so embedding
coverage is visible rather than inferred. (Amended to match what shipped: the
draft called these `embedded_count` / `unembedded_count`, but wiki pages carry
embeddings too and are not counted here, so the names say `drawers`.)

**~~Semantic search under `driver=none` returns a silent empty result set.~~
Withdrawn on verification.** The review flagged `PalaceSearchService.php:63-68`
as user-reachable. It is not: nothing in `app/` calls `search()` with
`mode='semantic'`, `DrawerSearchService.php:29` hard-codes `mode: 'hybrid'`, and
`hybridSearch` skips the semantic leg cleanly under `NullDriver`
(`PalaceSearchService.php:141-145`). The early-return is unreachable defensive
code, not a defect. No task.

## New defect: D10 — the Ollama driver cannot store an embedding

`drawers.embedding` and `wiki_pages.embedding` are both hard-coded
`vector(1536)` (`2026_04_24_100002:24`, `2026_04_24_100003:25`), while
`nomic-embed-text` is 768-d (`config/mnemon.php:14`). Postgres rejects a 768-d
value into a `vector(1536)` column, so with `MNEMON_EMBEDDING_DRIVER=ollama`
every `drawer_add` fails at save, `mnemon:reembed` fails on its first
`saveQuietly()`, and every semantic query fails on dimension mismatch. Nothing
in the app or the migrations ever ALTERs the column.

`README.md:149-155` advertises Ollama as a supported driver. It is not.

**Out of scope for this piece** — fixing it means either a
`mnemon:reembed --resize` that recreates the column at the driver's dimension,
or an untyped `vector` column with a dimension check in application code. Both
deserve their own design. This piece cuts the Ollama compose profile and
corrects the README rather than shipping a documented option that hard-errors.

## CI

Three jobs.

1. **`tests`** — PHP 8.4 pinned (matching `composer.json`'s `^8.4`), a
   `pgvector/pgvector` service container, the full suite against PostgreSQL,
   plus a matrix leg on SQLite to keep the fast
   contributor loop honest. `phpunit.xml:26-27` pins `DB_CONNECTION=sqlite`;
   PHPUnit's `<env>` without `force` does not override an exported variable, so
   the workflow exports `DB_CONNECTION=pgsql`. That mechanism is stated here so
   the implementer does not have to discover it. (Shipped without a
   `passport:keys` step: the `TestCase` hook below generates them for CI and
   contributors alike, so a separate CI step would be dead weight.)
2. **`lint`** — `pint --test`.
3. **`compose`** — build the image, `docker compose up -d`, poll `/up`, curl the
   OAuth discovery document **and the authorize page**, assert the admin
   credential file exists, then `down && up` again to prove the bootstrap is
   idempotent.

The third job is the highest-value one and the least obvious. Everything this
piece produces — Dockerfile, entrypoint, compose topology, Caddyfile, bootstrap
ordering — is invisible to a CI that only runs PHPUnit.

**One test gap worth closing while here:** with `driver=none` in tests and
factories leaving embeddings null, the PostgreSQL leg proves the vector SQL
*parses*, not that a real round-trip works. A test inserting a synthetic 1536-d
vector and querying it closes the operator path for real — and a test asserting
each driver's declared dimensions against the column width would have caught D10
statically.

## Test-environment Passport keys

The suite assumes keys exist on disk; 140 tests fail without them, with no
indication why. A `TestCase` hook runs `passport:keys` when the key file is
missing, guarded by a static flag. This fixes a fresh clone's `php artisan test`
for every contributor, not only the CI runner.

## Documentation

The doc truth-up for **install** lands in this piece, not Piece 4 — shipping an
install whose docs describe a different install is the failure mode this whole
exercise exists to prevent.

- README: Docker quickstart as the primary path; correct the driver table, which
  still names `openai` as the default and advertises Ollama.
- `USERGUIDE.md`: native install demoted to an alternative; the "generated
  password" claim corrected.
- `CONTRIBUTING.md` (new): running tests, the two database backends, driver
  selection.
- One backup line in the README — `docker compose exec db pg_dump …` and "run
  this before upgrading". Full backup tooling stays out of scope, but an
  entrypoint that auto-migrates on every image change, aimed at someone's
  personal knowledge base, owes them that sentence.
- Upgrade note: `git pull && docker compose build`, since no registry image is
  published. State the `pg17` pin and that a future major bump needs
  `pg_upgrade` rather than silently refusing to start on the old `pgdata`.

## Also fixed here — SHIPPED

**Timezone.** `routes/console.php:19,40` encodes Central Time as hard-coded UTC
hours with comments — wrong half the year, and wrong for every user who is not
Cooper. Schedule in server-local time and expose `TZ`/`APP_TIMEZONE`.

Landed on `piece-2-group-2`: every scheduled command now declares
`->timezone(config('app.timezone'))`, `config/app.php` wires
`'timezone' => env('APP_TIMEZONE', 'UTC')` (it was hard-coded, so the env var
was previously decorative), and the two absolute-time commands moved from
UTC-instant hours to the local hours the comments actually describe (3am /
4am). `TZ`/`APP_TIMEZONE` are both exposed in `.env.docker.example`.

**`MAIL_MAILER=log`** with a live contact form (`routes/web.php:12`) silently
writes email to the log. Document it or drop the contact form from the
open-source build.

Landed on `piece-2-group-2`, corrected in review: submissions were never
*only* logged — `ContactController::store` already persisted a
`ContactSubmission` row before attempting any send. The real release blocker
was worse than "silent logging": the mailer sent every submission to the
project author's personal Gmail address, hard-coded with no config key
behind it, in a repo about to go MIT. Fixed by adding `mnemon.contact_to`
(`MNEMON_CONTACT_TO` env, blank by default) — the controller now sends only
when it's configured, addressing that value, and skips the send entirely
otherwise. The submission is always persisted either way. Documented as such
in `.env.docker.example`.

## Out of scope

Backups beyond the one documented `pg_dump` line, monitoring, multi-arch
registry publishing, worker-mode tuning, the D10 schema fix, and connecting the
`benchmark/` harness to the compose stack (Piece 3 owns that, and should state
how it points at a running stack).

## Suggested sequencing

This spec is broad enough that landing order matters. Three groups, in order:

1. **CI + test keys + the three product bugs. — SHIPPED.** Independently
   valuable, lands fastest, and closes the "defects reach main because the
   suite cannot run PostgreSQL" gap immediately. Nothing here depends on
   Docker existing. See "What group 1 shipped" below.
2. **Image and compose. — SHIPPED.** The bulk of the work: Dockerfile,
   entrypoint, Caddyfile, `.env.docker.example`, the `compose` CI job.
3. **Documentation. — SHIPPED.** Written against what shipped, once it had
   shipped.

Group 1 makes group 2 verifiable rather than hoped-at, which is why it goes
first even though group 2 is the headline.

Groups 2 and 3 both landed on the `piece-2-group-2` implementation plan
(seven tasks — image, entrypoint, compose stack, idempotency proof, CI job,
docs, and this timezone/mail closeout). See "What group 2 shipped" below.

## What group 1 shipped

Landed on `piece-2-group-1`. **Group 2 must not re-implement any of this.**

- **`.github/workflows/ci.yml` (new).** Two jobs: `tests` (PHP 8.4, matrix over
  `sqlite` and `pgsql`, `pgvector/pgvector:pg17` service container) and `lint`
  (`pint --test`). The workflow exports `DB_CONNECTION`/`DB_DATABASE` to beat
  `phpunit.xml`'s unforced `<env>`, and also exports:
  - `CI_REQUIRE_PGSQL` — turns the driver-guarded skips in
    `tests/Feature/VectorColumnTest.php` into failures on the pgsql leg, so a
    broken override cannot make that leg silently run SQLite twice and still
    exit 0.
  - `MNEMON_EMBEDDING_DRIVER=none` — the observers embed only on PostgreSQL, so
    the pgsql leg was the first thing in the suite able to reach a provider.
  It also sets `permissions: contents: read`, `timeout-minutes: 20`, and names
  the sqlite extensions rather than relying on `setup-php`'s defaults.
  **The `compose` job is group 2's.**
- **Test-environment Passport keys.** `tests/TestCase.php` runs `passport:keys`
  when either key file is missing, latching only after both exist.
  `Http::preventStrayRequests()` lives there too: no test reaches the network
  unless it stubs the call itself.
- **Admin seeder.** `Str::password(24)`, written to the path in
  `config('mnemon.admin_password_path')` (default
  `storage_path('admin-password.txt')`, gitignored) created restrictive-first
  at mode 0600, throwing rather than creating an admin it cannot hand back a
  password for. Re-seeding leaves an existing admin alone. **Group 2's
  entrypoint should read `config('mnemon.admin_password_path')` rather than
  hard-coding the path**, and must seed only when the users table is empty.
- **`brain_status`.** Reads the per-driver dimensions key, and reports
  `embedded_drawers` / `unembedded_drawers`, guarded by
  `Schema::hasColumn('drawers', 'embedding')` because SQLite never gets the
  column.
- **Vector coverage.** `tests/Feature/VectorColumnTest.php` round-trips a real
  1536-d vector through `drawers.embedding` and exercises the `<=>` operator on
  the pgsql leg, plus a database-free assertion that the shipped default
  driver's declared width matches the `vector(1536)` column (the D10 static
  guard).

Still open from this spec and **owned by later groups**: flipping
`config/mnemon.php`'s default driver to `none`, the README driver table, the
Dockerfile/entrypoint/Caddyfile/`.env.docker.example`, the `compose` CI job, the
timezone fix, and the documentation truth-up.

## What group 2 shipped

Landed on `piece-2-group-2` (seven tasks). Group 3 (or whatever lands next)
must not re-implement any of this.

- **Docker image.** Multi-stage `Dockerfile` (assets → vendor → runtime) on
  FrankenPHP, classic mode. `public/build/manifest.json` and every file it
  references are asserted to exist inside the built image — the guard against
  a repeat of the Vite-manifest-throws-on-the-consent-screen class of bug.
- **Entrypoint and Caddyfile.** `docker/entrypoint.sh` runs the bootstrap
  (`config:clear`/`view:clear`/`cache:clear`, conditional `key:generate`,
  `migrate --force`, conditional `passport:keys` + `chmod 600`, admin seed
  only when the users table is empty) in exactly the `app` container;
  `scheduler` waits on `app`'s `/up` healthcheck instead of sharing the
  bootstrap. `docker/Caddyfile` gets automatic HTTPS when `DOMAIN` is set, a
  bare `:80` otherwise (the `auto_https off` global-switch bug from an
  earlier draft — total TLS outage on any `DOMAIN` — is gone).
- **Compose stack.** `compose.yaml`: `app`, `db` (`pgvector/pgvector:pg17`,
  unpublished port), `scheduler` (`schedule:work`, no queue worker — nothing
  implements `ShouldQueue`). Interpolated `HTTP_BIND`/`HTTPS_BIND`, a
  `caddy_data` named volume (certs used to die on every `down`), and
  `${MNEMON_ENV_FILE:-.env}` so tooling can point compose at a template
  instead of a real `.env`.
- **`docker/smoke.sh`.** Boots the stack in its own `mnemon-smoke` Compose
  project — never the default `mnemon` namespace a self-hoster's own
  `docker compose up` uses from the same checkout — and runs `down -v`
  before `up` so every run starts from nothing; leftover state from a prior
  run would otherwise let the first-boot assertions below pass without the
  code they exist to test. Asserts `/up` responds, that
  `public/build/manifest.json` and every asset file it references exist
  inside the running container, and that exactly one user exists after
  first boot; then restarts `app` and asserts `APP_KEY` and the admin
  password are unchanged. It does **not** assert a rendering OAuth consent
  screen — no such assertion exists — and it does not rotate the admin user
  as part of its own logic; an earlier review pass gutted
  `AdminUserSeeder::run()` as a one-off fault injection to confirm the
  assertions could actually fail, then restored it. That was a review-time
  check, not something the shipped script does.
- **`compose` CI job.** Runs `docker/smoke.sh` on every PR. It does not
  itself "prove" anything beyond running the script and failing the build
  if it exits non-zero — the claim that it "proves the guard catches the
  asset-stage failure" described a review-time fault injection (temporarily
  breaking the asset stage and confirming the script failed), not something
  the CI job re-demonstrates on each run.
- **Documentation truth-up.** README Docker quickstart as the primary install
  path (native install demoted, not deleted), `docs/USERGUIDE.md`,
  `CONTRIBUTING.md` (PHP 8.4 floor stated explicitly).
- **Timezone and mail (this task).** Every scheduled command declares
  `->timezone(config('app.timezone'))`; `config/app.php` wires
  `'timezone' => env('APP_TIMEZONE', 'UTC')` (previously hard-coded, so the
  env var was decorative); the two absolute-time commands moved from
  UTC-instant hours to actual local hours. `routes/console.php`'s header no
  longer describes a Forge queue-worker daemon nothing in the app uses.
  Separately — found during this task, not in the original plan — the contact
  form mailed every submission to the project author's personal Gmail
  address, hard-coded with no config key behind it, on the eve of an MIT
  release; fixed via `mnemon.contact_to` / `MNEMON_CONTACT_TO` (blank by
  default, submission always persisted regardless of whether mail is
  configured).

**Confirmed still open, deliberately not touched by group 2:**
`config/mnemon.php`'s default `MNEMON_EMBEDDING_DRIVER` is still `openai`, not
`none` — the Docker template overrides it to `none` in `.env.docker.example`,
but the application-level default was explicitly out of scope for this group
(native installs without a `.env.docker.example` still default to `openai`
and need `OPENAI_API_KEY`). The `benchmark/` harness still points at a live
deployed host (`benchmark/config.py`'s `MNEMON_URL` default) rather than the
compose stack — Piece 3's, per "Out of scope" above.
