# Launch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Get Mnemon ready to launch as a portfolio piece:
- polish the repo;
- draft the essay and every launch post, where they are seen by no one except Cooper;
- tag v1.0.0.

**Architecture:** The work happens in two repositories.
- **Mnemon** gets only what serves someone who clones or evaluates it:
  - the backlinks fix;
  - the README screenshot and pitch;
  - the v1.0.0 release;
  - a link to the essay, added once the essay is published.
- **The private `coopers98/coopersellers.com` repo** gets the essay, the samwise brief and the post drafts, under `launch/mnemon/`. Its deploy publishes only `public/`, so `launch/` is never served.

**Tech Stack:** Laravel 13 / PHPUnit (Mnemon); plain Markdown (drafts); the `gh` CLI (release); Playwright's headless Chrome (screenshot).

**Spec:** `docs/design/2026-10-14-launch-design.md`

## Global Constraints

- **Claude drafts; Cooper posts.** Never post, submit, open a PR on a third-party repo, or publish a GitHub release without Cooper's explicit go-ahead in this session.
- **Ask before the first push to `coopers98/coopersellers.com`.** Any push to its `main` redeploys `public/` to Cloudflare Pages.
- **Ask before pushing Mnemon's `main`.** It deploys trymnemon.com and Cooper's personal install.
- **No launch drafts in the Mnemon repo.** No `docs/launch/`, and no Show HN or Reddit text, anywhere under `/root/projects/mnemon`.
- **Every factual claim in the essay and posts must already appear in `README.md` or `benchmark/`** as committed. No new numbers, and no rounding that changes a number's meaning.
- **Don't name Cooper's email address anywhere.** Point people to GitHub issues.
- **Writing style:**
  - Plain, first-person for Cooper's posts.
  - No hype words: "revolutionary", "game-changer", "blazing".
  - No emoji in the HN text.
- **Commits** go directly to `main` in both repos, ending with the line `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- **Before any push,** `php artisan test --compact` and `./vendor/bin/pint --test` must pass.

## Review Focus

1. **A page whose `related` is `null`, or a wiki where no page has `related`.** The byline must show "Backlinks 0" without errors. Pinned in Task 1.
2. **Page names containing `/` or `:`** (`wiki/index`, `project:alpha`). JSON encoding escapes `/` as `\/`, so a count done with a database `LIKE` would miss them. The count must compare decoded arrays. Pinned in Task 1.
3. **The Postgres CI job.** `related` is a `json` column (not `jsonb`), so a `LIKE` or JSON operator in SQL behaves differently from SQLite or errors. The count must not depend on SQL JSON operators. Covered by Task 1 running in both CI legs.
4. **Essay numbers drifting from the README's.** For example, writing "89%" where the README says hit_rate@1 0.886, or quoting the subset table as final. Task 4 ends with a line-by-line fact check against the README.
5. **The README screenshot showing stale or broken UI.** For example, captured before the phone-menu or paper-sheet fixes are deployed, or at a size where text is unreadable on GitHub. Task 2 captures from the live site after the deploy, at 1440×900, and the image is checked by eye.

---

### Task 1: Backlinks count

**Files:**
- Modify: `app/Http/Controllers/WikiController.php` (`show()`)
- Modify: `resources/views/wiki/show.blade.php:66`
- Test: `tests/Feature/WikiFrontendTest.php`

**Interfaces:**
- Produces: the view variable `$backlinkCount` (an int), passed from `WikiController::show`.

- [ ] **Step 1: Write the failing tests.** Append them to `WikiFrontendTest` (it already logs in `$this->user` in `setUp`):

```php
    // ---- Backlinks ----

    public function test_backlinks_count_pages_that_list_this_one_as_related(): void
    {
        WikiPage::factory()->create(['name' => 'project:alpha', 'related' => []]);
        WikiPage::factory()->create(['name' => 'person:ada', 'related' => ['project:alpha', 'concept:x']]);
        WikiPage::factory()->create(['name' => 'concept:x', 'related' => ['project:alpha']]);
        WikiPage::factory()->create(['name' => 'concept:y', 'related' => ['person:ada']]);

        $this->actingAs($this->user)->get(route('wiki.show', 'project:alpha'))
            ->assertOk()
            ->assertSee('<b>Backlinks</b> 2', false);
    }

    public function test_backlinks_ignore_self_reference_and_null_related(): void
    {
        WikiPage::factory()->create(['name' => 'wiki/index', 'related' => ['wiki/index']]);
        WikiPage::factory()->create(['name' => 'concept:z', 'related' => null]);

        $this->actingAs($this->user)->get(route('wiki.show', 'wiki/index'))
            ->assertOk()
            ->assertSee('<b>Backlinks</b> 0', false);
    }

    public function test_backlinks_match_names_containing_slashes(): void
    {
        WikiPage::factory()->create(['name' => 'wiki/index', 'related' => []]);
        WikiPage::factory()->create(['name' => 'wiki/log', 'related' => ['wiki/index']]);

        $this->actingAs($this->user)->get(route('wiki.show', 'wiki/index'))
            ->assertOk()
            ->assertSee('<b>Backlinks</b> 1', false);
    }
