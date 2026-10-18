# Changelog

Notable changes to Mnemon. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Releases are tagged
from v1.0.0; earlier entries are grouped by the date the work landed on `main`.
Plugin versions refer to the Claude Code plugin in `plugins/mnemon/`.

---

## [Unreleased]

### Fixed
- **A failed digest model call no longer loses the turns it was given.** A
  timeout, an OpenAI 429 or 5xx, a missing `OPENAI_API_KEY` and unparseable
  model output all came back as "no proposals", so `session_digest` recorded
  the slice as digested and answered success. The client moved past those
  turns, and a retry was served the empty replay. The tool now answers with a
  tool error that leads with `Retryable:` or `Not retryable:` and says the
  digest did not run; nothing is stored and the slice is not recorded, so the
  next Stop resends from the same start (through its own, later end) and it is
  digested normally. Failed digests appear in the audit trail with outcome
  `failed`. A model that finds nothing worth keeping is still a successful,
  empty digest.
- **A digest that fails part-way no longer duplicates drawers on retry.** The
  model's proposals are checked before use (text content, a numeric
  confidence), and the drawers and the slice record are written in one
  transaction, so a failure leaves nothing behind for the retry to repeat.
- **Mnemon's own recall and wake output is no longer digested back into the
  palace.** It reached the digest as hook output and attachments, and came back
  as new drawers. The plugin no longer sends it (see the capture change below),
  and the server now strips `<system-reminder>` spans and Mnemon recall/wake
  blocks itself, so devices still on plugin 0.3.10 stop feeding it back too.
- **Digest progress can no longer move backwards.** `last_digest_turn` is
  written as the larger of the stored and new values, whoever writes it, so a
  stale copy of the session state cannot rewind it and have turns digested
  twice. The digest worker's lock is now an atomic `mkdir` lock per session
  (replacing a PID file that two workers could both claim), recovered when its
  owner is gone or it is older than 10 minutes. Session-state writes apply
  their change to the state on disk, and a write that is not a JSON object is
  refused rather than resetting the session (which used to clear `@nomemo`).
  Plugin 0.3.11.
- **A reply that arrives while a digest runs is no longer lost.** Its Stop
  used to exit and leave the turns to "the next Stop", which never came if the
  user quit. The running digest now picks the range up when it finishes.
  Plugin 0.3.11.
- **A transcript record of an unexpected shape no longer stops capture for the
  session.** It made cleaning fail with no output, which read as "nothing to
  send"; the slice was never sent and nothing was logged. Such a record is now
  skipped, and a cleaning failure is logged. Plugin 0.3.11.
- **Messages typed while Claude was still working are digested.** Claude Code
  stores them as queued-command attachments, often with no user record, so
  they were dropped. Plugin 0.3.11.

### Changed
- **Capture now sends only the conversation.** Digests see the user's and the
  assistant's words and nothing else: no tool calls or tool output, no
  thinking, no attachments (system-prompt and environment snapshots,
  CLAUDE.md, attached and edited files), no hook output, no meta, compact-summary
  or sidechain records, and no harness wrappers such as `<system-reminder>` or
  slash-command output. The old filter matched nothing at the top level and let
  93.5% of transcript bytes through; measured on 1,319 real transcripts, what
  is sent is now 2.5% of the raw size. Each turn goes as
  `{"role","ts","text"}`, consecutive turns of one role merged, a slash
  command as `/name args`. A long message keeps its start and its end, marked
  where it was cut: 16,000 characters for the user's (after injected context is
  removed), 32,000 for the assistant's. Facts that
  appeared only in tool output (a command's result, a file's contents) are no
  longer seen by the digest. Secret redaction on the client now also covers
  GitHub (`ghp_`, `gho_`, `ghs_`, `github_pat_` …), Slack (`xoxb-`, `xoxp-` …),
  Aiven, `PASSWORD=`-style assignments, credentials in URLs and PEM private
  keys. Plugin 0.3.11.
