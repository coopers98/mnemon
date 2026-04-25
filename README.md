# Mnemon

A self-hosted second brain for AI-augmented work. Mnemon stores everything you and your agents care about — verbatim — and exposes it back to any AI tool that speaks the [Model Context Protocol](https://modelcontextprotocol.io/). It is one shared memory across Claude, Cursor, ChatGPT, your own scripts, and whatever else you connect.

Built on Laravel 13, PostgreSQL + pgvector, and Filament v5.

---

## What this is

Mnemon has two layers:

- **The palace** — verbatim, append-only storage organised as **wings → rooms → drawers**. Everything you put in comes back out exactly as it went in. Nothing is summarised at ingest, nothing is lost. Retrieval is hybrid: pgvector cosine distance + Postgres full-text search + a temporal recency boost, weighted and merged.
- **The wiki** — synthesised, structured pages that distill what's in the palace into knowledge you can read directly. Wiki pages are typed (`person:`, `project:`, `concept:`, `decision:`, `synthesis:`), markdown-rendered, and tracked for staleness. They compound over time.

Agents read and write both layers via a small MCP toolset. Humans manage everything through a Filament admin panel at `/admin`.

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
- **Per-agent authorisation.** API keys carry scopes (`palace:read`, `wiki:write`, `*`, etc.) and optional wing restrictions (`work`, `project:*`). A scratch agent can read but not write; a project-specific agent can only see its own wing.
- **Audit trail.** Every MCP tool invocation lands in `brain_sessions`. You can see what each agent has been doing, when, and against which key.
- **Owned and self-hosted.** All data lives in your Postgres. No third-party SaaS, no vendor lock-in, no terms of service that change next quarter.

---

## How to use it

### As a human (admin panel)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed   # creates an admin user + a `*` admin API key (printed once)
php artisan serve
```

Then visit `http://localhost:8000/admin`. The panel ships:

- **Dashboard** — drawer count, wiki page count, drawers added in the last 7 days (with sparkline), and the timestamp of the latest write.
- **Wings / Rooms / Drawers** — full CRUD plus a Drawer view page with soft-delete, force-delete with confirmation, and restore. Drawers can be filtered by wing, source, date range, or trashed status.
- **Wiki pages** — markdown-rendered view, type badge, type filter, word count, last-compiled-at staleness tracking. Edits only reset staleness when the actual content changes.
- **API keys** — create, revoke (soft), per-key scopes and wing restrictions. The plaintext key is shown **once** at creation in a persistent notification with a copy button. Lose it and you mint a new one.
- **MCP audit log** — read-only browser for every tool invocation. Filter by tool name, source key, or date range. The full input JSON is pretty-printed on the view page.
- **Search** — custom page at `/admin/search` with a live form. Toggle between palace, wiki, or both; optionally narrow by wing. Cross-source results are jointly normalised before sorting so the merged ranking is meaningful.

### As an AI agent (MCP)

Mnemon exposes seven Phase 1 tools over a JSON-RPC-ish HTTP transport at `/api/mcp/call`. Authenticate with a bearer API key. Each tool is gated by scope.

| Tool | Scope | What it does |
|---|---|---|
| `brain_status` | `palace:read` | Drawer/wiki counts, wings, embedding driver, staleness summary |
| `palace_wake_up` | `palace:read` | Recent drawers, wing activity, stale wiki pages |
| `drawer_add` | `palace:write` | Add a drawer (auto-creates the wing/room if missing, embeds content) |
| `drawer_search` | `palace:read` | Hybrid search; supports `wing`, `room`, `mode`, `limit` |
| `drawer_get` | `palace:read` | Fetch a single drawer by id |
| `context_get` | `wiki:read` | Read a wiki page by name |
| `context_set` | `wiki:write` | Upsert a wiki page (auto-updates the index/log; stamps `last_compiled_at`) |
| `context_list` | `wiki:read` | List wiki pages, optionally filtered by type |

Wing restrictions on a key short-circuit before the tool even runs — a key restricted to `project:atlas` can never see a drawer in `personal`.

A small reference client and example agent integrations land alongside Sprint 6.

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

- **Framework:** [Laravel 13](https://laravel.com) (PHP 8.3+)
- **Admin UI:** [Filament v5](https://filamentphp.com)
- **Database:** PostgreSQL with the [pgvector](https://github.com/pgvector/pgvector) extension; SQLite is supported as a test backend (vector columns are skipped on SQLite, so semantic mode falls back to full-text)
- **Vector PHP client:** [`pgvector/pgvector`](https://github.com/pgvector/pgvector-php)
- **Tests:** PHPUnit 12, Livewire-style Filament page tests

The MCP server is a small in-house implementation in `app/Mcp/` and `app/Http/Controllers/McpController.php`. It is intentionally minimal — Phase 1 only needs HTTP + bearer auth + tool dispatch + audit logging.

---

## Inspiration

Mnemon is a synthesis of three quite different projects, plus the protocol that ties them all together:

- **[MemPalace](https://github.com/MemPalace/MemPalace)** — the architectural ancestor of the palace layer. The "store raw, retrieve hard" insight, the hybrid (semantic + BM25 + temporal) retrieval recipe, and the LongMemEval benchmark numbers that make the case for verbatim storage. Mnemon's `PalaceSearchService` is a Laravel-native rewrite of this approach against pgvector + Postgres `to_tsvector`.
- **[Karpathy's LLM Wiki](https://x.com/karpathy/status/1893140634685850106)** — the wiki compilation layer. The idea that LLMs should be writing into a structured, interlinked wiki (not just into vector stores) is what makes the wiki/palace split natural. Wiki pages compound; chat history evaporates.
- **[Anthropic's Model Context Protocol](https://modelcontextprotocol.io/)** — the access layer. Every tool Mnemon exposes is an MCP tool, so any MCP-aware client (Claude Desktop, Claude Code, Cursor, custom agents built on Anthropic's SDK) connects without bespoke integration.
- **[Mem0](https://github.com/mem0ai/mem0)** and **Memori** — the extract-and-store competitors. Mnemon deliberately rejects this approach (extraction loses fidelity), but they are the reason there's a clear opinion about NOT doing it.
- **OpenClaw** — the personal-agent runtime that Sprint 6 will integrate with for session ingest and memory-file imports. Mnemon is built so OpenClaw can route its `memory_search` to Mnemon alongside local files.

The classical **method of loci** is the naming convention. A wing is a section of a memory palace; a room is a place within it; a drawer is a single thing you remember. Slugs and human-readable identifiers everywhere — the wing called `project:atlas` is searchable, scopable, and human-meaningful.

---

## What ships today

Sprints 1–5 are complete on `main` (≈225 tests passing).

- **Sprint 1 — Foundation.** Wings/Rooms/Drawers/WikiPages/BrainSessions/ApiKeys models + migrations, config, seeders.
- **Sprint 2 — Embedding engine.** Driver pattern (OpenAI / Ollama / none), `mnemon:reembed` artisan command, automatic embedding on drawer/wiki create+update.
- **Sprint 3 — Hybrid retrieval.** `PalaceSearchService` (semantic / fulltext / hybrid modes with temporal boost), `WikiSearchService`, wing/room scoping.
- **Sprint 4 — MCP server.** All seven Phase 1 tools, API key middleware with scope and wing-restriction enforcement, audit logging on every call.
- **Sprint 5 — Filament v5 admin panel.** Six resources, dashboard stats widget, custom palace + wiki Search page. See [`docs/IMPLEMENTATION-PLAN.md`](docs/IMPLEMENTATION-PLAN.md#sprint-5-filament-dashboard) for the full breakdown.

---

## Limitations

This is a working personal tool, not a finished product. Honest constraints today:

- **Phase 1 toolset only.** Seven MCP tools cover read/write of drawers and wiki pages. There is no automatic synthesis (drawers → wiki page), no scheduled compaction, no cross-page link resolution, and no streaming.
- **No OpenClaw / no automatic ingest yet.** Sprint 6 will add `mnemon:ingest-sessions` and `mnemon:import-memory`. Until then, agents add drawers explicitly via `drawer_add`, or you paste content through the admin panel.
- **Not deployed.** Sprint 7 (Forge setup, domain, SSL) is open. The README's quick-start gets you running locally; production deploy is your problem for now.
- **Single-tenant.** The Filament panel authenticates any registered user as an admin (`canAccessPanel()` returns `true`). There are no per-user scopes inside the panel — API keys provide the agent-level isolation, not user-level.
- **API key plaintext is shown exactly once.** No recovery, no email-the-secret. If you lose it, revoke and regenerate.
- **Semantic search needs Postgres + pgvector.** SQLite (the test DB) gracefully falls back to full-text + temporal, but if you run locally on SQLite you get no semantic ranking.
- **Word count is ASCII-only.** `getWordCountAttribute()` uses PHP's `str_word_count`. Multi-byte content under-counts. Documented; will be revisited if it ever matters.
- **Source filter dropdowns are cached for 60s.** Newly added drawer sources or new MCP tool names take up to a minute to appear in the BrainSession/Drawer filter dropdowns.
- **`brain_sessions.source` is non-nullable.** The audit log requires every invocation to identify itself with a key name; anonymous calls are rejected upstream by the auth middleware.
- **No drawer hard delete from the API.** The Filament Drawer resource exposes `forceDelete` and `restore`, but the MCP layer is read+append only — agents can't delete or modify existing drawers, by design.
- **No nested resource routing.** Rooms-under-Wings and Drawers-under-Rooms are flat resources with filters in the panel. True Filament nested URLs (`/admin/wings/{wing}/rooms/{room}`) are deferred.

---

## Documentation

- [`CLAUDE.md`](CLAUDE.md) — agent / contributor conventions (canonical for AI work)
- [`AGENTS.md`](AGENTS.md) — pointer for non-Claude agents
- [`docs/FRD.md`](docs/FRD.md) — functional requirements
- [`docs/IMPLEMENTATION-PLAN.md`](docs/IMPLEMENTATION-PLAN.md) — sprint plan, status, decisions
- [`docs/discovery.md`](docs/discovery.md) — initial discovery + competitive analysis

---

## Common commands

```bash
php artisan test --compact            # run the test suite (target: ~225 tests passing)
./vendor/bin/pint                     # format PHP
php artisan migrate:fresh --seed      # rebuild the DB from scratch
php artisan mnemon:reembed            # re-embed all drawers + wiki pages with the current driver
php artisan mnemon:create-key NAME    # mint an API key from the CLI
php artisan serve                     # http://localhost:8000  (admin: /admin)
```

---

## License

MIT.
