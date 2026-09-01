# Claim audit — truth-up pass

> **Dated audit, 2026-09-01.** This records every user-visible claim checked
> during the truth-up pass, the evidence it was checked against, and what
> happened to it. Unlike the rest of `docs/design/`, this document is meant to
> be **re-run**: every row names a command, a `file:line`, or a query a reader
> can execute to confirm the verdict independently.

## What this covers

The enumerated universe, walked surface by surface rather than by re-checking a
correction list — a list-driven audit finds only what the list already knows:

Blade views carrying user-visible prose (landing, palace, wiki, consent, auth,
error and mail templates); `README.md`; `docs/USERGUIDE.md`; `CONTRIBUTING.md`;
`benchmark/README.md`; `docs/OPENCLAW-INTEGRATION.md`; `AGENTS.md`;
`CLAUDE.md`; everything remaining under `public/`; `composer.json` and
`package.json` metadata; Filament navigation and column labels; artisan command
descriptions; and named routes.

## Summary

| Verdict | Rows |
|---|---:|
| Corrected | 56 |
| Removed (claim deleted, not reworded) | 10 |
| Confirmed true, left as written | 10 |
| **Total audit rows** | **76** |

Rows, not individual claims: several rows group instances deliberately. The
first row of §1 covers 11 separate falsehoods inside the deleted mock-ups; the
"Active Tokens" row covers three occurrences; the Filament and artisan rows
each cover a whole surface. Counted at the level this table records them, the
number is 72 — and that is the number this document stands behind, because a
larger one derived by expanding groups would not be independently checkable.

The corrections landed across seven commits, `0330e83` through `94bcfc7`.

## Rule applied

Every one of these is a claim, not decoration: algorithm and protocol names,
named endpoints and tool names, absolute words (*every*, *never*, *zero*,
*only*), counts, driver capabilities, references to fixed defects, and links.

---

## 1. The public web root

| Claim | Where | Evidence | Verdict |
|---|---|---|---|
| Four HTML mock-ups served publicly, one a frozen copy of the landing page carrying every claim this pass corrects | `public/sample_*.html`, `public/design_system.html` | `git grep -niE "bm25\|reciprocal.rank\|stdio\|Mnemon HQ" -- public/` returned 11 hits before deletion (7 in `sample_landing.html`, 3 in `design_system.html`, 1 in `sample_wiki.html`), 0 after | **Removed** |
| "No telemetry, no analytics, no phone-home" while every page `@import`ed Google Fonts | `public/styles/mnemon.css:6` | The `@import` is gone; `grep -rnE "fonts\.googleapis\|fonts\.gstatic\|cdn\.\|unpkg\|jsdelivr" public/styles/ resources/views/` returns nothing, and the rendered page returns 0 external references | **Corrected** — fonts self-hosted |
| Self-hosted OFL faces shipped without their licences | `public/fonts/` | `public/fonts/{eb-garamond,inter,jetbrains-mono}-OFL.txt`, each containing the SIL Open Font License 1.1 text | **Corrected** — licences added |

Note: the naive download was 1.9MB across 28 files, of which 1.15MB was
byte-identical duplicates — Google serves one variable file across several
weights. Deduplicated to 8 unique files (484KB); the 28 `@font-face` rules
point at them.

## 2. The wing-isolation guarantee — the highest-stakes correction

Ground truth established directly, not taken from the spec:

```bash
grep -rn "wing" database/migrations/*create_wiki_pages*   # no output: no wing column
grep -c "requireWingAccess" app/Mcp/Tools/ContextGetTool.php    # 0
grep -c "requireWingAccess" app/Mcp/Tools/ContextListTool.php   # 0
sed -n '18,34p' app/Services/RecallService.php   # wiki leg gets no wing patterns
```

| Claim | Where | Evidence | Verdict |
|---|---|---|---|
| A token "cannot read or write any other wing, **regardless of which tool is called**" | `docs/USERGUIDE.md:379` (was) | The final clause is exactly what is false — `wiki_pages` has no wing column | **Corrected** |
| "lose the work laptop, the attacker can't access `personal` content even if they extract the token" | `docs/USERGUIDE.md` | Anything compiled into the wiki is reachable by any token | **Corrected** |
| "a project-specific agent sees only its own wing" | `README.md:87` | Same defect | **Corrected** |
| "Choose which wings this agent can see" — shown at the moment of granting | `resources/views/mcp/authorize.blade.php:65` | Worst placement for an implication the wiki cannot honour; the screen now states the restriction is palace-only | **Corrected** |
| Wing restrictions provide per-agent isolation (unqualified) | `docs/OPENCLAW-INTEGRATION.md` | Same defect, in a doc that survives the restructure | **Corrected** |
| The limitation is stated naming all exposing channels | `README.md:280` | `context_get`, `context_list`, `palace_wake_up`, `brain_status`, **and `recall`** | **Corrected** — see below |

