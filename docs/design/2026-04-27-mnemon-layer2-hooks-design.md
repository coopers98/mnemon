# Mnemon Layer 2 — Claude Code Hooks Design

> **Dated design rationale, 2026-04-27.** This document records what was designed and
> believed at that date. It is not maintained as current documentation, and it may
> describe behaviour the code no longer has. Check the code before relying on it.

**Date:** 2026-04-27
**Status:** Approved (ready for implementation plan)
**Author:** Cooper + Claude (brainstorm)
**Supersedes / extends:** `docs/superpowers/specs/2026-04-26-mcp-rework-design.md`

## Goal

Make Mnemon's memory automatic in Claude Code: every prompt is silently primed with relevant palace context; every session quietly digests to drawers without the agent (or the user) thinking about it. This is the first harness adapter — Cursor, ChatGPT, and other adapters are deferred but reuse the same MCP tools.

The MCP rework (b7501c1 / 989e5de) made Mnemon a spec-compliant MCP server. That alone is insufficient for the "shared brain across all my agents" goal because it requires every agent to *think* to call MCP tools. Layer 2 wraps that protocol surface with harness-specific hooks that capture and recall on the agent's behalf.

## Non-goals

- Cursor / ChatGPT / other harness adapters. Each is a separate spec; they reuse the same server-side MCP tools.
- Multi-device coordination. Each device's hook state (watermark, debounce, `@nomemo`) is local-only.
- Pre-store approval UI for digested drawers. Digest fires async and persists; review is post-hoc in Filament.
- Reactive recall mid-turn. Recall fires only at `UserPromptSubmit`; if the agent goes off and explores something new, the next prompt is the next opportunity.
- Replacing existing MCP tools. Agents that *do* think to call `drawer_search` directly still can.
- A local daemon (Approach 3 below). Deferred until token-refresh or offline-capture pain is real.

## Decisions (locked during brainstorm)

| Decision | Choice |
|---|---|
| Recall trigger | Local heuristic gate (length + stopwords + `/`-commands) + recent-fire suppression (~30s) |
| Recall payload | Single hybrid endpoint, server-ranked wiki + drawer mix, token-budget capped |
| Recall confidence floor | 0.45 (`recall.confidence_floor`); separate from the digest floor below |
| Digest confidence floor | 0.5 (`digest.confidence_floor`); high-confidence threshold for new wings is 0.85 (always queues regardless) |
| Recall budget | 800ms wall-time, fail-open silently |
| Capture trigger | Every-Stop with debouncing via lockfile + pending-rerun marker |
| Capture mechanism | Client-side structural whitelist (drop tool I/O + thinking) + regex sanitize → server LLM digest |
| Capture routing | LLM picks wing/room from existing list; new rooms auto-created; new wings always queued for Filament approval |
| Capture dedup | Hook-side watermark keyed on Claude Code's native `session_id`; LLM also receives `recent_drawer_ids` |
| Capture transport | Detached background POST; hook returns < 50ms |
| Sanitize layering | Client (structural + regex) + server (regex defense-in-depth) |
| Off-switch | `@nomemo` per-session marker; suppresses both recall and capture for the rest of that session |
| Architecture | MCP-native: new tools `recall` + `session_digest`; hooks call `/mcp` with existing Passport bearer |
| Hook installation | New artisan command `mnemon:install-claude-code-hooks` |

## Architecture

Three hooks installed in `~/.claude/hooks/`:

| Hook | Trigger | Calls | Blocking? | Budget |
|---|---|---|---|---|
| `mnemon-wake.sh` | `SessionStart` | `palace_wake_up` (existing) | yes | 2s |
| `mnemon-recall.sh` | `UserPromptSubmit` | `recall` (new) | yes | 800ms |
| `mnemon-capture.sh` | `Stop` | `session_digest` (new) | no (detached) | 10s server-side |

Two new MCP tools added to `MnemonServer`:

- **`recall`** — single hybrid endpoint that takes a prompt and returns the best mix of wiki excerpts + drawer hits, capped at a token budget.
- **`session_digest`** — takes a sanitized transcript slice + session metadata, returns proposed drawers with wing/room routing, and persists the high-confidence ones.

One new artisan command:

- **`php artisan mnemon:install-claude-code-hooks`** — copies hook scripts to `~/.claude/hooks/`, writes `~/.mnemon/config.json` with the user's Passport bearer, registers the hooks in `~/.claude/settings.json`.

Auth: hooks read the existing Passport bearer from `~/.mnemon/config.json` (mirrored from `~/.claude.json` at install). No new auth surface. Wing restrictions, `BrainSession` audit, and rate limits all flow through the existing `/mcp` endpoint.

State: per-session state lives in `~/.mnemon/sessions/<session_id>.json`:

```json
{
  "last_digest_turn": 0,
  "last_recall_at": 0,
  "recent_drawer_ids": [],
  "nomemo": false,
  "disabled": false
}
```

State is local-only; loss is recoverable (worst case: one extra digest run, caught by LLM-side dedup against `recent_drawer_ids`).

## New MCP tools

### `recall`

**Schema:**

```php
'prompt'        => 'required|string|max:4000',
'token_budget'  => 'integer|min:200|max:4000',  // default 1500
'wing'          => 'nullable|string',
```

**Annotation:** `#[IsReadOnly]`.

**Handler logic:**

1. `requireScope('mcp:use')`. Apply wing-pattern filter from `mcp_token_restrictions`.
2. Embed `prompt` once. Run two parallel queries:
   - **Wiki:** top-3 `WikiPage`s by name fuzzy-match + embedding cosine similarity, weighted (name match dominates if present).
   - **Drawers:** top-10 by embedding cosine + fulltext, reusing `DrawerSearchService`'s pipeline.
3. Score each candidate on a unified 0–1 confidence scale. Apply hard floor (`config/mnemon.php` → `recall.confidence_floor`, default 0.45). Drop everything below.
4. Pack greedily up to `token_budget`:
   - Highest-confidence wiki page first; excerpt to first 600 tokens if longer with a `…[truncated, full page at mnemon://wiki/<slug>]` marker.
   - Next-best wiki page if room.
   - Drawers in confidence order.
5. Return:

```json
{
  "found": true,
  "summary": "Found 1 wiki page and 3 drawers relevant to this prompt.",
  "wiki": [
    {"slug": "person:dorothy-vaughan", "title": "...", "content": "...", "confidence": 0.91}
  ],
  "drawers": [
    {"id": 42, "wing": "work", "room": "meetings", "snippet": "...", "confidence": 0.78}
  ],
  "tokens_used": 1180
}
```

Below-floor: `{"found": false}`. Hook injects nothing.

**Audit:** standard `BrainSessionLogger` row; `result_count` = total items returned.

### `session_digest`

**Schema:**

```php
'session_id'        => 'required|string|max:100',
'harness'           => 'required|string|max:50',  // "claude-code" for v1
'turn_range'        => 'required|array:start,end',
'transcript'        => 'required|string|max:200000',  // already sanitized client-side
'recent_drawer_ids' => 'array',
'auto_persist'      => 'boolean',                     // default true
```

**Annotation:** `#[IsDestructive]`.

**Handler logic:**

1. `requireScope('mcp:use')`. Apply wing-pattern filter.
2. Server-side sanitize pass (defense-in-depth — same regex set as `ContentSanitizer`).
3. Load wing slugs + room slugs accessible to this token.
4. Load `Drawer`s for any IDs in `recent_drawer_ids` so the LLM can avoid duplicates.
5. Call OpenAI (or configured driver) with a structured prompt:
   > "Here are existing wings: [...]. Existing rooms per wing: [...]. Drawers already captured this session: [...]. Here is the new transcript slice. Extract 0–N drawers worth storing. For each: `content`, `wing_slug`, `room_slug`, `confidence` (0–1), `propose_new_wing` (bool), `propose_new_room` (bool), `rationale`."