```

If `route('wiki.show', 'wiki/index')` doesn't resolve (the route parameter may not accept `/`), check `routes/web.php`. If the route really cannot serve slashed names, replace the slashed name with `synthesis:a/b`-style names that the route does accept, and say so in the report. The test must still exercise a `/` in a name that appears in `related`.

- [ ] **Step 2: Run the tests and confirm they fail.**

Run: `php artisan test --compact --filter=backlinks`
Expected: the first and third tests FAIL (the page shows "Backlinks 0"); the second passes.

- [ ] **Step 3: Implement it.** In `WikiController::show`, after `$relatedPages`:

```php
        // Backlinks: other pages whose `related` list names this one. Counted in
        // PHP because `related` is a json column, and SQL JSON matching differs
        // between SQLite and Postgres (and LIKE misses names json_encode escapes).
        $backlinkCount = WikiPage::where('name', '!=', $page->name)
            ->get(['related'])
            ->filter(fn (WikiPage $other) => in_array($page->name, $other->related ?? [], true))
            ->count();
```

Add `'backlinkCount'` to the `compact(...)` call. In `resources/views/wiki/show.blade.php`, change line 66 to:

```blade
        <span><b>Backlinks</b> {{ $backlinkCount }}</span>
```

- [ ] **Step 4: Run the tests and the full suite.**

Run: `php artisan test --compact --filter=backlinks`, then `php artisan test --compact`, then `./vendor/bin/pint --test`.
Expected: all pass. Fix any Pint finding with `./vendor/bin/pint` and re-run.

- [ ] **Step 5: Commit.**

```bash
git add app/Http/Controllers/WikiController.php resources/views/wiki/show.blade.php tests/Feature/WikiFrontendTest.php
git commit -m "fix: wiki pages count their backlinks instead of always showing 0

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 6: Push and verify the deploy.** This is a gate: ask Cooper first.
  - Push `main`. This carries 3fc35fe (the phone menu), the design and plan docs, and Task 1.
  - Wait for CI to pass on the pushed commit: `gh run list --branch main --limit 1`.
  - Confirm both Forge sites finished deploying it (Forge site IDs 3425882 and 3155099, via `$SP/forge.sh`).
  - Confirm the live page shows a non-zero count: `curl -s https://trymnemon.com/wiki/<a page other pages relate to> | grep -o '<b>Backlinks</b> [0-9]*'`.

---

### Task 2: README first screen

**Files:**
- Create: `docs/images/demo-wiki.png`
- Modify: `README.md` (lines 1–14 only)

**Interfaces:**
- Consumes: the live trymnemon.com, after Task 1's deploy.

