# Mnemon

A self-hosted second brain for AI-augmented work. Mnemon stores everything you and your agents care about — verbatim — and exposes it back to any AI tool that speaks the [Model Context Protocol](https://modelcontextprotocol.io/). It is one shared memory across Claude, Cursor, ChatGPT, your own scripts, and whatever else you connect.

Built on Laravel 13, PostgreSQL + pgvector, and Filament v5.

---

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

By default the stack binds to loopback only (`127.0.0.1:8080` / `127.0.0.1:8443`),
so a local trial is never exposed to the network. To serve on a real hostname with
automatic HTTPS from Let's Encrypt instead:

1. Point a DNS **A/AAAA record for the hostname at this host before you start the
   stack.** Let's Encrypt validates over HTTP-01, so a name that doesn't resolve
   yet fails issuance and Caddy serves nothing on that hostname.
2. Set `DOMAIN` in `.env` to that hostname.
3. Set `HTTP_BIND=0.0.0.0:80` and `HTTPS_BIND=0.0.0.0:443` in `.env`. These
   default to loopback so a "just trying it" run doesn't serve your knowledge
   base to the internet — leaving them at the defaults while `DOMAIN` is set is
   the single most likely reason certificate issuance fails.

Running behind a TLS-terminating reverse proxy is **not yet supported**: the
app does not process `X-Forwarded-*` headers, so behind such a proxy OAuth
discovery and asset URLs would still be advertised as `http://`, and a browser
blocks the mixed content that results on the consent screen. Use the `DOMAIN`
path above for HTTPS.

See [`docs/USERGUIDE.md`](docs/USERGUIDE.md#native-install-the-alternative-to-docker) for the native
(non-Docker) install, and [`CONTRIBUTING.md`](CONTRIBUTING.md) for running the
test suite.

---

## What this is

Mnemon has two layers:

- **The palace** — verbatim, append-only storage organised as **wings → rooms → drawers**. Nothing is summarised at ingest and nothing is overwritten. Content is stored as written, with one deliberate exception: `ContentSanitizer` redacts API keys, tokens and credentials embedded in URLs before the drawer is saved. Retrieval is hybrid: pgvector cosine distance + Postgres full-text search + a temporal recency boost, weighted and merged.
- **The wiki** — synthesised, structured pages that distill what's in the palace into knowledge you can read directly. Wiki pages are typed (`person:`, `project:`, `concept:`, `decision:`, `synthesis:`), markdown-rendered, and tracked for staleness. They compound over time.

Agents read and write both layers via 14 MCP tools. Humans manage everything through a Filament admin panel at `/admin`, browse the wiki at `/wiki`, and explore the palace at `/palace`.

The name is from [Mnemosyne](https://en.wikipedia.org/wiki/Mnemosyne) — the Greek personification of memory. The wing/room/drawer hierarchy is named after the classical [method of loci](https://en.wikipedia.org/wiki/Method_of_loci) (the original "memory palace").

---

## Why it exists

Every AI tool maintains its own isolated memory. Claude knows things Cursor doesn't. ChatGPT doesn't know what Claude learned last week. Every new agent starts from zero. The result is constant re-teaching, repeated context dumps, and a fragmented picture of you spread across platforms that never talk to each other.

Existing approaches all make tradeoffs:

| Approach | Examples | Tradeoff |
|---|---|---|
| **Extract-and-store** | [Mem0](https://github.com/mem0ai/mem0), Memori | LLM extracts facts at ingest; clean API but lossy. ~49 % on LongMemEval. |
| **Retrieve-raw** | [MemPalace](https://github.com/MemPalace/MemPalace) | Store raw, retrieve hard. 96.6 % R@5 on LongMemEval, zero API calls. CLI/Python only. |
| **Compile-knowledge** | [Karpathy's LLM Wiki](https://x.com/karpathy/status/1893140634685850106) | Synthesise sources into a persistent wiki. Compounds over time. No retrieval engine of its own. |

Mnemon picks **all three**: store raw at the bottom (MemPalace's verbatim insight), put a synthesised wiki on top (Karpathy's idea), and expose both layers over MCP so any agent can use them. Self-hosted in Laravel because data sovereignty matters and Laravel makes it easy to ship.

---

## Problems it solves

- **Cross-tool memory.** One place every agent reads from and writes to. The next session — in a different tool, on a different machine — starts with full context.
- **Verbatim retention.** No lossy summarisation at ingest. The drawer you stored is the drawer you retrieve, minus any credentials `ContentSanitizer` redacted on the way in.
- **Hybrid retrieval.** Semantic, keyword, and recency together — so finding "that thing about retries last week" works even when the keyword is misremembered and the meeting note didn't use the same words.
- **Synthesis without losing source.** The wiki is the compiled view; the palace remains the canonical record. Wiki pages can be regenerated from drawers; the reverse is not true.
- **Per-agent authorisation.** OAuth tokens carry the single scope `mcp:use` and optional per-token wing restrictions captured at the consent screen. Wing restrictions are the real isolation mechanism **for palace content** — a project-specific agent reads only its own wing's drawers; a read-only agent gets a token restricted to wings that have no write-capable counterpart. They do not extend to the wiki layer — see [Limitations](#limitations).
- **Audit trail.** Every MCP tool invocation lands in `brain_sessions`. You can see what each agent has been doing, when, and against which key.
- **Knowledge graph.** Entities and typed relationships extracted from wiki pages — `extractEntity()` takes a `WikiPage`, so drawers are not a source — with graph traversal queries for discovering connections across your knowledge base.
- **Confidence & quality scoring.** Every piece of content carries a confidence score that decays over time, plus a multi-factor quality score. Stale or low-quality content surfaces automatically for review.
- **Scheduled maintenance.** Confidence decay and retention pruning run on schedule and act on the data. The lint and stale-page commands are health checks: they report findings as JSON to the scheduled log and change nothing. Repair is available through the `wiki_lint` MCP tool, which an agent has to call.
- **Owned and self-hosted.** All data lives in your Postgres. No third-party SaaS, no vendor lock-in, no terms of service that change next quarter.

---

## How to use it

### As a human

**Admin panel** at `/admin` — full CRUD for wings, rooms, drawers and wiki pages; OAuth clients and access tokens are view-and-revoke only. Dashboard with stats, sparklines, and audit log browser.

**Wiki frontend** at `/wiki` — browsable, rendered wiki pages. Requires login; every `/wiki` and `/palace` route sits behind `auth` middleware today.

**Palace browser** at `/palace` — explore wings, rooms, and drawers visually.

**Landing page** at `/` — overview and entry point.

Use the [Quickstart](#quickstart) above to get a running instance fastest. The
steps below are the native (non-Docker) install — useful for developing on
Mnemon itself, or if you'd rather manage PHP and Postgres yourself:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed   # creates an admin user
php artisan passport:install # generates OAuth encryption keys + a personal access client
php artisan serve
```

Then visit `http://localhost:8000/admin`. The panel ships:

- **Dashboard** — drawer count, wiki page count, drawers added in the last 7 days (with sparkline), and the timestamp of the latest write.
- **Wings / Rooms / Drawers** — full CRUD plus a Drawer view page with soft-delete, force-delete with confirmation, and restore. Drawers can be filtered by wing, source, date range, or trashed status.
- **Wiki pages** — markdown-rendered view, type badge, type filter, word count, last-compiled-at staleness tracking. Edits only reset staleness when the actual content changes.
- **OAuth clients & tokens** — view registered OAuth clients (via Dynamic Client Registration or manual creation), revoke access tokens, and inspect per-token wing restrictions. Managed in the Filament panel.
- **MCP audit log** — read-only browser for every tool invocation. Filter by tool name, source, or date range. The full input JSON is pretty-printed on the view page.
- **Search** — custom page at `/admin/search` with a live form. Toggle between palace, wiki, or both; optionally narrow by wing. Cross-source results are jointly normalised before sorting so the merged ranking is meaningful.

### As an AI agent (MCP)

Mnemon exposes 14 tools over Streamable HTTP at `POST /mcp` (JSON-RPC 2.0). Authenticate with an OAuth 2.1 bearer token issued via Passport. All tools require the `mcp:use` scope; wing restrictions (selected at the consent screen) provide per-agent isolation.

To connect from Claude Code:

```bash
claude mcp add --transport http mnemon https://your-mnemon-host/mcp
# Complete the browser OAuth flow — log in, grant scopes, select wing restrictions
```

All tools require scope `mcp:use`. Wing restrictions on the token provide per-agent isolation.

| Tool | What it does |
|---|---|
| `brain_status` | Drawer/wiki counts, wings, embedding driver, staleness summary |
| `palace_wake_up` | Recent drawers, wing activity, stale wiki pages |
| `drawer_add` | Add a drawer (auto-creates wing/room if missing, embeds content, flags related wiki pages for recompilation) |
| `drawer_search` | Hybrid search; supports `wing`, `room`, `mode`, `limit` |
| `drawer_get` | Fetch a single drawer by id |
| `context_get` | Read a wiki page by name (includes structured metadata, confidence, sources) |
| `context_set` | Upsert a wiki page (auto-updates index/log; stamps `last_compiled_at`) |
| `context_list` | List wiki pages, optionally filtered by type |
| `wiki_lint` | Detect stale, orphan, empty, and low-confidence wiki pages; auto-fix mode with audit trail |
| `wiki_compile` | Gather related drawers for wiki page compilation; supports consolidation tiers |
| `wiki_graph` | Query the knowledge graph — entities, typed relationships, graph traversal |
| `wiki_history` | Supersession and revision history for wiki pages; track how knowledge evolved |
| `recall` | Hybrid recall of wiki excerpts and drawer snippets for a prompt, packed into a token budget; powers the Claude Code `mnemon-recall.sh` hook |
| `session_digest` | Digest a sanitized transcript slice into drawer proposals; persists high-confidence ones, queues new-wing proposals for review; powers the Claude Code `mnemon-capture.sh` hook |

Wing restrictions on a token short-circuit before the tool even runs — a token restricted to `project:atlas` can never see a drawer in `personal`.

---

## Layer 2 — automatic memory in Claude Code

The MCP server exposes Mnemon to any agent that asks. **Layer 2** is automatic capture and recall for Claude Code: every prompt is silently primed with relevant palace context, and every session quietly digests to drawers in the background.

Install with:

```bash
php artisan mnemon:install-claude-code-hooks
```

This adds three hooks to `~/.claude/hooks/`:

- `mnemon-wake.sh` (SessionStart) — injects recent palace state at session start.
- `mnemon-recall.sh` (UserPromptSubmit) — injects relevant wiki + drawer context per prompt, gated to skip chitchat.
- `mnemon-capture.sh` (Stop) — digests the session transcript to drawer proposals; high-confidence ones auto-persist; new wings queue for admin review.

See [`docs/USERGUIDE.md`](docs/USERGUIDE.md#automatic-memory-in-claude-code) for the full walkthrough.

---

### OpenClaw integration

Mnemon integrates with [OpenClaw](https://openclaw.com) for automatic memory synchronisation:

- `mnemon:import-memory` — imports OpenClaw memory files into the palace
- `mnemon:ingest-sessions` — ingests OpenClaw session transcripts as drawers
- `mnemon:sync-openclaw` — bidirectional sync between Mnemon and OpenClaw memory

OpenClaw's `memory_search` can route queries to Mnemon alongside local files, giving agents a unified view across both systems.

### As an embedding backend

Configure via `MNEMON_EMBEDDING_DRIVER`:

| Driver | Model | Dimensions | Notes |
|---|---|---|---|
| `openai` | `text-embedding-3-small` | 1536 | The application default (`config/mnemon.php`). Needs `OPENAI_API_KEY`. The Docker Quickstart overrides this to `none` in `.env.docker.example`, so trying Mnemon needs no account and spends nothing. |
| `none` | — | — | Disables embeddings; search falls back to full-text + temporal only. |

A third driver, `ollama` (`nomic-embed-text`, 768 dimensions), is implemented
but currently unusable: the `drawers` and `wiki_pages` tables define
`embedding` as a fixed `vector(1536)` column, so a 768-dimension vector fails
to write. Selecting `ollama` will error the first time anything tries to
store an embedding. This is tracked as defect D10 and is not fixed in this
release — if you need fully local embeddings, `none` (no semantic ranking,
full-text + temporal only) is the working option today.

Switching drivers requires `php artisan mnemon:reembed` to backfill embeddings under the new model.

---

## Stack

- **Framework:** [Laravel 13](https://laravel.com) (PHP 8.4+)
- **Admin UI:** [Filament v5](https://filamentphp.com)
- **Database:** PostgreSQL with the [pgvector](https://github.com/pgvector/pgvector) extension; SQLite is supported as a test backend (vector columns are skipped on SQLite, so semantic mode falls back to full-text)
- **Vector PHP client:** [`pgvector/pgvector`](https://github.com/pgvector/pgvector-php)
- **Tests:** PHPUnit 12, Livewire-style Filament page tests (430 passed / 1 skipped on SQLite, 431 passed on PostgreSQL)

The MCP server is built on [`laravel/mcp`](https://github.com/laravel/mcp) and [`laravel/passport`](https://laravel.com/docs/passport) — Streamable HTTP + OAuth 2.1 + Dynamic Client Registration + tool/resource/prompt dispatch + audit logging.

---

## Inspiration

Mnemon is a synthesis of three quite different projects, plus the protocol that ties them all together:

- **[MemPalace](https://github.com/MemPalace/MemPalace)** — the architectural ancestor of the palace layer. The "store raw, retrieve hard" insight, the hybrid (semantic + BM25 + temporal) retrieval recipe, and the LongMemEval benchmark numbers that make the case for verbatim storage. Mnemon's `PalaceSearchService` is a Laravel-native rewrite of this approach against pgvector + Postgres `to_tsvector`.
- **[Karpathy's LLM Wiki](https://x.com/karpathy/status/1893140634685850106)** — the wiki compilation layer. The idea that LLMs should be writing into a structured, interlinked wiki (not just into vector stores) is what makes the wiki/palace split natural. Wiki pages compound; chat history evaporates.
- **[Anthropic's Model Context Protocol](https://modelcontextprotocol.io/)** — the access layer. Every tool Mnemon exposes is an MCP tool, so any MCP-aware client (Claude Desktop, Claude Code, Cursor, custom agents built on Anthropic's SDK) connects without bespoke integration.
- **[Mem0](https://github.com/mem0ai/mem0)** and **Memori** — the extract-and-store competitors. Mnemon deliberately rejects this approach (extraction loses fidelity), but they are the reason there's a clear opinion about NOT doing it.
- **[OpenClaw](https://openclaw.com)** — the personal-agent runtime that Mnemon integrates with for session ingest, memory-file imports, and bidirectional memory sync.

The classical **method of loci** is the naming convention. A wing is a section of a memory palace; a room is a place within it; a drawer is a single thing you remember. Slugs and human-readable identifiers everywhere — the wing called `project:atlas` is searchable, scopable, and human-meaningful.

---

## What's built

All sprints complete. 440 passing, 7 skipped on SQLite; CI runs the same suite against PostgreSQL 17.

- **Sprint 1 — Foundation.** Wings/Rooms/Drawers/WikiPages/BrainSessions models + migrations, config, seeders.
- **Sprint 2 — Embedding engine.** Driver pattern (OpenAI / Ollama / none), `mnemon:reembed` artisan command, automatic embedding on drawer/wiki create+update.
- **Sprint 3 — Hybrid retrieval.** `PalaceSearchService` (semantic / fulltext / hybrid modes with temporal boost), `WikiSearchService`, wing/room scoping.
- **Sprint 4 — MCP server.** All 14 MCP tools, OAuth 2.1 + Passport with `mcp:use` scope and per-token wing-restriction enforcement, audit logging on every call.
- **Sprint 5 — Filament v5 admin panel.** Six resources, dashboard stats widget, custom palace + wiki Search page.
- **Sprint 6 — OpenClaw integration.** Session ingest, memory-file imports, bidirectional sync, reference client.
- **Tier 1 — Karpathy core.** Structured metadata (confidence, sources, related), source citations with drawer previews, cascade awareness (`drawer_add` flags wiki pages), `wiki_lint`, `wiki_compile`.
- **Tier 2 — Production hardening.** Confidence scoring + decay, supersession / revision history, consolidation tiers (raw → reviewed → consolidated), quality scoring (multi-factor heuristic), lint auto-fixer with audit trail, reachable through the `wiki_lint` MCP tool, retention management (configurable half-lives), security filtering (ContentSanitizer).
- **Tier 3 — Scale & Advanced.** Knowledge graph (entities, typed relationships, graph traversal via `wiki_graph`), revision history queries via `wiki_history`.
- **Wiki Frontend.** Browsable wiki at `/wiki`, palace browser at `/palace`, landing page at `/`. Every `/wiki` and `/palace` route requires login; only the landing page at `/` is public.

See [`docs/IMPLEMENTATION-PLAN.md`](docs/IMPLEMENTATION-PLAN.md) for the full breakdown of each sprint.

---

## Scheduled tasks

Mnemon runs several automated maintenance tasks to keep the knowledge base healthy:

| Schedule | Command | What it does |
|---|---|---|
| Daily, 03:00 | `mnemon:decay-confidence` | Applies time-based confidence decay to drawers and wiki pages |
| Every 6 hours | `mnemon:auto-lint` | Health check. Reports stale, orphan, empty and low-confidence pages as JSON; repairs nothing |
| Every 4 hours | `mnemon:auto-compile-stale` | Reports pages with pending drawers as JSON; recompiles nothing |
| Weekly, Sunday 04:00 | `mnemon:apply-retention --force` | Enforces retention policies; archives or removes content past its configured half-life |

Times are in `APP_TIMEZONE` (default `UTC`). `mnemon:sync-openclaw` is a manual
convenience wrapper, not a scheduled task — run it yourself when you want it.

---

## Limitations

This is a working personal tool, not a finished product. Honest constraints today:

- **Wing restrictions do not cover the wiki.** `wiki_pages` has no wing column and `WikiPage` has no wing relation, so wiki content has no wing dimension to filter on. Any token carrying `mcp:use` can read any wiki page, whatever its restrictions, through five channels: `context_get` (full page content), `context_list` (enumerates every page), `palace_wake_up` and `brain_status` (leak page names such as `person:jane-doe`), and `recall` — whose wiki leg receives no wing patterns at all, and which runs automatically on every prompt, so the exposure does not require an agent to ask for it. Wiki pages are compiled palace content, so a token restricted to `work` can read a synthesis of any `personal` drawer that has been compiled. **Do not compile anything into the wiki that a restricted agent must not read.** Closing this needs a `wing_id` on `wiki_pages` plus a compile-time association; documented as a known limitation for this release, not fixed.
- **Single-tenant.** The Filament panel authenticates any registered user as an admin (`canAccessPanel()` returns `true`). OAuth tokens provide agent-level isolation via the single `mcp:use` scope and per-token wing restrictions.
- **OAuth tokens expire.** Access tokens are valid for 1 hour; refresh tokens for 90 days. Revoke tokens via the Filament panel under OAuth Access Tokens. Compromised tokens are invalidated immediately on revocation.
- **Semantic search needs Postgres + pgvector.** SQLite (the test DB) gracefully falls back to full-text + temporal, but if you run locally on SQLite you get no semantic ranking.
- **The `ollama` embedding driver can't store an embedding.** The `embedding` column is a fixed `vector(1536)`, and `nomic-embed-text` produces 768-dimension vectors — writes fail. Tracked as D10, not fixed in this release. See [As an embedding backend](#as-an-embedding-backend).
- **Word count is ASCII-only.** `getWordCountAttribute()` uses PHP's `str_word_count`. Multi-byte content under-counts. Documented; will be revisited if it ever matters.
- **Source filter dropdowns are cached for 60s.** Newly added drawer sources or new MCP tool names take up to a minute to appear in the BrainSession/Drawer filter dropdowns.
- **`brain_sessions.source` is non-nullable.** The audit log requires every invocation to identify itself with a key name; anonymous calls are rejected upstream by the auth middleware.
- **No drawer hard delete from the API.** The Filament Drawer resource exposes `forceDelete` and `restore`. The MCP layer cannot delete a drawer or rewrite its content, but it is not purely read+append: `context_set` promotes its source drawers to the `consolidated` tier (`ContextSetTool.php:184`).
- **No nested resource routing.** Rooms-under-Wings and Drawers-under-Rooms are flat resources with filters in the panel. True Filament nested URLs (`/admin/wings/{wing}/rooms/{room}`) are deferred.
- **No streaming.** MCP transport is request/response only. Server-sent events or WebSocket streaming is not implemented.

---

## Documentation

- [`docs/USERGUIDE.md`](docs/USERGUIDE.md) — **day-to-day playbook**: setup, connecting agents, OAuth, multi-device, troubleshooting, FAQ
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — running the test suite (SQLite and Postgres), formatting, the Docker smoke test
- [`CLAUDE.md`](CLAUDE.md) — agent / contributor conventions (canonical for AI work)
- [`AGENTS.md`](AGENTS.md) — pointer for non-Claude agents
- [`docs/FRD.md`](docs/FRD.md) — functional requirements
- [`docs/IMPLEMENTATION-PLAN.md`](docs/IMPLEMENTATION-PLAN.md) — sprint plan, status, decisions
- [`docs/discovery.md`](docs/discovery.md) — initial discovery + competitive analysis

---

## Common commands

```bash
php artisan test --compact              # run the test suite (430 passed / 1 skipped on SQLite)
./vendor/bin/pint                       # format PHP
php artisan migrate:fresh --seed        # rebuild the DB from scratch
php artisan mnemon:reembed              # re-embed all drawers + wiki pages with the current driver
php artisan passport:client             # register an OAuth client from the CLI
php artisan serve                       # http://localhost:8000  (admin: /admin, wiki: /wiki, palace: /palace)

# Import & sync
php artisan mnemon:import-memory        # import OpenClaw memory files into the palace
php artisan mnemon:ingest-sessions      # ingest OpenClaw session transcripts as drawers
php artisan mnemon:sync-openclaw        # bidirectional sync with OpenClaw

# Maintenance (also run on schedule)
php artisan mnemon:decay-confidence     # apply time-based confidence decay
php artisan mnemon:auto-lint            # report lint findings as JSON
php artisan mnemon:auto-compile-stale   # report pages with pending drawers as JSON
php artisan mnemon:apply-retention      # enforce retention policies
```

---

## License

MIT — see [LICENSE](LICENSE).