6. For each proposal:
   - `confidence < 0.5` → drop.
   - `propose_new_wing` (any confidence) → enqueue `{wing_slug, name, rationale, drawer_payload}` to `wiki_pending_wings`. Do **not** create wing or drawer.
   - `propose_new_room` → auto-create the room within its (existing) wing; tag `metadata.auto_created = true`.
   - Otherwise → create the drawer in the existing wing/room.
7. Tag every auto-created drawer. `source` is set to `"<harness>:session_digest"` (e.g., `"claude-code:session_digest"`) — distinct from the OAuth-client-name source that `ResolvesAgentSource` would stamp, so digest-captured drawers are easy to filter in Filament. `metadata` carries:

```json
{
  "captured_via": "session_digest",
  "harness": "claude-code",
  "session_id": "<id>",
  "confidence": 0.83
}
```

8. Return:

```json
{
  "proposals": [...],
  "persisted": [{"id": 412, "wing": "work", "room": "meetings"}],
  "queued_for_review": [...],
  "pending_wings": [{"wing_slug": "project:atlas", "rationale": "..."}]
}
```

**Audit:** `BrainSessionLogger` row per call; `result_count` = drawers persisted (not proposed).

### `wiki_pending_wings` table (new)

```sql
CREATE TABLE wiki_pending_wings (
  id BIGSERIAL PRIMARY KEY,
  wing_slug VARCHAR(255) NOT NULL,
  wing_name VARCHAR(255) NOT NULL,
  rationale TEXT,
  drawer_payload JSONB NOT NULL,        -- {content, room_slug, source, metadata}
  status VARCHAR(20) DEFAULT 'pending', -- pending | approved | rejected
  proposed_by_session_id VARCHAR(100),
  proposed_by_token_id VARCHAR(100),
  created_at TIMESTAMPTZ DEFAULT now(),
  decided_at TIMESTAMPTZ
);
CREATE INDEX wiki_pending_wings_status_idx ON wiki_pending_wings (status);
```

Filament admin gets a `WikiPendingWingResource` with two row actions:

- **Approve** — atomically creates the `Wing`, the `Room` (if not present), the `Drawer`, and updates this row to `status = approved, decided_at = now()`.
- **Reject** — updates to `status = rejected, decided_at = now()`. Drawer is dropped permanently.

Idempotent on double-approve via `status = pending` precondition.

## Hook scripts

All three are POSIX bash. Use only `curl`, `jq`, `cat`, `date`, `mkdir`. No Node, Python, or external runtimes.

### `~/.mnemon/lib/common.sh`

Sourced by all three hooks. Helpers:

- `mnemon_token()` — reads `~/.mnemon/config.json` (`endpoint` + `bearer_token`). Falls back to parsing `~/.claude.json`'s mnemon entry if the config file is absent.
- `mnemon_call <method> <params_json> <timeout_ms>` — wraps `curl -m` with a JSON-RPC envelope. Returns `result` field on success; empty on timeout/error.
- `mnemon_session_state <session_id>` — reads/writes `~/.mnemon/sessions/<session_id>.json`. Atomic via tmpfile + `mv`.
- `mnemon_log_error <message>` — appends to `~/.mnemon/capture-errors.log` with timestamp.

If `mnemon_token` returns empty, every hook short-circuits to a no-op. SessionStart prints the one-line warning the first time per session.

### `mnemon-wake.sh` (SessionStart)

```
1. Load token. If missing → print warning, exit 0.
2. Read session_id from hook stdin.
3. Initialize ~/.mnemon/sessions/<session_id>.json with default state.
4. Call `palace_wake_up` (timeout 2000ms).
5. On success: emit a <system-reminder> with compact rendering:
   - recent_drawers (top 5, snippets)
   - active_wings (top 5)
   - pending_update_pages (top 3)
6. On timeout/error: silent no-op.
```

