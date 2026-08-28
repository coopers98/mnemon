# Mnemon Release Roadmap

**Date:** 2026-08-28
**Supersedes:** `2026-08-27-release-roadmap.md` and `2026-08-27-mcp-surface-design.md`, both written against a stale snapshot and deleted.

## What happened, and why this document exists

A release-readiness review on 2026-08-27 was carried out against a local
checkout that was 68 commits behind `origin/main`. It produced a roadmap, an
MCP-surface design, and a nine-task implementation plan — all targeting the
bespoke MCP stack (`ApiKey`, `BaseTool`, `McpController`,
`AuthenticateApiKey`) that `5af9f87` had already deleted upstream. The branch
was closed unmerged (PR #12) and its findings re-verified against the real
codebase. This document is that re-verification.

The lesson is recorded because it was expensive: **fetch before reviewing.**

## Current architecture

- MCP server at `POST /mcp` (Streamable HTTP, JSON-RPC 2.0) built on
  `laravel/mcp`, with Resources, Prompts, and 14 tools.
- OAuth 2.1 via Passport. A single scope, `mcp:use`, gates everything —
  the four-scope model was collapsed in `db22be1` because `laravel/mcp`
  advertises only `mcp:use` in its DCR metadata.
- **Per-token wing restrictions (`mcp_token_restrictions`) are therefore the
  only per-agent isolation mechanism that exists.** There is no read-only
  scope to fall back on. Every finding below should be read in that light.
- Layer 2: Claude Code hooks (`mnemon-wake.sh`, `mnemon-recall.sh`,
  `mnemon-capture.sh`) driving `recall` and `session_digest`.

## Decisions that still stand

| Decision | Choice |
|---|---|
| Release shape | MIT-licensed, self-hosted open source. No multi-tenancy, no billing. |
| Agent write policy | Read everywhere, write narrowly. Wing restrictions are the enforcement point. |
| Wiki compilation | Agent-driven via MCP prompts (`SynthesizeWing`, `DrawerToWiki`, `FindStaleWikiPages`) — now shipped. No server-side LLM dependency. |

## Already resolved upstream — do not re-do

- **Wing restrictions are query filters, not parameter guards.**
  `DrawerSearchTool` passes `allowedWingPatterns` into `DrawerSearchService`
  regardless of whether a `wing` argument was supplied, and
  `PalaceWakeUpTool` filters via `whereHas('room.wing', …)`. This was the
  central defect of the abandoned branch.
- **`wiki_compile`'s tier promotion is guarded.**
  `WikiCompileTool:47` calls `requireWingAccess()` before the
  `raw → reviewed` promotion at `:75`.
- **A real MCP surface exists** — Streamable HTTP, tool schemas, Resources,
  Prompts, rate limiting (`throttle:mcp`), OAuth consent with wing selection.

## Live defects, verified 2026-08-28

### D7 — wing slugs still diverge by creation path

`app/Models/Wing.php:25` slugs a new wing with `Str::slug($wing->name)`,
which strips namespace colons: `project:atlas` becomes `projectatlas`.
`app/Mcp/Tools/WikiCompileTool.php:45` derives its lookup slug with
`Str::slug(str_replace(':', '-', $name))`, producing `project-atlas`.

The blast radius is narrower than it was — `DrawerWriteService::createDrawer()`
now takes a slug directly rather than slugifying a display name — but a wing
created through Filament or a seeder with a colon in its name is unreachable
by `wiki_compile`, and a restriction pattern cannot cover both spellings.

Fix: one canonical `Wing::slugify()` used by the model event and every
lookup, matching the dashed form the tools already assume.

### D8 — `context_set` mutates and probes with no wing check

`app/Mcp/Tools/ContextSetTool.php` has no wing enforcement at all — no
`requireWingAccess`, no `wingPatternsFor`.

- `:67` — `Drawer::whereIn('id', $sources)->count()` is an existence oracle
  over every wing; the resulting "source drawer IDs do not exist" error
  discloses whether an ID exists outside the token's wings.
- `:166` — `Drawer::whereIn('id', $sources)->update(['tier' => 'consolidated'])`
  promotes tiers across every wing, changing what tier-filtered searches
  return for other agents.

This matters more than it would have under the old model: with scopes
collapsed to `mcp:use`, wing restrictions are the *only* isolation, and this
tool ignores them. Fixing it needs a decision on whether unauthorised source
IDs should error or be silently dropped — silently dropping avoids the oracle,
erroring is friendlier to a legitimate caller.

### D4 — the audit trail still records only successes

`BrainSessionLogger::log(Request, string $tool, array $input, int $resultCount)`
has no outcome concept, `brain_sessions` has no `outcome` or `error` column,
and nothing anywhere writes a row for a denial. `RequiresWingAccess` and
`RequiresScope` return `Response::error(...)` without logging.

The rework spec's audit section (`2026-04-26-mcp-rework-design.md`) designed
the OAuth provenance columns but did not revisit this, so it is a genuine gap
rather than a deliberate omission. An agent probing tools it lacks wing access
for leaves no trace.

### Known limitation — wing restrictions are palace-layer only

`wiki_pages` has no wing association and `WikiPage` has no wing relation, so
any token with `mcp:use` reads every wiki page regardless of its restrictions:
`context_get` returns full page content, `context_list` enumerates every page,
and `palace_wake_up` / `brain_status` leak page names such as
`person:jane-doe`.

Wiki content is compiled palace content, so a token restricted to `work` can
read a synthesis of every `personal` drawer that has ever been compiled.

Closing this needs a `wing_id` on `wiki_pages` plus a compile-time
association — a schema change with a migration story for existing pages.
**Decision: documented as a known limitation for launch, not fixed.** It must
be stated plainly in the README rather than implied away.

## Findings from the 2026-08-28 session, already fixed

- **PHP floor was wrong.** `composer.json` declared `^8.3` and all three docs
  said "PHP 8.3+", but the lock has required 8.4 since Passport landed
  (`lcobucci/clock` needs `~8.4.0 || ~8.5.0`; `symfony/psr-http-message-bridge
  v8.0.8` needs `>=8.4`). A clean checkout at the documented minimum could not
  `composer install` at all. Raised to `^8.4` in `a49d719`.
- **`main` was red.** `WikiFrontendTest` asserted `'Revision History'` while
  the design-system pass restyled the heading to sentence case. Fixed in
  `6c4faf1`, treating the view as authoritative.

## Findings from the 2026-08-28 session, open

- **`passport:keys` is an undocumented hard prerequisite.** Without it,
  **140 tests fail** with "Invalid key supplied". A new contributor cloning
  the repo sees a catastrophically broken suite with no indication why. This
  belongs in the install story.
- **No PostgreSQL in CI.** `pdo_pgsql` is not installed in the development
  environment and the suite pins SQLite. The `to_tsvector` / `plainto_tsquery`
  fulltext path, the pgvector `<=>` operator, and every `varchar` length
  constraint are unexercised. SQLite is more permissive than PostgreSQL, so
  this class of defect is structurally invisible today.

## Sequence

| # | Piece | Contents | Gates |
|---|---|---|---|
| 1 | **Authorization & audit correctness** | D7, D8, D4. Small, security-relevant, no new dependencies. | Piece 4 |
| 2 | **Install story** | Docker Compose with pgvector; keyless default embedding driver; `passport:keys` documented or automated; **`pdo_pgsql` + a PostgreSQL CI service**. | Pieces 3, 4 |
| 3 | **Benchmark** | Finish the LongMemEval harness in `benchmark/` and publish a number. Note when publishing that it exercises the palace layer only, not the wiki. | Piece 4 |
| 4 | **Truth-up pass** | README and landing page against the shipped product; `LICENSE` added; docs pruned and restructured; repository made public. | Piece 6 |
| 5 | **Public demo** | Read-only demo instance. Requires a hardening pass — and no demo token may carry write access while D8 is open. | Piece 6 |
| 6 | **Launch** | Positioning, surfaces, timing. |

The MCP surface, which the superseded roadmap listed as its first piece, is
done. Piece 1 is what remains of that work.

## Gate on making the repository public

D7, D8 and D4 should land before the flip. None is an unauthenticated bypass —
all three require a valid `mcp:use` token — so this is a lower bar than the
one the superseded roadmap set, and it is a judgement rather than a hard rule.
The wiki limitation ships documented rather than fixed.

Two things are hard requirements regardless: a `LICENSE` file must exist
before the repository is public, and the GitHub OAuth token currently embedded
in the `origin` remote URL must be rotated.

## Documentation

`docs/` holds a mix of current material (`USERGUIDE.md`, `design_system.md`,
the MCP rework and Layer 2 specs) and superseded build artifacts
(`FRD.md`, `IMPLEMENTATION-PLAN.md`, `GAP-ANALYSIS.md`,
`WIKI-FRONTEND-PLAN.md`) that describe an architecture two rewrites out of
date. `discovery.md` is worth keeping and promoting — its competitive
landscape is the positioning argument.

The prune and restructure belong to Piece 4. No documentation site before
launch: README plus in-repo `docs/` is sufficient for a project with no users
yet.
