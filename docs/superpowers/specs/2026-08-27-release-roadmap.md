# Mnemon Release Roadmap

**Date:** 2026-08-27
**Status:** Approved decomposition; individual pieces specced separately.

## Context

Mnemon is being prepared for its first public release. Today the repository is
private, has no `LICENSE` file, and is deployed as a single private instance at
mnemon.example.com. All development sprints are marked complete and the
test suite passes, but a release readiness pass surfaced defects that the test
suite does not cover and that the README actively misdescribes.

This document records the decisions taken, the defects verified against the
code, and the sequence of work to release.

## Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Release shape | **MIT-licensed, self-hosted open source** | No multi-tenancy, no billing, no hosted tier. Fastest path to released; monetisation deferred. |
| MCP transport | **Streamable HTTP with header auth now; OAuth post-launch** | Claude Code connects natively with `--header`. Claude Desktop connects via the `mcp-remote` stdio bridge until OAuth lands. |
| Agent write policy | **Read everywhere, write narrowly** | The risk is not agents reading the wrong wing — it is every ad-hoc session writing noise into the palace. Recommended agent key is read-only; writer keys are minted deliberately. |
| Wiki compilation | **Ship as an MCP prompt** | The user's own agent performs synthesis. No server-side LLM dependency, which would otherwise fight the keyless-install goal in Piece 2. A server-side compiler is post-launch roadmap. |

## Verified defects

Each of the following was confirmed by reading the code, not inferred. They are
the reason Piece 0 exists.

### D1 — Wing restrictions do not restrict (security)

`PalaceSearchService::baseQuery()` applies `where('wings.slug', $wing)` only when
`$wing !== null`. `DrawerSearchTool.php:49` calls `requireWingAccess()` under the
same condition. A key restricted to one wing that omits the `wing` parameter
searches the entire palace and receives full verbatim drawer content.

The same gap exists in `PalaceWakeUpTool` (returns previews of recent drawers
across all wings), `BrainStatusTool` (enumerates every wing), and
`ContextGetTool` (returns drawer previews via `source_details`, gated on scope
only). The wiki layer has no wing concept at all, so any key with `wiki:read`
reads every page regardless of restriction.

This matters more under MCP than under REST: with REST the caller writes the
parameters, so the gap is never exercised. Under MCP the model writes them, and
`wing` is optional.

`README.md:101` states the opposite — "a key restricted to `project:atlas` can
never see a drawer in `personal`". That is a false security claim.

### D2 — The wildcard restriction convention cannot match any real wing

`ApiKey::canAccessWing()` compiles `project:*` to `/^project:.*$/`. Wing slugs
are generated as `Str::slug(str_replace(':', '-', $name))`, so `project:atlas`
is stored as `project-atlas`. The regex can never match. `ApiKeyTest.php:74`
passes only because it asserts against slugs the system cannot produce.

The two call sites also disagree about which space the check runs in:
`DrawerAddTool.php:47` checks the slugified value; `DrawerSearchTool.php:50`
checks the raw client parameter and then queries `wings.slug` with it. With a
`project:*` restriction there is no input that both authorises and retrieves.

### D3 — A read-scoped key mutates data

`WikiCompileTool::requiredScope()` returns `palace:read`, then executes
`Drawer::whereIn($rawDrawerIds)->update(['tier' => 'reviewed'])`, changing
subsequent tier-filtered search results. `README.md:97` documents this tool's
scope as `wiki:write`. The "read-only agent key" policy is undermined until this
is resolved.

`WikiLintTool` writes conditionally via `auto_fix`, so it cannot be classified
as read-only either.

### D4 — The audit trail records only successes

`BaseTool::logSession()` is called at the end of each `execute()`, after every
throw path. Scope and wing denials throw before it; middleware rejections never
reach a tool; `McpController::call()` logs nothing on error. `README.md:45`
claims "every MCP tool invocation lands in `brain_sessions`" — false for exactly
the invocations an audit trail exists to capture.

### D5 — The wiki has no compiler

`AutoCompileStaleCommand` selects pages with pending drawers and `json_encode`s
their names to stdout. Its own `$description` reads "output their names". The
README's scheduled-task table says it "gathers drawers and recompiles wiki pages
flagged as stale". `wiki_compile` gathers source drawers; nothing synthesises
them into a page. The compounding-knowledge pillar currently works only because
a private OpenClaw agent runs the loop by hand.

Resolved by decision: ship compilation as an MCP prompt driven by the user's own
agent, and correct the README.

### D6 — Mnemon does not speak MCP

`POST /api/mcp/call` accepts `{"tool": ..., "params": {...}}` and returns
`{"result": ...}`. There is no JSON-RPC 2.0 envelope, no `initialize` handshake,
no `tools/list` or `tools/call`, and no Streamable HTTP. `GET /api/mcp/tools`
returns an array of names with no descriptions and no input schemas.

No MCP client can connect. OpenClaw, the only integrated agent, is not an MCP
client — it uses curl against the REST endpoint plus server-side artisan import
commands.

## Sequence