Output budget: ~600 tokens of injected context.

### `mnemon-recall.sh` (UserPromptSubmit)

```
1. Load token. If missing → exit 0.
2. Read prompt + session_id from hook stdin.
3. If prompt starts with @nomemo → set state.nomemo=true, exit 0.
4. Load session state. If state.nomemo or state.disabled → exit 0.
5. Local gate:
   a. len(prompt) < 15 → exit 0
   b. prompt matches stopword list ("ok", "yes", "no", "thanks",
      "continue", "go", "do it", "stop", "wait", any prompt starting
      with "/") → exit 0
   c. (now() - state.last_recall_at) < 30s → exit 0
6. Update state.last_recall_at = now().
7. Call `recall` with {prompt, token_budget: 1500} (timeout 800ms).
8. If result.found:
   emit <system-reminder> formatted as:
     "Mnemon recall:
        Wiki: [page title] — [excerpt]
        Drawers: 1) [snippet] (wing/room) 2) ..."
9. Else: silent no-op.
```

### `mnemon-capture.sh` (Stop)

```
1. Load token. If missing → exit 0.
2. Read transcript + session_id + last_turn_index from hook stdin.
3. Load session state. If state.nomemo or state.disabled → exit 0.
4. Local sanitize:
   a. Structural: drop <tool_use>, <tool_result>, <thinking> blocks
      (operating on the structured transcript JSON).
   b. Regex: redact AWS-key, generic-token, JWT, bearer-prefix patterns
      (mirroring ContentSanitizer regexes — duplicated for v1, see Risks).
5. Slice: only turns > state.last_digest_turn.
6. If slice empty → exit 0.
7. Spawn detached background process:
     POST `session_digest` with {session_id, harness:"claude-code",
       turn_range, transcript: cleaned_slice, recent_drawer_ids:
       state.recent_drawer_ids}
     On success: append persisted ids to state.recent_drawer_ids
       (cap at last 50); update state.last_digest_turn = turn_range.end.
     On error: log to ~/.mnemon/capture-errors.log.
   Detached via `nohup ... &` or `setsid` so hook returns immediately.
8. Hook exits 0 within ~50ms.
```

**Debounce mechanism:** the detached worker takes a lockfile at `~/.mnemon/sessions/<session_id>.digest.lock`. If a worker is already running for this session, the new one writes a `pending_rerun` marker file and exits. The running worker, on completion, checks for the marker and re-runs once if present. This collapses rapid fire-fire-fire into one round of work + at most one re-check.

**Stale-lock recovery:** lockfiles store the worker's PID. A new worker that finds a lockfile checks whether the PID is still alive (`kill -0 <pid>`); if not, it claims the lock and proceeds. This handles the rare crash-mid-digest case without manual intervention.

## `mnemon:install-claude-code-hooks` artisan command

```
php artisan mnemon:install-claude-code-hooks
   [--endpoint=https://mnemon.example.com/mcp]
   [--token=<paste>]
   [--force]
```

Flow:

1. Verify `~/.claude/` exists. If not: abort with instructions ("Install Claude Code first: …").
2. Resolve `endpoint`: flag → env `MNEMON_URL` → prompt.
3. Resolve `bearer_token`: flag → parse `~/.claude.json` for an existing mnemon entry → prompt.
4. Verify token: call `tools/list` against `<endpoint>` with bearer; abort if 401.
5. Write `~/.mnemon/config.json` with `{endpoint, bearer_token}` (chmod 600).
6. Copy `mnemon-wake.sh`, `mnemon-recall.sh`, `mnemon-capture.sh`, `lib/common.sh` from `resources/hooks/claude-code/` (shipped in the repo) to `~/.claude/hooks/`.
7. Update `~/.claude/settings.json` to register the three hooks. Use a marker comment (`// mnemon-managed`) so re-runs don't duplicate.
8. Print: `Mnemon Claude Code hooks installed. Test with: claude → ask anything.`