- **Secret assignments are redacted in the forms they actually take**, on the
  plugin and the server alike: any case (`password=`), a flag
  (`--password=`), a suffix after the name (`AWS_SECRET_ACCESS_KEY=`,
  `SECRET_KEY_BASE=`), a prefix glued to it (`PGPASSWORD=`), the names
  `TOKEN`, `ACCESS_KEY`, `PWD` and `PASSWD` (`GITHUB_TOKEN=`, `MYSQL_PWD=`),
  and JSON or YAML keys (`"password": "…"`, `api_key: …`, `token: …`).
  `NOSECRET=`, a bare `PWD=` (the working directory) and `PASSWORD==x` are
  left alone. On the server, a redacted value no longer runs on past the end
  of its JSON string and swallows the rest of the transcript up to the next
  space. Plugin 0.3.11.
- **Long stretches are digested in chunks, oldest first, and none of them is
  dropped.** Each digest sends the oldest part of what is undigested, up to
  `digest_chunk_chars` (60,000), and advances only to the transcript line that
  part ends on; the rest is carried forward and sent next, by the same worker
  (up to ten chunks a run) or the next Stop. The raw slice read per pass is
  bounded by `max_digest_bytes`, now 2 MiB (was 256 KiB), and also keeps the
  oldest lines: before, both caps kept the newest turns and the older ones
  were never digested. Chunk boundaries depend only on the transcript, the
  pointer and the chunk size, so a retry resends exactly the same range.
  Plugin 0.3.11.
- **Repeated digest failures back off.** One failure is retried on the next
  Stop; after that the wait starts at 60 seconds and doubles to 30 minutes
  (`digest_backoff_base_seconds`, `digest_backoff_seconds`). A failure the
  server reports as not retryable (a missing API key) waits the full 30
  minutes at once. A one-line chunk that fails three times in a row for
  something it may itself cause -- the server rejects it (HTTP 413, a
  validation error) or the model's answer to it is not a proposals list -- is
  skipped and logged after the third attempt, so one bad line cannot hold up
  the rest. A network failure, a 401, a 429 or 5xx, a model timeout or a "not
  retryable" answer never counts towards that: an outage does not cost a
  line. The kind of the last failure is kept in the session state
  (`digest_failed_kind`). A digest that finds no credentials when it runs
  keeps its range as pending, so an ended session still counts as
  undigested. A Stop inside the wait records its range, so the turns are sent by a later Stop -- for a
  session that has ended, when it is resumed. SessionStart says how many
  sessions have turns undigested after failures (only those with work left,
  a transcript that still exists and no `@nomemo`), and clears what can never
  be retried; `/mnemon:doctor` reports the same count. A success resets it.
  The client waits 90 seconds for a digest, above the server's 60.
  Plugin 0.3.11.
- **A digest whose reply was lost is not digested again.** Plugin 0.3.11
  sends `replay: "by_start"` with each digest; the server then recognises a
  range it already stored by its start, whatever end the retry asks for, and
  says where the stored range ended (`turn_end`), and the plugin advances there
  and sends the rest. Without that argument -- plugin 0.3.10 and older, which
  advance to the end they sent whatever the reply says -- only the exact same
  range is replayed, as in 1.0.0: a retry with a later end is digested again,
  which can repeat drawers but never skips turns.
- **Turns are no longer digested twice when two digests overlap.** A digest
  that starts after another has finished now sends from where that one left
  off, rather than from where its own Stop read the pointer. Plugin 0.3.11.
- **A digest's embeddings are computed after it commits.** They were an HTTP
  call per drawer inside the digest's transaction, holding its locks; a slow
  embedding API could keep a digest open past the client's timeout. Embedding
  calls now time out after 10 seconds (`MNEMON_EMBEDDING_TIMEOUT`). Any server
  failure while storing a digest is audited and answered as a retryable tool
  error, and a digest that loses a race to another for the same range leaves
  nothing behind.
- **The server's secret redaction matches the plugin's.** Token prefixes in
  prose, comparisons such as `PASSWORD==x`, names such as `NOSECRET=`, a bare
  `//host:8080@` and a PEM header mentioned without a key are no longer
  redacted; AWS key ids, JWTs, GitHub fine-grained and Slack tokens, PEM
  private keys (plain, encrypted with Proc-Type/DEK-Info headers, JSON-escaped with a
  literal `\n`, or cut off with no END line), and `scheme://user:password@` (digits-only
  passwords included) are. A pattern that fails keeps the text rather than
  storing it empty. Drawers are de-duplicated by a hash of their redacted
  content, so text redacted differently now hashes differently: a drawer
  stored before this change is not matched by an identical one stored after.

