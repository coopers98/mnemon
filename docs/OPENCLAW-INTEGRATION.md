# Mnemon — OpenClaw Integration Guide

How to connect OpenClaw's memory system to Mnemon for dual-layer knowledge management.

---

## Architecture

```
OpenClaw Memory (file-based)          Mnemon (database-backed)
├── MEMORY.md (curated index)    ──→  wing: memory-index
├── memory/YYYY-MM-DD*.md        ──→  wing: session:daily, room: YYYY-MM
├── memory/projects/*.md         ──→  wing: project:<name> + wiki page
├── memory/people/*.md           ──→  wing: person:<name> + wiki page
├── memory/decisions/*.md        ──→  wing: decision, room: <slug>
└── misc .md files               ──→  wing: session:daily, room: misc
```

Both systems run side by side. OpenClaw's file-based memory is the primary source of truth; Mnemon supplements it with semantic search and compiled wiki pages.

---

## Initial Import

### 1. Copy memory files to the Mnemon server

```bash
# From the OpenClaw host
rsync -avz ~/.openclaw/workspace/memory/ forge@<server>:/tmp/openclaw-memory/
scp ~/.openclaw/workspace/MEMORY.md forge@<server>:/tmp/openclaw-memory/MEMORY.md
```

### 2. Dry run (verify what will be imported)

```bash
ssh forge@<server> 'cd /home/forge/<site>/current && php artisan mnemon:import-memory /tmp/openclaw-memory --dry-run'
```

### 3. Run the import

```bash
ssh forge@<server> 'cd /home/forge/<site>/current && php artisan mnemon:import-memory /tmp/openclaw-memory'
```

The command:
- Maps files to wings/rooms based on directory structure and filename patterns
- Creates wiki pages for project and people files automatically
- Generates OpenAI embeddings for each drawer (semantic search)
- Deduplicates via SHA-256 content hash — safe to re-run

### 4. Verify

Mnemon's MCP endpoint is `POST /mcp` (Streamable HTTP, JSON-RPC 2.0), authenticated with an OAuth 2.1 bearer token.

**From Claude Code** — the easiest path. The MCP client handles the OAuth flow automatically:

```bash
claude mcp add --transport http mnemon https://mnemon.example.com/mcp
# Opens a browser window — log in, choose which wings to grant access to.
# After consent, the token is stored. Scope: mcp:use (single scope, auto-granted).
```

**Direct curl** — obtain a Passport bearer token first (e.g. via `php artisan tinker` or the personal-access-token flow), then:

```bash
curl -s -X POST https://mnemon.example.com/mcp \
  -H "Authorization: Bearer <access_token>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"brain_status","arguments":{}}}' | jq .
```

---

## Ongoing Sync

Re-run the import periodically to pick up new memory files. The SHA-256 dedup ensures only new content is added:

```bash
rsync -avz ~/.openclaw/workspace/memory/ forge@<server>:/tmp/openclaw-memory/
ssh forge@<server> 'cd /home/forge/<site>/current && php artisan mnemon:import-memory /tmp/openclaw-memory'
```

### Session Transcript Ingestion

For OpenClaw session transcripts (if available as .md files):

```bash
ssh forge@<server> 'cd /home/forge/<site>/current && php artisan mnemon:ingest-sessions /path/to/sessions'
```

---

## Wiki Compilation Workflow

Imported files are stored verbatim as drawers. To get the Karpathy-style compiled wiki, follow the compile cycle:

### 1. Check what needs updating

```bash
# Via MCP
{"tool": "wiki_lint", "params": {"focus": "stale"}}

# Or check brain_status for pending_update_pages
{"tool": "brain_status", "params": {}}
```

### 2. Gather source material

```bash
{"tool": "wiki_compile", "params": {"name": "project:atlas-abs", "limit": 20}}
```

Returns the current wiki page content + all recent drawers from that wing.

### 3. Synthesize and update