`--force` reinstalls scripts; safe because they're versioned by the repo.

## Failure modes & latency

| Failure | Behavior |
|---|---|
| Recall server timeout (> 800ms) | Hook silently injects nothing; no transcript noise |
| Recall server 4xx/5xx | Same — silent no-op |
| Capture server failure | Logged to `~/.mnemon/capture-errors.log`; not retried (next Stop covers because watermark didn't advance) |
| Token expired/revoked | Recall: silent no-op. Capture: logged. SessionStart: print one-line warning, set `state.disabled = true` for the session |
| Mnemon entirely unreachable | All three hooks short-circuit cleanly; agent operates without memory; no error noise |
| State file corrupted/missing | Hook reinitializes; worst case is one duplicate digest run, caught by `recent_drawer_ids` + LLM-side dedup |
| `~/.claude/` missing at install time | Installer aborts with instructions |

Server-side budgets:

- `recall` P95 < 400ms (single embedding + two indexed queries).
- `session_digest` async — no SLA, target < 10s typical case.

## File layout (additions)

```
app/
  Mcp/
    Tools/
      RecallTool.php                 # NEW
      SessionDigestTool.php          # NEW
    Servers/
      MnemonServer.php               # MODIFIED — register the two new tools
  Console/Commands/
    InstallClaudeCodeHooks.php       # NEW — artisan installer
  Filament/Resources/
    WikiPendingWingResource.php      # NEW
  Models/
    WikiPendingWing.php              # NEW
  Services/
    SessionDigestService.php         # NEW — wraps the LLM call + persistence
    RecallService.php                # NEW — server-side ranking + packing
config/
  mnemon.php                         # MODIFIED — add `recall.confidence_floor`,
                                     #   `digest.confidence_floor`, `digest.driver`
database/migrations/
  YYYY_MM_DD_create_wiki_pending_wings_table.php   # NEW
resources/hooks/claude-code/
  mnemon-wake.sh                     # NEW
  mnemon-recall.sh                   # NEW
  mnemon-capture.sh                  # NEW
  lib/common.sh                      # NEW
tests/
  Feature/Mcp/Tools/
    RecallToolTest.php               # NEW
    SessionDigestToolTest.php        # NEW
  Feature/Console/
    InstallClaudeCodeHooksTest.php   # NEW
  Feature/Filament/
    WikiPendingWingResourceTest.php  # NEW
  Hooks/                             # NEW directory
    ClaudeCodeHooksTest.sh           # bash test harness
```

## Testing strategy

Following existing patterns (`tests/Feature/Mcp/Tools/*`, `RefreshDatabase` on SQLite, `MakesMcpRequests` trait).

1. **`recall` tool** — happy path returns mixed payload; below-floor returns `{found: false}`; wing-restricted token sees only allowed wings; token-budget cap honored; malformed prompt rejected with validation error.
2. **`session_digest` tool** — high-confidence proposal auto-persists; new-wing proposal queues to `wiki_pending_wings` and does NOT create the wing or drawer; new-room proposal auto-creates room; below-confidence proposal dropped; sanitize layer redacts secrets in the persisted drawer; `recent_drawer_ids` argument prevents duplicate proposals (LLM mock returns same content twice → second is dropped).
3. **`wiki_pending_wings`** — Filament approval action creates wing + room + drawer atomically; rejection drops everything; idempotent on double-approve.
4. **Hook scripts** — bash-level: smoke tests via `shellcheck` + a tiny harness that pipes synthetic stdin and asserts on stdout/exit code. Mock the Mnemon endpoint with a local fixture server. Cover: no-token short-circuit, `@nomemo` mode, stopword gate, recent-fire suppression, debounce lockfile collapse.
5. **Installer** — artisan command writes the right files with the right permissions; doesn't duplicate hook entries on re-run; aborts cleanly when `~/.claude/` is missing; rejects an invalid token.

## Migration & cutover

This is purely additive — no removed code, no destructive migrations.

1. Single PR containing the new tools, services, models, migrations, hook scripts, and installer.
2. Deploy.
3. On Cooper's machine: `php artisan mnemon:install-claude-code-hooks` (it'll find the existing `~/.claude.json` mnemon entry).
4. Open a fresh Claude Code session; verify `mnemon-wake.sh` runs by checking the transcript for the SessionStart `<system-reminder>` containing palace state.
5. Ask a question Mnemon should know about; verify the recall block shows.
6. End the session normally; check Filament for new drawers tagged `captured_via: "session_digest"`.