**`recall` is the channel the roadmap's own enumeration omits.** Its wiki leg
receives no wing patterns at all while its drawer leg receives both `wing` and
`allowedWingPatterns` (`RecallService.php:22-30`), and it runs automatically on
every prompt — so the exposure happens without an agent asking for it. The
README now lists five channels, not four.

## 3. The landing page

### Retrieval

| Claim | Where | Evidence | Verdict |
|---|---|---|---|
| "BM25 over tsvector" | `landing/index.blade.php:229` | `grep -rniE "bm25\|idf\|ts_rank" app/` returns nothing. The score counts matched terms; no term frequency, no IDF | **Corrected** |
| "Reciprocal-rank fusion by default" | `:485` | `PalaceSearchService.php:225-227` computes `semScore*0.6 + ftScore*0.3 + temporal*0.1` — a weighted linear sum. RRF would sum `1/(k+rank)` | **Corrected** |
| "re-rank" advertised in the spec sheet | `:327` | No re-ranker exists — and the same page separately boasted "No black-box re-rankers" | **Removed** |
| "Tunable per-room" | `:485` | Weights live in `config/mnemon.php` and are global; rooms scope a search, not the weights | **Corrected** |
| "Every result carries provenance to the verbatim row" | `:485` | Confirmed against a real captured response: results carry `id`, `wing`, `wing_slug`, `room`, `room_slug` | **True** |

### Architecture

| Claim | Where | Evidence | Verdict |
|---|---|---|---|
| "MCP · stdio + sse" | `:478` | Transport is Streamable HTTP at `POST /mcp` | **Corrected** |
| "A dozen tools": `recall`, `seal`, `compile`, `walk`, `cite` | `:479` | `MnemonServer.php:35-49` registers 14; only `recall` of those five exists | **Corrected** |
| "Composer-installable package. Drops cleanly into existing apps" | `:472` | `composer.json` is `type: project` | **Corrected** |
| "Uses the framework's queue, cache, jobs, and broadcasting" | `:472` | `routes/console.php` states in its own comment that nothing implements `ShouldQueue` | **Corrected** |
| "The wiki rebuilds itself each night" | prose block | Nothing rebuilds it server-side | **Removed** |
| "Issue an API key in the admin" | prose block | That stack was deleted; auth is OAuth | **Corrected** |
| "Embeddings are computed lazily — only when an agent actually asks" | prose block | `DrawerObserver` embeds eagerly and synchronously on write | **Corrected** |
| "`bge-m3` … anything that returns a vector" | `:491` | Only `openai` and `none` work; the Ollama driver cannot store 768-d vectors in a `vector(1536)` column | **Corrected** |
| Fig. 4: `POST /mcp/wiki.search`, response with a `"sealed": true` field | Fig. 4 | Not an endpoint; no wiki-search tool; that field exists nowhere | **Corrected** — replaced with a real JSON-RPC exchange captured from a live `drawer_search` call |

### Integrity and privacy

| Claim | Where | Evidence | Verdict |
|---|---|---|---|
| "byte-perfect" | `:224` | `ContentSanitizer::sanitize()` runs `preg_replace` over seven credential patterns before storage | **Corrected** |
| "sealed by content hash" | prose block | Drawers have no hash column — `grep -rn "hash" database/migrations/*create_drawers*` returns nothing | **Corrected** |
| "Zero egress by default" | `:494` | True only after the font work, and only on the Docker default: `.env.example` ships `MNEMON_EMBEDDING_DRIVER=openai`, which sends every drawer to OpenAI at write time | **Corrected** — now states which default |
| "Outbound calls require an allow-list and live on the audit log" | `:495` | Neither exists | **Removed** |
| "Three commands. One migration. One key." | `:508` | The native install is about ten commands, and there is no key to issue | **Corrected** |
| "We read every note" / "Replies arrive from a real person, not a queue" | contact section | False on a default install: `MNEMON_CONTACT_TO` is empty. The page ships to every self-hoster and cannot promise their readers a reply | **Corrected** |
| "© Mnemon HQ" | footer | Names an organisation that does not exist | **Removed** (and again in `wiki/index.blade.php`'s editor's note) |
| "v0.4 · primer" edition tag | `:113` | No versioning scheme behind it | **Removed** |
| Postgres "≥ 15" | `:465` | Compose and CI both run pg17; `docs/USERGUIDE.md` said 14+. Nothing below 17 is tested | **Corrected** — states what is tested |
| Adjectives replaced with a measured number | `:485` | LongMemEval-S, n=500: correct session ranked first for 443/500 with embeddings, 371/500 without | **Corrected** |

