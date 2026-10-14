# Launch — design

**Date:** 2026-10-14
**Roadmap:** Piece 6 of `2026-08-28-release-roadmap.md` ("Positioning, surfaces, timing")
**Status:** draft, awaiting review

## Goal

Mnemon goes from "public repo with 0 stars" to a project someone evaluating
Cooper can open and take seriously: a polished repo, a live demo, a tagged
release, and an essay on coopersellers.com that tells the engineering story.
A first wave of GitHub stars from one well-placed launch is the secondary goal.

## Decisions already made

- **Primary purpose: portfolio.** The mix includes users and contributors, but
  nothing here is a sustained growth campaign. If Mnemon settles into being a
  well-presented pet project, that is an acceptable outcome.
- **Positioning: the engineering story, not the feature list.** The README already
  leads with it: retrieval measured on the full 500-question LongMemEval-S run,
  the 25-question subset that turned out to be misleading, the corrections
  published rather than overwritten, and $36.76 spent against a ~$37 estimate.
  Every surface leads with the same story, framed for that audience.
- **Sequence: staged (approach A).**
  1. Polish.
  2. Soft surfaces (directories).
  3. Launch day: Show HN, the essay, LinkedIn and X.
  4. Reddit, staggered over the following week.
- **Surfaces:** Show HN; Reddit (r/selfhosted, r/ClaudeAI, r/mcp, r/laravel);
  MCP directories; Cooper's own channels (coopersellers.com, LinkedIn, X).
- **Show HN links the repo**, not the essay. Stars come from the repo; the essay
  carries the portfolio value on Cooper's own channels.
- **Launch material stays out of the Mnemon repo.** The Mnemon repo is for people
  who clone or evaluate the software. Post drafts would be noise to them, and
  publishing those drafts early would tip the launch. Mnemon gets only what
  serves those people:
  - the polish fixes;
  - the README screenshot and pitch;
  - a link to the published essay;
  - the release notes, in the GitHub release body.
- **The essay, the post drafts and the samwise brief live in the private
  `coopers98/coopersellers.com` repo, under `launch/mnemon/`.** Two reasons:
  - **Nothing there is served.** That repo deploys only `public/`, to Cloudflare
    Pages.
  - **It is where the essay will be published.** The site is worked on from the
    samwise device, and an agent there finds everything in the repo it already
    works in.

  Any push to that repo's `main` triggers a redeploy of `public/`. That is
  harmless while `public/` is unchanged, but Claude asks before its first push
  there.
- **Claude drafts; Cooper posts.** Every post, PR to a third-party repo, and
  directory submission goes out under Cooper's own accounts, by Cooper. Claude
  never posts on his behalf.

## Part 1 — Polish (this repo)

These happen before anything is announced.

1. **Backlinks count.** `wiki/show.blade.php` prints
   `$page->backlinks_count ?? 0`, but nothing loads it, so every page says
   "Backlinks 0".
   - **Definition:** a page's backlinks are the other wiki pages whose `related`
     list contains its name.
   - **Implementation:** `WikiController::show` computes the count, and the
     view prints it.
   - **Tests:** cover zero, more than one, and a page that lists itself (which
     does not count). They must pass on both SQLite and PostgreSQL, since CI
     runs both.
2. **Push the phone-menu fix** (3fc35fe, already committed) together with the
   backlinks fix.
3. **README first screen.**
   - Add a screenshot of the demo, taken from trymnemon.com, committed under
     `docs/images/`, placed directly after the pitch paragraph.
   - Tighten the pitch so a cold reader knows what Mnemon is and why it is
     different within the first screen.
   - Link the essay once it is published.

   The benchmark section stays where it is.
4. **v1.0.0 release.**
   - A git tag `v1.0.0` and a GitHub release whose notes cover what ships, the
     benchmark headline, and the known limitations taken from the README's
     Limitations section (no new claims).
   - The notes are drafted in the release body. They are not kept as a file in
     the repo.
   - Tagging and publishing the release are outward-facing, so Claude asks
     before doing either.
5. **Cooper's items (Claude cannot do these):**
   - Set the repo homepage to https://trymnemon.com (it currently points at the
     repo itself).
   - Pin the repo on his GitHub profile.
   - Apply the FPM `pm.max_children = 4` recipe on atlas-abs.
   - In the Cloudflare dashboard: SSL Full (strict), Always Use HTTPS, and one
     rate-limit rule. Show HN traffic is the first real load the demo will see.

## Part 2 — The essay