**Rollback:** delete `~/.claude/hooks/mnemon-*.sh`, remove the hook entries from `~/.claude/settings.json` (or run `claude config reset hooks`). Server-side: revert the PR. The new tools remain dormant until clients call them — server-side rollback is graceful.

## Documentation updates

| File | Updates required |
|---|---|
| `README.md` | New §"Layer 2 — automatic memory in Claude Code" pointing at the user guide section. |
| `docs/USERGUIDE.md` | New §"Automatic memory in Claude Code" walking through `mnemon:install-claude-code-hooks`, what each hook does, the `@nomemo` toggle, capture review in Filament, troubleshooting (capture-errors.log, disabling hooks). |
| `CLAUDE.md` | Add `recall` and `session_digest` to the tools list; mention `wiki_pending_wings` in the models list. |
| `docs/FRD.md` | Add a "Harness adapters" section noting the layered model (MCP + per-harness hooks). |

A grep pass at PR time over `recall_tool`, `session_digest`, `mnemon-wake`, `mnemon-recall`, `mnemon-capture`, `wiki_pending_wings`, `~/.mnemon` to ensure nothing dangles.

## Risks & open questions

- **OpenAI dependency for capture.** `session_digest` makes an LLM call per fire. At ~$0.01/call and a few sessions/day this is fine, but worth tracking. v2: support local LLM driver via Ollama, mirroring the existing embedding-driver abstraction.
- **Bash sanitize regex drift.** The hook's regexes are duplicated from `ContentSanitizer`. v1 ships with the duplicate. v2: an artisan command emits a `mnemon-sanitize.regex` file the hook sources, generated from the canonical PHP source.
- **Token refresh.** Passport access tokens expire after 1 hour. Hooks reading the bearer from `~/.mnemon/config.json` will hit 401 once the underlying refresh advances the token elsewhere. v1 workaround: hook re-reads `~/.claude.json` on 401 and re-attempts once. Real fix is the daemon (Approach 3 — deferred).
- **Session-id stability across `/clear`.** Claude Code's `/clear` may or may not produce a new `session_id` (verify during impl). If it doesn't, recall will keep injecting from a stale watermark. Mitigation: SessionStart hook always re-initializes state — verify `/clear` triggers SessionStart.
- **Recall noise.** If `recall` returns marginal hits, the agent gets distracted. The 0.45 floor is the main lever; tune empirically after a week of real use.
- **Cross-harness portability of `session_digest`.** The transcript shape Claude Code emits won't match Cursor's. The endpoint accepts a `harness` field and a free-form `transcript` string, so it's flexible — but server-side parsing logic may need per-harness branches as adapters are added.

## Future work (out of scope here)

1. **Cursor adapter** — same MCP tools, different hook idiom (Cursor's MCP integration is in-app, not file-based; hooks may need to live in a Cursor extension).
2. **ChatGPT adapter** — likely impossible without an OpenAI-side hook surface; possibly a userscript.
3. **Local daemon (Approach 3)** — `mnemon-agent` per device for token refresh, offline capture buffer, single sanitize implementation.
4. **Cross-device session continuity** — the `session_get/set/list` tools flagged in the MCP rework spec; allows recall context to follow you across devices.
5. **Local LLM driver for `session_digest`** — Ollama integration mirroring existing embedding-driver abstraction.
6. **Pre-store digest review UI** — opt-in mode where digested drawers queue for approval rather than auto-persisting.