The measured number carries its sample size deliberately. Publishing a bare
ratio from the 25-question subset — whose Wilson 95% interval is [0.65, 0.94] —
while deleting "BM25" would have been a fresh overclaim of exactly the kind
this pass exists to remove.

## 4. README, USERGUIDE, benchmark and OpenClaw docs

| Claim | Where | Evidence | Verdict |
|---|---|---|---|
| "Self-healing maintenance … the system takes care of itself" | `README.md` | `AutoLintCommand` and `AutoCompileStaleCommand` only `$this->line(json_encode(...))`. `WikiLintAutoFixer` is reachable only from `WikiLintTool` | **Corrected** |
| "auto-lint … repairs stale, orphan, and low-confidence pages" | `README.md`, `docs/USERGUIDE.md` | Same | **Corrected** |
| "auto-compile-stale … recompiles wiki pages flagged as stale" | both | The command's own description says "output their names" | **Corrected** |
| auto-lint "will repair the wiki page (drop the broken citation, mark for recompile)" | `docs/USERGUIDE.md` | Doubly false: no detector checks drawer citations (they are stale, orphan, empty, low-confidence), and the fixer prunes page-to-page `related` refs | **Corrected** |
| Confidence decay and retention pruning act on the data | `README.md` | `routes/console.php` schedules `mnemon:decay-confidence` and `mnemon:apply-retention --force`, which do act | **True** — the correction is narrower than "none of it works" |
| "the MCP layer is read+append only — agents can't delete or modify existing drawers" | `README.md` | `ContextSetTool.php:184` runs `Drawer::whereIn('id', $sources)->update(['tier' => 'consolidated'])` | **Corrected** |
| Knowledge-graph entities "extracted from drawers and wiki pages" | `README.md` | `KnowledgeGraphService::extractEntity(WikiPage $page)` takes only a page | **Corrected** |
| "Deployed at [a placeholder domain]", asserted twice | `README.md:5`, `:243` | A placeholder presented as a live deployment | **Removed** |
| "full CRUD for … OAuth clients, and access tokens" | `README.md:100` | Contradicted by its own "view / revoke" a few lines later | **Corrected** |
| Test counts | `README.md:243` | Re-derived, not copied: 440 passing, 7 skipped on SQLite | **Corrected** |
| "comes back out exactly as it went in" / "The drawer you stored is the drawer you retrieve" | `README.md:56`, `:84` | Same sanitizer problem | **Corrected** |
| "switch the embedding driver to async via the queue… Run `php artisan queue:work`" | `docs/USERGUIDE.md` | No queue path exists; `grep -nE "ShouldQueue\|dispatch\|queue" app/Observers/DrawerObserver.php` returns nothing | **Corrected** |
| Legacy probe `curl .../api/mcp/tools` | `docs/USERGUIDE.md:111` | `php artisan route:list \| grep -c api/mcp` → 0 | **Removed** |
| "~50 drawers/sec" re-embed throughput | `docs/USERGUIDE.md:547` | `ReembedCommand` embeds one record per API round trip; a timed run measured ~2/sec (1,201 drawers, 10m18s) | **Corrected** |
| "the drop_api_keys migration in this rework" | `docs/USERGUIDE.md` | Internal process language in a public document | **Corrected** |
| `mnemon:auto-lint --dry-run` | `docs/USERGUIDE.md:588` | Ran it: *The "--dry-run" option does not exist.* | **Corrected** |
| Filament label "Access Control → Active Tokens" | `docs/USERGUIDE.md` ×3 | `OauthAccessTokenResource.php:23` → `'Access Tokens'` | **Corrected** |
| "pg_dump serializes vector columns as base64 blobs" | `docs/USERGUIDE.md:727` | pgvector dumps as text, in the bracketed literal form the type accepts | **Corrected** |
| Instructions to work around D17 "until it's fixed" | `benchmark/README.md:60-69` | The inverse of the usual drift — a document asserting a defect that no longer exists. `docker/entrypoint.sh:159-165` creates the personal access client on boot; fixed in `13b2ec2` | **Corrected** |
| A tool table reading as complete, listing 10 | `docs/OPENCLAW-INTEGRATION.md` | There are 14 | **Corrected** |
| Every artisan command referenced across the five documents | all | 24 distinct commands extracted and checked against `php artisan list` — all exist | **True** |