- [ ] **Step 1: Capture the screenshot.**

```bash
B=/root/.cache/ms-playwright/chromium_headless_shell-1217/chrome-headless-shell-linux64/chrome-headless-shell
SP=/tmp/claude-0/-root-projects-mnemon/91bac7ad-a872-454d-a9c0-ef6acf995888/scratchpad
for path in wiki "palace" "wiki/project:tidepool"; do
  f=$(echo "$path" | tr '/:' '__')
  $B --no-sandbox --hide-scrollbars --window-size=1440,900 --screenshot=$SP/shot-$f.png "https://trymnemon.com/$path"
done
```

- `project:tidepool` is a guess. List real page names first with `curl -s https://trymnemon.com/wiki | grep -o 'href="https://trymnemon.com/wiki/[^"]*"' | head`, then substitute one.
- Look at each image with the Read tool. Pick the one that best shows "a compiled page with sources", probably a project page.
- Copy it to `docs/images/demo-wiki.png`. If it is over 400 KB, shrink it with `pngquant` or `optipng` if either is installed. If neither is installed, keep the original and note it in the report.

- [ ] **Step 2: Rewrite the pitch and add the image.** Replace the paragraph that starts `A self-hosted second brain for AI-augmented work.` and the `**Try it:**` line with:

```markdown
**One memory for every AI tool you use.** Mnemon is a self-hosted memory server. It keeps what you and your agents learn, verbatim, and serves it back to Claude, Cursor, ChatGPT or your own scripts over the [Model Context Protocol](https://modelcontextprotocol.io/). A compiled wiki sits on top, so what was learned stays readable instead of buried in raw notes.

**Try it:** a read-only demo with a sample brain is at **https://trymnemon.com**.

[![A compiled wiki page in the Mnemon demo](docs/images/demo-wiki.png)](https://trymnemon.com)
```

Leave the badges, the "Built on…" line and everything from `## Measured, not asserted` onward untouched.

- [ ] **Step 3: Check the render.**
  - Confirm the image path resolves: `test -f docs/images/demo-wiki.png`.
  - Confirm the Markdown has no broken link syntax: `grep -n "demo-wiki" README.md`.

- [ ] **Step 4: Commit.**

```bash
git add README.md docs/images/demo-wiki.png
git commit -m "docs: README opens with a one-line pitch and a demo screenshot

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Launch workspace and samwise brief (coopersellers.com repo)

**Files (in a clone of `coopers98/coopersellers.com`):**
- Create: `launch/mnemon/BRIEF.md`
- Create: `launch/mnemon/README.md`

**Interfaces:**
- Produces: the clone at `/root/projects/coopersellers.com`. Tasks 4 and 5 write into its `launch/mnemon/`.

- [ ] **Step 1: Clone, and confirm that `launch/` is not deployed.**

```bash
cd /root/projects && gh repo clone coopers98/coopersellers.com
grep -n "directory\|public" coopersellers.com/.github/workflows/deploy.yml
```

Expected: the deploy command publishes `public` only. If it publishes the repo root instead, STOP and tell Cooper, because drafts there would be served.

- [ ] **Step 2: Write `launch/mnemon/README.md`.**

```markdown
# Mnemon launch material — private, not deployed

Drafts for Mnemon's launch (design: https://github.com/coopers98/mnemon/blob/main/docs/design/2026-10-14-launch-design.md).
Nothing in `launch/` is served; the deploy publishes `public/` only.

| File | What |
|---|---|
| `BRIEF.md` | Instructions for publishing the essay on coopersellers.com |
| `essay.md` | The essay (source of truth) |
| `show-hn.md` | Show HN title + first comment |
| `reddit-*.md` | One post per subreddit |
| `directories.md` | MCP directory entries |
| `linkedin.md`, `x-thread.md` | Cooper's own channels |