## [1.0.0] — 2026-10-18

### Added
- **`/mnemon:doctor`** (`plugins/mnemon/scripts/mnemon-doctor.sh`) says
  whether this device's connection works and, if not, why not: tools, plugin
  version, config and credential source, token lifetime, reachability (DNS,
  connection and TLS failures named apart from an HTTP answer), a real
  `palace_wake_up` call listing the reachable wings, and the last day's
  errors. One `ok` / `warn` / `FAIL` line per check, each problem with its fix;
  exits 0 iff nothing failed; never prints a token or secret. Runs on Linux,
  macOS and Git Bash on Windows. The "not connected" and "authorization is
  dead" session-start banners now point at it. Plugin 0.3.10.
- **Plugin auto-update is turned on by the setup script.** Claude Code leaves
  auto-update off for third-party marketplaces, so a device stayed on the
  version it installed until someone ran `claude plugin update`. `/install` now
  merges `autoUpdate: true` into the marketplace's `extraKnownMarketplaces`
  entry in `~/.claude/settings.json`. It backs the file up first, writes the new
  one atomically, leaves a file that is not plain JSON untouched, and can be
  skipped with `MNEMON_NO_AUTOUPDATE=1`. `/mnemon:doctor` reports the effective
  setting and where it comes from, along with any environment variable that
  disables it. Plugin 0.3.10.
- **A public, read-only demo** (`MNEMON_DEMO=true`). The app serves only GET
  and HEAD requests for the landing page, `/how-it-works`, `/wiki*`, `/palace*`
  and `/up`; everything else, including `/admin`, `/mcp` and `/oauth`, gets a
  bare 404. It answers only `MNEMON_DEMO_HOST`, refuses to run with
  `APP_DEBUG=true`, and rate-limits each client (`MNEMON_DEMO_RATE_LIMIT`,
  120 a minute by default). Its brain is a synthetic one in `database/demo/`.
- **`php artisan mnemon:demo-reset`** rebuilds the demo brain from the fixtures
  and swaps it in atomically. It refuses to run outside demo mode, on anything
  but a SQLite file inside `storage/`, or over a database that is not a demo
  one. Demo mode schedules it nightly at 04:00 and does not schedule decay or
  retention.
- **`TRUSTED_PROXIES`** names the proxy addresses or CIDRs whose
  `X-Forwarded-For`, `-Host`, `-Port`, `-Proto` and `-Prefix` headers are
  trusted. Unset, no proxy is trusted (except on Laravel Cloud and
  `*.on-forge.com` / `*.on-vapor.com` hosts, where the framework default
  trusts all).
- **`MNEMON_DEMO_URL`** makes a real install's landing page and
  `/how-it-works` link to the public demo.
- **`capture_ignore`** in `~/.mnemon/config.json` stops capture for the
  repositories it names; recall still runs. An assistant's workspace answering
  a heartbeat every 20 minutes had digested the same checklist into 518 drawers
  in `project-mnemon`, ~400 of them near-identical. Plugin 0.3.9.
- CI, license and stack badges at the top of the README.
- `.gitleaksignore` covering the synthetic credentials in `ContentSanitizer`'s
  own tests, so a public clone does not report ten findings that all resolve to
  "this is the test for the redactor".
- **Connected Devices** (`/admin/connected-devices`) — what has actually
  reached this instance and when, with per-device call counts and tool variety.
  Devices provisioned with `mnemon:device-client` that have never successfully
  connected are listed as **never seen** rather than being silently absent,
  which is the failure worth catching when wiring up a new machine.
- `brain_sessions.device` stores the bare credential name alongside the display
  `source`. `source` embeds the token id and device tokens refresh hourly, so
  grouping the audit trail by it counted one machine once per hour — the live
  instance showed a single laptop as sixteen devices. Existing rows are
  backfilled by the migration.

