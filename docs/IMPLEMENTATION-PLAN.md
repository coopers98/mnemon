# Mnemon — Implementation Plan

**FRD:** `docs/FRD.md`
**Stack:** Laravel, Postgres + pgvector, Filament, Laravel MCP Server
**Repo:** `coopers98/mnemon`
**Server:** 198.51.100.10 (Forge, shared)
**Created:** 2026-04-24

---

## Sprint Overview

| Sprint | Name | What Ships | Est. | Status |
|--------|------|-----------|------|--------|
| 1 | Foundation | Laravel project, migrations, models, config, seeders | 1 session | ✅ Complete (#1) |
| 2 | Embedding Engine | Driver system, OpenAI + Ollama + none drivers, reembed command | 1 session | ✅ Complete (#2) |
| 3 | Hybrid Retrieval | pgvector search + full-text + temporal boost, search API | 1 session | ✅ Complete (#3) |
| 4 | MCP Server | All 7 Phase 1 tools, API key auth middleware | 1-2 sessions | ✅ Complete (#4) |
| 5 | Filament Dashboard | Palace browser, wiki browser, search, audit log, key management | 1-2 sessions | ⏳ **Next** |
| 6 | OpenClaw Integration | Session ingest, memory file import, search routing | 1 session | ⏳ Not started |
| 7 | Deploy & Polish | Forge setup, domain, SSL, smoke tests, CLAUDE.md | 1 session | ⏳ Not started |

**Current state:** Sprints 1–4 merged to `main`. Filament 5 is installed (`composer.json`) but `app/Filament/` is empty — Sprint 5 is where to pick up.

---

## Sprint 1: Foundation ✅

### Laravel Project Setup
- `laravel new mnemon` (or scaffold in existing repo)
- Postgres connection config
- pgvector extension: `CREATE EXTENSION IF NOT EXISTS vector;`

### Migrations

**`create_wings_table`**
```
id (bigint PK), name (varchar), slug (varchar unique), description (text nullable),
created_at, updated_at
```

**`create_rooms_table`**
```
id (bigint PK), wing_id (FK → wings), name (varchar), slug (varchar),
created_at, updated_at
UNIQUE(wing_id, slug)
```

**`create_drawers_table`**
```
id (bigint PK), room_id (FK → rooms), content (text), embedding (vector nullable),
source (varchar nullable), metadata (jsonb nullable),
deleted_at (soft delete), created_at, updated_at
INDEX: HNSW on embedding, GIN on metadata
```

**`create_wiki_pages_table`**
```
id (bigint PK), name (varchar unique), type (varchar — person/project/concept/decision/synthesis),
title (varchar), content (text), embedding (vector nullable),
description (varchar nullable), last_compiled_at (timestamp nullable),
created_at, updated_at
```

**`create_brain_sessions_table`**
```
id (bigint PK), tool_name (varchar), source (varchar), input (jsonb),
result_count (int nullable), created_at
```

**`create_api_keys_table`**
```
id (bigint PK), name (varchar), key_hash (varchar unique),
scopes (jsonb), wing_restrictions (jsonb nullable),
last_used_at (timestamp nullable), revoked_at (timestamp nullable),
created_at, updated_at
```

### Models
- `Wing` — hasMany Rooms, slug auto-generated from name
- `Room` — belongsTo Wing, hasMany Drawers, slug auto-generated
- `Drawer` — belongsTo Room, soft deletes, casts metadata to array
- `WikiPage` — casts type as enum, embedding nullable
- `BrainSession` — read-only audit model
- `ApiKey` — scopes cast to array, `hasScope(string)` and `canAccessWing(string)` methods

### Config (`config/mnemon.php`)
```php
return [
    'embedding' => [
        'driver' => env('MNEMON_EMBEDDING_DRIVER', 'openai'),
        'drivers' => [
            'openai' => [
                'model' => env('MNEMON_OPENAI_MODEL', 'text-embedding-3-small'),
                'dimensions' => 1536,
            ],
            'ollama' => [
                'model' => env('MNEMON_OLLAMA_MODEL', 'nomic-embed-text'),
                'host' => env('MNEMON_OLLAMA_HOST', 'http://localhost:11434'),
                'dimensions' => 768,
            ],
            'none' => [],
        ],
    ],
    'retrieval' => [
        'weights' => [
            'semantic' => 0.6,
            'fulltext' => 0.3,
            'temporal' => 0.1,
        ],
        'temporal_boost_days' => 7,
        'default_limit' => 5,
        'max_limit' => 20,
    ],
    'wiki' => [
        'stale_days' => 30,
    ],
];
```

### Seeders
- Create default `*` admin API key (output plaintext key once on seed)
- No palace content seeded — that's the dogfood phase

### Tests
- Model relationship tests (wing → rooms → drawers)
- ApiKey scope checking (`hasScope`, `canAccessWing`)
- Slug auto-generation on Wing and Room
- Soft delete on Drawer

---

## Sprint 2: Embedding Engine ✅

### EmbeddingManager (Driver Pattern)
- `App\Services\EmbeddingManager` — resolves driver from config
- `EmbeddingDriver` interface: `embed(string $text): ?array`, `dimensions(): int`
- `OpenAiEmbeddingDriver` — calls text-embedding-3-small via HTTP
- `OllamaEmbeddingDriver` — calls Ollama API
- `NullEmbeddingDriver` — returns null (full-text fallback mode)

### Drawer Integration
- On `Drawer::creating` / `Drawer::updating` — auto-embed content
- On `WikiPage::creating` / `WikiPage::updating` — auto-embed content
- Embedding stored as pgvector column

### Artisan Commands
- `mnemon:reembed` — re-embeds all drawers and wiki pages using current driver. Progress bar. Batch size configurable.
- `mnemon:create-key {name} {--scopes=*} {--wings=}` — create API key from CLI

### Tests
- EmbeddingManager resolves correct driver from config
- OpenAI driver returns correct dimensions (mock HTTP)
- NullDriver returns null, drawers saved without embedding
- Reembed command processes all records

---

## Sprint 3: Hybrid Retrieval ✅

### PalaceSearchService
- `App\Services\PalaceSearchService`
- `search(string $query, ?string $wing, ?string $room, int $limit, string $mode): Collection`

### Search Modes

**Semantic only (`mode: semantic`):**
- Embed query → pgvector `<=>` cosine distance → rank by similarity

**Full-text only (`mode: fulltext`):**
- Postgres `to_tsvector('english', content) @@ plainto_tsquery('english', $query)`
- Rank by `ts_rank`

**Hybrid (`mode: hybrid`, default):**
- Run both semantic and full-text
- Normalize scores to 0-1 range within each set
- Apply temporal boost: `score += temporal_weight * (1 - days_old / boost_days)` for content within `temporal_boost_days`
- Weighted sum: `final = semantic_weight * semantic + fulltext_weight * fulltext + temporal_boost`
- Deduplicate (same drawer from both searches), take max score
- Sort by final score, limit

### Wing/Room Scoping
- If wing provided, join through rooms → wing where wing.slug = $wing
- If room provided, filter by room.slug within the wing
- If neither, search globally

### Tests
- Semantic search returns relevant results (seeded test data + mocked embeddings)
- Full-text search matches keyword queries
- Hybrid mode combines and deduplicates correctly
- Temporal boost increases score for recent content
- Wing scoping filters correctly
- Empty results return empty collection (not error)
- Limit is respected

---

## Sprint 4: MCP Server ✅

### MCP Registration
- Use Laravel's native MCP server support
- Register all 7 tools in `app/Providers/McpServiceProvider.php`
- Serve via `php artisan mcp:serve`

### API Key Middleware
- Extract key from MCP request metadata (or header for HTTP transport)
- Hash and look up in `api_keys` table
- Check `revoked_at` is null
- Check required scope for the tool being called
- Check wing restrictions if tool is scoped to a wing
- Log to `brain_sessions` on every call (tool, source = key name, input)
- Update `last_used_at` on key

### Tool Implementations

**`brain_status`** — Scope: `palace:read`
- Query: drawer count, wiki page count, wing list with drawer counts, embedding driver name, last drawer created_at
- Report wiki pages older than `stale_days` as stale
- Return as structured JSON

**`palace_wake_up`** — Scope: `palace:read`
- Last 10 drawers added (content preview, wing/room, source, created_at)
- Wings sorted by most recent activity
- Wiki pages updated in last 7 days
- Stale wiki pages (>30 days since last_compiled_at)

**`drawer_add`** — Scope: `palace:write`
- Validate inputs (content required, wing required)
- Auto-create wing if not exists (by slug)
- Auto-create room if not exists (default: current month `YYYY-MM` if not specified)
- Embed content via EmbeddingManager
- Create drawer record
- Return: drawer ID, wing/room path, embedding confirmation

**`drawer_search`** — Scope: `palace:read`
- Delegate to PalaceSearchService
- Check wing restrictions on API key before searching
- Return: array of results with id, content, wing, room, source, created_at, score

**`drawer_get`** — Scope: `palace:read`
- Fetch by ID, check wing restrictions
- Return: full drawer record

**`context_get`** — Scope: `wiki:read`
- Look up wiki_page by name
- Return: content, type, title, description, last_compiled_at, word count
- Not found: return structured error (not exception)

**`context_set`** — Scope: `wiki:write`
- Upsert wiki_page by name
- Auto-infer type from name prefix (`person:` → person, `project:` → project, etc.)
- Embed content
- Set last_compiled_at to now
- Auto-update `wiki/index` page (regenerate from all wiki_pages)
- Append entry to `wiki/log` page
- Return: page ID, created/updated flag

**`context_list`** — Scope: `wiki:read`
- List all wiki pages, optionally filtered by type
- Return: array of {name, type, description, last_compiled_at, word_count}

### Tests
- Each tool returns correct structure
- API key with insufficient scope is rejected
- Wing-restricted key cannot read other wings
- Revoked key is rejected
- brain_sessions logged on every call
- drawer_add auto-creates wing and room
- context_set updates index and log
- drawer_search respects wing scoping

---

## Sprint 5: Filament Dashboard ⏳ Next

### Dashboard Page
- Stat widgets: total drawers, total wiki pages, drawers added (7d), last write
- Quick search bar → redirects to search results page

### Palace Browser (EvaluationResource-style)
**WingResource**
- List: name, description, drawer count, room count, last activity
- View: wing detail with rooms table
- Create/Edit: name, description

**RoomResource** (nested under Wing)
- List: name, drawer count, last activity
- View: room detail with drawers table

**DrawerResource**
- List: content preview (truncated), wing/room path, source, created_at
- View: full content, metadata (formatted JSON), source, timestamps
- Delete: hard delete with confirmation
- Filters: by wing, by source, date range

### Wiki Browser
**WikiPageResource**
- List: name, type, description, last_compiled_at, word count
- Filters: by type
- View: rendered markdown content, metadata
- Edit: name, content (markdown textarea), description
- Create: name, type selector, content, description

### Search Page
- Custom Filament page (not a resource)
- Search input + scope selector (all / palace / wiki / wing dropdown)
- Results table: content snippet, source location, score
- Click through to drawer or wiki page detail

### API Key Management
**ApiKeyResource**
- List: name, scopes display, wing restrictions, last_used_at, status (active/revoked)
- Create: name, scope checkboxes, wing restriction multi-select, generates key (shown once)
- Edit: name, scopes, wing restrictions (key itself not editable)
- Revoke action (soft — sets revoked_at)

### MCP Audit Log
**BrainSessionResource**
- List: tool_name, source (key name), result_count, created_at
- View: full input JSON expanded
- Filters: by tool_name, by source, date range
- Read-only (no create/edit/delete)

### Tests
- Dashboard widgets render with correct counts
- Wing/Room/Drawer CRUD works
- WikiPage CRUD works
- API key creation generates hash
- Revoked keys show as revoked
- Search page returns results

---

## Sprint 6: OpenClaw Integration ⏳

### Session Ingest Command
- `mnemon:ingest-sessions` — reads OpenClaw session transcripts and stores as drawers
- Maps to wings based on session context (channel, project mentions)
- Deduplication: skip content already stored (hash check)
- Source: `openclaw`

### Memory File Import Command
- `mnemon:import-memory {path}` — imports markdown memory files as drawers
- `memory/YYYY-MM-DD.md` → wing `session:daily`, room `YYYY-MM`
- `memory/projects/*.md` → wing `project:<name>`, compile as wiki pages
- `memory/people/*.md` → wing `person:<name>`, compile as wiki pages
- Source: `openclaw-import`

### OpenClaw Memory Search Integration
- Document how to configure OpenClaw to query Mnemon MCP alongside local files
- Provide example OpenClaw skill or config that routes `memory_search` to both sources
- Merge and deduplicate results

### Tests
- Ingest command creates drawers with correct wing/room mapping
- Import command handles daily files and project files
- Duplicate content is skipped
- Source field correctly set

---

## Sprint 7: Deploy & Polish ⏳

### Forge Setup
- Create site on 198.51.100.10
- Domain: TBD (pending rename decision — mnemon.example.com as placeholder)
- Postgres database with pgvector extension
- Deploy script: `git pull`, `composer install`, `php artisan migrate`, `php artisan config:cache`
- SSL via Let's Encrypt
- `.env` configuration (DB, embedding driver, OpenAI key)

### CLAUDE.md
- Project conventions, architecture overview, commands
- MCP tool reference
- Test commands

### Smoke Tests
- Hit each MCP tool via artisan tinker or test script
- Verify Filament dashboard loads
- Verify search returns results after seeding test content
- Verify API key auth blocks unauthorized access

### Documentation
- README.md with project overview, setup instructions, MCP tool list
- Quick start guide for connecting Claude Code / Cursor / OpenClaw

### Initial Dogfood
- Import existing `memory/` files
- Create API keys for OpenClaw and Claude Code
- Run first real queries against own data
- Note retrieval quality observations for tuning

---

## Testing Strategy

- **Unit tests:** models, embedding drivers, search service, API key scope logic
- **Feature tests:** each MCP tool end-to-end, Filament CRUD, auth middleware
- **No browser/Dusk tests** in Phase 1 — Filament is tested via HTTP assertions
- **Test DB:** SQLite in-memory where possible, Postgres for pgvector-specific tests
- Target: 100+ tests by end of Sprint 7