Order: directories (days 1–3) → launch day: Show HN + essay + LinkedIn + X → Reddit, one every day or two: selfhosted, ClaudeAI, mcp, laravel.
```

- [ ] **Step 3: Write `launch/mnemon/BRIEF.md`.** It must stand alone for an agent with no other context. Content:

```markdown
# Brief: publish the Mnemon essay on coopersellers.com

You are working in the coopersellers.com repo: plain static HTML/CSS, no build
step. `public/` deploys to Cloudflare Pages on every push to `main`.

**Do not push until Cooper says the essay is final.**

## 1. Article page

- Create `public/writing/mnemon/index.html`, served at `/writing/mnemon`, unless
  Cooper names a different path.
- Render the content of `launch/mnemon/essay.md` as static HTML by hand. There
  is no Markdown pipeline, and none should be added.
- Use the site's existing `/styles.css` and the same header and footer pattern as
  `public/index.html`. Add only the minimal extra CSS an article needs (measure,
  headings, a table, code), in a `<style>` block or appended to `styles.css`. Do
  not restyle the one-pager.
- In `<head>`:
  - `<title>` set to the essay title followed by " — Cooper Sellers";
  - a `<meta name="description">` of one sentence;
  - `<link rel="canonical" href="https://coopersellers.com/writing/mnemon">`;
  - Open Graph `og:title`, `og:description`, `og:url`, and `og:type=article`.
- Check it with `python3 -m http.server 8080 --directory public` at desktop and
  at 375px width.

## 2. "Things I've built"

In `public/index.html`, the Mnemon entry currently links to
`https://mnemon.cooperhosting.net`. That is Cooper's private install, and
visitors hit a login. Change it to:
- **primary link:** https://trymnemon.com (the live demo);
- **secondary link:** https://github.com/coopers98/mnemon;
- **once the article exists:** a "write-up" link to `/writing/mnemon`.

Keep the entry's existing markup pattern.

## 3. Link from the one-pager

If the one-pager has no place for writing, add the article only through the
Mnemon entry's "write-up" link. Do not add a blog index for a single post.

## Done when

- `/writing/mnemon` renders correctly at desktop and mobile widths.
- The canonical tag points at itself.
- The Mnemon entry no longer links mnemon.cooperhosting.net.
- Cooper has approved the push.
```

- [ ] **Step 4: Commit locally. Do not push.**

```bash
cd /root/projects/coopersellers.com
git add launch/mnemon/README.md launch/mnemon/BRIEF.md
git commit -m "launch: Mnemon launch workspace and publishing brief

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: The essay

**Files:**
- Create: `/root/projects/coopersellers.com/launch/mnemon/essay.md`

**Interfaces:**
- Consumes:
  - `README.md`, `benchmark/README.md` and `docs/design/2026-08-28-release-roadmap.md` (the Piece 3 sections) in Mnemon, as the only sources for facts.
  - `docs/design/2026-10-14-launch-design.md`, Part 2, for the arc.

- [ ] **Step 1: Collect the facts.** Make a scratch list, in the scratchpad and not in either repo. For every number and claim the essay will use, give the exact source line (`README.md:NN`). At minimum:
  - the 500-question run and both legs;
  - hit_rate@1 0.742 → 0.886;
  - recall@5 0.832 → 0.952;
  - MRR 0.817 → 0.924;
  - QA accuracy 0.557 → 0.627, judged by gpt-4o at K=5;
  - $36.76 spent against a ~$37 estimate;
  - the 25-question subset that showed recall@10 as saturated;
  - the "three caveats";
  - palace-only scope;
  - the self-judged QA leg;
  - 14 MCP tools;
  - OAuth 2.1;
  - Docker quickstart;
  - keyless mode.

