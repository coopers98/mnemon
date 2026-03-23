# Mnemon — Project Discovery Notes
*Last updated: March 2026*

## The Problem

Every AI tool maintains its own isolated memory. Claude knows things Cursor doesn't.
ChatGPT doesn't know what Claude learned last week. Every new agent starts from zero.
The result is constant re-teaching, repeated context dumps, and a fragmented picture
of you spread across platforms that never talk to each other.

## The Solution

Mnemon is a self-hosted, Laravel-native second brain with a built-in MCP server.
One Postgres database. One API. Every AI tool you use reads from and writes to the
same persistent memory of you.

Named after Mnemon (NEE-mon) — Greek for "one who remembers."

## Core Principles

- **Self-hosted first.** Your memories live on your infrastructure, not a SaaS platform.
- **Laravel-native.** Not a Node sidecar. Not Supabase-dependent. Fits naturally into
  existing Laravel/PHP projects and skillsets.
- **Driver-based.** Embedding model, vector backend, and DB are all configurable.
  Swap OpenAI for Ollama. Use Postgres or MySQL. Disable vectors entirely and fall
  back to full-text search.
- **MCP built-in.** Not bolted on. Uses Laravel's native MCP server support.
- **Write-back capable.** Agents don't just read — they can add, update, and enrich
  memories as they work.
- **Direct UI included.** A Vue PWA for browsing, capturing, and searching your brain
  without going through an agent at all.
- **Single-user for v1.** Multi-tenancy is a future concern, not a v1 constraint.

## License

MIT. No commercial restrictions. OSS first, let usage patterns determine if anything
commercial makes sense later.

---

## Architecture

### Database

Postgres (primary, recommended) with pgvector for vector similarity search.
MySQL 9.0+ and MariaDB supported via driver abstraction. Full-text fallback when
no vector driver is configured.

#### Core Tables

**memories**
The primary store. Every captured thought, note, fact, or agent observation.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| content | text | The memory itself |
| embedding | vector(1536) | Null when embeddings disabled |
| source | varchar | claude, cursor, chatgpt, manual, api, etc |
| tags | jsonb | GIN indexed for fast filtering |
| context | text | Why this was captured |
| importance | tinyint | 1-5, default 3 |
| expires_at | timestamp | Nullable, for ephemeral memories |
| deleted_at | timestamp | Soft delete only |
| created_at / updated_at | timestamps | |

**contexts**
Named, structured context blocks. Fetched directly by name, not searched.
Used for global profile, project summaries, conventions, etc.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar unique | e.g. global_profile, project:apollo |
| content | text | Freeform, injected as-is into system prompts |
| description | text | What this context block is for |
| created_at / updated_at | timestamps | |

**brain_sessions**
Audit log of all MCP tool calls. Not agent-facing — internal record keeping.

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| tool_name | varchar | Which MCP tool was called |
| source | varchar | Which agent/client called it |
| input | jsonb | What was passed in |
| result_count | int | How many results returned |
| created_at | timestamp | |

---

### Embedding Drivers

Configured via `config/brain.php`. Driver pattern mirrors Laravel's cache/mail/queue.

| Driver | Model | Cost | Notes |
|---|---|---|---|
| openai | text-embedding-3-small | ~$0.02/1M tokens | Recommended default |
| openai | text-embedding-3-large | ~$0.13/1M tokens | Higher quality |
| ollama | nomic-embed-text, mxbai-embed-large | $0 | Self-hosted, fully local |
| none | — | $0 | Falls back to full-text search |

Personal usage cost at any paid driver is effectively negligible (under $1/month
for active daily use).

---

### MCP Tool Surface

All tools are registered via Laravel's native MCP server support and served via
`php artisan mcp:serve`.

#### Memory Operations

**memory_add**
Primary write tool. Called by agents to persist something worth remembering.
- Inputs: content (required), source, tags, context, importance (1-5), expires_at
- Returns: created memory ID, embedding confirmation

