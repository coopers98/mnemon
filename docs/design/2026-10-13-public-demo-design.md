# Public demo and landing site — design

**Date:** 2026-10-13
**Roadmap:** Piece 5 of `2026-08-28-release-roadmap.md` ("Read-only demo instance")
**Status:** approved after self-review and adversarial review (2026-10-13)

## Goal

A visitor who has never installed Mnemon opens **trymnemon.com**, reads what
Mnemon is, and within a minute is browsing a real Mnemon: compiled wiki pages,
the drawers they were compiled from, and the wings and rooms those drawers sit
in. Nothing they do can change anything, and the site costs close to nothing
to run.

trymnemon.com is also the project's public face. The landing page and the
`/how-it-works` explainer live there, so `mnemon.cooperhosting.net` can go back
to being one person's install.

## Decisions already made

- **What the demo shows:** the existing web UI (`/wiki`, `/palace`), with no
  login. It is not an MCP demo and not an interactive capture demo.
- **Data:** a synthetic second brain written for the demo. No real data,
  including scrubbed real data.
- **Hosting:** a separate, isolated Forge site with its own SQLite database,
  running the same code with demo mode switched on. It shares a server with the
  production install; §4 covers what keeps them apart.
- **Domain:** trymnemon.com, on the apex, behind Cloudflare. The landing page is
  at `/` and the demo is at `/wiki` and `/palace`. One site, one deploy.

## Non-goals

- Writing in the demo: no capture, no compiling, no editing, no MCP.
- Semantic search. The web UI's search is full-text only (`WikiController`
  calls `PalaceSearchService` with `mode: 'fulltext'`), so the demo needs no
  embeddings and no OpenAI key.
- A separate marketing site. The landing page is the existing
  `landing/index.blade.php`.

## 1. Demo mode

`MNEMON_DEMO=true` sets `config('mnemon.demo.enabled')`. `config/mnemon.php`
gains:

```php
'demo' => [
    // A public, read-only instance holding a synthetic brain. See
    // docs/design/2026-10-13-public-demo-design.md.
    'enabled' => (bool) env('MNEMON_DEMO', false),
    // The one hostname the demo answers to. Any other Host header gets a 404.
    'host' => env('MNEMON_DEMO_HOST'),
    // Requests per minute per client (per IPv4 address, per IPv6 /64).
    'rate_limit' => (int) env('MNEMON_DEMO_RATE_LIMIT', 120),
    // Set on a real install to link its pages to the public demo.
    'url' => env('MNEMON_DEMO_URL'),
],
```

### The `DemoMode` middleware