- **File:** `launch/mnemon/essay.md` in the coopersellers.com repo.
- **Length:** 1,200–1,800 words, in Cooper's first person.
- **Working title:** "Measuring my agent's memory instead of trusting it".
- **Arc:**
  1. **The problem:** each AI tool has its own memory, or none, so one shared
     memory across all of them is the point.
  2. **The design:** a verbatim palace and a compiled wiki, and why the two
     layers are kept separate.
  3. **The measurement:**
     - the LongMemEval-S run;
     - the subset that misled;
     - the corrections kept on the record;
     - the cost estimate that held.
  4. **What it does not do:** the benchmark covers the palace, not the wiki;
     the QA leg is self-judged; the README's Limitations, briefly.
  5. **Try it:** the demo, the repo, the Docker quickstart.
- **Constraint:** every factual claim must match the README or `benchmark/` as
  committed. No numbers that are not already published there.

### The coopersellers.com brief

`launch/mnemon/BRIEF.md` in the coopersellers.com repo is a self-contained
instruction file for an agent on samwise. It tells that agent to:
- add an article page at a path Cooper confirms (default `/writing/mnemon`),
  rendering `launch/mnemon/essay.md` as static HTML under `public/`;
- match the site's existing styles;
- set `<link rel="canonical">` on that page to itself;
- in "Things I've built", point Mnemon at https://trymnemon.com and
  https://github.com/coopers98/mnemon instead of mnemon.cooperhosting.net (a
  login wall to visitors);
- not publish until Cooper says the essay is final.

## Part 3 — Posts and submissions

All drafts go in `launch/mnemon/` in the coopersellers.com repo, one file per surface. Each draft follows the
norms of the place it is posted.

| File | Surface | Framing |
|---|---|---|
| `show-hn.md` | Show HN | Title in the "Show HN: Mnemon – …" form, plus Cooper's first comment: what it is, why built, the benchmark in two lines, demo link, what feedback is wanted. |
| `reddit-selfhosted.md` | r/selfhosted | Docker Compose, your data on your box, keyless mode needs no API key. |
| `reddit-claudeai.md` | r/ClaudeAI | One memory shared across Claude Code, Claude.ai and other tools; Layer 2 automatic capture. |
| `reddit-mcp.md` | r/mcp | 14 tools over Streamable HTTP, OAuth 2.1 with DCR, per-token wing restrictions. |
| `reddit-laravel.md` | r/laravel | Laravel 13 + `laravel/mcp` + Passport + Filament 5, pgvector, what building MCP on Laravel was like. |
| `directories.md` | MCP directories | The awesome-mcp-servers entry line and its category, plus listing copy for mcp.so-style directories. The official MCP registry is included only if it fits a self-hosted server with no single public endpoint; the plan checks that rather than assuming it. |
| `linkedin.md` | LinkedIn | The portfolio framing, linking the essay. |
| `x-thread.md` | X | A 4–6 post thread, linking the essay and the repo. |

## Part 4 — Schedule

| When | What | Who |
|---|---|---|
| Day 0 | Polish merged and deployed; v1.0.0 released | Claude, with Cooper's go-ahead for the push and release |
| Day 0 | Homepage link, pin, FPM, Cloudflare | Cooper |
| Days 1–3 | Directory submissions | Cooper |
| Before launch | Essay published on coopersellers.com | samwise agent, after Cooper's sign-off |
| Launch day (Tue–Thu, about 8–9am US Eastern) | Show HN; LinkedIn and X posts linking the essay | Cooper |
| Launch day, first 3–4 hours | Answer HN comments | Cooper (Claude drafts replies on request) |
| Launch +1 to +7 days | One subreddit every day or two, in the order selfhosted, ClaudeAI, mcp, laravel | Cooper |

The launch date itself is Cooper's call. Nothing in this design fixes it.

## Out of scope

- Paid promotion, Product Hunt, newsletters, and outreach to individuals.
- New product features. Polish fixes only what a first-time visitor would
  notice.
- mnemon.cooperhosting.net, which is left alone (decided 2026-10-14).
- Building a blog system on coopersellers.com beyond the one article page.

## Success

- **Repo:** has a release, a screenshot, a correct homepage link, and a README
  that sells on its first screen.
- **Essay:** live on coopersellers.com, and Mnemon's entry in "Things I've built"
  goes somewhere a visitor can see.
- **Show HN:** posted, and the demo stays up through it.
- **Stars:** however many arrive is fine.
