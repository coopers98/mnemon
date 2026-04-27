# Functional Requirements Document — Mnemon
**Version:** 1.0
**Last Updated:** April 2026
**Stack:** Laravel, Postgres + pgvector, Filament, Laravel MCP Server
**Repo:** coopers98/mnemon
**Server:** 198.51.100.10 (Forge, shared)

---

## 1. Overview

Mnemon is a self-hosted, Laravel-native second brain with two layers:

1. **The Palace** — verbatim storage with hybrid retrieval. Content goes in raw. The retrieval layer (vector + full-text + temporal proximity) does the work.
2. **The Wiki** — LLM-compiled, human-directed knowledge pages. Persistent synthesis on top of the palace. Not automatic — invoked explicitly.

Primary interface: MCP server (`php artisan mcp:serve`).
Management interface: Filament admin dashboard.
Primary consumer: OpenClaw (dogfood), then Laravel developers via Composer package.

**Scope of this document:** Phase 1 (MVP) only. Phase 2 and 3 features are noted but not specified here.

---

## 2. Users & Access

**Phase 1: Single user with OAuth-scoped tokens.** Filament admin protected by a single admin user.

**Agents** access Mnemon via MCP, authenticated with OAuth 2.1 bearer tokens issued via Laravel Passport. Each token carries coarse permission scopes and optional per-token wing restrictions captured at the consent screen.

### 2.1 OAuth Scopes

| Scope | Allows |
|---|---|
| `palace.read` | `drawer_search`, `drawer_get`, `brain_status`, `palace_wake_up`, `wiki_compile` |
| `palace.write` | `drawer_add` |
| `wiki.read` | `context_get`, `context_list`, `wiki_lint`, `wiki_graph`, `wiki_history` |
| `wiki.write` | `context_set` |

**Wing restrictions (optional):** A token can be limited to specific wings at the OAuth consent screen. Patterns support wildcards (e.g. `project:*`). Null = unrestricted.

**Client management:** Clients register via Dynamic Client Registration (DCR) or manually via `php artisan passport:client`. Access tokens are revocable via the Filament admin panel.

**Schema:**
```sql
-- Managed by laravel/passport
oauth_clients (id, name, secret, redirect, ...)
oauth_access_tokens (id, user_id, client_id, name, scopes, revoked, expires_at, ...)
oauth_refresh_tokens (id, access_token_id, revoked, expires_at)

-- Mnemon extension
mcp_token_restrictions
  access_token_id (varchar(100), PK, FK → oauth_access_tokens.id ON DELETE CASCADE)
  wing_patterns   (jsonb nullable; null = unrestricted)
  created_at
```

Access tokens expire after 1 hour; refresh tokens after 90 days. Every MCP request must include a valid, non-revoked bearer token with the required scope. All MCP calls are logged to `brain_sessions`.

**Phase 3:** Multi-user and tenant isolation.

---

## 3. The Palace (Layer 1)

### 3.1 Structure

Content is organized hierarchically:

```
Palace
└── Wing  (e.g., "project:atlas-abs", "person:cooper", "topic:laravel")
    └── Room  (e.g., "sprint-47", "architecture-decisions", "2026-04")
        └── Drawer  (a verbatim content block)
```

- **Wings** are top-level contexts. Named with a prefix convention: `project:`, `person:`, `topic:`. Created explicitly by the user/agent.
- **Rooms** subdivide a wing. Created on write if they don't exist.
- **Drawers** are the atomic storage unit. One verbatim content block per drawer. Soft-delete only.

### 3.2 Storage Rules

- **Verbatim only.** Content is stored exactly as provided. No summarization, no extraction, no paraphrasing at ingest time.
- **One embedding per drawer.** Generated on write. Re-generated if content is updated.
- **Source tracking.** Every drawer records its source: `claude`, `cursor`, `manual`, `openclaw`, `api`, etc.
- **Metadata.** Freeform JSON field for caller-provided context (session ID, timestamp, project, etc.).
- **Soft delete only.** Hard deletes via Filament UI only, never via MCP.

### 3.3 Retrieval

Hybrid search combining three signals:

1. **Semantic** — pgvector cosine similarity on embeddings
2. **Full-text** — Postgres `tsvector` / `ts_rank`
3. **Temporal proximity** — recency boost. Content from the last 7 days scores higher than older content, all else equal.

The hybrid score is a weighted sum. Default weights: semantic 0.6, full-text 0.3, temporal 0.1. Configurable via `config/mnemon.php`.

