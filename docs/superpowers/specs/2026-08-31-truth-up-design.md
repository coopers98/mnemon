# Truth-up pass — design

**Status:** approved 2026-08-31, not yet implemented.
**Roadmap piece:** 4. Gates piece 6 (launch) and the repository going public.

## Goal

Make every public claim in this repository true, and reduce the public surface
to documents worth a stranger's time. This is the last piece before the
repository becomes visible, so it is the last chance to catch a claim that a
reader can disprove by reading the source.

## Why this piece is not cosmetic

An audit of the landing page — the most public artifact in the project —
found seven claims the code does not support:

| Claim | Reality |
|---|---|
| "BM25 over tsvector" | No BM25 anywhere. The score is `SUM(CASE WHEN to_tsvector(content) @@ plainto_tsquery(term) THEN 1 ELSE 0 END)` — a count of matched terms, with no term frequency and no inverse document frequency |
| "Reciprocal-rank fusion by default" | A weighted linear blend: semantic 0.6, fulltext 0.3, temporal 0.1, in `config/mnemon.php` |
| "BM25 · cosine · re-rank · attribution" | No re-ranker exists — and the same page separately claims "No black-box re-rankers" |
| "Tunable per-room" | The weights are global; nothing is per-room |
| "Outbound calls require an allow-list and live on the audit log" | No allow-list exists, and no embedding driver ever writes a `BrainSession` — outbound calls are not audited |
| "anything that returns a vector. Swap them; we re-index in the background" | D10: the Ollama driver cannot store an embedding at all. Re-indexing is a manual `mnemon:reembed` |
| "A dozen tools out of the box" | 14 |

Verified by grep: `bm25`, `reciprocal`, `rrf`, `allowlist`, `allow-list`,
`rerank` and `re-rank` occur **zero** times across `app/` and `config/`.

The BM25 claim has also propagated into `README.md:231`, `docs/discovery.md:21`
and `docs/GAP-ANALYSIS.md:18` — the last of which marks it "✅ Complete".

These are not stale version numbers. "BM25" and "reciprocal-rank fusion" are
specific, checkable technical claims that read as differentiators, and the
project's own pitch is "boring, knowable infrastructure — audit it on a Sunday
afternoon." A reader who accepts that invitation finds a term-match count and a
weighted sum. Overclaiming is worst precisely where the product invites
inspection.

The honest version is a stronger pitch anyway: PostgreSQL full-text joined to
pgvector cosine, with weights a reader can find in one config file, and no
black box anywhere. That is a real position. It is simply not the one currently
being made.

## Scope

**In:**

1. Correct every false claim on the landing page, keeping its voice and structure.
2. Correct the same claims wherever they appear in `README.md` and `docs/`.
3. Restructure the documentation tree for a public reader.
4. A claim-verification pass over the surviving public docs.

**Out:** the LLM-judged QA benchmark layer (its own plan, deferred), the five
open defects D10/D12/D13/D15/D16 (documented, not fixed here), and the act of
flipping the repository to public, which is the user's and is separately gated
on rotating the GitHub token in the `origin` remote.

## Decisions

**Landing page: correct the claims, keep the voice.** The treatise styling is
distinctive and stays. Only the false technical assertions change. Where a
claim was aspirational — a per-room tunable, an egress allow-list — it is
removed rather than softened into something unfalsifiable; "we may add X" on a
landing page is worth less than not mentioning X.

**Process docs: keep the specs, drop the plans.** `docs/superpowers/specs/*`
explain why the system is shaped as it is and move to `docs/design/`. The
implementation plans are step-by-step task lists with embedded code and
in-flight corrections; they are noise to a stranger and are preserved in git
history regardless.

**Historical documents are removed, not archived.** `IMPLEMENTATION-PLAN.md`,
`WIKI-FRONTEND-PLAN.md`, `GAP-ANALYSIS.md`, and `discovery.md` are dated April
2026, describe intentions rather than the system, and contain claims now known
false. Archiving them under a "historical" heading still ships documents
asserting Mnemon uses BM25; a reader who finds them has no way to know which
parts still hold. Git history preserves them for anyone who wants the
archaeology.

`FRD.md` is judged individually during implementation: if its requirements
still describe the shipped system it is updated and kept, and if it has drifted
it goes the same way as the others. That judgement needs the document in front
of the implementer, so it is not pre-decided here.

## The claim-verification pass

This is the deliverable that distinguishes the piece from a tidy-up, and it is
the part most likely to be skipped under time pressure.

Every factual claim in the surviving public documents gets checked against the
code, and the check is recorded. A claim is any statement a reader could
disprove: a count, a version, a command, an algorithm name, a capability, a
guarantee. For each one the implementer records the claim, the file and line,
the evidence, and the verdict.

Rules that follow from what this audit already found:

- **Algorithm names are claims.** "BM25", "reciprocal-rank fusion", "re-rank"
  are checkable and were all false. Any named technique must appear in the
  code or come out of the document.
- **Absolute words are claims.** "never", "every", "all", "no telemetry",
  "requires an allow-list" — each needs either evidence or removal.
- **Counts are claims.** Tool counts, test counts, and defect counts drift
  every time the code changes; each gets re-derived rather than copied.
- **A capability claimed for a driver must work on that driver.** The Ollama
  case is the standing example: it is offered as swappable and cannot store an
  embedding.

## Verification

The pass is only worth something if it can fail. For each corrected claim the
implementer records the command or file that establishes the truth, so the
audit can be re-run later rather than re-argued.

Two checks specifically:

- Grep the whole tracked tree for the terms this audit found false, and confirm
  each surviving occurrence is either accurate or explicitly describing another
  project. `README.md:231` describes MemPalace's approach and then says
  Mnemon rewrites it — the fix must not simply delete the word, because the
  attribution to prior work is worth keeping and is accurate about *them*.
- Confirm no document asserts a capability that the roadmap simultaneously
  records as an open defect. That contradiction is what makes a docs tree
  untrustworthy as a whole rather than wrong in one place.

## What "done" looks like

A public reader can read the landing page, `README.md`, `docs/USERGUIDE.md`,
`CONTRIBUTING.md` and `benchmark/README.md`, check any claim in them against
the source, and find it holds. The remaining tree is design rationale under
`docs/design/`, and nothing else.
