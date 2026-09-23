# Changelog

Notable changes to Mnemon. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); the project does not
yet tag releases, so entries are grouped by the date the work landed on `main`.
Plugin versions refer to the Claude Code plugin in `plugins/mnemon/`.

---

## Unreleased

### Added
- CI, license and stack badges at the top of the README.
- `.gitleaksignore` covering the synthetic credentials in `ContentSanitizer`'s
  own tests, so a public clone does not report ten findings that all resolve to
  "this is the test for the redactor".

### Changed
- The LongMemEval results now open the README, with the scope caveat (palace
  layer only, self-judged QA) and the subset correction stated inline.

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
  `wiki/index` and `wiki/log` pages that enumerate other pages — are treated as
  non-existent for a restricted token rather than public. `context_get` returns
  the same "not found" for a forbidden page as for a missing one, so the error
  is not an existence oracle across wings.
- **`brain_status` listed every wing by name** to any token, regardless of
  restrictions. Not the documented wiki hole, but the same boundary: a
  `work`-restricted agent learned that `personal` existed and how much was in
  it. Now filtered.

### Fixed
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

### Security
- Replaced the fixture person name used across the search, recall and hook
  tests, the fixture server and the user guide. It was a real individual, named
  with a job title. The replacement preserves the properties the tests depend
  on — the stop-word first name, and the `y → i` stem that two search-service
  comments cite to explain the D11 tsquery defect.
- Scrubbed the maintainer's live instance hostname, a server IP and a rotated
  OAuth client id from the full history.

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