**Search scoping:** queries can be scoped to a wing, a room, or global (all wings). Default: global.

**Result format:** each result returns drawer ID, content (full verbatim), wing/room path, source, created_at, and similarity score.

---

## 4. The Wiki (Layer 2)

### 4.1 Concept

Wiki pages are LLM-compiled, human-directed markdown files stored in Postgres. They synthesize palace content into persistent, interlinked knowledge.

**Key rules:**
- Wiki pages are never auto-generated. They are created/updated via explicit MCP tool call (`context_set`) or through the Filament UI.
- The LLM writes them. The human directs what to compile and when.
- Wiki pages can contradict palace content (palace is raw history; wiki is current understanding).
- Every wiki page has a `last_compiled_at` timestamp so agents can judge freshness.

### 4.2 Wiki Page Types

| Type | Naming convention | Purpose |
|---|---|---|
| Entity — Person | `person:<name>` | Who someone is, relationship, preferences |
| Entity — Project | `project:<name>` | Status, architecture, current sprint, key decisions |
| Concept | `concept:<name>` | Explanation of a technical or domain concept |
| Decision | `decision:<slug>` | What was decided, why, alternatives rejected |
| Synthesis | `synthesis:<topic>` | Multi-source analysis compiled into a single view |

### 4.3 Index & Log

**`wiki/index`** — a special wiki page maintained automatically. Lists all wiki pages with a one-line summary, organized by type. Updated on every `context_set` call.

**`wiki/log`** — append-only record of operations (ingest, compile, lint). Each entry prefixed with `## [YYYY-MM-DD] operation | description` for parsability.

---

## 5. MCP Tool Surface (Phase 1)

All tools exposed via `php artisan mcp:serve`. Transport: stdio (Phase 1), HTTP (Phase 2).

### 5.1 Orientation

**`brain_status`**
Returns: drawer count, wiki page count, wing list with counts, embedding driver in use, last write timestamp.
Agents call this first to orient. No inputs required.

**`palace_wake_up`**
Returns: recent activity (last 10 drawers added), wings with most recent activity, wiki pages updated in last 7 days, any wiki pages flagged as stale.
Designed as session start call. No inputs required.

### 5.2 Palace Operations

**`drawer_add`**
Store verbatim content in the palace.
- Inputs: `content` (required, string), `wing` (required, string), `room` (optional, string — defaults to current month), `source` (optional, string), `metadata` (optional, object)
- Returns: drawer ID, embedding confirmation, wing/room path created/used

**`drawer_search`**
Hybrid search across the palace.
- Inputs: `query` (required, string), `wing` (optional, string — scopes to wing), `room` (optional, string — scopes to room), `limit` (optional, int, default 5, max 20), `mode` (optional: `semantic|fulltext|hybrid`, default `hybrid`)
- Returns: ranked results with drawer ID, content, wing/room path, source, created_at, score

**`drawer_get`**
Fetch a specific drawer by ID.
- Inputs: `id` (required)
- Returns: full drawer record

### 5.3 Wiki Operations

**`context_get`**
Fetch a wiki page by name.
- Inputs: `name` (required, string — e.g. `project:atlas-abs`)
- Returns: page content + metadata (type, last_compiled_at, word count)
- Behavior: returns 404-equivalent JSON if not found (does not create)

**`context_set`**
Write or overwrite a wiki page.
- Inputs: `name` (required, string), `content` (required, string), `description` (optional, string — one-line summary for index)
- Returns: page ID, created or updated flag
- Side effect: updates `wiki/index` and appends to `wiki/log`

**`context_list`**
List all wiki pages.
- Inputs: `type` (optional: `person|project|concept|decision|synthesis|all`, default `all`)
- Returns: array of {name, description, last_compiled_at, word_count}

---

## 6. Filament Admin Dashboard

Single-user Filament panel at `/admin`. Used for browsing, searching, and managing the palace and wiki.

### 6.1 Dashboard Widgets
- Total drawer count
- Total wiki page count
- Drawers added in last 7 days
- Last write timestamp
- Quick search bar (triggers hybrid search)

### 6.2 Palace Browser
- Wings list with drawer counts
- Wing detail: rooms within the wing, drawer counts per room
- Room detail: paginated list of drawers, sortable by created_at and score
- Drawer detail: full verbatim content, source, metadata, timestamps
- Hard delete available on drawer detail (admin only)