- [ ] **Step 2: Write the essay.**
  - **Length:** 1,200–1,800 words, in Cooper's first person.
  - **Title:** "Measuring my agent's memory instead of trusting it". A better title is fine if the arc earns it; note it in the report.
  - **Sections, in order:**
    1. **The problem:** each tool's memory is isolated, so one shared memory is the point.
    2. **The design:** the verbatim palace, the compiled wiki, and why the two are kept apart.
    3. **The measurement:** the full run, the misleading subset, corrections kept on the record, and the cost estimate that held.
    4. **What it does not do:** the scope caveats and the README's Limitations, briefly.
    5. **Try it:** the demo, the repo, `docker compose up`.
  - **Voice:** Cooper's site is plain and direct ("I like building things"). Match it, with short paragraphs.
  - **Format:**
    - one small table for the benchmark, at most;
    - no code blocks except a single quickstart command;
    - links to https://trymnemon.com and https://github.com/coopers98/mnemon.

- [ ] **Step 3: Fact check.** Re-read the essay sentence by sentence against the scratch list.
  - Every number must match its source exactly, or be a faithful rounding stated as such ("about 89%" for 0.886 is acceptable; "89.6%" is not).
  - Remove any claim with no source.
  - Write the check table (claim | source line | match) at the end of the report, not in the essay.

- [ ] **Step 4: Commit locally. Do not push.**

```bash
cd /root/projects/coopersellers.com
git add launch/mnemon/essay.md
git commit -m "launch: Mnemon essay draft

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: Post and directory drafts

**Files (all in `/root/projects/coopersellers.com/launch/mnemon/`):**
- Create: `show-hn.md`, `reddit-selfhosted.md`, `reddit-claudeai.md`, `reddit-mcp.md`, `reddit-laravel.md`, `directories.md`, `linkedin.md`, `x-thread.md`

**Interfaces:**
- Consumes:
  - the fact list from Task 4 Step 1;
  - the essay URL `https://coopersellers.com/writing/mnemon`. If Cooper picks a different path in Task 3, use that one.

- [ ] **Step 1: `show-hn.md`.**
  - **Title:** under 80 characters, in the form `Show HN: Mnemon – <what it is>`. For example: `Show HN: Mnemon – self-hosted memory server for AI agents, with a benchmark`.
  - **URL:** https://github.com/coopers98/mnemon
  - **Cooper's first comment, 150–300 words:**
    - what it is;
    - why he built it;
    - the benchmark in two lines, with the caveat;
    - the demo link;
    - the stack in one line;
    - the specific feedback wanted (retrieval design, the wiki-compile approach).
  - No marketing tone and no emoji.
  - Add a short "posting notes" section:
    - post Tue–Thu, 8–9am US Eastern;
    - stay for 3–4 hours;
    - don't ask for upvotes anywhere.

- [ ] **Step 2: Four Reddit drafts.** Each has a title, a body of 120–250 words, and a "check the sub's rules first" line naming that sub's self-promotion rule.
  - **r/selfhosted:**
    - Docker Compose;
    - your data on your box;
    - keyless mode needs no API key;
    - Postgres + pgvector.
  - **r/ClaudeAI:**
    - one memory across Claude Code, Claude.ai and others;
    - Layer 2 automatic capture (recall per prompt, end-of-session digest).
  - **r/mcp:**
    - 14 tools;
    - Streamable HTTP;
    - OAuth 2.1 with DCR;
    - per-token wing restrictions;
    - what was hard.
  - **r/laravel:**
    - Laravel 13 + `laravel/mcp` + Passport + Filament 5;
    - pgvector via raw statements;
    - SQLite tests with a Postgres CI leg;
    - lessons.

- [ ] **Step 3: `directories.md`.**
  - **awesome-mcp-servers** (github.com/punkpeye/awesome-mcp-servers): the one-line entry in that list's exact format. Read its current README and CONTRIBUTING with `gh api` or WebFetch to get the format and the right category, probably "Knowledge & Memory". Include the language and scope emoji legend values it requires.
  - **mcp.so**, plus one or two other active directories found by a quick search: a name, a 1-sentence description, a 3-sentence description, tags.
  - **The official MCP registry** (registry.modelcontextprotocol.io). Read its publishing docs. Write down whether a self-hosted server with no single public endpoint can be listed, and how (for example a `server.json` describing a remote with a placeholder URL, or a package). If it can't be listed, say so plainly and draft nothing for it.
  - Submit nothing. Cooper submits.

