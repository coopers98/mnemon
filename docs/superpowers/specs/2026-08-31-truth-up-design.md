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
never granted. The exposed channels are `context_get`, `context_list`,
`palace_wake_up`, `brain_status`, **and `recall`** — the last matters most and
is missing from the roadmap's own enumeration: `RecallService` searches the
wiki with no wing patterns and returns full page content, and it is the
automatic per-prompt path, so the leak happens without an agent asking for it. The roadmap already knows this and already decided it "must be
stated plainly in the README rather than implied away"; the README's
Limitations section does not mention it and the USERGUIDE asserts the opposite.

This is the worst class of claim in the repository. A wrong algorithm name
costs credibility; a wrong isolation guarantee costs someone their data. It is
also the claim the first version of this spec would not have caught, because
its cross-check looked at numbered defects and this is filed as a "known
limitation".

**A production server's IP ships at HEAD.** `docs/FRD.md:6`,
`docs/IMPLEMENTATION-PLAN.md:11` and `:411` each carry the IPv4 address of a
shared Forge server, labelled as such. It is deliberately not quoted here: this
document is itself tracked and moves to `docs/design/` as a public file, and
the repository's own D14 lesson is that a disclosure defect must be described,
not reproduced. The first version of this spec slated
IMPLEMENTATION-PLAN for removal but left FRD "to implementer judgement" —
leaving a shared server's IP behind a judgement call made in flight.

**Git history publishes infrastructure and identity, and no document owns the
decision.** HEAD is clean — earlier passes fixed the tracked tree — but making the
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
produce something real to say: a reproducible LongMemEval retrieval table.
Measured numbers where the adjectives were is a better trade than either the
adjectives or silence.

**Which number ships, and how it is framed, is pinned here rather than left to
the implementer.** The 500-question run is in progress. If it has completed when
the landing page is edited, its figures ship. If it has not, the 25-question
subset figures ship — and either way the number carries its sample size and,
for the subset, the interval. The benchmark README's own instruction governs:
prefer "found evidence for 21 of 25 questions at rank 1" over three decimal
places, because the Wilson 95% interval on that figure is [0.65, 0.94]. A bare
"0.840 vs 0.960" headline at n=25 would be a fresh overclaim of exactly the
kind this pass exists to eliminate, and publishing one while deleting "BM25"
would be indefensible.

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
doc. `design_system.md` describes the landing page's visual language and is design
rationale: it moves to `docs/design/`. Note the first revision of this spec
claimed it was "referenced by no public document" — that was false, and caught
by review: `public/design_system.html` links it three times. Those links are
part of the link-integrity work, and that file is itself slated for removal
below.

**The git-history decision belongs to the user and is a hard gate.** The first
version of this spec briefed it on diff-content counts alone, which understated
it badly. Measured at HEAD:

- **Commit metadata, which no diff grep reaches.** 193 commits are authored by
  the author's personal email; 9 by `clawdbot@openclaw.ai`; and 23 authored /
  24 committed by `root@` the **current production VPS's fully-qualified
  hostname** — confirmed by `hostname -f` on this machine. Publishing history
  publishes that FQDN in metadata.
- **Diff content.** 16 commits touch the old deployment hostname, 3 the
  `forge@<ip>` SSH target, 5 the personal email — including the redaction
  commits themselves, whose removal diffs contain the strings.

Three options, with the trade-off stated rather than a recommendation smuggled
in as a default:

1. *Accept the exposure.* The email and old hostname are the author's own and
   arguably already public. The production VPS hostname in metadata is the part
   least likely to have been considered.
2. *Rewrite history* with `git-filter-repo`, including a **mailmap pass** for
   the metadata identities — a content-only filter leaves all three untouched.
3. *Squash to a fresh root commit.* Discards the development record, including
   the defect ledger that is arguably the most interesting thing here.

