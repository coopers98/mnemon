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

**Status: resolved.** `Wing::slugify()` is now the single definition, used by
the model creating event and by `wiki_compile`'s lookup.

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
tool ignored them.

**Status: resolved.** The existence check now counts only drawers the token may
see, so a forbidden drawer is indistinguishable from a missing one — the oracle
is closed while the existing error message is kept. The error-vs-drop question
resolved itself: computing existence over the visible set gives the friendly
error *and* no disclosure. The consolidation update relies on that check having
narrowed sources to visible drawers, which the code notes.

### D4 — the audit trail still records only successes

`BrainSessionLogger::log(Request, string $tool, array $input, int $resultCount)`
has no outcome concept, `brain_sessions` has no `outcome` or `error` column,
and nothing anywhere writes a row for a denial. `RequiresWingAccess` and
`RequiresScope` return `Response::error(...)` without logging.

The rework spec's audit section (`2026-04-26-mcp-rework-design.md`) designed
the OAuth provenance columns but did not revisit this, so it is a genuine gap
rather than a deliberate omission. An agent probing tools it lacks wing access
for leaves no trace.

**Status: resolved.** `brain_sessions` gained `outcome` (indexed, default
`success`) and a nullable `error`; `BrainSessionLogger::logDenial()` writes the
row before the error Response, reusing the same OAuth provenance rendering; both
guards call it. Only authenticated callers reach a tool guard, so this adds no
unauthenticated write path into the audit table.

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

### D10 — the Ollama embedding driver cannot store an embedding

`drawers.embedding` and `wiki_pages.embedding` are hard-coded `vector(1536)`
(`2026_04_24_100002:24`, `2026_04_24_100003:25`), while `nomic-embed-text`
declares 768 dimensions (`config/mnemon.php:14`). Postgres rejects a 768-d value
into that column, so under `MNEMON_EMBEDDING_DRIVER=ollama` every `drawer_add`
fails at save, `mnemon:reembed` fails on its first write, and every semantic
query fails on dimension mismatch. Nothing ever ALTERs the column.

`README.md:149-155` advertises Ollama as a supported driver. It is not — this is
a live defect, found while designing Piece 2.

Fixing it means either a `mnemon:reembed --resize` that recreates the column at
the active driver's dimension, or an untyped `vector` column with a dimension
check in application code. Both deserve their own design, so Piece 2 cuts the
Ollama compose profile and corrects the README rather than shipping an option
that hard-errors.

### D11 — full-text search has different semantics on each engine, and production is the strict one

**Status: resolved** (round 1 `e1c7b67`, round 2 below). Two follow-ups are
deliberately left open and called out at the end: PostgreSQL has no substring
match, and the unselective full-text query is still a sequential scan.

Found by the PostgreSQL CI leg on its first run, and reproduced against a local
`pgvector/pgvector:pg17` container.

`WikiSearchService` (and `PalaceSearchService` alongside it) take two different
paths. On SQLite it splits the query into words and ORs
`LOWER(content) LIKE '%word%'` per word — **any** word matching is a hit. On
PostgreSQL it uses `to_tsvector(...) @@ plainto_tsquery('english', ?)`, and
`plainto_tsquery` **ANDs** every surviving lexeme.

Verified directly:

```
plainto_tsquery('english','what do we know about dorothy')  ->  'know' & 'dorothi'
… @@ to_tsvector('Dorothy is the lead engineer on the Atlas project.')  ->  false
… plainto_tsquery('english','dorothy')                       ->  true
… websearch_to_tsquery('english','dorothy')                  ->  true
```

So a natural-language query returns **nothing** on PostgreSQL unless every
non-stop-word appears in the document, while the same query returns matches on
SQLite. This is not a test artifact: `recall` is the Layer 2 automatic-memory
tool driven by the Claude Code hooks, and conversational queries are precisely
its input. On the production driver it would silently surface no wiki context.

Three tests fail on PostgreSQL for this reason and pass on SQLite:
`RecallServiceTest::test_returns_mixed_payload_within_budget`,
`RecallToolTest` (same payload assertion), and
`SearchPageTest::test_results_are_sorted_by_score`.

(The third of those, `SearchPageTest`, exercises the **palace** path — the
Filament search page searches drawers — so `PalaceSearchService` had failing
coverage too, not just `WikiSearchService`.)

**Round 1 (`e1c7b67`) — matching.** Both engines were given the same
semantics, differing only in the match primitive: membership is OR across the
distinct query words, and the raw score is the number of distinct query words
present. On PostgreSQL that is one bound `plainto_tsquery` per word instead of
one for the whole query, summed in a CASE expression:

