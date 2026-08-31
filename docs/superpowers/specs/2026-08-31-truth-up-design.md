# Truth-up pass — design

**Status:** revised 2026-08-31 after an adversarial review found the first
version audited one page and generalised. Not yet implemented.
**Roadmap piece:** 4. Gates piece 6 (launch) and the repository going public.

## Goal

Make every public claim in this repository true, decide what the repository
publishes about its author's infrastructure, and reduce the public surface to
documents worth a stranger's time. This is the last gate before the repository
is visible.

## What the audit found

The first pass checked the landing page's spec-sheet cells and found seven
false claims. An adversarial review confirmed all seven and then found that
grep-driven checking had located every claim containing a searchable keyword
and missed every claim that did not. The real list is much longer, and three
findings outrank everything in the original scope.

### The seven confirmed false claims

| Claim | Reality |
|---|---|
| "BM25 over tsvector" | A count of distinct matched terms. No term frequency, no IDF |
| "Reciprocal-rank fusion by default" | Weighted linear blend, 0.6/0.3/0.1, `config/mnemon.php:34-38` |
| "BM25 · cosine · re-rank" | No re-ranker; the same page also boasts "No black-box re-rankers" |
| "Tunable per-room" | Rooms are a scoping filter; weights are global |
| "Outbound calls require an allow-list and live on the audit log" | No allow-list; `OpenAiDriver` writes `Log::error`, never a `BrainSession` |
| "Swap them; we re-index in the background" | `vector(1536)` is hard-coded in both migrations (D10); `mnemon:reembed` is a manual foreground command |
| "A dozen tools" | 14 |

`bm25`, `reciprocal`, `rrf`, `rerank`, `allowlist` occur **zero** times across
`app/` and `config/`.

### Three findings that outrank the original scope

**A false security guarantee.** `docs/USERGUIDE.md:380` states a token "cannot
read or write any other wing, **regardless of which tool is called**".
`USERGUIDE.md:429` attaches a threat model: "lose the work laptop, the attacker
can't access `personal` content even if they extract the token". `README.md:87`
says "a project-specific agent sees only its own wing".

All three are false. `wiki_pages` has no wing column, and `ContextGetTool` and
`ContextListTool` contain zero `requireWingAccess` calls — so a restricted
token can read any wiki page, including syntheses compiled from wings it was
never granted. The roadmap already knows this and already decided it "must be
stated plainly in the README rather than implied away"; the README's
Limitations section does not mention it and the USERGUIDE asserts the opposite.

This is the worst class of claim in the repository. A wrong algorithm name
costs credibility; a wrong isolation guarantee costs someone their data. It is
also the claim the first version of this spec would not have caught, because
its cross-check looked at numbered defects and this is filed as a "known
limitation".

**A production server's IP ships at HEAD.** `docs/FRD.md:6`,
`docs/IMPLEMENTATION-PLAN.md:11` and `:411` all carry
`198.51.100.10 (Forge, shared)`. The first version of this spec slated
IMPLEMENTATION-PLAN for removal but left FRD "to implementer judgement" —
leaving a shared server's IP behind a judgement call made in flight.

**Git history publishes infrastructure and identity, and no document owns the
decision.** Verified: the old deployment hostname appears in 14 commits, the
SSH target `forge@198.51.100.10` in 2, the author's personal email in 4. HEAD
is clean of all three — earlier passes fixed the tracked tree — but making the
repository public publishes the history with it, and `git log -p` is one
command. The roadmap's gate covers rotating the origin-remote token and
grepping the *tracked tree*; neither reaches this.

The first version of this spec argued that deleting historical documents was
safe because "git history preserves them". That argument cuts both ways and it
was only noticed pointing one direction.

## Why this matters more than tidying

The project's pitch is "boring, knowable infrastructure — audit it on a Sunday
afternoon." Overclaiming is least survivable exactly where you invite
inspection. A reader who takes that invitation and finds a fictional API
example, a fictional transport, and a security promise contradicted by the
schema does not conclude that one section is stale. They conclude the
documentation is decorative.

## Decisions

**Landing page: correct the claims, keep the voice.** The treatise styling
stays. Aspirational claims are removed rather than softened into something
unfalsifiable — "we may add an allow-list" is worth less than not mentioning
one.

