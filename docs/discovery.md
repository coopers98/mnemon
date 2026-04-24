# Mnemon — Project Discovery Notes
*Last updated: April 2026*

---

## The Problem

Every AI tool maintains its own isolated memory. Claude knows things Cursor doesn't.
ChatGPT doesn't know what Claude learned last week. Every new agent starts from zero.
The result is constant re-teaching, repeated context dumps, and a fragmented picture
of you spread across platforms that never talk to each other.

Existing solutions fall into three camps — all with meaningful tradeoffs:

**Extract-and-store** (Mem0, Memori): LLMs extract facts from conversations and store
them as structured records. Clean API, but the extraction step loses fidelity. Benchmark
scores (~49% on LongMemEval) reflect this loss. You get searchable summaries, not truth.

**Retrieve-raw** (MemPalace): Store conversations verbatim, get really good at finding
them. No extraction loss. MemPalace hits 96.6% R@5 on LongMemEval with zero API calls
by storing raw text and applying hybrid retrieval (vector + BM25 + temporal proximity).
Best-in-class benchmarks. But it's Python, CLI-focused, and has no direct UI.

**Compile-knowledge** (Karpathy's LLM Wiki): LLMs synthesize sources into a persistent,
interlinked wiki. Not retrieval — compilation. The knowledge is pre-processed, cross-
referenced, and ready to use. The wiki compounds over time. Nothing disappears into
chat history.

None of these are Laravel. None are self-hosted with a proper UI. None combine
verbatim retrieval with wiki-layer synthesis.

---

## The Solution

Mnemon is a self-hosted, Laravel-native second brain: verbatim storage and best-in-class
retrieval at the base (MemPalace approach), with a Karpathy-style wiki compilation layer
on top, exposed via MCP, and managed through a Filament dashboard.

**The key insight borrowed from MemPalace:** store raw, don't summarize at ingest. The
retrieval layer does the work. Extraction-based systems lose signal; verbatim systems
preserve it.

**The key insight borrowed from Karpathy:** retrieval is not enough. Some knowledge is
worth compiling into persistent, interlinked pages — not re-derived from raw storage
on every query. The wiki layer is optional, human-directed, and sits on top of the raw
palace without replacing it.

**What makes Mnemon different:**
- Laravel-native — `composer require`, no Python sidecar, no Node process
- Filament dashboard — browse, search, manage from a browser; no CLI required
- Dual-layer — raw verbatim palace + optional compiled wiki
- Driver-based embeddings — swap OpenAI for Ollama without touching code
- OpenClaw integration — ingest directly from our own session history (dogfood)
- MCP-first — the primary agent interface, with Laravel's native MCP server support

Named after Mnemon (NEE-mon) — Greek for "one who remembers."

---

## Architecture

### The Palace (Layer 1 — Verbatim Storage)

Borrowed from MemPalace. Conversations and content stored verbatim, not summarized.
Structure is hierarchical:

```
Palace
├── Wing (top-level context: a project, a person, a topic area)
│   ├── Room (sub-context: a sprint, a relationship thread, a research area)
│   │   └── Drawer (a verbatim content block with metadata)
│   └── Room ...
└── Wing ...
```

Retrieval is hybrid: pgvector semantic search + full-text (Postgres tsvector) +
temporal proximity boost. This combination is what drives MemPalace's benchmark numbers.

**Why verbatim:** extraction loses signal. "We decided to use UUIDs for the assets
table" in a summary might become "UUID usage discussed" — which won't match "what did
we decide about asset IDs?" Verbatim storage preserves the original language.

### The Wiki (Layer 2 — Compiled Knowledge)

Borrowed from Karpathy. LLM-maintained markdown files that synthesize palace content
into persistent, interlinked knowledge. The LLM writes and maintains the wiki; you
read it and direct the work.

Wiki pages are not automatically generated — they're human-directed. You ask me to
compile knowledge about a topic; I read relevant drawers and write/update the page.
This is not a background job. It's a workflow.

```
wiki/
├── index.md          — catalog of all pages with one-line summaries
├── log.md            — append-only record of ingest/compile/lint operations
├── entities/
│   ├── people/
│   └── projects/
├── concepts/
├── decisions/
└── syntheses/
```

The wiki lives in the `wiki/` directory and is stored in the same Postgres DB as
the palace (as `wiki_pages`). It's browsable via Filament and searchable via MCP.

### Embedding Drivers

Driver pattern mirrors Laravel's cache/mail/queue.

| Driver | Model | Notes |
|---|---|---|
| openai | text-embedding-3-small | Recommended default |
| openai | text-embedding-3-large | Higher quality |
| ollama | nomic-embed-text | Self-hosted, fully local |
| none | — | Falls back to full-text only |

### MCP Tool Surface

Primary agent interface. Served via `php artisan mcp:serve`.

**Phase 1 tools (MVP):**
- `brain_status` — memory count, wing list, embedding driver, last write
- `palace_wake_up` — orientation tool: recent activity, current wings, pending wiki items
- `drawer_add` — store verbatim content in a wing/room
- `drawer_search` — hybrid search (semantic + full-text + temporal)
- `context_get` — fetch a named wiki page or context block by name
- `context_set` — write or overwrite a named wiki page
- `context_list` — list all wiki pages with last-updated timestamps

**Phase 2 additions:**
- `drawer_get` — fetch specific drawer by ID
- `wing_list`, `room_list` — navigate the palace structure
- `wiki_ingest` — trigger LLM wiki compilation for a topic
- `wiki_lint` — flag contradictions, stale claims, orphan pages
- `knowledge_graph_add/query/invalidate` — temporal entity-relationship graph

### Database

Postgres primary. pgvector extension for vector similarity.

**Core tables:**
```
wings            — top-level contexts (id, name, description, slug)
rooms            — sub-contexts within wings (id, wing_id, name, slug)
drawers          — verbatim content blocks (id, room_id, content, embedding,
                   source, metadata jsonb, created_at)
wiki_pages       — compiled knowledge (id, path, title, content, embedding,
                   last_compiled_at)
brain_sessions   — MCP audit log (id, tool_name, source, input jsonb,
                   result_count, created_at)
```

---

## Competitive Landscape (Updated April 2026)

| | Mnemon | MemPalace | Mem0 | Cloudflare |
|---|---|---|---|---|
| Stack | Laravel/PHP | Python | Python | Managed |
| Self-hosted | ✅ | ✅ | ✅ | ❌ |
| MCP native | ✅ | ✅ (29 tools) | ✅ | ❌ |
| Storage model | Verbatim | Verbatim | Extract | Extract |
| Wiki layer | ✅ | ❌ | ❌ | ❌ |
| Direct UI | ✅ Filament | ❌ CLI only | Dashboard | ❌ |
| Temporal graph | Phase 2 | ✅ | ❌ | ✅ |
| Composer package | Phase 3 | ❌ | ❌ | ❌ |
| Benchmark (LongMemEval) | TBD | 96.6% R@5 | 49.0% | — |

**Key competitors to watch:**
- **MemPalace** — closest architecture. MIT license. We borrow their retrieval approach
  and improve with Filament UI + wiki layer + Laravel stack.
- **Mem0** (~48K stars, $24M) — extract-based, benchmark weaker, but best community.
  Not a direct threat in the Laravel space.
- **Cloudflare Agent Memory** (private beta, April 2026) — managed service for Cloudflare
  Workers agents. No self-hosted option. Different audience.
- **Recallium** — developer-focused MCP memory. Node/TypeScript. Typed memories.
  Project-scoped. Most similar developer positioning but wrong stack.

---

## Dogfooding Plan

Mnemon will serve as a secondary memory provider for OpenClaw (alongside the current
file-based `memory/` system). This means:

1. All conversations ingested into the palace over time
2. Wiki pages compiled for Cooper's profile, active projects, key decisions
3. `memory_search` tool in OpenClaw routing queries to Mnemon MCP as well as local files
4. This is both validation and the best possible signal about what actually needs building

The file-based memory system doesn't go away — it's the primary source. Mnemon
supplements it and we discover through use what retrieval quality really looks like
against our own data.

---

## What This Is Not

- Not a notes app
- Not a SaaS product (v1)
- Not multi-tenant (v1)
- Not a replacement for project-specific vector stores in production RAG pipelines
- Not a port of MemPalace — we borrow their retrieval insights but build our own thing
- Not trying to win benchmarks on day one — we're building for our own use first

---

## Open Questions

- Wing structure: auto-inferred from source, or manually assigned by the user/agent?
- Wiki compilation: manual trigger only, or can agents trigger it autonomously?
- Conflict between wiki pages and palace content: how does the agent know which to trust?
- Repo name: `mnemon` standalone or `laravel-mnemon` to signal ecosystem intent?

---

## Stack Summary

| Layer | Technology |
|---|---|
| Backend | Laravel (PHP) |
| Database | Postgres + pgvector |
| Vector Search | pgvector + Postgres full-text (hybrid) |
| Embeddings | OpenAI, Ollama, or none (driver-based) |
| MCP Transport | Laravel native MCP server |
| Admin UI | Filament |
| Frontend (Phase 3) | Vue PWA (for non-Filament consumer UI) |
