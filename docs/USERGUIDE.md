# Mnemon User Guide

A practical guide to running Mnemon as your shared second brain across every AI agent and device you use.

The [README](../README.md) is the project overview. This document is the day-to-day playbook.

---

## Table of contents

1. [Getting started](#getting-started)
2. [Connecting your first agent (Claude Code)](#connecting-your-first-agent)
3. [Connecting more agents](#connecting-more-agents)
4. [Mental model and conventions](#mental-model)
5. [Daily workflows](#daily-workflows)
6. [The OAuth consent flow, in detail](#oauth-consent)
7. [Running a shared brain across multiple devices](#multi-device)
8. [Bulk loading content](#bulk-loading)
9. [Re-embedding and driver migration](#re-embedding)
10. [Scheduled maintenance](#scheduled-maintenance)
11. [Production deployment](#production-deployment)
12. [Backup and disaster recovery](#backup-and-disaster-recovery)
13. [Troubleshooting](#troubleshooting)
14. [FAQ](#faq)

---

## Getting started

### Prerequisites

- PHP 8.3+
- PostgreSQL 14+ with the `pgvector` extension installed (or SQLite for local-only)
- Composer
- Node optional (Filament's vendor JS is published from the package — you don't need to compile assets unless you customize them)

### Install

```bash
git clone https://github.com/coopers98/mnemon.git
cd mnemon
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```ini
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=mnemon
DB_USERNAME=mnemon
DB_PASSWORD=...

# Required for the default embedding driver
OPENAI_API_KEY=sk-...

# Optional — defaults are sensible
MNEMON_EMBEDDING_DRIVER=openai      # or "ollama" or "none"
PASSPORT_PRIVATE_KEY=               # leave blank; passport:install generates files
PASSPORT_PUBLIC_KEY=
```

### Initialize

```bash
createdb mnemon                       # if Postgres
psql mnemon -c "CREATE EXTENSION IF NOT EXISTS vector;"
php artisan migrate --seed            # creates an admin user
php artisan passport:install          # generates oauth-private.key / oauth-public.key
php artisan serve
```

The seeder prints the admin email and password. The default is `admin@mnemon.local` with a generated password — capture it from the seeder output.

Visit `http://localhost:8000/admin` and log in. You should see the dashboard with empty wing/drawer counts.

### Verify it's running

```bash
# Public discovery endpoint, no auth needed
curl -s http://localhost:8000/api/mcp/tools 2>/dev/null || \
  curl -s http://localhost:8000/.well-known/oauth-authorization-server | head -20
```

You should see either an empty tool list (legacy probe) or the OAuth discovery document (the new path).

---

## Connecting your first agent

The fastest path to "I have an agent reading my brain" is Claude Code. Once that works, every other client follows the same pattern.

### Step 1: Register the MCP server with Claude Code

```bash
claude mcp add --transport http mnemon https://mnemon.example.com/mcp
```

(Replace the URL with `http://localhost:8000/mcp` if you're testing locally.)

Claude Code opens a browser to the OAuth authorize endpoint. The first time, Claude Code performs Dynamic Client Registration (DCR) — Mnemon auto-creates an OAuth client named after the requesting application.

### Step 2: Log in to Mnemon

The browser shows the Filament admin login (or the Mnemon landing page → login). Use your admin credentials. After login, you're redirected to the consent screen.

### Step 3: Grant scopes and wings

The consent screen looks like this:

```
Authorize "Claude Code"

Scopes:
  ☑ palace.read     Read drawers and palace metadata
  ☑ palace.write    Add drawers
  ☑ wiki.read       Read wiki pages, history, graph
  ☑ wiki.write      Compile, lint, and write wiki pages

Wing access:
  ☐ All wings (no restriction)
  ☐ work
  ☐ personal
  ☐ research

[ Authorize ]   [ Deny ]
```

For your first agent, the simplest grant is **all four scopes + the "All wings" master toggle** — full access. You can mint additional, more restrictive tokens for specific agent installs later (see [Multi-device](#multi-device)).

Click **Authorize**. The browser closes. Claude Code shows `mnemon: connected`.

### Step 4: Test it

In Claude Code, run `/mcp`. You should see Mnemon listed with 12 tools, 3 resources, 3 prompts. Then ask Claude something that should trigger a tool call:

> "Add a drawer to the work wing in a 'notes' room with content 'first ever drawer in mnemon, hello world'"

Claude calls `drawer_add`. Verify the drawer appears in the Filament admin under Drawers.

> "Search drawers for 'hello'"

Claude calls `drawer_search`. The drawer comes back.

You're connected. Every other agent install follows the same flow.

---

## Connecting more agents

### Claude Desktop

Claude Desktop's MCP support uses a JSON config file:

- macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`
- Windows: `%APPDATA%\Claude\claude_desktop_config.json`

Add a `mcpServers` entry:

```json
{
  "mcpServers": {
    "mnemon": {
      "transport": "http",
      "url": "https://mnemon.example.com/mcp"
    }
  }
}
```

Restart Claude Desktop. The first MCP call triggers the OAuth flow in your default browser. Same consent screen, same flow as Claude Code.

### Cursor

Cursor's MCP integration is configured in Settings → MCP → Add. Enter the URL `https://mnemon.example.com/mcp`. Cursor handles OAuth in-app. Same consent screen.

### ChatGPT (custom GPT or desktop)

If you're building a custom GPT with MCP support, configure the MCP endpoint as `https://mnemon.example.com/mcp` with OAuth 2.1 + DCR. The GPT registers as an OAuth client on first authorize.

### Custom agents (Anthropic SDK, OpenAI SDK, raw HTTP)

For programmatic clients, mint a Personal Access Token from the CLI:

```bash
php artisan tinker
```

```php
$user = \App\Models\User::first();
$token = $user->createToken('My Custom Agent', ['palace.read', 'palace.write'])->accessToken;
echo $token;
```

Use that token as a bearer:

```bash
curl -X POST https://mnemon.example.com/mcp \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/call",
    "params": {
      "name": "drawer_search",
      "arguments": {"query": "dorothy", "limit": 5}
    }
  }'
```

Personal access tokens **don't have wing restrictions** (the consent screen flow is what captures them). Use them only for agents that legitimately need full access.

### Per-device naming convention

Each OAuth client gets a name. Use it to identify the device:

- `Claude Code @ work-laptop`
- `Claude Code @ personal-desktop`
- `Cursor @ work-laptop`
- `ChatGPT Desktop @ phone`

This name appears in `BrainSession.source` for every audit row, so you can ask "what did the work-laptop install do today?"

To customize the name, edit it in the Filament admin under **Access Control → OAuth Clients** after first registration.

---

## Mental model

### Wings → rooms → drawers

The palace hierarchy is three levels deep, intentionally shallow:

- **Wing** — top-level grouping. Examples: `work`, `personal`, `research`, `project:atlas`, `person:cooper`.
- **Room** — category inside a wing. Examples: `notes`, `meetings`, `decisions`, `email`.
- **Drawer** — single piece of verbatim content. A meeting note, a code snippet, a quote, an email body.

### When to make a new wing vs a new room

A new wing is a new context boundary. Use a new wing when:

- The content has a different audience or sensitivity (work vs personal)
- You'd want to grant a different agent access to just this content
- The content has its own lifecycle independent of other wings

A new room is just an organizational sub-bucket within a wing. Use a new room when:

- The content fits one of your existing wings, but a sub-grouping helps you find it
- You don't need separate auth for it

### Slug conventions

Use `noun:identifier` for entity-specific wings:

- `project:atlas` — everything about the Atlas project
- `person:cooper` — everything about a specific person
- `client:acme` — everything about a client

Use bare nouns for general namespaces:

- `work` — generic work content not tied to a project
- `personal` — personal notes
- `research` — exploratory reading

### Wiki page types

The wiki layer is structured. Every wiki page has a type:

- `person:` — facts about a person (role, contact, history with you)
- `project:` — a project's state, key decisions, current status
- `concept:` — a concept, technique, or pattern (e.g. `concept:optimistic-locking`)
- `decision:` — a decision and its rationale (an ADR)
- `synthesis:` — anything else you want compiled — newsletters, weekly recaps, knowledge maps

Type is inferred from the page name's prefix when you call `context_set` with `name: "person:cooper"`. No prefix → defaults to `synthesis`.

### Drawers vs wiki pages

Drawers are raw and append-only. Wiki pages are compiled from drawers — synthesized, structured, intended for direct human reading.

Rule of thumb:

- **Capture** to a drawer (verbatim, append-only)
- **Compile** to a wiki page (synthesized, mutable, citable)
- **Read** wiki pages first; fall back to drawers when synthesis is missing

The wiki regenerates from drawers when called via `context_set` or `wiki_compile`. Drawers don't regenerate from anything — losing them loses information permanently.

---

## Daily workflows

### Capture a meeting

After a meeting, paste your notes into Claude Code:

> "Add this to the work wing under meetings: [paste]"

Claude calls `drawer_add` with `wing: "work"`, `room: "meetings"`. The drawer auto-stamps the OAuth client name in `source`.

If the meeting is about a specific project, prefer the project wing:

> "Add this to project:atlas under meetings: [paste]"

### Synthesize from drawers

After accumulating several drawers about a topic, ask Claude to synthesize:

> "Synthesize what we know about Dorothy Vaughan into a wiki page"

Claude calls `drawer_search` to gather sources, then `context_set` with `name: "person:dorothy-vaughan"` and the synthesized content. The page auto-updates `wiki/index` and `wiki/log`.

The `SynthesizeWingPrompt` MCP prompt automates this — your client can offer it as a one-click action.

### Recall from past sessions

> "What do we know about Dorothy's role at Atlas?"

Claude calls `context_get` (wiki page lookup by name) first; if the wiki has a page, you get the synthesized answer. Otherwise Claude falls back to `drawer_search` for raw sources.

### Find stale wiki pages

> "Show me wiki pages that haven't been compiled in 30+ days"

Claude calls `context_list` and filters by `last_compiled_at`. The `FindStaleWikiPagesPrompt` automates this.

You can also let it run automatically — `mnemon:auto-compile-stale` is on a 6-hour schedule (see [Scheduled maintenance](#scheduled-maintenance)).

### Use the knowledge graph

> "What concepts are referenced from project:atlas?"

Claude calls `wiki_graph` with `start_page: "project:atlas"`, `max_depth: 2`. You get a typed-edge subgraph showing related entities.

---

## OAuth consent

The consent screen is where you decide what each agent can see and do. Worth understanding deeply.

### Scopes

Four coarse scopes:

- `palace.read` — read drawers, search, palace metadata
- `palace.write` — add drawers (cannot delete; drawers are append-only)
- `wiki.read` — read wiki pages, list, history, graph
- `wiki.write` — compile, lint, set wiki pages

You can grant any subset. A scratch read-only agent might get only `palace.read` + `wiki.read`. A trusted writer agent gets all four.

### Wing access

Independent of scopes — controls *which wings* the granted scopes apply to.

- **All wings (no restriction)** — token sees every wing. Use for trusted personal-device agents.
- **Specific wings** — check the wings you want this agent to see. The token cannot read or write any other wing, even if it has `palace.read`.

Wing restrictions support wildcard patterns. The consent screen exposes one checkbox per wing in your database. To grant `project:*` (all project-prefixed wings), check each `project:*` wing manually, or grant "All wings" and rely on the agent to scope its calls.

### Token lifetimes

- Access token: **1 hour**. Expires fast — agents must refresh.
- Refresh token: **90 days**. Most clients refresh transparently.
- Personal access token: **90 days**. Issued via tinker for headless agents.

When tokens expire, the agent is forced through the OAuth flow again. Refresh tokens avoid this — Claude Code, Claude Desktop, and Cursor all refresh transparently.

### Revoking

Filament admin → **Access Control → Active Tokens** → row → **Revoke**. Takes effect immediately. Revoking the parent OAuth client revokes all its tokens.

You can also revoke from `php artisan tinker`:

```php
\Laravel\Passport\Token::find('token-id')->update(['revoked' => true]);
```

---

## Multi-device

The whole point of Mnemon is one shared brain across every device and agent. Strategy:

### One OAuth client per device, not per agent

Don't create separate clients for "Claude Code" and "Claude Desktop" on the same laptop. Create one client per *physical device* (since devices are what get lost or compromised), and let multiple agents on that device share it.

Or: register fresh per-agent on each device — DCR makes this cheap. Either works.

### Per-device wing restrictions

Suggested layout:

| Device | Client name | Wings granted |
|---|---|---|
| Work laptop | `Mnemon @ work-laptop` | `work`, `project:*`, `person:*` (work-related) |
| Personal desktop | `Mnemon @ home-desktop` | All wings |
| Phone (read-only) | `Mnemon @ phone` | All wings, `palace.read` + `wiki.read` only |
| Untrusted environment (CI, scratch) | `Mnemon @ ci` | Specific wing only, write disabled |

This way: lose the work laptop, the attacker can't access `personal` content even if they extract the token.

### Auditing what each device did

Filament admin → **Access Control → Active Tokens** → click a token → see its associated `BrainSession` rows. Each row shows tool name, input, result count, timestamp.

Or query directly:

```php
\App\Models\BrainSession::where('access_token_id', '...')->latest()->limit(50)->get();
```

The dashboard's **Live MCP Sessions** widget shows the 5 most recent calls across all tokens, sorted by recency.

### Adding a new device

1. On the new device, run `claude mcp add --transport http mnemon https://mnemon.example.com/mcp` (or equivalent for your client).
2. Browser opens to authorize. Log in.
3. Grant scopes and wings appropriate for that device's trust level.
4. Verify connection. Done.

The DB now has one new row in `oauth_clients`, one in `oauth_access_tokens`, and one in `mcp_token_restrictions` (if you set wing restrictions).

---

## Bulk loading

### From OpenClaw

Mnemon ships three OpenClaw integration commands:

```bash
php artisan mnemon:import-memory      # bulk import OpenClaw memory files
php artisan mnemon:ingest-sessions    # ingest session transcripts as drawers
php artisan mnemon:sync-openclaw      # bidirectional sync (also runs daily)
```

See `docs/OPENCLAW-INTEGRATION.md` for the full setup.

### From Markdown files

There's no first-class command yet. The pattern:

```bash
php artisan tinker
```

```php
foreach (glob('/path/to/notes/**/*.md') as $file) {
    \App\Models\Drawer::create([
        'room_id' => \App\Models\Room::firstOrCreate(['slug' => 'imports'])->id,
        'content' => file_get_contents($file),
        'source'  => 'bulk-import',
        'metadata' => ['original_path' => $file],
    ]);
}
```

(The `Room` factory needs a wing — adapt as needed.)

For larger imports, write a one-off artisan command. The `app/Console/Commands/` directory has examples.

### From a CSV / JSON dump

Same pattern via tinker or a one-off command. The `Drawer` model accepts arbitrary `metadata` JSON, so import sources can attach traceability fields.

---

## Re-embedding

If you change the embedding driver (e.g. switching from OpenAI to Ollama, or upgrading the OpenAI model), existing embeddings are stale — they were computed against the old model and live in vector space the new model doesn't know about.

```bash
php artisan mnemon:reembed
```

This re-embeds every drawer and wiki page using the currently configured driver. It's idempotent — running twice gives the same result.

Performance: ~50 drawers/sec against OpenAI's embeddings API; faster against Ollama (local). Budget accordingly. Consider running it overnight if you have thousands of drawers.

To switch drivers:

1. Update `MNEMON_EMBEDDING_DRIVER` in `.env`
2. Run `php artisan config:clear`
3. Run `php artisan mnemon:reembed`
4. Verify via `brain_status` that the embedding driver/dimensions match the new config

If you have wiki pages compiled against old embeddings (they reference drawer IDs, not vectors directly, so they're fine), no further action needed.

---

## Scheduled maintenance

Mnemon ships several scheduled commands. Wire them up in your crontab or via Forge's scheduled jobs:

```cron
* * * * * cd /path/to/mnemon && php artisan schedule:run >> /dev/null 2>&1
```

The schedule (defined in `routes/console.php` or `app/Console/Kernel.php`) covers:

| Schedule | Command | Purpose |
|---|---|---|
| Daily | `mnemon:decay-confidence` | Apply time-based confidence decay |
| Every 6h | `mnemon:auto-lint` | Run wiki_lint with auto-fix; repair stale/orphan/low-confidence pages |
| Every 6h | `mnemon:auto-compile-stale` | Recompile wiki pages flagged stale |
| Daily | `mnemon:apply-retention` | Enforce retention policies (archive past half-lives) |
| Daily | `mnemon:sync-openclaw` | Sync with OpenClaw |

You can also run any of these manually:

```bash
php artisan mnemon:auto-lint --dry-run   # preview what auto-lint would change
```

### Monitoring health

The `brain_status` MCP tool returns key health metrics:

- `wings`, `rooms`, `drawers`, `wiki_pages` — totals
- `drawers_by_tier` — raw / reviewed / consolidated
- `stale_wiki_pages` — count needing recompile
- `pending_update_pages` — pages with new drawers since last compile
- `last_write` — when something last changed

Wire it into a dashboard or run periodically from any agent.

---

## Production deployment

### Forge / Vapor / VPS

Mnemon is a vanilla Laravel app. Any standard Laravel deploy works. Key considerations:

#### `.env` essentials

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://mnemon.example.com    # MUST match the URL agents use

DB_CONNECTION=pgsql
DB_HOST=...                           # use a managed Postgres if possible
DB_DATABASE=mnemon

OPENAI_API_KEY=sk-...                 # if using OpenAI embedding driver

# OAuth keys — see "Passport key persistence" below
PASSPORT_PRIVATE_KEY=
PASSPORT_PUBLIC_KEY=
```

#### Passport key persistence across deploys

`php artisan passport:install` generates `storage/oauth-private.key` and `storage/oauth-public.key`. **These files MUST persist across deploys.** If they change, every issued token becomes invalid.

Options:

1. **Generate once on first deploy, then commit the keys to a private vault.** Restore via deployment script before `php artisan migrate`.
2. **Store key contents in `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` env vars** (Passport reads from env first, then file). This is the recommended approach for ephemeral filesystems like Forge / Vapor.

To export keys from a working deploy:

```bash
php artisan passport:keys --show         # may not exist; alternative:
cat storage/oauth-private.key | base64
cat storage/oauth-public.key | base64
```

Set the env vars to the contents (one-line, can be plain or base64 — Passport handles both formats since v11).

#### HTTPS and OAuth redirect_uri

OAuth 2.1 requires `https://` for non-localhost redirect URIs. If `APP_URL` is `http://`, the OAuth flow will reject DCR client registrations with `https://` redirects.

Configure your reverse proxy (Forge nginx, Cloudflare, etc.) to:

- Terminate TLS
- Forward `X-Forwarded-Proto: https` to PHP
- Trust the proxy in `config/trustedproxy.php` (or `App\Http\Middleware\TrustProxies`)

If consent flows redirect to `http://` URLs by mistake, your trusted-proxies config is wrong.

#### Migrations on deploy

```bash
php artisan migrate --force            # --force needed in production
```

#### Scheduled tasks

Add to crontab:

```cron
* * * * * cd /path/to/mnemon && php artisan schedule:run >> /dev/null 2>&1
```

Or use Forge's "Scheduled Jobs" UI to register the same command.

#### Worker queues

Mnemon runs synchronous embedding generation by default. If you have a high write volume, switch the embedding driver to async via the queue (see `app/Observers/DrawerObserver.php` for the integration point). Run `php artisan queue:work` (or use Horizon).

### Docker

A reference Dockerfile isn't shipped, but the standard Laravel docker pattern works. Key bind mounts:

- `storage/oauth-*.key` (or set as env vars)
- `storage/app/public` if you have file uploads
- The Postgres data dir (or use a managed Postgres)

---

## Backup and disaster recovery

Mnemon's value compounds over time. Treat it like a primary database — back it up.

### What to back up

1. **The Postgres database**, including the `vector` extension's columns. Standard `pg_dump` handles vector columns natively (they serialize as base64 blobs).
2. **Passport encryption keys** (`storage/oauth-*.key` or the env-var versions). Without them, no OAuth tokens validate.
3. **`.env`** — has DB credentials and `OPENAI_API_KEY`.

### Daily dump

```bash
pg_dump -Fc mnemon > /backups/mnemon-$(date +%Y%m%d).dump
```

`-Fc` is custom format (compressed binary). Restore with `pg_restore`.

Rotate / offsite. For a personal install, a small cron + S3 / B2 / object storage works:

```bash
pg_dump -Fc mnemon | gzip | aws s3 cp - s3://your-bucket/mnemon/$(date +%Y%m%d).dump.gz
```

### Restore on a fresh box

```bash
createdb mnemon
psql mnemon -c "CREATE EXTENSION IF NOT EXISTS vector;"
pg_restore -d mnemon /backups/mnemon-20260427.dump
# Restore Passport keys from your secrets manager
# php artisan migrate           # only if the dump was older than current migrations
```

### Pre-deploy snapshot

Always snapshot before destructive migrations:

```bash
pg_dump -Fc mnemon > /backups/mnemon-pre-deploy-$(date +%Y%m%d-%H%M).dump
```

The drop_api_keys migration in this rework is a good example of a destructive change worth snapshotting.

---

## Troubleshooting

### `401 Unauthorized` on `POST /mcp`

- Bearer token missing → set `Authorization: Bearer <token>` header
- Token expired → refresh via the OAuth refresh endpoint or re-authorize
- Token revoked → check Filament admin under Active Tokens

### `403`-style MCP error: "Missing required scope"

The token doesn't have the scope the tool requires. Check the scope table:

| Scope | Tools |
|---|---|
| `palace.read` | brain_status, palace_wake_up, drawer_search, drawer_get |
| `palace.write` | drawer_add |
| `wiki.read` | wiki_history, wiki_graph, context_get, context_list |
| `wiki.write` | wiki_lint, wiki_compile, context_set |

To fix: re-authorize and grant the missing scope.

### MCP error: "Token does not have access to wing"

The token's wing restrictions exclude the wing you're trying to read or write. Either:

- Re-authorize with broader wing access
- Or have the agent stay within the granted wings

### `419 CSRF mismatch` on `/oauth/authorize`

The consent form's CSRF token expired (sessions are short). Restart the OAuth flow from the `claude mcp add` step. If it persists, check that cookies are being preserved across the redirect.

### Embedding driver fails

Symptom: `drawer_add` errors with timeout / 401 / 429.

- OpenAI driver: verify `OPENAI_API_KEY` is set and not rate-limited. Check API quota.
- Ollama driver: verify `ollama serve` is running and `nomic-embed-text` is pulled.

Workaround during outage: temporarily set `MNEMON_EMBEDDING_DRIVER=none`. New drawers won't get embeddings; semantic search degrades to full-text. Re-enable and `mnemon:reembed` once the driver recovers.

### `pgvector` not installed

```
ERROR:  type "vector" does not exist
```

Install the extension on the DB:

```bash
psql mnemon -c "CREATE EXTENSION IF NOT EXISTS vector;"
```

If you don't have the extension binary, install it via your Postgres distribution's package manager (`postgresql-15-pgvector` on Debian/Ubuntu) or build from source.

### Scheduled tasks not running

```bash
php artisan schedule:list             # shows what should run
php artisan schedule:test             # interactive — runs now
```

If `schedule:run` isn't in cron, none of the scheduled tasks will fire. Add it.

### Filament panel 500s

Common causes:

- `oauth_*` tables not migrated → run `php artisan migrate`
- `mcp_token_restrictions` table missing → run `php artisan migrate`
- Filament asset cache stale → `php artisan filament:cache-components`

Check `storage/logs/laravel.log` for the actual stack trace.

---

## FAQ

**Q: Can I revoke a single agent's access without affecting other agents?**

Yes. Filament admin → Access Control → Active Tokens → revoke the specific token. Other tokens issued to the same user but for different OAuth clients are unaffected.

To revoke an entire client (and all its tokens at once), revoke at the OAuth Clients page.

**Q: Should I run one Mnemon for work and another for personal?**

Probably not. Wing restrictions on tokens give you the isolation you need without running two databases. Run one Mnemon, organize content with `work` and `personal` wings, and grant work-laptop tokens access only to `work`-prefixed wings.

If your organization has compliance requirements that prevent personal data from touching the same DB, run two — but at that point the OAuth boundary is too weak anyway.

**Q: What happens when I force-delete a drawer that wiki pages cite?**

Drawers can be soft-deleted (default) or force-deleted. Wiki pages that cite a force-deleted drawer have a dangling reference in their `sources` JSON. The wiki page itself isn't broken — the citation just points to a non-existent ID.

`wiki_lint` flags drawers with broken citations. `mnemon:auto-lint` will repair the wiki page (drop the broken citation, mark for recompile) on its next run.

Soft delete is preferred for this reason — it's reversible.

**Q: Can I migrate from Mem0 / Memori / another memory system?**

There's no first-class importer. The pattern: export from your old system to JSON or Markdown, then bulk-load via tinker (see [Bulk loading](#bulk-loading)).

If you're coming from MemPalace (the architectural ancestor), Mnemon's drawer schema is a near-direct port — drawer content, source, metadata translate 1:1.

**Q: How do I share Mnemon with a teammate?**

Mnemon is currently single-tenant — any registered user has admin access. To share:

1. Add the teammate's email as a user in Filament (or via `php artisan tinker → User::create([...])`).
2. They log in with the same admin role.
3. They register their own OAuth client(s) for their devices.

True multi-tenancy (per-user wings, per-user tokens scoped to their own data) isn't implemented.

**Q: Can I run Mnemon entirely offline?**

Yes, with `MNEMON_EMBEDDING_DRIVER=ollama` (local Ollama daemon) or `=none` (full-text-only search). No external API calls. The OAuth flow needs a browser but doesn't require external servers.

**Q: Can I use Mnemon as a knowledge base for a static site?**

The wiki layer is rendered markdown. You could pull `WikiPage::all()` and generate a static site from it. There's no built-in static export, but it's a few lines of code.

**Q: How do I see what an agent is doing in real time?**

Two options:

1. **Filament dashboard** — Live MCP Sessions widget shows the 5 most recent tool calls.
2. **Database tail** — `psql mnemon -c "select tool_name, source, created_at from brain_sessions order by id desc limit 20;"` or `\watch 5` to refresh every 5s.

**Q: Is content end-to-end encrypted?**

No. Mnemon stores content in plaintext (with optional column encryption you'd need to add). The DB is encrypted at rest at the disk level (depending on your Postgres setup), and TLS protects in transit. But anyone with DB access reads everything.

`ContentSanitizer` redacts known secret patterns (API keys, tokens, passwords) before storing — this is a *defense in depth* against accidentally storing secrets in drawer content. It doesn't make content private from someone with DB access.

For sensitive personal content, run Mnemon on a server only you control.

**Q: How do I extend Mnemon with a new MCP tool?**

1. Create `app/Mcp/Tools/MyNewTool.php` extending `Laravel\Mcp\Server\Tool`
2. Set `protected string $name = 'my_new_tool';` and `protected string $scope = 'palace.read';` (or wherever it fits)
3. Implement `handle(Request): Response` and `schema(JsonSchema): array`
4. Register in `app/Mcp/Servers/MnemonServer.php::$tools`
5. Write a Feature test in `tests/Feature/Mcp/Tools/MyNewToolTest.php` using the `MakesMcpRequests` trait

See `app/Mcp/Tools/DrawerSearchTool.php` for a complete reference.

---

## Where to next

- [README](../README.md) — project overview
- [`docs/FRD.md`](FRD.md) — functional requirements (the "what" and "why")
- [`docs/IMPLEMENTATION-PLAN.md`](IMPLEMENTATION-PLAN.md) — sprint history (the "how it got built")
- [`docs/superpowers/specs/2026-04-26-mcp-rework-design.md`](superpowers/specs/2026-04-26-mcp-rework-design.md) — full MCP rework design spec
- [Filament documentation](https://filamentphp.com/docs) — for admin panel customization
- [`laravel/mcp` documentation](https://laravel.com/docs/12.x/mcp) — for extending the MCP server
- [`laravel/passport` documentation](https://laravel.com/docs/passport) — for OAuth customization
- [Model Context Protocol spec](https://modelcontextprotocol.io/) — for MCP client and server semantics