```sql
-- membership
(to_tsvector('english', content) @@ plainto_tsquery('english', ?)) OR (…) …
-- score
( CASE WHEN to_tsvector('english', content) @@ plainto_tsquery('english', ?)
       THEN 1 ELSE 0 END + … )
```

`ts_rank` was rejected: it ranks by lexeme frequency, not by how many query
words hit, so it does not reproduce the ordering the SQLite path defines.
`websearch_to_tsquery` was rejected because it also ANDs unquoted words. Every
word is a binding — nothing user-supplied reaches the SQL text.

**Round 1 fixed the matching and left `recall` still empty.** Round 1
normalized the score by the *total* word count, and `RecallService` filters the
wiki leg against `mnemon.recall.confidence_floor` — 0.45, with no
`MNEMON_RECALL_FLOOR` in `.env.example` to move it. A conversational prompt
scored 0.167–0.333 and was filtered out entirely, so `recall` returned
`found => true` with an empty `wiki` array: D11's original symptom, unchanged.
Measured on the container at floor 0.45, `what is the atlas project about` was
also a *regression* — it matched at 1.0 before `e1c7b67` and 0.333 after. No
test caught any of this because every `recall` test lowered the floor to 0.1 or
0.0.

**Round 2 — scoring, stop words, safety.** The denominator is now the number of
*searchable* terms, not the number of words typed. Stop words are dropped from
the query before it reaches SQL (`TokenizesSearchQueries`, holding PostgreSQL
17's own 127-entry `english.stop` list verbatim), so "what do we know about
dorothy" is scored as two terms, not six: a page that says "dorothy" scores 0.5
and clears the shipped floor. A query where every term hits still scores exactly
1.0, unchanged. Both services share the one tokenizer, so they cannot drift, and
`RecallServiceTest` now runs at the shipped floor with no config override.

A query that is *nothing but* stop words — a person named Will, an "IT" wing, a
project called "Down", or the degenerate "what do we" — has no dictionary form
at all and used to return nothing on PostgreSQL. It now falls through to the
substring primitive on both engines, so the two agree.

Two safety defects in the round-1 form were fixed at the same time: `%` and `_`
were interpolated into the LIKE pattern unescaped (a query of `%` returned every
row at score 1.0 on SQLite), and the word count reaching the engine was
unbounded (SQLite raises "Expression tree is too large" at 997 distinct words,
PostgreSQL "stack depth limit exceeded" at ~4,063 — and `recall` passes the raw
user prompt). Terms are now escaped with `ESCAPE '!'` — deliberately not a
backslash, which breaks PDO's placeholder scanner — and capped at 32, keeping
the longest words rather than an arbitrary prefix.

**The engines are not ordered by capability.** An earlier draft of this entry,
and of both service docblocks, claimed PostgreSQL was "strictly more capable".
It is not, and the trade runs in both directions:

```
PostgreSQL wins (stemming)      "engineers"  matches "engineer"
SQLite wins (substring)         auth      -> "authentication"
                                compiler  -> "WikiPageCompiler"
                                dorothy   -> "dorothy.vaughan@example.com"
                                1234      -> "ISSUE-1234"
```

PostgreSQL's `@@` matches whole lexemes, so none of the four SQLite cases match
there. Closing that gap on PostgreSQL — a trigram index, or matching substrings
alongside lexemes — is a design question of its own and is **open**.

**Indexing.** `to_tsvector('english', content)` had no index on either table, so
every full-text search was a sequential scan that evaluated `to_tsvector` once
per query term per row, on a path `recall` runs on every user prompt. A
functional GIN index on both tables (`2026_08_28_200000_add_fulltext_indexes`,
PostgreSQL-guarded) fixes the selective case. Measured with `EXPLAIN ANALYZE` on
50,000 rows, prompt `what do we know about dorothy`:

| | terms in SQL | no index | GIN index |
|---|---|---|---|
| before round 2 | 6 | 5,326 ms | 843 ms |
| after round 2 | 2 | 1,699 ms | **288 ms** |

End-to-end `recall` against 50,000 drawers and 50,000 wiki pages: 4,066 ms →
630 ms. Index size is ~4.5 MB per table at 50,000 rows.

The index does **not** help an unselective query — six content words matching
half the corpus stays at ~4,800 ms, because the planner correctly rejects a
BitmapOr covering half the table and the seq scan is dominated by evaluating
`to_tsvector` per row per term. A `tsvector` column
`GENERATED ALWAYS AS (to_tsvector('english', content)) STORED`, indexed instead
of the expression, takes that same query to **47 ms** (~100x) and the selective
one to 32 ms, because the vector is never recomputed. That is a schema change
with a table rewrite and roughly a doubling of text storage, so it is **open**
rather than done here.

### D12 — full-text search is a sequential scan on PostgreSQL

Surfaced while fixing D11 and measured against a live `pgvector/pgvector:pg17`
container at 50k rows. A functional GIN index on `to_tsvector('english', content)`
was added, and **the planner correctly declines it** for the unselective queries
`recall` issues — the scan stays around 4.8 s. A *stored generated* `tsvector`
column measures roughly **47 ms**, about 100x faster, because the expression is
materialised rather than recomputed per row.

That is a schema change (a generated column plus an index on it, and a decision
about whether the same applies to `drawers` and `wiki_pages` alike), so it is
recorded rather than rushed. Note this is **not** a regression introduced by
D11 — the pre-D11 query was also a seq scan at a comparable cost. But `recall`
runs on every user prompt through the Claude Code hooks, so it is the hot path
in the product.

### D13 — wiki and drawer confidences are not comparable

`RecallService` filters both legs against a single `confidence_floor`, but the
wiki leg's score is now an **absolute** fraction of searchable terms matched
while the drawer leg is **min-max normalised** so its top hit is always 1.0. The
code comments are accurate as of D11's fix, but one bar is being applied to two
different scales, so the floor is effectively stricter for wiki than for
drawers. Deciding whether both should be absolute, both relative, or separately
configured is a product question about what `confidence` is supposed to mean.

### D14 — the contact form emails a hardcoded personal address

`app/Http/Controllers/ContactController.php:24` is
`Mail::to('coopersellers@gmail.com')->send(...)`. It is the only occurrence and
there is no config key behind it. The contact form ships on the public landing
page (`resources/views/landing/index.blade.php:591`) in every install, so on an
MIT self-hosted release every operator's instance mails the project author's
personal address with their own visitors' submissions, the operator never learns
anyone contacted them, and the address sits in a public repository to be
scraped.

Submissions are not lost — `ContactController::store` writes a
`ContactSubmission` row *before* attempting the send and catches failures — so
this is a misdirection and disclosure problem, not a data-loss one.

Being fixed in install-story group 2, Task 7: a `MNEMON_CONTACT_TO` config key
defaulting to null, sending only when set, with no fallback recipient.

### D15 — a malformed `client_id` returns 500 instead of a 4xx

`GET /oauth/authorize?client_id=x` returns a 500. The value is cast to UUID
against `oauth_clients.id` and PostgreSQL raises on the malformed input before
any validation runs, so an unauthenticated request can trip a server error.
Pre-existing and unrelated to the Docker work; found while smoke-testing the
compose stack. Deliberately out of scope for group 2.

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
| ~~1~~ | ~~**Authorization & audit correctness**~~ | ~~D7, D8, D4.~~ **Done** — see the status blocks above. | — |
| 2 | **Install story** | Docker Compose with pgvector; keyless default embedding driver; `passport:keys` documented or automated; **`pdo_pgsql` + a PostgreSQL CI service**. | Pieces 3, 4 |
| 3 | **Benchmark** | Finish the LongMemEval harness in `benchmark/` and publish a number. Note when publishing that it exercises the palace layer only, not the wiki. | Piece 4 |
| 4 | **Truth-up pass** | README and landing page against the shipped product; `LICENSE` added; docs pruned and restructured; repository made public. | Piece 6 |
| 5 | **Public demo** | Read-only demo instance. Requires a hardening pass — and no demo token may carry write access while D8 is open. | Piece 6 |
| 6 | **Launch** | Positioning, surfaces, timing. |

The MCP surface, which the superseded roadmap listed as its first piece, is
done. Piece 1 is what remains of that work.

## Gate on making the repository public

D7, D8 and D4 have landed. The original condition was that they should land
before the flip; None is an unauthenticated bypass —
all three require a valid `mcp:use` token — so this is a lower bar than the
one the superseded roadmap set, and it is a judgement rather than a hard rule.
The wiki limitation ships documented rather than fixed.

Three things are hard requirements regardless: a `LICENSE` file must exist
before the repository is public, the GitHub OAuth token currently embedded in
the `origin` remote URL must be rotated, and D14 must land — publishing a
repository that mails every operator's contact submissions to a personal Gmail
address is not a defect to document, it is one to fix first.

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
