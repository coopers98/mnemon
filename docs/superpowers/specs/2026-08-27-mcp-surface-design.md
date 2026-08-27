# Design: Real MCP Surface

**Date:** 2026-08-27
**Piece:** 1 of the release roadmap (`2026-08-27-release-roadmap.md`)
**Depends on:** Piece 0 (authorization & audit correctness)

## Goal

Make the project's headline claim true: any MCP-aware client can connect to
Mnemon and use the palace and the wiki.

**Done when** `claude mcp add --transport http mnemon https://<host>/mcp --header
"X-API-Key: ..."` succeeds, `tools/list` returns thirteen tools with accurate
input schemas, wiki pages are readable as MCP resources, and a fresh Claude
session that has just connected calls `palace_wake_up` without being asked.

That last clause is the real acceptance criterion. A server that connects
cleanly and is then ignored has not delivered the purpose.

## Architecture

Three layers. The bottom one already exists and does not move.

```
Claude Code ──────http + header──────┐
                                     ├──→ laravel/mcp
Claude Desktop ──mcp-remote (stdio)──┘    (JSON-RPC 2.0: initialize,
                                           tools/list, tools/call,
                                           resources/*, prompts/*)
                                                    │
OpenClaw ────┐                                      ↓
benchmark ───┼────REST──→ McpController      13 adapters (~10 lines each)
curl users ──┘            (kept, unchanged)         │
                                     │              │
                                     └──────┬───────┘
                                            ↓
                          App\Mcp\Tools\* — execute(array, ApiKey): array
```

### Why adapters rather than a rewrite

The tool tests call `$tool->execute($params, $apiKey)` directly — 103 direct
invocations across `McpToolTest` (30), `Tier1KarpathyCoreTest` (37),
`Tier2ProductionHardeningTest` (21), `Tier3ScaleAdvancedTest` (12) and
`McpAuthTest` (3). Only `McpAuthTest` exercises HTTP, in 8 places.

Rewriting the tools onto `laravel/mcp`'s `Tool` base class would discard that
safety net during the exact change it protects, and would couple the domain
layer directly to a pre-1.0 package. The adapter layer confines the beta
dependency to roughly 130 disposable lines.

### Why the REST endpoint stays

`McpController` is 67 lines. The benchmark client, OpenClaw, and any curl user
depend on it. "Speaks MCP, or just curl it" is a feature for a self-hosted tool.
It is not deprecated.

## Components

### Unchanged

All twelve existing `App\Mcp\Tools\*`, `McpController`, `routes/api.php`.
`AuthenticateApiKey` is extended, not replaced.

### New — schema metadata on the domain tool

`BaseTool` gains `inputSchema(JsonSchema $schema): array` and
`description(): string`, implemented per tool.

The schema lives on the domain tool rather than the adapter so it sits adjacent
to the validation it describes. `DrawerSearchTool` currently validates `mode`
against `['semantic','fulltext','hybrid']` at line 39 and no client can discover
that constraint.

Descriptions are written as **usage policy, not parameter documentation**. A
description that says when to call a tool and when not to is what makes an agent
use it correctly; one that restates the parameter names is wasted.

### New — 13 adapters

`app/Mcp/Server/Tools/`, each extending `Laravel\Mcp\Server\Tool`. `schema()`
returns the domain schema; `handle()` resolves the `ApiKey` and delegates to
`execute()`. No logic.

### New — `wiki_search` tool

`WikiSearchService` already exists and powers `WikiController` and the Filament
search page, but is exposed to no agent. Today agents can read a wiki page by
exact name or list every page — there is no retrieval path into the compiled
layer at all, which makes "expose both layers over MCP" only half true.

This is the thirteenth tool. Scope: `wiki:read`.

### New — wiki pages as MCP resources

Resource template `mnemon://wiki/{name}`, `text/markdown`, plus the wiki index
as a listed resource.

Resources are not a duplicate of `context_get`. A resource can be @-mentioned by
the *user* in Claude Code and attached in Desktop, which puts a wiki page into
context without requiring the model to decide to call a tool. That is the
"browsable compiled knowledge" pillar delivered directly.

Drawers-as-resources are deferred — the palace is large, and drawer retrieval is
properly a search problem.

### New — server instructions and prompts

The `MnemonServer` `instructions` field tells a connecting agent to call
`palace_wake_up` at session start and to store drawers after decisions and
outcomes worth remembering.

Prompts (user-invocable, appearing as slash commands in Claude Code):

- **`wake-up`** — run the session-start orientation explicitly.
- **`compile-stale`** — the wiki compiler. Gathers stale pages and their pending
  drawers and instructs the user's own agent to synthesise and write back via
  `context_set`. This is the decided shipping form of wiki compilation; there is
  no server-side LLM dependency.

Instructions and prompts are as load-bearing as the schemas. MCP tools are
model-initiated: without a behavioural contract, a freshly connected agent has
no reason to consult the memory and will not.

### New — `MnemonServer`

Registered as `Mcp::web('/mcp', MnemonServer::class)` in `routes/ai.php`.

## Authentication and scoping

Middleware on the MCP route accepting both `X-API-Key` and `Authorization:
Bearer`. Bearer because most MCP servers use it and the README already promises
it; accepting both means the documentation stops being wrong either way.

