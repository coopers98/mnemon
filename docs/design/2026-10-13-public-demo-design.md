# Public demo and landing site — design

**Date:** 2026-10-13
**Roadmap:** Piece 5 of `2026-08-28-release-roadmap.md` ("Read-only demo instance")
**Status:** proposed

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
- **Hosting:** a separate instance with its own database, running the same
  code with demo mode switched on. The real brain is never reachable from it.
- **Domain:** trymnemon.com, on the apex. The landing page is at `/` and the
  demo is at `/wiki` and `/palace`. One site, one deploy.

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
    // Set on a real install to link its pages to the public demo.
    'url' => env('MNEMON_DEMO_URL'),
],
```

### What is reachable: an allowlist

A new `App\Http\Middleware\DemoMode` is **prepended to the global middleware
stack** in `bootstrap/app.php`, so it runs before routing for every request,
including routes that Filament (`/admin`), Passport (`/oauth/*`) and
`laravel/mcp` (`/mcp`, `/.well-known/*`) register themselves. When demo mode is
off it does nothing. When demo mode is on:

- The method must be `GET` or `HEAD`. Anything else gets a 404.
- The path must match the allowlist. Anything else gets a 404:
  - `/`
  - `/how-it-works`
  - `/wiki`, `/wiki/*`
  - `/palace`, `/palace/*`
  - `/up`
  - built assets under `/build/*`, `/favicon.ico`, `/robots.txt`. The web
    server normally serves these before PHP; they are listed in case it does
    not.

An allowlist rather than unregistering routes, because Mnemon does not own most
of its routes. A package update that adds a route is closed in demo mode by
default rather than open by default.

A 404 rather than a 403, so a probe learns nothing about which paths exist.

### Auth and throttling

The `/wiki` and `/palace` group in `routes/web.php` keeps `auth` on a real
install and drops it in demo mode:

```php
Route::middleware(config('mnemon.demo.enabled') ? [] : ['auth'])
```

`DemoMode` also rate-limits every request it lets through to 120 a minute per
IP, using `RateLimiter`, so the limit lives in one place and covers `/` and
`/how-it-works` too. Over the limit, it returns 429.

### Scheduled jobs

`mnemon:decay-confidence` and `mnemon:apply-retention` change data, so they are
not scheduled in demo mode. `mnemon:demo-reset` is scheduled in their place
(see §4).

## 2. UI changes

All are conditional on `config('mnemon.demo.enabled')` and invisible on a real
install.

- **Banner:** a one-line strip on every page: "Demo · a read-only sample brain.
  Install your own →". It links to the install section of the landing page.
- **Colophon** (`partials/colophon.blade.php`): no sign-in or sign-out link.
- **Landing** (`landing/index.blade.php`):
  - The "Sign in" calls to action become "Browse the demo" (→ `/wiki`).
  - The contact form is hidden, because POST is refused. A link to the GitHub
    repository's issues replaces it.
- **Real installs:** when `MNEMON_DEMO_URL` is set, the landing page and
  `/how-it-works` show a "See the demo" link. The README links to
  https://trymnemon.com unconditionally.

## 3. The synthetic brain

### Content

Hand-written fixtures in `database/demo/`, loaded by
`Database\Seeders\DemoSeeder`. They are plain files so they can be reviewed in a
diff:

- `palace.yaml`: wings, rooms and drawers. Each drawer has:
  - a fixture key (`key: auth-decision-1`);
  - content;
  - a source (`claude-code:session_digest`, `codex:session_digest`, `manual`);
  - metadata;
  - `days_ago`.
- `wiki/<name>.md`: one file per wiki page. Front matter holds the type, title,
  description, `sources:` (drawer fixture keys) and `related:` (page names).
  Optional `revisions:` hold earlier bodies, so the history view has something
  to show.

The persona is a fictional solo developer, Rowan Vale, building two or three
small products, with a few collaborators. Roughly:

- **About 5 wings:** `person-rowan`, two or three `project-*` wings, `decisions`,
  `unfiled`.
- **About 15 rooms and about 120 drawers.** The drawers read like real digest
  output: decisions with reasons, bugs and their root causes, a preference or
  two, a few low-value notes.
- **About 10 wiki pages** covering all five page types (`person`, `project`,
  `concept`, `decision`, `synthesis`). Every page links to its source drawers,
  and the related links form a small graph.

All names, companies, domains, people and figures are invented. No real
person, project or credential appears. The seeder test (§5) scans the fixtures
with `ContentSanitizer` and fails if anything token-shaped is in them.

### Loading

`DemoSeeder` creates wings, rooms and drawers in fixture order and keeps a
fixture-key → drawer-id map. It then creates wiki pages, translating `sources`
keys to ids, and writes revisions oldest-first so `revision_count` is right.

Timestamps come from `now()->subDays($days_ago)`, so the demo always looks
recent and `last_compiled_at` never trips the 30-day stale check. Embeddings are
left null.

It refuses to run unless demo mode is on.

## 4. Reset, deploy and hosting

### `php artisan mnemon:demo-reset`

1. If demo mode is off, it exits non-zero with "demo mode is off; refusing to
   wipe this database". It does this before touching the database. This guard
   exists because the same command on a real install would erase the brain.
2. Runs `migrate:fresh --force`.
3. Runs `DemoSeeder`.

It is scheduled daily at 04:00 in demo mode only, so stale timestamps and any
data a bug let through are both undone every night. The demo site's deploy
script runs it after `migrate`.

### Forge site

- **Site:** trymnemon.com (and `www` redirecting to the apex) on the existing
  server, with Let's Encrypt.
- **Repository and branch:** same repository, `main`, auto-deploy on push.
- **Database:** a new Postgres database and a new database user that owns only
  that database. The production database user is not reused.
- **`.env`:**
  - its own `APP_KEY`;
  - `MNEMON_DEMO=true`;
  - `MNEMON_EMBEDDING_DRIVER=none`;
  - no `OPENAI_API_KEY`;
  - no mail credentials;
  - `SESSION_DRIVER=cookie` and `CACHE_STORE=file`, so neither sessions nor the
    rate limiter write rows.
- **Deploy script:** the production script plus `php artisan mnemon:demo-reset
  --no-interaction`.
- **Scheduler:** the usual Forge `schedule:run` cron.
- **DNS:** the user registers trymnemon.com and points the apex and `www` at the
  server.

### mnemon.cooperhosting.net afterwards

It keeps working as today. Whether its `/` should redirect to trymnemon.com or
show a plain sign-in page is left open. It is not needed for the demo, and it
can be decided once trymnemon.com is live.

## 5. Testing

Feature tests, with `config(['mnemon.demo.enabled' => true])` set per test
unless noted:

- **Throttle:** request 121 in a minute from one IP returns 429.
- **Reachable pages:** with no user, `/`, `/how-it-works`, `/wiki`, a wiki page,
  its history, `/wiki/search?q=…`, `/palace`, a wing, a room and a drawer each
  return 200.
- **Closed paths:**
  - `POST /contact`, `POST /login` and `POST /mcp` each return 404;
  - `/login`, `/install`, `/admin`, `/oauth/authorize` and
    `/.well-known/oauth-authorization-server` each return 404 on GET.
- **Route sweep:** every route in `Route::getRoutes()` whose URI is not on the
  allowlist returns 404, called with each of its methods. This is the test that
  catches a route a package adds later.
- **Demo mode off** (no config override): `/wiki` and `/palace` redirect to
  `/login`, and `POST /contact` still works. This guards against regressions on
  real installs.
- **Reset guard:** with demo mode off, `mnemon:demo-reset` exits non-zero and
  the existing rows survive.
- **Seeder:** in demo mode, the seeder loads, and then:
  - every page's sources resolve to drawers;
  - every `related` name resolves to a page;
  - every page has `revision_count` ≥ 1;
  - `ContentSanitizer` changes nothing in any fixture.
- **UI:** in demo mode the banner is rendered and the sign-in link and contact
  form are not.

## Open items

- Whether `mnemon.cooperhosting.net/` redirects to trymnemon.com (decide after
  launch).
- Registering trymnemon.com and adding its DNS records is the user's step.
  Forge's GitHub connection was confirmed healthy on 2026-10-13, so the site can
  be created once DNS is in place.