**Positioning: replace deleted adjectives with measured numbers.** The
corrected spec sheet is genuinely more modest — term-count full-text, one
working embedding provider, no per-room tuning, no allow-list. Claiming the
honest version is "stronger" as a matter of course would be a rationalisation.
What makes it stronger in fact is that piece 3 was sequenced first precisely to
produce something real to say: a reproducible LongMemEval retrieval table
(keyless hit_rate@1 0.840, embedded 0.960, identical across two independent
ingests). Measured numbers where the adjectives were is a better trade than
either the adjectives or silence.

**Process docs: keep the specs, drop the plans** — with one exception. The
design specs move to `docs/design/`. The implementation plans are removed,
**except `2026-08-31-benchmark-qa-layer.md`**, which is not a historical
artifact but the design for deferred work the roadmap actively points at.
Dropping it would orphan those references.

**Historical documents are removed, not archived**, and that now includes
`FRD.md`. Leaving it to in-flight judgement was a dodge with a concrete cost:
it is the document carrying the server IP. Its content is half-updated — the
OAuth section is current while it still claims 12 tools, `mcp:serve` as the
primary interface, and Composer-package distribution — and mixed freshness is
worse than uniform staleness, because a reader cannot tell which half to trust.
`IMPLEMENTATION-PLAN.md`, `WIKI-FRONTEND-PLAN.md`, `GAP-ANALYSIS.md` and
`discovery.md` go with it.

This reverses the roadmap, which called `discovery.md` "worth keeping and
promoting — its competitive landscape is the positioning argument". The
reversal is deliberate: the README's "Why it exists" section absorbed that
content. The roadmap is updated in the same commit so the two do not disagree
in public.

**`OPENCLAW-INTEGRATION.md` and `design_system.md` are decided here, not
deferred.** `OPENCLAW-INTEGRATION.md` documents a live integration path and is
linked from the USERGUIDE: it stays, and is claim-checked like any other public
doc. `design_system.md` describes the landing page's visual language, is
referenced by no public document, and is design rationale: it moves to
`docs/design/`.

**The git-history decision belongs to the user and is a hard gate.** Three
options, with the trade-off stated rather than a recommendation smuggled in as
a default:

1. *Accept the exposure.* The hostname and email are the author's own and
   arguably already public. The SSH target names a shared Forge box by IP.
2. *Rewrite history* with `git-filter-repo` before flipping public. Every SHA
   changes, which is cheap now and expensive after anyone clones or forks.
3. *Squash to a fresh root commit.* Cleanest surface, discards the development
   record entirely — including the defect ledger that is arguably the most
   interesting thing about the project.

No implementation work in this piece touches history. The decision is recorded
here so that flipping public without making it is a visible omission rather
than an oversight.

## The correction list

Beyond the seven, the following were found and must be fixed. This list is the
floor, not the ceiling — the verification pass may find more.

**Landing page** (`resources/views/landing/index.blade.php`): the "stdio + sse"
transport (it is Streamable HTTP at `POST /mcp`, and `README.md:289` says so);
the tool names `seal`, `compile`, `walk`, `cite` (only `recall` exists); the
entire Fig. 4 sample exchange (`POST /mcp/wiki.search` is not an endpoint, and
the response shape and `"sealed": true` field exist nowhere); "Laravel · native
package" and "Composer-installable package" (`composer.json` is
`laravel/laravel`, `type: project` — an application); every queue, jobs and
broadcasting claim (`routes/console.php` states in its own comment that nothing
implements `ShouldQueue`); "The wiki rebuilds itself each night"; "Issue an API
key in the admin" (that stack was deleted); "Embeddings are computed lazily"
(`DrawerObserver` embeds eagerly and synchronously); "byte-perfect" and "sealed
by content hash" (`ContentSanitizer` deliberately rewrites content before
storage, and no hash column exists); "Zero egress by default" (`.env.example`
ships `MNEMON_EMBEDDING_DRIVER=openai`, so the *native* install sends every
drawer to OpenAI at write time — only the Docker path defaults to `none`);
"Three commands" (the native install needs six).