### Changed
- **Wiki writes go through `WikiPageWriter`**, which `context_set` and the
  demo seeder now share.
- The wiki index says "Syntheses", a wiki page no longer shows its title twice,
  and the palace footer shows the database driver in use rather than a fixed
  "postgres · localhost:5432".
- **Digests use `gpt-5.6-luna`** by default. On six real transcript slices it
  wrote denser, self-contained drawers than `gpt-5.4-mini`, returned nothing for
  a cron run that 5.4-mini filed two drawers from, and cost about a quarter as
  much per digest, at 2–3× the latency (digests run detached).
- **Digests used `gpt-5.4-mini`**, not `gpt-4o-mini`. A single `MNEMON_LLM_MODEL`
  setting names the chat model Mnemon's LLM work runs on, so the wiki compiler
  will share it; `MNEMON_DIGEST_OPENAI_MODEL` still overrides it for digests.
- The LongMemEval results now open the README, with the scope caveat (palace
  layer only, self-judged QA) and the subset correction stated inline.

### Fixed
- **`/wiki/search?q[]=` returned 500**, and **the palace wing page's wiki link
  never appeared**: it looked for `like '%project-x%'`, which never matches a
  page named `project:x`.
- **A drawer naming a room its wing lacks was dropped.** When the digest model
  picked an existing wing but a room that did not exist, without proposing it,
  the drawer was skipped without a trace. It is now filed in the wing's
  `notes` room.
- **Captured sessions were filed under the wrong project.** `session_digest`
  received only the transcript, so the digest model guessed the wing — and a
  wiki compile on 2026-10-06 found most new drawers in the busiest wings
  belonged elsewhere (all 755 in `project-ananke`, ~85–90% in `project-mnemon`).
  Proposing a new wing only queued it for a review nobody did, so unknown
  projects were squeezed into whatever wing looked closest. The capture hook
  (plugin 0.3.6) now sends the session's `project` — its repository name — and
  the server files project-specific drawers under that project's wing, matched
  by slug or by the wing's new **aliases**, creating the wing if the token is
  unrestricted. Person and other cross-project filings are unchanged. A repository that spans
  many projects can be excluded with `project_ignore` in `~/.mnemon/config.json`.
- **A client that sent no project still had its drawers guessed into project
  wings.** A device on plugin 0.3.4 sent no `project` for five weeks, and the
  model spread its 3,096 drawers across 24 wings, most of them wrong. Without a
  project, project-specific drawers now go to an **`unfiled`** wing, with the
  model's guess kept as `suggested_wing` in the drawer's metadata, rather than
  into another project's wing. A repository in `project_ignore` still wants the
  model's choice: the capture hook (plugin 0.3.7) now says so by sending
  `project_filing: "model"`. A wing-restricted token that cannot see `unfiled`
  keeps the model's choice among its own wings. Person filings are unchanged.
- **A session running in its scratchpad named no project.** A scratchpad
  (`/tmp/claude-<uid>/<cwd-slug>/<session>/scratchpad`) is not a repository, so
  final-commit's scratchpad sessions sent nothing. The capture hook (plugin
  0.3.8) now treats a scratchpad as the directory it belongs to, rebuilding that
  path from the slug by walking the filesystem, since the slug alone cannot
  tell `final-commit` from `final/commit`.
- **`wiki/index` and `wiki/log` were a permanent 404 on the web UI.** The wiki
  show route constrains the page name to `[a-z0-9:_-]+`, which has no `/`, so
  the two pages the application maintains for itself could never be opened.
  They are renamed to `wiki:index` and `wiki:log`, matching the `type:slug`
  convention every other page already uses. Widening the route was rejected:
  with `/` allowed, the greedy `wiki.show` pattern swallows the `/history`
  suffix of the route registered after it.
- **A long prompt silently disabled recall for the whole session.**
  `RecallTool` validates `prompt` => `max:4000` and `mnemon-recall.sh` sent it
  uncapped, so any prompt over the limit was rejected whole and the session got
  no memory at all. Structurally the same defect as the transcript cap below,
  in the sibling hook. The prompt is now sliced to `max_recall_prompt_chars`
  (3800) inside the jq call that builds the params, so the slice is by codepoint
  and cannot produce invalid UTF-8.