### 6.3 Wiki Browser
- Paginated list of wiki pages, filterable by type
- Page detail: rendered markdown, metadata, edit button
- Edit: markdown editor inline (no live preview required)
- Create new page form

### 6.4 Search
- Global search page: hybrid search across palace and wiki
- Scope selector: all / palace only / wiki only / by wing
- Results show content snippet, source, wing/room path, score

### 6.5 MCP Audit Log
- Paginated list of `brain_sessions` records
- Columns: tool name, source, result count, timestamp
- Input detail expandable per row

---

## 7. Embedding Driver System

Configured via `config/mnemon.php`. Mirrors Laravel's driver pattern.

```php
'embedding' => [
    'driver' => env('MNEMON_EMBEDDING_DRIVER', 'openai'),
    'drivers' => [
        'openai' => [
            'model' => env('MNEMON_OPENAI_MODEL', 'text-embedding-3-small'),
            'dimensions' => 1536,
        ],
        'ollama' => [
            'model' => env('MNEMON_OLLAMA_MODEL', 'nomic-embed-text'),
            'host'  => env('MNEMON_OLLAMA_HOST', 'http://localhost:11434'),
            'dimensions' => 768,
        ],
        'none' => [], // Falls back to full-text only
    ],
],
```

Switching drivers re-embedds all existing content on next artisan command (`mnemon:reembed`). Embedding dimensions stored per-drawer so mixed-driver content can coexist during migration.

---

## 8. OpenClaw Integration (Dogfood)

Mnemon serves as a secondary memory provider for OpenClaw alongside the existing file-based `memory/` system.

**Ingest sources:**
- Conversation sessions (manual trigger via MCP call or Artisan command)
- Daily memory files (`memory/YYYY-MM-DD.md`) — batch import
- Project memory files (`memory/projects/*.md`) — compile as wiki pages

**Memory search routing:**
- `memory_search` tool in OpenClaw queries both local files AND Mnemon palace
- Results are merged and de-duplicated before returning
- This is Phase 1 integration; Phase 2 will allow Mnemon to become the primary source

**Wing convention for OpenClaw:**
- `project:<name>` — per-project palace content
- `person:<name>` — per-person context
- `session:daily` — daily conversation content
- `meta:decisions` — architectural and workflow decisions

---

## 9. Data Model (Schema Summary)

```sql
wings
  id, name, slug (unique), description, created_at, updated_at

rooms
  id, wing_id (FK), name, slug, created_at, updated_at
  UNIQUE(wing_id, slug)

drawers
  id, room_id (FK), content (text), embedding (vector),
  source (varchar), metadata (jsonb), deleted_at, created_at, updated_at
  INDEX: GIN on metadata, HNSW on embedding

wiki_pages
  id, name (varchar unique), type (enum), title, content (text),
  embedding (vector), description (varchar), last_compiled_at,
  created_at, updated_at

brain_sessions
  id, tool_name, source, input (jsonb), result_count, created_at
```

---

## 10. Non-Goals (Phase 1)

- No multi-user / multi-tenant support
- No public-facing parent/consumer UI
- No automatic wiki compilation (always human-directed)
- No real-time features (Reverb deferred)
- No mobile app
- No Composer package (Phase 3)
- No import from MemPalace, Mem0, or other systems
- No rate limiting
- No background queue for embeddings (synchronous on write, acceptable for personal scale)

---

## 11. Phase Roadmap

| Phase | Scope |
|---|---|
| **1 — MVP** | Palace storage, hybrid retrieval, 7 MCP tools, Filament dashboard, OpenClaw integration |
| **2 — Depth** | Temporal knowledge graph, wiki lint tool, MCP auth, HTTP transport, advanced Filament features |
| **3 — Package** | `composer require mnemon/mnemon`, Filament plugin extraction, multi-user |

---

## 12. Resolved Questions

1. **Wing auto-creation:** ✅ Auto-create on write. User can rename/merge/delete in Filament after the fact.
2. **Wiki conflict resolution:** ✅ Freshness signals only in Phase 1. `brain_status` reports wiki pages not updated in 30+ days as stale. Automatic conflict detection deferred to Phase 2 `wiki_lint` tool.
3. **Embedding dimension mismatch on driver switch:** ✅ Require full re-embed via `php artisan mnemon:reembed`. No mixed-dimension storage.
4. **Repo naming:** ✅ Keep `mnemon` for now. Rename pending brand decision (Cairn, Lodestar, Tidepool under consideration). `laravel-` prefix at Phase 3 Packagist publish.
