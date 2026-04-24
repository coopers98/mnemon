# Mnemon — CLAUDE.md

## Project Overview

Mnemon is a self-hosted second brain built on Laravel 13. It has two layers: the **palace** (verbatim, append-only storage organized into wings → rooms → drawers) and the **wiki** (compiled, synthesized pages that distill palace content into structured knowledge). The system is exposed via MCP (Model Context Protocol) tools so AI agents can read and write to it programmatically, and managed through a Filament 5 admin panel. API key authentication with per-key scope and wing restrictions controls access.

## Stack

- **Framework:** Laravel 13 (PHP 8.3+)
- **Admin UI:** Filament 5
- **Primary DB:** PostgreSQL with pgvector extension (for semantic embeddings)
- **Test DB:** SQLite (vector columns skipped in SQLite migrations)
- **Embeddings:** OpenAI `text-embedding-3-small` (1536d) or Ollama `nomic-embed-text` (768d)

## Commands

```bash
# Development server
php artisan serve

# Run tests
php artisan test --compact

# Lint (PHP CS Fixer via Pint)
./vendor/bin/pint

# Migrate
php artisan migrate

# Migrate fresh with seed
php artisan migrate:fresh --seed

# Seed only
php artisan db:seed
```

## Architecture

### The Palace (verbatim storage)

Raw content lives in a three-level hierarchy:

- **Wing** — top-level namespace (e.g., "Work", "Personal", "Research"). Has a unique slug.
- **Room** — category within a wing (e.g., "Meeting Notes", "Ideas"). Slug is unique per wing.
- **Drawer** — individual piece of content. Stores verbatim text, optional embedding, source attribution, and arbitrary JSON metadata. Supports soft deletes.

### The Wiki (compiled knowledge)

- **WikiPage** — synthesized articles compiled from palace drawers. Types: `person`, `project`, `concept`, `decision`, `synthesis`. Tracks `last_compiled_at` to detect staleness (default: 30 days).

### MCP Tools

Planned for Sprint 2. Tools will allow AI agents to store drawers, retrieve by semantic/fulltext search, and read/write wiki pages. Authenticated via API keys.

### API Keys

`ApiKey` model handles bearer-token authentication:
- **Scopes** — array of allowed operations (e.g., `['drawers.write', 'wiki.read']` or `['*']` for full access)
- **Wing restrictions** — optional array of wing slugs the key can access. Supports wildcard patterns like `project:*`. Null = unrestricted.
- Keys are stored as SHA-256 hashes; the plaintext is only shown once at creation.

### Audit Trail

- **BrainSession** — create-only log of every MCP tool invocation. Records tool name, source, input, and result count.

## File Structure Conventions

```
app/
  Models/          — Eloquent models (Wing, Room, Drawer, WikiPage, BrainSession, ApiKey)
  Providers/
    Filament/      — Filament panel providers
config/
  mnemon.php       — Mnemon-specific config (embedding, retrieval, wiki)
database/
  migrations/      — Prefixed 2026_04_24_1000XX_create_*_table.php for Mnemon tables
  seeders/         — AdminUserSeeder, ApiKeySeeder, DatabaseSeeder
tests/
  Unit/            — Model unit tests (no DB required where possible)
  Feature/         — Migration/integration tests (use RefreshDatabase)
docs/              — Project documentation, FRD, implementation plan
```

## Notes

- Vector columns (`embedding vector(1536)`) are added via raw `DB::statement` and only run on PostgreSQL. SQLite migrations skip them gracefully — tests run on SQLite.
- Filament admin panel lives at `/admin`. The `User` model implements `FilamentUser`.
- All slug generation happens in model `creating` events using `Str::slug()`.