- [ ] **Step 4: `linkedin.md` and `x-thread.md`.**
  - **LinkedIn:** 150–250 words, framed as a portfolio piece: what Cooper built, the measured-not-asserted angle, and a link to the essay.
  - **X:** 4–6 posts, each under 280 characters. The first post stands alone. Links go to the essay and the repo.

- [ ] **Step 5: Fact check.** Run the same check as Task 4 Step 3 across all eight files.

- [ ] **Step 6: Commit locally. Do not push.**

```bash
cd /root/projects/coopersellers.com
git add launch/mnemon/
git commit -m "launch: Mnemon post and directory drafts

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: v1.0.0 release (gated)

**Files:** none in the repo. The release notes live in the GitHub release body.

- [ ] **Step 1: Draft the notes** in the scratchpad (`$SP/release-notes-v1.0.0.md`):
  - **What Mnemon is:** two sentences.
  - **What ships:**
    - palace and wiki;
    - 14 MCP tools;
    - OAuth 2.1 / DCR / device grant;
    - Layer 2 Claude Code hooks;
    - Filament admin;
    - the Docker quickstart;
    - keyless mode.
  - **The benchmark headline:** the four-row table from the README.
  - **Known limitations:** the README's Limitations, condensed and with nothing added.
  - **Links:** the demo, the quickstart, the docs.
  - Every item must exist in the README. Check `## What's built` and `## Limitations`.

- [ ] **Step 2: Show Cooper the notes and ask to push and release.** Only after a yes:

```bash
cd /root/projects/mnemon
git push origin main
gh run watch "$(gh run list --branch main --limit 1 --json databaseId --jq '.[0].databaseId')" --exit-status
git tag -a v1.0.0 -m "Mnemon v1.0.0"
git push origin v1.0.0
gh release create v1.0.0 --title "Mnemon v1.0.0" --notes-file "$SP/release-notes-v1.0.0.md" --latest
```

- [ ] **Step 3: Verify.**
  - `gh release view v1.0.0 --json tagName,isLatest,url`
  - Confirm the README image renders at `https://github.com/coopers98/mnemon` by fetching it and finding `demo-wiki.png`.

---

### Task 7: Link the essay from the README (after it is published)

This runs only once `https://coopersellers.com/writing/mnemon`, or the path Cooper chose, returns 200.

**Files:**
- Modify: `README.md` (the `**Try it:**` line from Task 2)

- [ ] **Step 1: Confirm the essay is live.**

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://coopersellers.com/writing/mnemon
```

Expected: `200`. If it isn't, stop here. This task waits.

- [ ] **Step 2: Add the link.** Change the Try-it line to:

```markdown
**Try it:** a read-only demo with a sample brain is at **https://trymnemon.com**. The story of how it was built and measured: [Measuring my agent's memory instead of trusting it](https://coopersellers.com/writing/mnemon).
```

Use the essay's final title if it changed.

- [ ] **Step 3: Commit, then ask before pushing.**

```bash
git add README.md
git commit -m "docs: README links the launch essay

Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

## Cooper's checklist (not tasks; nothing here is automated)

- Set the repo homepage to https://trymnemon.com (GitHub → repo → About → ⚙).
- Pin the repo on his GitHub profile.
- Run the FPM `pm.max_children = 4` recipe on atlas-abs.
- Cloudflare: SSL Full (strict), Always Use HTTPS, one rate-limit rule.
- Have the samwise agent follow `launch/mnemon/BRIEF.md`, then approve its push.
- Submit to the directories (days 1–3), then Show HN, LinkedIn and X on launch day, then Reddit over the next week.