- **An OpenAI timeout reached the client as an opaque 500.** The digest driver
  guarded `! $response->successful()`, which covers an error status but not a
  timeout — the HTTP client throws, so the failure escaped the tool and arrived
  as "Something went wrong while processing the request". Diagnosed from the
  live instance: `cURL error 28` against api.openai.com. It now degrades like an
  error status, and the 20s budget is `config('mnemon.digest.timeout')`,
  default 60.
- **D10 — the `ollama` embedding driver could not store anything.** `embedding`
  was `vector(1536)`, sized for `text-embedding-3-small`, so
  `nomic-embed-text`'s 768-dimension vectors failed on every write with
  "expected 1536 dimensions, not 768". The documented local-embeddings option
  was configurable, selectable, and inert.

  The column is now an unconstrained `vector`. pgvector only requires a
  declared width for HNSW/IVFFlat indexes, and there is no index on
  `embedding` — the only indexes on these tables are GIN full-text — so the
  width could simply go. Semantic queries filter on
  `vector_dims(embedding)`, taken from the probe vector rather than config, so
  rows written by a previous driver are invisible to semantic search instead of
  raising "different vector dimensions" and taking down the whole query.
  `mnemon:reembed` migrates them.

  Verified end to end against a real Ollama server: `nomic-embed-text` returns
  768 dimensions, they store, and semantic search ranks correctly.
- **Wiki pages always showed "Backlinks 0".** The count is now the number of
  other pages whose `related` list names the page, matched on the whole name.

### Security
- **Wing restrictions now cover the wiki.** `wiki_pages` had no wing dimension,
  so any token carrying `mcp:use` could read any wiki page whatever its
  restrictions — through `context_get`, `context_list`, `palace_wake_up`,
  `brain_status`, and `recall`, which runs automatically on every prompt. Wiki
  pages are compiled palace content, so a token restricted to `work` could read
  a synthesis of `personal` drawers.

  A page's wing is derived from its name using the mapping `wiki_compile`
  already enforces (`project:atlas` → `project-atlas`), so the fix needs no
  column and no backfill. Pages that map to no permitted wing — including the
  `wiki:index` and `wiki:log` pages that enumerate other pages — are treated as
  non-existent for a restricted token rather than public. `context_get` returns
  the same "not found" for a forbidden page as for a missing one, so the error
  is not an existence oracle across wings.
- **`brain_status` listed every wing by name** to any token, regardless of
  restrictions. Not the documented wiki hole, but the same boundary: a
  `work`-restricted agent learned that `personal` existed and how much was in
  it. Now filtered.
- Replaced the fixture person name used across the search, recall and hook
  tests, the fixture server and the user guide. It was a real individual, named
  with a job title. The replacement preserves the properties the tests depend
  on — the stop-word first name, and the `y → i` stem that two search-service
  comments cite to explain the D11 tsquery defect.
- Scrubbed the maintainer's live instance hostname, a server IP and a rotated
  OAuth client id from the full history.
- **The wiki MCP resource ignored wing restrictions.** `context_get` hid a
  page outside the token's wings, but `resources/read mnemon://wiki/{slug}`
  returned its full body. The resource now applies the same rule and returns
  the same "not found" for a forbidden page as for a missing one. `wiki_history`
  (revision hashes, agent ids) and `wiki_graph` (page names and edges) were open
  the same way and are now filtered too: the graph neither returns a forbidden
  page nor traverses through one.