Scope and wing enforcement stay inside `execute()`, so both transports enforce
identically and there is no second code path to keep in sync. Piece 0 changes
*how* wing restrictions are enforced (query filters rather than parameter
guards); this design assumes that work has landed.

**`tools/list` under a scoped key.** Tool existence is not a secret in an
open-source server, so the concern is not disclosure — it is that a read-only
key sees `drawer_add` and `context_set` listed, and an agent instructed to store
what it learns will call them, fail, and may conclude the server is broken.

Resolution, in order of preference:
1. If `laravel/mcp` supports per-request tool registration, filter the list by
   the key's scopes. **Verify before committing to this.**
2. Otherwise, keep the full list, state the required scope in each description,
   and make the denial message name the missing scope and say a writer key must
   be minted.

## Payload discipline

This is where the "leave the domain layer untouched" principle has to bend, and
the design says so explicitly rather than discovering it in production.

`DrawerSearchTool` returns full verbatim `content` for every hit. Drawers are
session transcripts; the benchmark caps a single drawer at 30,000 characters
(`benchmark/config.py:53`) and `config/mnemon.php` allows `limit` up to 20. A
worst-case response is roughly 600KB — on the order of 150,000 tokens in one
tool result. A routine `limit=5` over transcript drawers still costs tens of
thousands.

MCP pagination applies to `tools/list`, not to tool results. Only the
application layer can fix this.

- `drawer_search` gains a preview/full mode, defaulting to previews. Preview
  then `drawer_get` for the full text is the correct agent loop regardless.
- `palace_wake_up` caps its stale-page and pending-page lists, which are
  currently unbounded.
- `brain_status` caps its wing enumeration.
- `context_list` gains a limit and cursor.

## Error convention

Four tools currently return `['error' => ...]` as a *successful* result —
`ContextGetTool:34`, `WikiCompileTool:46`, `WikiGraphTool:34`,
`WikiHistoryTool:34` — while `DrawerGetTool:31` throws `McpException::notFound`.
Through adapters, drawer misses would become MCP `isError` results and wiki
misses would become successful JSON containing an error key, and every schema
for those four tools would have to describe a union result shape.

One convention: domain misses become `isError` tool results; JSON-RPC errors are
reserved for protocol violations. Verify what `laravel/mcp`'s `Response::error()`
actually emits before reusing the `McpController` mapping wholesale.

## Read/write classification

`#[IsReadOnly]` is a promise to clients, which may auto-approve annotated tools.
It must not be applied on the basis of a tool's name.

Piece 0 resolves `wiki_compile` (currently `palace:read` but mutates drawer
tiers) and `wiki_lint` (writes conditionally under `auto_fix`). This piece
applies the annotation only to tools that survive that reclassification.
`drawer_get` and `context_get` write access-tracking on read; that is acceptable
under a read-only hint as internal bookkeeping, but the decision is recorded
here rather than left implicit.

## Testing

- **All 103 domain-level tests stay as they are.** That is the point of the
  adapter approach.
- **Guard test:** every `McpToolRegistry` entry has an adapter, and each schema's
  `required` fields match what `execute()` actually rejects.
- **Guard test, enums and ranges:** the dangerous drift is not missing required
  fields but wrong constraints — `mode`, the `tier` whitelist, `max_depth` 1–5,
  `confidence` values. Note that `limit` is silently *clamped*, not rejected
  (`DrawerSearchTool.php:38`): a schema declaring `maximum: 20` describes an
  error that never occurs while the server quietly returns fewer results than
  asked. The clamp must be documented in the description.
- **Protocol tests:** `initialize`, `tools/list`, `tools/call`, `resources/list`,
  `resources/read`, `prompts/list`, `prompts/get`.
- **Payload tests:** assert the caps above actually bound response size.
- `McpAuthTest`'s 8 REST cases stay; parallel cases added for the MCP route.

## Dependencies and risks

**`laravel/mcp` is `v1.0.0-beta.1`** (released 2026-08-14; 43 versions
published). It declares Laravel 13 support and PHP ^8.2. First-party and moving
fast, but pre-1.0 — pin it exactly and expect at least one bump before launch.
The adapter layer is what makes that bump cheap.

**`mcp-remote` and Claude Desktop.** Desktop mangles spaces inside `args`, so
the header must be written `"Authorization:${AUTH_HEADER}"` with no space after
the colon, with the value supplied via `env`. This must appear verbatim in the
install documentation.

**Schema authoring is the bulk of the work.** `DrawerSearchTool` alone has six
parameters, two enums, a whitelist, and a silent clamp. Vague or wrong schemas
are worse than none, because agents will call the tools confidently and wrongly.

**Local stdio is foreclosed by header auth.** `Mcp::local()` has no HTTP headers
and therefore no key. For a self-hosted single-user product, stdio is a natural
transport. This design does not ship it. If it is wanted later, it needs a
documented synthetic full-access key for local mode.

## Out of scope

OAuth for Desktop (post-launch). Docker and the install story (Piece 2). README
and landing page rewrite (Piece 4). Drawers as resources. Subscriptions,
sampling, progress, and cancellation — nothing in these tools is long-running or
returns non-text content, and client support is thin.