## 5. Authenticated views, admin strings, metadata

| Claim | Where | Evidence | Verdict |
|---|---|---|---|
| "The contents below are byte-perfect; nothing has been rewritten" — shown to a logged-in user viewing the actual record | `palace/drawer.blade.php:63` | Sanitizer redacts before storage | **Corrected** |
| "a sealed snapshot — a content hash that can be replayed against its source drawers" | `wiki/history.blade.php:31` | `ContextSetTool.php:192` stores `hash('sha256', $content)` — the page content. Not the drawers, and there is no replay | **Corrected** |
| "the vermilion mark denotes a *sealed* entry — its source drawers have all been hashed and are accounted for in the audit log" | `wiki/index.blade.php` | Drawers are not hashed. The mark means `last_compiled_at` is set | **Corrected** |
| sealed/unsealed as page state | `wiki/show.blade.php`, `wiki/index.blade.php` | Means compiled / not compiled | **Corrected** |
| "sealed" as a metaphor for access denied or downtime | `errors/403.blade.php`, `errors/419.blade.php`, `errors/503.blade.php` | Asserts no mechanism — "this room is sealed against your bearer" is 403, "even sealed wax has a half-life" is session expiry | **True** — left as written, deliberately |
| Filament navigation labels and column labels | `app/Filament/**` | Checked against what each resource does | **True** |
| Nine artisan command descriptions | `app/Console/Commands/*` | Checked against behaviour; `auto-lint`'s own description already said "output findings as JSON" | **True** — the commands were honest, the docs were not |
| `SyncOpenclawCommand`: "import memory, report new content, run lint" | `SyncOpenclawCommand.php:14` | Calls those three commands in order at lines 33, 38, 43 | **True** |
| Named routes | `php artisan route:list` | Accurate descriptors | **True** |
| `"name": "laravel/laravel"`, "The skeleton application for the Laravel framework." | `composer.json:3-5` | A first public release whose manifest says it is someone else's skeleton | **Corrected** |
| `package.json` metadata | `package.json` | `"private": true`, no name or description — asserts nothing | **True** |
| `public/robots.txt`, `.htaccess`, `index.php`, `favicon.ico` | `public/` | No prose claims | **True** |
| Contact submissions are stored but displayed nowhere | `ContactController` → `ContactSubmission` | No UI listed them; on a default install a visitor's note was seen by nobody | **Corrected** — read-only Filament resource added (the pass's one behaviour change), with four tests |

## 5b. Found by the audit, not by the correction list

These four survived every task in the plan and were caught only when this
document's own check 1 was run across the whole tree. They are the reason the
audit walks an enumerated universe instead of re-checking the correction list —
each is a duplicate of a falsehood corrected elsewhere, sitting on a surface
the plan did not enumerate.

| Claim | Where | Evidence | Verdict |
|---|---|---|---|
| "MCP · stdio + sse" — on the **public login page** | `auth/login.blade.php:91` | Same false transport corrected on the landing page in `772bc09` | **Corrected** |
| "BM25 over `tsvector` joined to `pgvector` cosine" — shown on every logged-in search | `wiki/search.blade.php:8` | Same false algorithm; no BM25 exists | **Corrected** |
| "© Mnemon HQ · MIT License" in the **shared footer partial** | `partials/footer.blade.php:4` | Included by `errors/layout.blade.php:119` and `layouts/wiki.blade.php:105`, so it rendered on every error and wiki page — the landing page's copy was corrected separately | **Corrected** |
| "© Mnemon HQ · MIT licensed" in **outgoing email** | `components/mail-layout.blade.php:68` | Same nonexistent organisation, in the one surface that leaves the server | **Corrected** |

The lesson generalises: the plan enumerated `landing`, `palace`, `wiki`,
`consent`, `auth` and `errors` views by name, and the falsehoods still hid in a
shared partial, a mail layout, a search page, and an aside on the login page.
A correction list cannot find its own blind spots.

## 6. Structure and links