The LLM reads the drawer content and writes a proper wiki page:

```bash
{"tool": "context_set", "params": {
  "name": "project:atlas-abs",
  "content": "<LLM-synthesized markdown>",
  "confidence": "high",
  "sources": [1, 5, 23],
  "related": ["person:cooper", "decision:uuid-migration"],
  "description": "Asset-backed securities platform — status and architecture"
}}
```

### 4. Periodic health checks

```bash
# Full lint
{"tool": "wiki_lint", "params": {}}

# Focused checks
{"tool": "wiki_lint", "params": {"focus": "orphans"}}
{"tool": "wiki_lint", "params": {"focus": "low_confidence"}}
```

---

## Import Command Options

### `mnemon:import-memory {path}`

| Option | Description |
|--------|-------------|
| `--dry-run` | Show what would be imported without writing |
| `--wing=daily` | Only import daily memory files |
| `--wing=project` | Only import project files |
| `--wing=person` | Only import people files |
| `--wing=decision` | Only import decision files |
| `--wing=memory-index` | Only import MEMORY.md |

### File Mapping Rules

| Pattern | Wing | Room | Wiki Page? |
|---------|------|------|-----------|
| `YYYY-MM-DD.md` | session:daily | YYYY-MM | No |
| `YYYY-MM-DD-suffix.md` | session:daily | YYYY-MM | No |
| `other-name.md` (in root) | session:daily | misc (or YYYY-MM if date found) | No |
| `projects/name.md` | project:name | notes | Yes (type: project) |
| `people/name.md` | person:name | notes | Yes (type: person) |
| `decisions/name.md` | decision | name-slug | No |
| `MEMORY.md` | memory-index | index | No |

### Deduplication

Content is hashed with SHA-256 and stored in `metadata.content_hash`. On re-import, any drawer with a matching hash in the same wing is skipped. This makes the import idempotent — run it as many times as you want.

### Security

- Files are validated with `realpath()` to prevent symlink traversal
- File size capped at 10MB
- Only relative paths stored in metadata (no server filesystem exposure)
- All reads use the resolved real path (TOCTOU protection)

---

## MCP Tool Reference

All fourteen tools require the single OAuth scope `mcp:use`. Wing restrictions on the token provide per-agent isolation **for palace content only** — wiki pages have no wing dimension, so `context_get`, `context_list`, `palace_wake_up`, `brain_status` and `recall` return wiki content regardless of the token's restrictions. See the README's Limitations.

| Tool | Description |
|------|-------------|
| `brain_status` | Drawer/wiki counts, wings, pending updates |
| `palace_wake_up` | Recent activity, pages needing compilation |
| `drawer_add` | Store verbatim content |
| `drawer_search` | Hybrid search (semantic + fulltext + temporal) |
| `drawer_get` | Fetch drawer by ID |
| `context_get` | Fetch wiki page (includes source previews) |
| `context_set` | Create/update wiki page with metadata |
| `context_list` | List all wiki pages |
| `wiki_lint` | Health check (stale, orphan, empty, low-confidence) |
| `wiki_compile` | Gather drawers for wiki page compilation |
| `wiki_history` | Revision history for a wiki page |
| `wiki_graph` | Entity and relationship traversal |
| `recall` | Per-prompt context injection (palace + wiki) |
| `session_digest` | End-of-session transcript digestion |

---

## Troubleshooting

**Import says "Path does not exist"**
The path must exist on the server where artisan runs, not your local machine. Copy files first with rsync/scp.

**"Permission denied for schema public"**
PG15+ issue. The database user needs schema privileges:
```sql
GRANT ALL ON SCHEMA public TO <user>;
```

**Embeddings failing**
Check `OPENAI_API_KEY` is set in the Forge `.env`. The embedding driver defaults to OpenAI text-embedding-3-small.

**Duplicate imports**
Safe to re-run — dedup via content hash skips already-imported content. Only changed files get new drawers.