- **A wing-restriction audit of every MCP surface** (14 tools, 3 resources,
  3 prompts) closed the remaining gaps, now pinned by one table-driven test,
  `tests/Feature/Mcp/WingIsolationTest.php`:
  - `palace_wake_up` listed every page with pending drawers by name.
  - `context_set` let a restricted token overwrite, or restore from the
    trash, any wing's page, and its `created_or_updated`, `revision_count` and
    conflict error said which hidden pages existed. The page name is now
    checked against the token's wings before the page is looked up.
  - `wiki_lint` reported every page's name, id and scores, and `auto_fix`
    soft-deleted stubs and pruned `related` arrays across all wings. Findings
    are now filtered to readable pages, with the summary counted after
    filtering, and for a restricted token a page is an orphan when no page it
    can read references it, so the orphan flag says nothing about hidden
    pages. `auto_fix` is refused for a restricted token.
  - `brain_status` totals (wiki pages, wings, rooms, drawers, drawers by tier,
    last write, embedding counts) spanned every wing; they now count only the
    token's wings.
  - `drawer_get` and `mnemon://drawer/{id}` answered a hidden drawer with
    "Token does not have access to wing: personal" and a missing one with
    "Drawer not found", so ids could be enumerated to map drawers to wings.
    A hidden drawer now answers exactly like a missing one.
  - `context_get` on a readable page returned `related` names of hidden pages
    and `source_details` previews of hidden drawers; `related`, `sources`,
    `source_details` and `source_count` are now narrowed to what the token can
    read.
  - `drawer_search` and `recall` searched every wing and filtered afterwards,
    so hidden drawers took limit slots and set the score normalisation: a
    `limit: 1` search came back empty exactly when a stronger hidden match
    existed. The search is now scoped to readable wings in SQL, and recall's
    wiki leg widens its search until it has three readable pages.
  - `palace_wake_up` and `context_set`'s source check matched patterns with
    SQL `LIKE`, where `%` and `_` are wildcards, and `drawer_add` stored its
    raw `wing` argument as the slug, so such characters could reach a
    pattern. Every wing scope now matches through one helper,
    `Wing::readableIds()`, and `drawer_add` slugifies its wing.
  - `session_digest` filed drawers into whatever wing the model named,
    unchecked against the token, and could queue new-wing proposals for any
    wing; whether it refiled a proposal also depended on whether a hidden
    wing existed. A wing outside the token's is now treated as missing:
    refiled into the session or `unfiled` wing when the token can see one,
    otherwise dropped and counted in `dropped_outside_wings`. Recent drawer
    ids from hidden wings no longer reach the model, and a replayed slice
    reports only drawers the token can read.
  - The `synthesize_wing` prompt told agents to write `synthesis:<wing>`,
    which a token restricted to that wing is refused; it now names the page
    after the wing.

---

## 2026-09-20 — plugin 0.3.5

### Fixed
- **Session capture had been failing silently for 17 days.** The slice cap was
  measured on the raw JSONL (`max_digest_bytes`, 262144) while `session_digest`
  validates what actually arrives (`transcript` => `max:200000`). Sanitization
  only strips about a fifth, so a slice under the raw cap still landed over the
  limit — a real transcript measured 260,257 characters on the wire. The
  failure was self-perpetuating: a rejected digest never advances
  `last_digest_turn`, so the next Stop re-sent the same oversized tail and
  failed identically. 499 consecutive failures; 120 of 203 session state files
  frozen at turn 0.

  The payload is now capped *after* sanitization at `max_digest_chars` (190000),
  dropping whole leading records so the newest turns survive.
- The hook test fixture accepted any payload size, which is why the suite passed
  throughout. It now enforces the same limit as the real tool.

---

## 2026-09-03 – 2026-09-04 — device authorization grant, plugin 0.3.0 – 0.3.4

### Added
- OAuth 2.1 **device authorization grant** (RFC 8628): consent screens, wing
  capture at consent, discovery metadata, and `mnemon:device-client` to
  provision one confidential client per device.
- `GET /install` serves a setup script that knows its own instance URL.
- SessionStart reports when a device is running a stale plugin version, and the
  reported version lands on the `BrainSession` row.
- Plugin 0.3.0: refresh-on-401 behind a lock, a dead-credential banner,
  proactive refresh at wake, and `scripts/mnemon-authorize.sh` enrolment.

### Changed
- Refresh tokens no longer rotate on use.
- Wing restrictions are keyed to the OAuth client rather than the access token.

### Fixed
- `passport:purge` orphaned refresh tokens beyond `TokenRevoker`'s reach — it
  finds them via `access_token_id`, and purge deleted access tokens (1h TTL)
  long before refresh tokens (90d).