| Claim | Where | Evidence | Verdict |
|---|---|---|---|
| A shared server's address, presented as the deployment target | two now-deleted documents | `git grep -nE '\b[0-9]{1,3}(\.[0-9]{1,3}){3}\b'` over the tracked tree returns only loopback, bind-all, and coincidental digit runs inside minified Filament vendor assets — each traced to its source | **Removed** — see the caveat below |
| Documents describing an architecture two rewrites out of date | `FRD.md`, `IMPLEMENTATION-PLAN.md`, `GAP-ANALYSIS.md`, `WIKI-FRONTEND-PLAN.md`, `discovery.md` | `FRD.md:41` was still asserting the wing-isolation guarantee corrected in `16d5803` | **Removed** |
| Roadmap: `discovery.md` is "worth keeping and promoting" | roadmap | This pass deletes it; the roadmap is reconciled rather than left contradicting the tree | **Corrected** |
| 10 broken relative markdown links | `AGENTS.md`, `README.md`, `docs/USERGUIDE.md` | The link checker in §"Re-running this audit" now reports `all relative links resolve` | **Corrected** |
| Referrers a Markdown link-checker cannot see | landing footer's FRD link, `CLAUDE.md`'s spec path, `design_system.md`'s file map | Each fixed by hand | **Corrected** |

## The caveat that outlives this audit

**Deleting the files removed the address from the tracked tree, not from git
history.** `git log -p` still contains it, and becomes readable by anyone the
moment the repository goes public. The plan's hard requirement 1 is written
against the tracked tree and is satisfied; the requirement as worded does not
achieve "the address is not public".

Removing it from history means rewriting every commit, which changes every SHA
and breaks the commit references in `docs/design/` — including several in this
file. That is a decision for the repository owner, tracked as requirement 6,
and is **open**.

## Re-running this audit

```bash
# 1. No falsehood vocabulary outside dated rationale.
# benchmark/ is excluded: `reciprocal_rank` there is a real MRR implementation,
# and its README discusses a superseded "embeddings only re-rank" conclusion.
git grep -niE "bm25|reciprocal.rank|rrf|re-rank|allow-list|allowlist|stdio|a dozen tools|byte-perfect|Mnemon HQ" \
  -- . ':!docs/design/' ':!*.lock' ':!benchmark/'
# Expected: one hit, README's description of MemPalace, which genuinely uses
# BM25. That is a true statement about another project, not a claim about this
# one. `mnemon.example.com` is deliberately not in this pattern: it is the
# RFC 2606 reserved documentation domain and is correct in command examples.
# What was wrong was asserting a *deployment* at it, which check 1b covers.

# 1b. No claimed live deployment
git grep -niE "deployed at" -- . ':!docs/design/' ':!*.lock'

# 2. No page reaches an external host
grep -rnE "https?://(fonts\.googleapis|fonts\.gstatic|cdn\.|unpkg|jsdelivr)" public/styles/ resources/views/

# 3. No routable address in the tracked tree.
# Note: a naive grep -v of loopback is NOT sufficient — minified vendor assets
# contain digit runs like 1.21.997.997 that look address-shaped but have octets
# over 255. Parse them properly.
git grep -ohE '\b[0-9]{1,3}(\.[0-9]{1,3}){3}\b' -- . ':!composer.lock' ':!package-lock.json' \
  | sort -u | python3 -c "
import sys, ipaddress
real = []
for line in sys.stdin:
    try: ip = ipaddress.IPv4Address(line.strip())
    except Exception: continue
    if not (ip.is_loopback or ip.is_unspecified or ip.is_private or ip.is_reserved):
        real.append(str(ip))
print(', '.join(real) if real else 'none — no routable address in the tracked tree')
"

# 4. Every relative markdown link resolves
python3 - <<'PY'
import re, subprocess, pathlib
bad = []
for f in subprocess.run(["git","ls-files","*.md"],capture_output=True,text=True).stdout.split():
    p = pathlib.Path(f)
    for m in re.finditer(r'\[[^\]]*\]\(([^)#][^)]*)\)', p.read_text()):
        t = m.group(1).split('#')[0].strip()
        if t.startswith(('http://','https://','mailto:')) or not t: continue
        if not (p.parent / t).resolve().exists(): bad.append(f"{f} -> {t}")
print("\n".join(bad) if bad else "all relative links resolve")
PY

# 5. Both suites, and the formatter
php artisan test --compact
cd benchmark && python3 -m unittest discover -s tests -t .
./vendor/bin/pint --test
```

Checks 1–3 are expected to produce no output beyond loopback and bind-all
addresses in check 3.

## What could not be verified here

- **The PostgreSQL-only paths.** The local suite pins SQLite. `to_tsvector` /
  `plainto_tsquery`, the pgvector `<=>` operator, and `varchar` length
  constraints are exercised only by the `tests (pgsql)` CI job, which passes.
- **Deploy keys on the repository.** The `gh` token available here returns 403
  on the deploy-keys endpoint, so that half of the deployment path is
  unverified.
- **The claim that this audit is complete.** It walks an enumerated universe,
  which is the best available defence against the failure mode it shares with
  the spec — fixing what is listed and missing what is not — but it is not a
  proof. Anything found later belongs in this table.