| # | Piece | Contents | Gates |
|---|---|---|---|
| 0 | **Authorization & audit correctness** | D1, D2, D3, D4. Restrictions become query filters rather than parameter guards; slug-space normalised at one boundary; read/write scopes reclassified; audit logging moved to the dispatch layer so denials are recorded. | Piece 1 and Piece 5 |
| 1 | **Real MCP surface** | Streamable HTTP via `laravel/mcp`; 13 tool schemas (the 12 existing tools plus a new `wiki_search`); server instructions; prompts, including `compile-stale` as the shipping form of wiki compilation; wiki pages as resources; payload caps; one unified error convention. Specced in `2026-08-27-mcp-surface-design.md`. | Everything downstream |
| 2 | **Install story** | Docker Compose with a pgvector image; keyless default embedding driver so a stranger can run `docker compose up` without an OpenAI key. Writing the user-facing install, configuration, and agent-connection docs belongs here — this is the piece where what a stranger actually needs becomes visible. | Piece 5 |
| 3 | **Benchmark** | Finish the LongMemEval harness in `benchmark/` and publish a number. Note when publishing that the harness exercises the palace layer only (`drawer_add`/`drawer_search`), not the wiki. | Piece 4 |
| 4 | **Truth-up pass** | README and landing page rewritten against the shipped product. `LICENSE` file added. Documentation pruned and restructured (see *Documentation* below). `CLAUDE.md` corrected. Repository made public, subject to the gate below. Lightweight positioning pass — one-liner and target reader — feeds the rewrite. | Piece 6 |
| 5 | **Public demo** | Read-only demo instance with real content. `/wiki` and `/palace` currently sit behind `auth` middleware despite the README claiming public read access. Requires a hardening pass: `GET /api/mcp/tools` is unauthenticated and no rate limiting is configured. | Piece 6 |
| 6 | **Launch plan** | Positioning, surfaces, timing. |

Piece 0 is first because its defects are security-relevant, independent of MCP,
and gate both the MCP surface and the public demo. It introduces no new
dependency and is testable against the existing suite.

## Gate on making the repository public

**Piece 0 must be merged before the repository is made public.**

This document describes an unpatched authentication bypass (D1, D2) in
file-and-line detail. Publishing it while those defects are unfixed hands an
exploit guide to anyone running Mnemon. The sequence already places Piece 0
first, so this costs nothing — it is recorded as an explicit gate rather than
left as a happy coincidence of ordering.

Once Piece 0 has landed, these specs describe fixed defects and become an honest
engineering record worth publishing.

### Credential hygiene, pre-launch

The `origin` remote currently embeds a GitHub OAuth token in the URL. It lives
in `.git/config` and is therefore not part of the repository contents — making
the repo public does not expose it — but it is a plaintext credential on disk.
Rotate it and move to SSH or a credential helper before launch.

The repository history itself is clean: 36 commits, `.env` never committed and
correctly gitignored, and the only key-shaped strings in history are deliberate
fake fixtures in `Tier2ProductionHardeningTest` for the `ContentSanitizer`
tests. No history rewriting is required.

## Documentation

### The problem

The repository has no user documentation. `docs/` holds six development
artifacts, all written across three days in April 2026 and untouched since. Each
was written to support building Mnemon; none helps a stranger install it,
connect an agent, or diagnose a failure. That work is currently done entirely by
a 238-line README that is wrong in several places.

For a self-hosted MIT project, install friction is the adoption funnel, so this
is a release blocker rather than a nicety.

There is a second problem. `IMPLEMENTATION-PLAN.md` announces that all sprints
are complete and `FRD.md` specifies a product that in part does not exist. A
visitor reads confident April-dated planning documents describing a finished
system, then finds that the wiki does not compile. Stale planning docs in a
public repository read as abandonment even when the project is active.

### Target structure

| Path | Audience | Contents |
|---|---|---|
| `README.md` | Everyone, thirty seconds | Pitch, benchmark number, quickstart, screenshot |
| `docs/` | Users | Install, configuration reference, connecting Claude Code and Claude Desktop, MCP tool reference, API keys and scopes, troubleshooting, upgrading |
| `docs/integrations/` | Users | `openclaw.md`, generalised |
| `CONTRIBUTING.md`, `docs/architecture.md` | Contributors | Palace/wiki model, embedding drivers, running tests, conventions |

### Dispositions

- **`discovery.md` — keep and promote to `docs/rationale.md`.** The one planning
  document worth publishing. Its competitive landscape and "What This Is Not"
  sections are the positioning argument — Mem0 versus MemPalace versus
  Karpathy's wiki, and why Mnemon takes all three. Feeds Piece 6.
- **`FRD.md`, `IMPLEMENTATION-PLAN.md`, `GAP-ANALYSIS.md`,
  `WIKI-FRONTEND-PLAN.md` — delete.** Not archived. They are superseded by
  shipped code and contradict it in places; git history preserves them.
  `WIKI-FRONTEND-PLAN.md` describes work that is finished.
- **`OPENCLAW-INTEGRATION.md` — keep, rewrite as a general integration guide.**
  Currently written as a personal runbook, complete with `ssh forge@<server>`
  and Forge deployment paths. As-is it reads as a document that escaped rather
  than one written for readers.
- **`CLAUDE.md` — correct.** It still states "MCP Tools — Planned for Sprint 2".
  The file instructing agents that work on this repository is out of date, which
  is a poor signal for a project whose subject is agent memory.
- **`docs/superpowers/specs/` — publish only after Piece 0.** See the gate above.

### Not doing

No documentation site before launch. README plus in-repository `docs/` is
sufficient for a project with no users yet; a site is work that competes with
the install story for the same attention and converts nothing until people
arrive.

## Deferred, named

- **OAuth for Claude Desktop.** Post-launch. `mcp-remote` is the documented
  interim path.
- **Server-side wiki compiler.** Post-launch roadmap item.
- **Export / egress.** A second brain whose only exit is `pg_dump` is an
  adoption obstacle. Not release-blocking; should be named publicly as roadmap.

## Open questions

- Should wiki pages carry a wing association, so wing restrictions can apply to
  the wiki layer? Currently they cannot. Piece 0 must at minimum make the
  behaviour explicit and documented, even if the answer is "wiki access is
  all-or-nothing per scope".
- Does `laravel/mcp` (v1.0.0-beta.1) support per-request tool registration? If
  so, `tools/list` can be filtered by key scope; if not, forbidden tools stay
  listed and their descriptions must state the scope they require.
