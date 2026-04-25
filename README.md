# Mnemon

A self-hosted second brain. Two layers:

- **Palace** — verbatim, append-only storage organized as wings → rooms → drawers
- **Wiki** — compiled, synthesized pages distilled from palace content

Exposed to AI agents via MCP (Model Context Protocol) tools. Managed through a Filament admin panel. API-key authenticated with per-key scopes and wing restrictions.

## Stack

- **Framework:** Laravel 13 (PHP 8.3+)
- **Admin UI:** Filament 5
- **Primary DB:** PostgreSQL with pgvector
- **Test DB:** SQLite (vector columns are skipped on SQLite)
- **Embeddings:** OpenAI `text-embedding-3-small` (1536d) or Ollama `nomic-embed-text` (768d)

## Quick Start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed   # creates an admin user + a `*` admin API key (printed once)
php artisan serve            # http://localhost:8000/admin
```

## Common Commands

```bash
php artisan test --compact            # run the test suite
./vendor/bin/pint                     # format PHP
php artisan migrate:fresh --seed      # rebuild DB from scratch
php artisan mnemon:reembed            # re-embed all drawers + wiki pages with current driver
php artisan mnemon:create-key NAME    # mint an API key from the CLI
php artisan mcp:serve                 # start the MCP server (Sprint 4)
```

## MCP Tools

Seven tools, gated by API-key scope:

| Tool | Scope | What it does |
|------|-------|--------------|
| `brain_status` | `palace:read` | Drawer/wiki counts, wings, embedding driver, staleness |
| `palace_wake_up` | `palace:read` | Recent drawers + wing activity + stale wiki pages |
| `drawer_add` | `palace:write` | Add a drawer (auto-creates wing/room) |
| `drawer_search` | `palace:read` | Hybrid semantic + full-text + temporal search |
| `drawer_get` | `palace:read` | Fetch a drawer by ID |
| `context_get` | `wiki:read` | Read a wiki page by name |
| `context_set` | `wiki:write` | Upsert a wiki page (auto-updates index/log) |
| `context_list` | `wiki:read` | List wiki pages, optionally filtered by type |

## Documentation

- [`CLAUDE.md`](./CLAUDE.md) — agent / contributor conventions (canonical)
- [`AGENTS.md`](./AGENTS.md) — pointer for non-Claude agents
- [`docs/FRD.md`](./docs/FRD.md) — functional requirements
- [`docs/IMPLEMENTATION-PLAN.md`](./docs/IMPLEMENTATION-PLAN.md) — sprint plan + status
- [`docs/discovery.md`](./docs/discovery.md) — initial discovery notes

## Status

Sprints 1–4 complete (foundation, embeddings, hybrid retrieval, MCP server).
Sprint 5 (Filament dashboard) is next. See the implementation plan for details.

## License

MIT.