**memory_search**
Primary read tool. Handles semantic, keyword, or hybrid search transparently.
- Inputs: query (required), mode (semantic|keyword|hybrid, default hybrid),
  tags filter, source filter, limit (default 5, max 20), min_importance
- Returns: ranked memories with similarity scores, tags, source, created_at

**memory_get**
Fetch a specific memory by ID.

**memory_update**
Correct or enrich an existing memory. Re-embeds if content changed. Prevents
agent-created duplicates.
- Inputs: id (required), any updatable fields

**memory_delete**
Soft delete only. Agents cannot hard delete. Hard deletes via UI only.

#### Context Operations

**context_get**
Fetch a named context block by name. Direct retrieval, not searched.
- Input: name (e.g. global_profile, project:mnemon)
- Returns: full context content + metadata

**context_set**
Write or overwrite a named context block.
- Inputs: name (required), content (required), description

**context_list**
Returns all context names with last updated timestamps. Lets agents orient
before deciding what to fetch.

#### Introspection

**brain_status**
Returns memory count, context count, embedding driver in use, last write
timestamp, tag taxonomy with counts. Agents call this first to orient.

**tag_list**
All tags with memory counts. Helps agents understand existing taxonomy before
writing new tags.

---

### Recommended Agent Lifecycle

A well-behaved agent using Mnemon follows this pattern:

1. `brain_status` — orient, confirm connection, see scale
2. `context_get('global_profile')` — know who you're working with
3. `context_get('project:X')` — if a project is relevant
4. `memory_search(query)` — pull relevant history
5. *Do work*
6. `memory_add(...)` — persist anything worth keeping
7. `context_set(...)` — update project context if it evolved

---

### REST API

The same service layer backing the MCP tools is exposed as a standard Laravel
REST API for:
- Non-MCP clients
- The Vue PWA
- Direct integrations (mobile, scripts, webhooks)

---

### Vue PWA (Direct UI)

Browser-based interface for interacting with the brain without going through
an agent. v1 scope is intentionally minimal:

- **Capture** — write a memory, assign tags, set source and importance
- **Search** — keyword + semantic search with tag/source filters
- **Browse** — paginated list, filterable, sortable
- **Detail** — view, edit, soft-delete individual memories
- **Contexts** — CRUD for named context blocks
- **Activity** — audit log feed showing what agents have been doing

Graph views, relationship maps, timeline visualization, and richer analytics
are explicitly deferred to later versions.

---

## What This Is Not

- Not a notes app
- Not a SaaS product (v1)
- Not multi-tenant (v1)
- Not a replacement for project-specific vector stores in production RAG pipelines
- Not Node.js, not Supabase-dependent, not Slack-dependent

---

## Competitive Landscape

**Open Brain (OB1)** — closest conceptual cousin. Node-ecosystem, Supabase-hosted,
Slack as capture UI, targets non-technical users. Mnemon differentiates on
Laravel-native stack, configurable drivers, direct UI, and developer-first positioning.

**Mem.ai, Notion AI, etc.** — SaaS products, not self-hosted, not MCP-native,
not designed for cross-agent memory sharing.

**Roll-your-own** — what most Laravel devs currently do. Mnemon replaces the need.

---

## Open Questions

- Importance field: agent-assigned, user-assigned, or both with separate fields?
- Ephemeral memory expiry: scheduled job vs query-time filtering?
- Audit log UI: live feed or paginated history?
- Repo name: `mnemon` standalone vs `laravel-mnemon` to signal ecosystem clearly?

---

## Stack Summary

| Layer | Technology |
|---|---|
| Backend | Laravel (PHP) |
| Database | Postgres (primary), MySQL 9+, MariaDB |
| Vector Search | pgvector, mysql vector, full-text fallback |
| Embeddings | OpenAI, Ollama, or none (driver-based) |
| MCP Transport | Laravel native MCP server |
| Frontend | Vue PWA |
| Real-time (future) | Laravel Reverb |