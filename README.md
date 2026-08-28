# Mnemon

A self-hosted second brain for AI-augmented work. Mnemon stores everything you and your agents care about — verbatim — and exposes it back to any AI tool that speaks the [Model Context Protocol](https://modelcontextprotocol.io/). It is one shared memory across Claude, Cursor, ChatGPT, your own scripts, and whatever else you connect.

Built on Laravel 13, PostgreSQL + pgvector, and Filament v5. Deployed at [mnemon.example.com](https://mnemon.example.com).

---

## What this is

Mnemon has two layers:

- **The palace** — verbatim, append-only storage organised as **wings → rooms → drawers**. Everything you put in comes back out exactly as it went in. Nothing is summarised at ingest, nothing is lost. Retrieval is hybrid: pgvector cosine distance + Postgres full-text search + a temporal recency boost, weighted and merged.
- **The wiki** — synthesised, structured pages that distill what's in the palace into knowledge you can read directly. Wiki pages are typed (`person:`, `project:`, `concept:`, `decision:`, `synthesis:`), markdown-rendered, and tracked for staleness. They compound over time.

Agents read and write both layers via 12 MCP tools. Humans manage everything through a Filament admin panel at `/admin`, browse the wiki at `/wiki`, and explore the palace at `/palace`.

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
- **Verbatim retention.** No lossy summarisation at ingest. The drawer you stored is the drawer you retrieve.
- **Hybrid retrieval.** Semantic, keyword, and recency together — so finding "that thing about retries last week" works even when the keyword is misremembered and the meeting note didn't use the same words.
- **Synthesis without losing source.** The wiki is the compiled view; the palace remains the canonical record. Wiki pages can be regenerated from drawers; the reverse is not true.
- **Per-agent authorisation.** OAuth tokens carry the single scope `mcp:use` and optional per-token wing restrictions captured at the consent screen. Wing restrictions are the real isolation mechanism — a project-specific agent sees only its own wing; a read-only agent gets a token restricted to wings that have no write-capable counterpart.
- **Audit trail.** Every MCP tool invocation lands in `brain_sessions`. You can see what each agent has been doing, when, and against which key.
- **Knowledge graph.** Entities and typed relationships extracted from drawers and wiki pages, with graph traversal queries for discovering connections across your knowledge base.
- **Confidence & quality scoring.** Every piece of content carries a confidence score that decays over time, plus a multi-factor quality score. Stale or low-quality content surfaces automatically for review.
- **Self-healing maintenance.** Automated lint, confidence decay, retention management, and stale-page recompilation run on schedule — the system takes care of itself.
- **Owned and self-hosted.** All data lives in your Postgres. No third-party SaaS, no vendor lock-in, no terms of service that change next quarter.

---

## How to use it

### As a human

**Admin panel** at `/admin` — full CRUD for wings, rooms, drawers, wiki pages, OAuth clients, and access tokens. Dashboard with stats, sparklines, and audit log browser.

**Wiki frontend** at `/wiki` — browsable, rendered wiki pages. No login required for reading.

**Palace browser** at `/palace` — explore wings, rooms, and drawers visually.

**Landing page** at `/` — overview and entry point.

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

Mnemon exposes 12 tools over Streamable HTTP at `POST /mcp` (JSON-RPC 2.0). Authenticate with an OAuth 2.1 bearer token issued via Passport. All tools require the `mcp:use` scope; wing restrictions (selected at the consent screen) provide per-agent isolation.

To connect from Claude Code:

```bash
claude mcp add --transport http mnemon https://mnemon.example.com/mcp
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

Three drivers ship out of the box. Configure via `MNEMON_EMBEDDING_DRIVER`:

| Driver | Model | Dimensions | Notes |
|---|---|---|---|
| `openai` | `text-embedding-3-small` | 1536 | Default. Needs `OPENAI_API_KEY`. |
| `ollama` | `nomic-embed-text` | 768 | For local-only setups. Needs an Ollama daemon. |
| `none` | — | — | Disables embeddings; search falls back to full-text + temporal only. |

Switching drivers requires `php artisan mnemon:reembed` to backfill embeddings under the new model.

---

## Stack

- **Framework:** [Laravel 13](https://laravel.com) (PHP 8.4+)
- **Admin UI:** [Filament v5](https://filamentphp.com)
- **Database:** PostgreSQL with the [pgvector](https://github.com/pgvector/pgvector) extension; SQLite is supported as a test backend (vector columns are skipped on SQLite, so semantic mode falls back to full-text)
- **Vector PHP client:** [`pgvector/pgvector`](https://github.com/pgvector/pgvector-php)
- **Tests:** PHPUnit 12, Livewire-style Filament page tests (413 passing)

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

All sprints complete. 413 tests passing. Deployed at [mnemon.example.com](https://mnemon.example.com).

- **Sprint 1 — Foundation.** Wings/Rooms/Drawers/WikiPages/BrainSessions models + migrations, config, seeders.
- **Sprint 2 — Embedding engine.** Driver pattern (OpenAI / Ollama / none), `mnemon:reembed` artisan command, automatic embedding on drawer/wiki create+update.
- **Sprint 3 — Hybrid retrieval.** `PalaceSearchService` (semantic / fulltext / hybrid modes with temporal boost), `WikiSearchService`, wing/room scoping.
- **Sprint 4 — MCP server.** All 12 MCP tools, OAuth 2.1 + Passport with `mcp:use` scope and per-token wing-restriction enforcement, audit logging on every call.
- **Sprint 5 — Filament v5 admin panel.** Six resources, dashboard stats widget, custom palace + wiki Search page.
- **Sprint 6 — OpenClaw integration.** Session ingest, memory-file imports, bidirectional sync, reference client.
- **Tier 1 — Karpathy core.** Structured metadata (confidence, sources, related), source citations with drawer previews, cascade awareness (`drawer_add` flags wiki pages), `wiki_lint`, `wiki_compile`.
- **Tier 2 — Production hardening.** Confidence scoring + decay, supersession / revision history, consolidation tiers (raw → reviewed → consolidated), quality scoring (multi-factor heuristic), self-healing lint (auto-fixer with audit trail), retention management (configurable half-lives), security filtering (ContentSanitizer).
- **Tier 3 — Scale & Advanced.** Knowledge graph (entities, typed relationships, graph traversal via `wiki_graph`), revision history queries via `wiki_history`.
- **Wiki Frontend.** Browsable wiki at `/wiki`, palace browser at `/palace`, landing page at `/`. Public read access, login at `/login` for write operations.

See [`docs/IMPLEMENTATION-PLAN.md`](docs/IMPLEMENTATION-PLAN.md) for the full breakdown of each sprint.

---

## Scheduled tasks

Mnemon runs several automated maintenance tasks to keep the knowledge base healthy:

| Schedule | Command | What it does |
|---|---|---|
| Daily | `mnemon:decay-confidence` | Applies time-based confidence decay to drawers and wiki pages |
| Every 6 hours | `mnemon:auto-lint` | Runs wiki_lint with auto-fix enabled; repairs stale, orphan, and low-confidence pages |
| Every 6 hours | `mnemon:auto-compile-stale` | Gathers drawers and recompiles wiki pages flagged as stale |
| Daily | `mnemon:apply-retention` | Enforces retention policies; archives or removes content past its configured half-life |
| Daily | `mnemon:sync-openclaw` | Syncs memory between Mnemon and OpenClaw |

---

## Limitations

This is a working personal tool, not a finished product. Honest constraints today:

- **Single-tenant.** The Filament panel authenticates any registered user as an admin (`canAccessPanel()` returns `true`). OAuth tokens provide agent-level isolation via the single `mcp:use` scope and per-token wing restrictions.
- **OAuth tokens expire.** Access tokens are valid for 1 hour; refresh tokens for 90 days. Revoke tokens via the Filament panel under OAuth Access Tokens. Compromised tokens are invalidated immediately on revocation.
- **Semantic search needs Postgres + pgvector.** SQLite (the test DB) gracefully falls back to full-text + temporal, but if you run locally on SQLite you get no semantic ranking.
- **Word count is ASCII-only.** `getWordCountAttribute()` uses PHP's `str_word_count`. Multi-byte content under-counts. Documented; will be revisited if it ever matters.
- **Source filter dropdowns are cached for 60s.** Newly added drawer sources or new MCP tool names take up to a minute to appear in the BrainSession/Drawer filter dropdowns.
- **`brain_sessions.source` is non-nullable.** The audit log requires every invocation to identify itself with a key name; anonymous calls are rejected upstream by the auth middleware.
- **No drawer hard delete from the API.** The Filament Drawer resource exposes `forceDelete` and `restore`, but the MCP layer is read+append only — agents can't delete or modify existing drawers, by design.
- **No nested resource routing.** Rooms-under-Wings and Drawers-under-Rooms are flat resources with filters in the panel. True Filament nested URLs (`/admin/wings/{wing}/rooms/{room}`) are deferred.
- **No streaming.** MCP transport is request/response only. Server-sent events or WebSocket streaming is not implemented.

---

## Documentation

- [`docs/USERGUIDE.md`](docs/USERGUIDE.md) — **day-to-day playbook**: setup, connecting agents, OAuth, multi-device, troubleshooting, FAQ
- [`CLAUDE.md`](CLAUDE.md) — agent / contributor conventions (canonical for AI work)
- [`AGENTS.md`](AGENTS.md) — pointer for non-Claude agents
- [`docs/FRD.md`](docs/FRD.md) — functional requirements
- [`docs/IMPLEMENTATION-PLAN.md`](docs/IMPLEMENTATION-PLAN.md) — sprint plan, status, decisions
- [`docs/discovery.md`](docs/discovery.md) — initial discovery + competitive analysis

---

## Common commands

```bash
php artisan test --compact              # run the test suite (413 tests passing)
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
php artisan mnemon:auto-lint            # run wiki_lint with auto-fix
php artisan mnemon:auto-compile-stale   # recompile stale wiki pages
php artisan mnemon:apply-retention      # enforce retention policies
```

---

## License

MIT.
