# Mnemon — CLAUDE.md

## Project Overview

Mnemon is a self-hosted second brain built on Laravel 13. It has two layers: the **palace** (verbatim, append-only storage organized into wings → rooms → drawers) and the **wiki** (compiled, synthesized pages that distill palace content into structured knowledge). The system is exposed via MCP (Model Context Protocol) tools so AI agents can read and write to it programmatically, and managed through a Filament 5 admin panel. OAuth 2.1 via Passport with the `mcp:use` scope and per-token wing restrictions controls access.

## Stack

- **Framework:** Laravel 13 (PHP 8.4+)
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

### MCP Server

Endpoint: `POST /mcp` (Streamable HTTP, JSON-RPC 2.0). Built on [`laravel/mcp`](https://github.com/laravel/mcp).

Authentication: OAuth 2.1 via Passport. Clients register via Dynamic Client Registration (DCR) or manually via `php artisan passport:client`. The consent screen (`/oauth/authorize`) lets the user grant the token and restrict which wings it can access.

Single scope: **`mcp:use`** — required for all 14 tools. The `laravel/mcp` package auto-injects this via `Registrar::ensureMcpScope()`. Wing restrictions (`mcp_token_restrictions`) are the real per-agent isolation mechanism.

**Layer 2 tools (automatic memory for Claude Code):** `recall` (per-prompt context injection, called by `mnemon-recall.sh`) and `session_digest` (end-of-session transcript digestion, called by `mnemon-capture.sh`) extend the original 12 tools.

Access tokens expire after 1 hour; refresh tokens after 90 days.

See full spec at `docs/superpowers/specs/2026-04-26-mcp-rework-design.md`.

### OAuth Clients & Tokens

Authentication is handled by Laravel Passport (OAuth 2.1):
- **OAuth clients** are registered via DCR (MCP clients like Claude Code do this automatically) or manually via `php artisan passport:client`.
- **Access tokens** carry the single scope `mcp:use`.
- **Per-token wing restrictions** are captured at the consent screen and stored in `mcp_token_restrictions` (FK → `oauth_access_tokens`).
- Token revocation via the Filament admin panel under OAuth Access Tokens.

### Audit Trail

- **BrainSession** — create-only log of every MCP tool invocation. Records tool name, source, input, and result count.

## File Structure Conventions

```
app/
  Mcp/
    Servers/       — MnemonServer (registers tools, resources, prompts)
    Tools/         — Tool handlers (one class per tool)
    Resources/     — MCP resource handlers (WingResource, DrawerResource, WikiPageResource)
    Prompts/       — MCP prompt handlers
    Concerns/      — Shared traits (RequiresScope, RequiresWingAccess, ResolvesAgentSource, etc.)
    Support/       — BrainSessionLogger and other support classes
  Models/          — Eloquent models (Wing, Room, Drawer, WikiPage, BrainSession, McpTokenRestriction, WikiPendingWing)
  Console/
    Commands/      — Artisan commands (includes InstallClaudeCodeHooks.php for Layer 2 setup)
  Providers/
    Filament/      — Filament panel providers
config/
  mnemon.php       — Mnemon-specific config (embedding, retrieval, wiki)
database/
  migrations/      — Prefixed 2026_04_24_1000XX_create_*_table.php for Mnemon tables
  seeders/         — AdminUserSeeder, DatabaseSeeder
tests/
  Unit/            — Model unit tests (no DB required where possible)
  Feature/         — Migration/integration tests (use RefreshDatabase)
docs/              — Project documentation, FRD, implementation plan
```

## Notes

- Vector columns (`embedding vector(1536)`) are added via raw `DB::statement` and only run on PostgreSQL. SQLite migrations skip them gracefully — tests run on SQLite.
- Filament admin panel lives at `/admin`. The `User` model implements `FilamentUser`.
- All slug generation happens in model `creating` events using `Str::slug()`.