A new `App\Http\Middleware\DemoMode` is **appended to the global middleware
stack** in `bootstrap/app.php`. Global middleware runs before routing, so it
covers routes that Filament (`/admin`), Passport (`/oauth/*`), `laravel/mcp`
(`/mcp`, `/.well-known/*`), Livewire and `/storage/{path}` register themselves.
Appending rather than prepending means it runs after the framework's
`TrustProxies`, so `$request->ip()` is already the real client (see "Real client
IPs").

When demo mode is off it does nothing. When demo mode is on, in this order:

1. **Debug guard.** If `app.debug` is true, it returns a bare 503. A public demo
   must never render a stack trace, and this makes a mistaken `.env` fail
   closed.
2. **Rate limit.** Every request is counted, **including the ones about to be
   refused**, so probing closed paths is limited too. The key is the client IP:
   IPv4 as-is, IPv6 truncated to its /64. Over `rate_limit` a minute, it returns
   a bare 429.
3. **Host.** If `demo.host` is set and the request's host differs, it returns a
   bare 404. This shuts out the Forge `*.on-forge.com` alias, where
   `TrustProxies` trusts every proxy.
4. **Method.** `getRealMethod()` must be `GET` or `HEAD`. `getMethod()` is not
   used, because it honours `_method` and `X-HTTP-METHOD-OVERRIDE`. Anything
   else gets a bare 404.
5. **Path.** `$request->path()` must match the allowlist. Anything else gets a
   bare 404:
   - `/`
   - `/how-it-works`
   - `/wiki`, `/wiki/*`
   - `/palace`, `/palace/*`
   - `/up`

Static files (`/styles/*`, `/fonts/*`, `/favicon.ico`, `/robots.txt`) are served
by nginx from `public/` before PHP runs, so they need no allowlist entry.

**Why a bare response.** Refused requests get an empty `response('', 404)`
rather than `abort(404)`. This skips rendering the Blade error page, so a flood
of refused requests is as cheap as Laravel can make it. Each bare response also
carries an `X-Mnemon-Demo: refused` header, so tests can tell a refusal from
the router's own 404.

**Why an allowlist.** Mnemon does not own most of its routes. A package update
that adds a route is closed in demo mode by default rather than open by
default.

### Auth

The `/wiki` and `/palace` group in `routes/web.php` swaps `auth` for a new
`auth.unless-demo` middleware (`AuthenticateUnlessDemo`, extending Laravel's
`Authenticate`). It checks `config('mnemon.demo.enabled')` **per request** and
passes the request through in demo mode; otherwise it behaves exactly like
`auth`.

The check cannot go in the route file (`config(...) ? [] : ['auth']`). Routes
are registered once at boot and baked in by `route:cache`, so a per-test config
override would never reach them, and the "demo off still requires login" test
would test nothing.

### Real client IPs

Behind Cloudflare, `REMOTE_ADDR` is a Cloudflare edge address. The framework's
`TrustProxies` middleware already reads `config('trustedproxy.proxies')` on
every request. Demo mode uses that mechanism instead of setting proxies itself;
setting them inside `DemoMode` would be wiped when `TrustProxies` ran.

- A new `config/trustedproxy.php` sets `proxies` from `TRUSTED_PROXIES`
  (comma-separated; unset keeps Laravel's default behaviour, so real
  installs are unchanged).
- The demo's `.env` sets `TRUSTED_PROXIES` to Cloudflare's published IPv4 and
  IPv6 ranges (https://www.cloudflare.com/ips/). The plan records the list with
  the date it was copied.
- Only those ranges are trusted. A request that reaches the origin directly
  cannot choose its IP by sending `X-Forwarded-For`.

The origin is locked to Cloudflare anyway (§4), so in practice every request
arrives from a trusted range.

### Not indexed

In demo mode, responses under `/wiki` and `/palace` carry
`X-Robots-Tag: noindex`, so search engines do not index a fictional person's
notes as if they were real. `/` and `/how-it-works` stay indexable.

### Data-changing commands

`mnemon:decay-confidence` and `mnemon:apply-retention` **refuse to run in demo
mode**: they exit non-zero with a message and change nothing. This guard lives
in the commands themselves, not in `routes/console.php`, so it also covers
manual runs and can be tested. `routes/console.php` also leaves both out of the
schedule in demo mode and schedules `mnemon:demo-reset` instead (§4).

### Bug fixed on the way

`/wiki/search?q[]=x` returns 500 today: `$request->string('q')` casts an array
to a string (`WikiController.php:67`). In demo mode that becomes a public 500
that anyone can trigger. The search reads `q` only when it is a string, and
treats anything else as an empty query.

## 2. UI changes

All are conditional on `config('mnemon.demo.enabled')` and invisible on a real
install.

- **Banner:** a one-line strip on every page: "Demo · a read-only sample brain.
  Run your own →". It links to the landing page's `#install` section.
- **Colophon** (`partials/colophon.blade.php`):
  - There is no sign-in or sign-out link.
  - The guest navigation shows Wiki (`/wiki`) and Palace (`/palace`). Today a
    guest gets no Wiki link and "Palace" points at `/#palace`.
- **Landing** (`landing/index.blade.php`):
  - The "Sign in" calls to action become "Open the wiki" (→ `/wiki`) and
    "Walk the palace" (→ `/palace`).
  - The `@auth`-only footer navigation (Wiki, Palace) is shown in demo mode.
  - The contact form is hidden, because POST is refused. A link to the GitHub
    repository's issues replaces it.
- **Palace index** (`palace/index.blade.php`): the "Open admin" button is hidden.
- **`/how-it-works`:** the "This instance currently holds…" figures and the
  reasoning that follows from them are relabelled "This demo holds…", with one
  sentence saying the brain is synthetic.
- **Wing → wiki link** (`PalaceController::wing`): the lookup
  `like '%project-x%'` never matches a page named `project:x`, so the "read wiki
  entry" link never appears. It is fixed to look up the page whose name is the
  wing slug with its first `-` turned into `:` (`project-x` → `project:x`,
  `person-rowan` → `person:rowan`). This is a real bug on every install; the
  demo just makes it visible.
- **Error pages:** the 403 page's "signed in" link is not reachable in demo mode
  (refusals are bare 404s), so it is left alone.
- **Real installs:** when `MNEMON_DEMO_URL` is set, the landing page and
  `/how-it-works` show a "See the demo" link. The README links to
  https://trymnemon.com unconditionally.

## 3. The synthetic brain

### Content

Hand-written fixtures in `database/demo/`, loaded by
`Database\Seeders\DemoSeeder`. They are plain files, so they can be reviewed in
a diff:

- `palace.yaml`: wings, rooms and drawers. Each drawer has:
  - a fixture key (`key: auth-decision-1`);
  - content;
  - a source (`claude-code:session_digest`, `codex:session_digest`, `manual`);
  - metadata;
  - `days_ago`.
- `wiki/<slug>.md`: one file per wiki page. The file name is a slug
  (`person-rowan.md`), because a name like `person:rowan` cannot be checked out
  on Windows. Front matter holds:
  - `name` (`person:rowan`);
  - title and description (the page type comes from the name prefix, so there
    is no `type` field);
  - `sources:` (drawer fixture keys);
  - `related:` (page names);
  - `days_ago`.

  An optional `revisions:` list holds earlier bodies, oldest first, each with
  its own `days_ago`. The file body is the current version.

The persona is a fictional solo developer, Rowan Vale, building two or three
small products, with a few collaborators. Roughly:

- **About 5 wings:** `person-rowan`, two or three `project-*` wings, `decisions`,
  `unfiled`.
- **About 15 rooms and about 120 drawers.** The drawers read like real digest
  output: decisions with reasons, bugs and their root causes, a preference or
  two, a few low-value notes.
- **About 10 wiki pages** covering all five page types (`person`, `project`,
  `concept`, `decision`, `synthesis`). Every page cites its source drawers, and
  the related links form a small graph. Wiki page names match their wing slugs
  where one exists, so the wing → wiki link appears.

**Constraints on the fixtures:**

- **Nothing real.** All names, companies, domains, people and figures are
  invented. No real person, project, path or credential appears.
- **Never stale.** Every page's current `days_ago` is under `wiki.stale_days`,
  so nothing shows as stale.
- **Spread-out dates.** Drawer `days_ago` values span about 90 days.

### Loading through the real write path

The seeder must produce exactly what the real system produces. So the write
logic in `ContextSetTool` is first **moved into a new
`App\Services\WikiPageWriter`**, and the tool calls it. The tool's behaviour is
unchanged, and its existing tests guard that.

`WikiPageWriter::write(...)` does what the tool does today:

- sets `sources`/`source_count`, `related` and `confidence`;
- increments `revision_count` and sets `previous_content_hash`;
- calculates `confidence_score` and `quality_score` (heuristic; no network);
- marks source drawers `tier = consolidated`;
- writes a `WikiPageRevision` row for every write, **including the current
  body**;
- extracts the entity and syncs `related` into graph edges through
  `KnowledgeGraphService`.

The tool keeps its MCP-specific work: argument parsing, the optimistic-lock
error message, the `wiki:index` and `wiki:log` pages, and `BrainSession`
logging.

`DemoSeeder`:

1. Creates wings, rooms and drawers in fixture order with `forceCreate`, so
   `created_at` comes from `days_ago`. `created_at` is not fillable, and a plain
   `create()` would silently stamp every drawer "now". It keeps a fixture-key →
   drawer-id map.
2. For each page, calls `WikiPageWriter::write()` once per earlier revision and
   once for the current body, translating `sources` keys to ids.
3. Back-dates the timestamps the writer stamped "now", using `forceFill`: each
   revision's `written_at`, and the page's `created_at`, `updated_at` and
   `last_compiled_at`.

Seeding makes no network calls: the drawer and wiki-page observers return early
on any connection other than pgsql. `DemoSeeder` refuses to run unless demo mode
is on.

## 4. Reset, deploy and hosting

### `php artisan mnemon:demo-reset`

1. **Guards, checked before anything is opened.** It exits non-zero, touching
   nothing, unless:
   - demo mode is on;
   - the default connection's driver is `sqlite`;
   - the database file's directory, resolved with `realpath()`, is inside
     `realpath(storage_path())`. Resolving both sides makes zero-downtime
     symlinks (`releases/<id>/storage` → the shared `storage`) compare
     correctly, and the directory exists even before the first build.
   - if a live database file already exists, it contains a `demo_marker` table.
     `DemoSeeder` creates that table, so a reset can only ever replace a
     database a previous reset built. A real brain pointed at by a mistaken
     `.env` is refused.

   This guard exists because the same command on a real install would erase the
   brain.
2. **Lock.** It takes an exclusive `flock` on `<database>.lock`. A second run,
   such as a deploy overlapping the 04:00 schedule, waits for the first.
3. **Clean start.** It deletes any leftover `<database>.building` and its
   `-journal`, `-wal` and `-shm` files from a crashed run.
4. **Build beside the live file.** It registers a temporary `demo_build` SQLite
   connection at `<database>.building` with `journal_mode=DELETE`, so all data
   lives in the one file, then runs:
   - `migrate --database=demo_build --force`;
   - `db:seed --class=DemoSeeder --database=demo_build --force`.

   `db:seed --database` switches the default connection for the seeder, so
   Eloquent writes go to the build file. Calling the seeder directly would write
   to the live file.
5. **Swap.** It calls `DB::purge('demo_build')`, `rename()`s the built file over
   the live one (atomic on one filesystem), and deletes any stale `-journal`,
   `-wal` or `-shm` beside the live path. A visitor sees the old brain or the
   new one, never an empty or half-seeded database. PHP-FPM workers open a fresh
   SQLite connection per request, so a worker mid-request on the old file
   finishes safely.
6. **Tidy.** It runs `cache:clear`, which empties the file cache, including rate
   limiter entries.

Steps 3–6 run with the lock held and release it in a `finally`.

**When it runs:**

- **Nightly.** It is scheduled daily at 04:00 in demo mode only, with
  `->withoutOverlapping()`. That undoes stale timestamps, any data a bug let
  through, and rate-limiter cache growth every night.
- **On deploy.** The deploy script runs it in place of `migrate`, immediately
  before `$ACTIVATE_RELEASE()`, so each deploy's schema, fixtures and code go
  live together. On the first deploy, when no live file exists yet, the swap
  creates it.

### Forge site

Site `3425569` on server `atlas-abs` (1153034) was created on 2026-10-13 with
zero-downtime releases, `storage` shared, PHP 8.4, and `www` redirecting to the
apex. It has no repository yet and serves a static holding page from
`releases/000-placeholder`. Until demo mode is merged and the site is
configured as below, the full app is never served from trymnemon.com.

**Isolation from production.** The production install is on the same server.

- **Own user and pool.** The demo site runs as its own Unix user with its own
  PHP-FPM pool (Forge website isolation). A bug in the demo then cannot read
  production's `.env` or Passport keys.
  - The pool's `pm.max_children` is capped, at 4, so a flood cannot use up
    production's workers.
  - If Forge cannot isolate an existing site, the site is deleted and recreated
    isolated. It is empty, and only the certificate needs reissuing.
- **Cloudflare only.** The trymnemon.com nginx server block accepts connections
  only from Cloudflare's IP ranges (`allow`/`deny`). Its origin cannot be hit
  directly to skip Cloudflare.
  - Production stays unproxied and reachable as today. The shared IP is already
    public through mnemon.cooperhosting.net's DNS.
- **No production credentials.** The demo's database is SQLite, so the site
  holds no credentials for the production Postgres cluster.

**Configuration:**

- **Repository and branch:** same repository, `main`, auto-deploy on push.
- **Web directory:** `current/public`.
- **Database: SQLite.** `DB_DATABASE` is the absolute path
  `/home/<isolated-user>/trymnemon.com/storage/demo.sqlite`, in shared `storage`
  so it survives releases. The default `database_path(...)` would sit inside a
  release. Full-text search on SQLite matches substrings rather than whole
  words, which is acceptable for a demo.
- **`.env` (complete list of what differs from `.env.example`):**
  - `APP_ENV=production`
  - `APP_DEBUG=false` (`DemoMode` also refuses to serve if it is true)
  - `APP_URL=https://trymnemon.com`
  - its own `APP_KEY`
  - `MNEMON_DEMO=true`
  - `MNEMON_DEMO_HOST=trymnemon.com`
  - `DB_CONNECTION=sqlite`
  - `DB_DATABASE=` the absolute path above
  - `MNEMON_EMBEDDING_DRIVER=none`
  - `TRUSTED_PROXIES=` Cloudflare's ranges
  - `SESSION_DRIVER=cookie`
  - `CACHE_STORE=file`
  - `LOG_STACK=daily`, `LOG_DAILY_DAYS=7`, `LOG_LEVEL=warning`
  - no `OPENAI_API_KEY`, no mail credentials

  Sessions and the rate limiter never write to the database. Logs rotate.
- **Deploy script:** the production script with two changes:
  - the Passport steps are removed, because nothing on the demo issues tokens;
  - `migrate --force` is removed, and `php artisan mnemon:demo-reset
    --no-interaction` runs immediately before `$ACTIVATE_RELEASE()`.
- **Scheduler:** Forge's Laravel scheduler integration on the site.

### DNS and Cloudflare (done 2026-10-13)

- The zone trymnemon.com is in Cloudflare and active. GoDaddy's nameservers
  point at it. The parking records Cloudflare imported were deleted.
- The apex and `www` are proxied A records to 165.245.153.92. The null MX and
  `v=spf1 -all` records stay, declaring that the domain sends no mail.
- Forge holds a Let's Encrypt certificate for the apex and `www`, issued over
  HTTP-01 before the proxy was turned on.
- **To confirm in the dashboard:** SSL mode Full (strict), and Always Use HTTPS.
  The API token cannot edit zone settings.
- **Rate limiting at the edge:** add a Cloudflare rate-limiting rule (the free
  plan includes one). Requests that reach Laravel then are already bounded, and
  requests through Cloudflare Workers, which can forge `X-Forwarded-For`, are
  limited at the edge.

### mnemon.cooperhosting.net afterwards

It keeps working as today. Two choices are left open until trymnemon.com is
live:

- **Its `/`:** redirect to trymnemon.com, or show a plain sign-in page.
- **Duplicate landing page:** until then, the same page is indexable on both
  hosts; a canonical link to trymnemon.com would settle that.

## 5. Testing

Feature tests, with demo mode on per test unless noted. Refusals are identified
by the `X-Mnemon-Demo: refused` header, never by the status alone.

- **Reachable pages:** with no user, these each return 200:
  - `/` and `/how-it-works`;
  - `/wiki`, a wiki page, its history, and `/wiki/search?q=…`;
  - `/palace`, a wing, a room and a drawer.
- **Route sweep:** every route in `Route::getRoutes()`, called with each of its
  methods and with route parameters filled with a dummy value:
  - any non-`GET`/`HEAD` method is refused, **including on `/wiki/*` and
    `/palace/*`**;
  - any `GET`/`HEAD` route whose path is not on the allowlist is refused.

  This catches a route a package adds later.
- **Method override:** `POST /wiki` with `_method=GET`, and with
  `X-HTTP-METHOD-OVERRIDE: GET`, is refused.
- **Host:** a request for `x.on-forge.com` is refused.
- **Debug guard:** with `app.debug` on, every request gets 503.
- **Throttle:**
  - request `rate_limit + 1` in a minute from one IP gets 429;
  - refused requests count towards the limit;
  - two IPv6 addresses in the same /64 share a bucket;
  - with `trustedproxy.proxies` set to a test range, two requests from that
    range with different `X-Forwarded-For` clients are counted separately;
  - a request from outside the range that sends `X-Forwarded-For` is counted
    under its own address.
- **Not indexed:** a `/wiki` response carries `X-Robots-Tag: noindex`; `/` does
  not.
- **Search input:** `/wiki/search?q[]=x` returns 200, with demo mode on and off.
- **Demo mode off:** `/wiki` and `/palace` redirect to `/login`, `POST /contact`
  still works, and no `X-Mnemon-Demo` header ever appears. This guards against
  regressions on real installs.
- **Commands in demo mode:** `mnemon:decay-confidence` and
  `mnemon:apply-retention` exit non-zero and change nothing.
- **Reset guards.** Each of these exits non-zero with every row still in place:
  - demo mode off;
  - a non-SQLite connection;
  - a database file outside `storage_path()`;
  - an existing database file without `demo_marker`.
- **Reset build:**
  - the live file is byte-identical until the rename;
  - after the reset, the live file holds the seeded brain;
  - no `.building`, `-journal`, `-wal` or `-shm` files are left behind;
  - a leftover `.building` from a "crashed" run is replaced;
  - a second reset replaces the first.
- **`ContextSetTool`:** its existing tests pass unchanged after the move to
  `WikiPageWriter`.
- **Seeder fidelity** (after a reset, on the built database):
  - for every page:
    - `revision_count == revisions()->count()`;
    - the latest revision's content equals the page content;
    - `source_count == count(sources)`;
  - every source id resolves to a drawer, and every such drawer is
    `consolidated`;
  - every `related` name resolves to a page, and the graph has edges;
  - no page is older than `wiki.stale_days`;
  - drawer `created_at` values span more than 30 days;
  - every `project-*` and `person-*` wing has a matching wiki page.
- **Fixture hygiene:** every fixture file is scanned for:
  - anything `ContentSanitizer` would change;
  - a denylist of real names, handles, hosts and paths (`cooper`,
    `cooperhosting`, `coopers98`, `atlas-abs`, `samwise`, `ananke`, `openclaw`,
    `/root/`, `/home/`);
  - email addresses other than `@example.com`.

  Drawer metadata is shown verbatim, so it is scanned too.
- **UI in demo mode:**
  - the banner and the guest Wiki and Palace navigation are rendered;
  - the sign-in link, the contact form and the "Open admin" button are not;
  - a wing page links to its wiki page.

## Open items

- How mnemon.cooperhosting.net's `/` behaves after launch, and a canonical link
  for its landing page (see §4).
- In the Cloudflare dashboard: confirm SSL mode Full (strict), turn on Always
  Use HTTPS, and add the rate-limiting rule (§4).
