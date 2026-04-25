# Mnemon — Gap Analysis vs Karpathy LLM Wiki + MemPalace

**Date:** 2026-04-25
**Sources reviewed:**
- [Karpathy's LLM Wiki idea file](https://gist.github.com/karpathy/442a6bf555914893e9891c11519de94f) (April 2026, 5K+ stars)
- [LLM Wiki v2 — agentmemory extensions](https://gist.github.com/rohitg00/2067ab416f7bbe447c1977edaaa681e2) (April 2026)
- [MemPalace](https://github.com/mempalace/mempalace) — verbatim retrieval system (96.6% R@5 on LongMemEval)

---

## What Mnemon Already Covers

| Feature | Karpathy Equivalent | Status |
|---------|-------------------|--------|
| Verbatim storage (Palace) | raw/ layer | ✅ Complete |
| Wiki compilation layer | wiki/ layer | ✅ Complete |
| index.md + log.md auto-maintained | Special wiki files | ✅ Auto-updated by context_set |
| Hybrid search (vector + BM25 + temporal) | qmd / search engine | ✅ Complete |
| Orientation tools (brain_status, palace_wake_up) | — | ✅ Complete |
| API key auth + audit trail | — | ✅ Complete |
| Filament dashboard for browsing | Obsidian as frontend | ✅ Complete |
| Staleness detection | Lint (stale claims) | ✅ Partial (stale_wiki_pages in brain_status) |
| Import from files | Ingest workflow | ✅ Sprint 6 |
| Embedding driver system | — | ✅ OpenAI / Ollama / None |

---

## Tier 1 — Core Karpathy Features ✅ COMPLETE (2026-04-25)

### 1. `wiki_lint` MCP Tool
**Karpathy:** "Periodically, ask the LLM to health-check the wiki."
**v2:** "The lint operation should automatically fix what it can."

What it detects (implemented):
- **Stale pages** — pages with pending drawers since last compile, or not compiled recently
- **Orphan pages** — wiki pages not referenced in any other page's related array
- **Empty pages** — pages with very short content (<50 chars)
- **Low confidence** — pages marked with low confidence rating

Deferred detectors (require LLM calls, not yet implemented):
- **Contradictions** — claims that conflict between wiki pages
- **Missing concepts** — topics referenced in wiki pages but lacking their own page
- **Missing cross-references** — pages that should link to each other but don't

MCP interface:
```
wiki_lint(focus?: "stale" | "orphans" | "empty" | "low_confidence" | "all")
→ { findings: [{ type, severity, page, description, suggestion }] }
```

### 2. Cascade Awareness on Ingest
**Karpathy:** "A single source might touch 10-15 wiki pages."
**v2:** "On new source: auto-ingest, extract entities, update graph, update index."

Currently `drawer_add` and `context_set` are disconnected. Adding a drawer about Atlas doesn't flag the `project:atlas` wiki page that new info exists.

Implementation approach:
- When a drawer is added, check if any wiki pages exist for the drawer's wing
- If the wing has an associated wiki page, mark it as "has new content since last compile"
- Add a `new_drawers_since_compile` count or flag to wiki pages
- `palace_wake_up` and `brain_status` should surface pages with pending new content
- Optional: `wiki_compile` tool that re-synthesizes a wiki page from its wing's drawers

### 3. Source Citations on Wiki Pages
**Karpathy:** "Every claim in the wiki traces back to a file in raw/."
**v2:** "Source-level citations back to raw/."

Wiki pages currently have no link back to the drawers they were compiled from.

Implementation:
- Add `sources` JSON column to `wiki_pages` table (array of drawer IDs)
- When `context_set` is called, the caller can include `drawer_ids` in params
- `context_get` returns the source drawer IDs alongside content
- Filament WikiPage view shows linked drawers

### 4. Structured Metadata (Frontmatter) on Wiki Pages
**Karpathy:** "Every wiki page must have YAML frontmatter: title, type, sources, related, created, updated, confidence."

Current WikiPage model has `type` and `last_compiled_at` but lacks:
- `confidence` (high/medium/low)
- `sources` (drawer IDs that informed this page)
- `related` (other wiki page names this page references)
- `description` (one-line summary for index)

Implementation:
- Add migration: `confidence` (varchar nullable), `sources` (jsonb nullable), `related` (jsonb nullable)
- `description` already exists but isn't populated by import
- Update `context_set` to accept and store these fields
- Update `context_get` and `context_list` to return them

### 5. Filing Answers Back (Crystallization)
**Karpathy:** "Good answers can be filed back into the wiki as new pages. Knowledge compounds."
**v2:** "Crystallization — taking a completed chain of work and distilling it into a structured digest."

Currently there's no mechanism to promote a query answer into a permanent wiki page from within the MCP flow. The agent would need to manually call `context_set` after a search.

Implementation:
- This is largely a workflow pattern, not necessarily a new tool
- Document the pattern: search → synthesize → context_set
- Optional: `wiki_crystallize` tool that takes a topic, searches relevant drawers, and auto-generates a wiki page draft
- Could be powerful for periodic "compile what I know about X" workflows

---

## Tier 2 — Production Hardening (Next)

### 6. Confidence Scoring + Decay
Every fact carries confidence based on: source count, recency, contradiction status.
Confidence decays with time, strengthens with reinforcement.

### 7. Supersession
When new information contradicts existing claims, the old claim is explicitly marked stale and linked to its replacement. Version control for knowledge.

### 8. Consolidation Tiers
Working memory → episodic → semantic → procedural. A promotion pipeline from raw observations to established facts.

### 9. Automation Hooks
- On session start: load relevant context from wiki
- On session end: compress session into observations
- On schedule: periodic lint, consolidation, retention decay

### 10. Security Filtering on Ingest
Strip API keys, passwords, PII before storing. Currently import commands store content verbatim.

---

## Tier 3 — Scale & Advanced (Later)

### 11. Knowledge Graph with Typed Relationships
Entity extraction, typed edges (uses, depends-on, contradicts, caused), graph traversal for queries.

### 12. Quality Scoring
Self-evaluate LLM-generated wiki content before saving.

### 13. Self-Healing Lint
Lint auto-fixes what it can (broken links, orphan pages) not just reports.

### 14. Retention / Forgetting Curves
Access tracking, exponential decay. Architecture decisions decay slowly; transient bugs decay fast.

### 15. Multi-Agent Mesh Sync
Multiple agents writing to the same wiki with conflict resolution.

---

## Priority Recommendation

**Tier 1:** ✅ COMPLETE (2026-04-25) — wiki_lint, wiki_compile, cascade awareness, source citations, structured metadata.

**Next (Tier 2):** Confidence scoring, supersession, consolidation tiers, automation hooks, security filtering. These harden the wiki for production use and reduce manual maintenance.

**Later (Tier 3):** Knowledge graph, quality scoring, self-healing lint, retention curves, multi-agent sync. When the wiki grows past hundreds of pages.