**Options 2 and 3 do not achieve their stated outcome on this repository.**
It has merged pull requests (#10, #14, #15) and the remote currently exposes 15
`refs/pull/*` refs. GitHub keeps those alive independently of branch history,
so a local rewrite force-pushed to the existing remote leaves every old commit
fetchable through the pull-request refs and the API. Achieving a genuinely
clean public surface requires **publishing to a fresh repository**, or asking
GitHub support to purge the refs. Anyone choosing option 2 or 3 without that
step gets the cost and not the benefit.

No implementation work in this piece touches history. The decision is recorded
here so that flipping public without making it is a visible omission rather
than an oversight.

## The public web root

`public/` holds four tracked HTML files — `sample_landing.html`,
`sample_palace.html`, `sample_wiki.html`, `design_system.html` — served
verbatim by the web server on every install. `sample_landing.html` is a frozen
copy of the landing page carrying the same falsehoods being corrected in the
Blade template: BM25, reciprocal-rank fusion, stdio transport, the allow-list
and telemetry claims, "Mnemon HQ".

Correcting `resources/views/landing/index.blade.php` while shipping an
uncorrected duplicate one URL away would defeat the entire pass. **These four
files are deleted.** They are design mock-ups that predate the real
implementation; nothing links to them from the application, and the live pages
supersede them.

**Every public page phones Google while the page declares it does not.**
`public/styles/mnemon.css:6` opens with an `@import` of
`fonts.googleapis.com`, and `resources/views/layouts/mnemon.blade.php:10` loads
that stylesheet on the landing, wiki and palace pages. The landing page's own
spec sheet says "Zero egress", and its footer says "No telemetry, no analytics,
no phone-home" — while rendering those words requires the visitor's browser to
call Google on every view.

This is the single easiest falsehood for the Sunday-afternoon auditor the page
invites to find, needing only DevTools. Either self-host the three families, or
drop the claim. Self-hosting is preferred: the claim is worth more than the
convenience, and it is the only option that makes the sentence true.

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

**More on README.md**: `:287` claims "the MCP layer is read+append only —
agents can't delete or modify existing drawers, by design". `ContextSetTool`
runs `Drawer::whereIn('id', $sources)->update(['tier' => 'consolidated'])` — an
MCP tool modifying existing drawers, and changing what tier-filtered searches
return.

**The contact section** promises "We read every note" and "Replies arrive from
a real person, not a queue". On a default install `MNEMON_CONTACT_TO` is empty
(the D14 fix) and there is **no Filament resource for `contact_submissions`** —
so a visitor's note lands in a table no UI displays and is mailed to nobody.
Either add the admin surface or say what actually happens. Separately, the
section ships the upstream author's GitHub identity and a "we" voice onto every
self-hoster's own landing page; it should address the operator's readers, not
this project's.

**`docs/USERGUIDE.md:591`** documents `php artisan mnemon:auto-lint --dry-run`.
`AutoLintCommand`'s signature has no such option and artisan throws.

**`composer.json`** still identifies the project as `"name": "laravel/laravel"`
with the description "The skeleton application for the Laravel framework." The
spec cites this file as evidence *against* the landing page's package claims;
its own metadata is a claim too, and a first public release whose manifest says
it is someone else's skeleton is a claim problem.

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
carry evidence a reader can re-run, and it must not quote a value it is telling
the project to remove.

**The universe of documents under audit is enumerated, because the first pass's
failure was scope rather than method.** It is: **every Blade view carrying user-visible prose** — not only the landing
page but the authenticated application, which repeats the same two claim
classes. `resources/views/palace/drawer.blade.php:63-64` says "The contents
below are byte-perfect; nothing has been rewritten", the same sentence being
deleted from the landing page and false for the same reason.
`resources/views/mcp/authorize.blade.php:65` says "Choose which wings this
agent can see" — the soft form of the isolation overpromise, shown at the exact
moment the user grants the token, which is the worst possible placement for it.
`resources/views/wiki/history.blade.php:31` describes "a sealed snapshot — a
content hash that can be replayed against its source drawers". Then:
`README.md`; `docs/USERGUIDE.md`;
`CONTRIBUTING.md`; `benchmark/README.md`; `docs/OPENCLAW-INTEGRATION.md`;
`AGENTS.md`; `CLAUDE.md`; everything remaining under `public/` after the
deletions above; `composer.json` and `package.json` metadata; and the
user-visible strings in the Filament admin panel, artisan command
descriptions, and route names. The specs moved to `docs/design/` are dated
design rationale and are **out** of the audit — but each gains a header stating
the date it describes and that it is not maintained as current documentation,
so a reader cannot mistake one for a description of today's system.

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

1. The shared-server IPv4 address currently in `docs/FRD.md` and
   `docs/IMPLEMENTATION-PLAN.md` appears nowhere in the tracked tree —
   including in this spec and in the claim audit.
2. The wiki wing-isolation limitation is stated plainly in `README.md`, naming
   every exposed channel including the automatic `recall` path, and the three
   false guarantees are corrected.
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