**README.md**: the self-healing maintenance story — `AutoCompileStaleCommand`
only prints candidate names and `AutoLintCommand` only outputs findings, so
"the system takes care of itself" is fiction; knowledge-graph entities
"extracted from drawers and wiki pages" (`extractEntity()` takes only a
`WikiPage`); "Deployed at mnemon.example.com" asserted twice — a placeholder
presented as a live deployment, residue of the de-personalisation pass; the
CRUD/revoke contradiction between `:100` and `:126`; the test counts, which
have drifted again.

**docs/USERGUIDE.md**: the same self-healing claims; the wing-isolation
guarantees above; "switch the embedding driver to async via the queue" offered
as configuration and contradicted sixteen lines later; the legacy
`/api/mcp/tools` probe against a deleted route; "~50 drawers/sec" reembed
throughput (the command embeds one record per round trip; the benchmark
measured ~2/sec); "the drop_api_keys migration in this rework" — internal
process language in a public document.

**benchmark/README.md**: the D17 workaround instruction, still telling readers
to run `passport:client --personal` "until it's fixed". D17 was fixed in
`13b2ec2`.

**Cross-cutting**: the Postgres floor disagrees three ways (landing says ≥15,
USERGUIDE says 14+, compose ships pg17); the `v0.4 · primer` edition tag has no
versioning scheme behind it; "© Mnemon HQ" names an organisation that does not
exist.

## The verification pass

Every factual claim in the surviving public documents is checked against the
code and the check is recorded. A claim is any statement a reader could
disprove.

The record lives at `docs/design/claim-audit.md` and **is committed**, which
makes it itself a public document subject to the same rules — every row must
carry evidence a reader can re-run.

Rules, extended after the first pass's failure mode:

- **Algorithm and protocol names are claims.** "BM25", "reciprocal-rank
  fusion", "stdio + sse". All were false. Any named technique or transport must
  appear in the code or leave the document.
- **Named endpoints, tools, and fields are claims.** The fictional
  `wiki.search` endpoint and the `seal`/`compile`/`walk`/`cite` tools were
  missed by keyword search because nothing flagged them as checkable. Every
  named identifier gets looked up.
- **Every code sample and command must execute against the shipped tree.** Not
  be plausible — execute.
- **Security and isolation promises are checked against the known-limitations
  list, not only against numbered defects.** The wiki wing gap is the standing
  example of what that distinction hides.
- **Absolute words are claims.** "never", "every", "no telemetry", "zero
  egress", "byte-perfect". Each needs evidence or removal.
- **Counts are claims**, re-derived rather than copied.
- **A capability claimed for a driver must work on that driver.** Ollama is the
  standing example.
- **Claims about fixed defects are as stale as claims about absent features.**
  The benchmark README's D17 workaround is the standing example of that
  inverse.
- **Links are claims.** Dead links are the most trivially checkable falsehood a
  repository can ship.
- **Claims about other projects need a citation or must be cut.** The Mem0 and
  MemPalace figures and the Karpathy references are unverifiable from this
  repository.

## Link integrity

The restructure moves and deletes documents that are linked from at least:
the landing page footer's FRD link, `AGENTS.md` (three links), `README.md`,
`docs/USERGUIDE.md` (including into `docs/superpowers/specs/`, which becomes
`docs/design/`), and `CLAUDE.md`. Every internal link in the surviving tree is
resolved after the move, and the check is part of the pass rather than a
follow-up.

## Hard requirements before the repository goes public

1. `198.51.100.10` appears nowhere in the tracked tree.
2. The wiki wing-isolation limitation is stated plainly in `README.md`, and the
   three false guarantees are corrected.
3. Every internal link resolves.
4. The claim audit is complete and committed.
5. The GitHub OAuth token in the `origin` remote is rotated (carried from the
   roadmap; the user's).
6. The git-history decision is made and recorded (the user's).

## What "done" looks like

A public reader can read the landing page, `README.md`, `docs/USERGUIDE.md`,
`CONTRIBUTING.md`, `benchmark/README.md` and `docs/OPENCLAW-INTEGRATION.md`,
check any claim against the source, and find it holds. Every internal link
resolves. `docs/design/` holds the design rationale and the claim audit. The
repository states its real limitations, including the one about wiki isolation
that it currently denies.