- Consent could be written by a POST that Passport never accepted.
- Revoking a token or client left the refresh token usable.
- A malformed client id returned 500 instead of an OAuth error.
- Token expiry was parsed in a format that made the countdown never fire.
- Duplicate drawers: content now carries a room-scoped sha256 and an identical
  one is refused at write, saving the embedding call.
- `MNEMON_DISABLE=1` makes all three hooks no-op, for cron-launched sessions.
- Hooks registered twice delivered each event twice; each is now claimed once.

---

## 2026-09-01 – 2026-09-02 — Claude Code plugin, Layer 2 hardening

### Added
- The hooks ship as an installable **Claude Code plugin**; `plugins/mnemon/` is
  the single source of truth.
- `session_digest` is idempotent per `(session_id, turn_range)`.
- A warning before the access token expires.

### Fixed
Thirteen defects found while dogfooding, **none of which had ever produced an
error message**:
- The hook installer wrote a shape Claude Code ignores, so every install was
  inert, and it silently destroyed pre-existing hooks.
- `mnemon-capture.sh` read keys Claude Code never sends, so capture could never
  run; the sanitizer filtered only top-level blocks, letting nested tool I/O
  through.
- Request bodies went through argv, dying at ~128KB on real transcripts.
- Capture sent the whole transcript every time and was rejected at 1MiB, whose
  HTML error page surfaced as a jq parse error.
- A 401 was indistinguishable from an empty palace, and no `Accept` header meant
  auth failures arrived as 302 redirects to an HTML login page.
- A zero-byte session state file wedged capture permanently.
- A stale worker copy shadowed its own source forever.
- `ContentSanitizer` never redacted modern `sk-proj-` keys and had no test file.

### Changed
- Truth-up pass: corrected the README, USERGUIDE, benchmark and OpenClaw docs
  against the shipped product, stated the wiki wing-isolation limitation, and
  removed three false guarantees.

---

## 2026-08-27 – 2026-09-01 — release readiness

### Added
- **LongMemEval benchmark harness** (`benchmark/`): resumable ingestion,
  retrieval metrics, and an LLM-judged QA layer. Full 500-question run across
  both legs — embeddings improve every metric at every depth (MRR 0.817 →
  0.924; QA accuracy 0.557 → 0.627), at $36.76 against a ~$37 estimate.
- **Docker install story**: compose stack (app, db, scheduler), entrypoint,
  Caddyfile and a production env template with the footguns documented inline.
- `LICENSE` (MIT).

### Fixed
- **D11** — `plainto_tsquery` was applied to the whole query, ANDing every
  lexeme, so a conversational prompt matched nothing on PostgreSQL. Terms are
  now per-term and the two engines agree. Found by the new PostgreSQL CI leg on
  its first run.
- **D12** — the tsvector is stored rather than recomputed per row.
- Stop words are dropped before the query reaches SQL, using PostgreSQL's own
  127-entry list, so a single stop-word term still searches.

---

## 2026-05-02 — Layer 2: automatic memory

### Added
- `recall` and `session_digest` MCP tools, extending the original 12 to 14.
- `RecallService` (hybrid wiki + drawer recall with token-budget packing) and
  `SessionDigestService` (transcripts into drawer proposals).
- The three Claude Code hooks — wake, recall, capture — and the shared bash
  helpers behind them.
- `wiki_pending_wings` and a Filament approval queue for new-wing proposals.

---

## 2026-04-24 – 2026-04-27 — MCP rework

### Added
- Rebuilt the MCP server on [`laravel/mcp`](https://github.com/laravel/mcp):
  12 tools, three resources (`mnemon://drawer|wiki|wing/...`) and three prompts.
- **OAuth 2.1 via Passport** with the single `mcp:use` scope, Dynamic Client
  Registration, a Mnemon-styled consent screen, and per-token wing restrictions.
- Filament admin for OAuth clients, tokens and live sessions.

---

## 2026-03-23 — initial

The palace (wings → rooms → drawers), the wiki layer, hybrid retrieval over
pgvector plus PostgreSQL full-text, and the Filament admin panel.

[1.0.0]: https://github.com/coopers98/mnemon/releases/tag/v1.0.0
