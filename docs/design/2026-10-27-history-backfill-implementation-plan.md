# History Backfill Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `mnemon-backfill` imports a device's past Claude Code memory notes and session transcripts into Mnemon. It estimates first, trials one project, and never passes its cap.

**Architecture:** The server gains five things:
- `occurred_at` on drawers;
- token usage reporting;
- a `digest` block on `brain_status` (pricing, prompt overhead, project resolution);
- a `project` argument on `drawer_add`;
- a completion-token limit on the digest model call.

The plugin's digest worker gains a single-call mode and a per-call report. A
new `scripts/mnemon-backfill.sh` drives that worker one call at a time:
- a read-only scan;
- memory-note import;
- the transcript loop, with cap, failure limit and marker ownership.

**Tech Stack:** Laravel 13 / PHP 8.4, `laravel/mcp`, PHPUnit on SQLite (CI also runs PostgreSQL 17); bash + jq + curl hooks, tested by `tests/Hooks/run-hook-tests.sh`.

**Spec:** `docs/design/2026-10-18-history-backfill-design.md`. Read it before any task. Where this plan is silent, the spec decides.

## Global Constraints

- **PHP tests:** only `php artisan test --compact`. This host's PHP 8.5 lacks
  dom/mbstring/xml, so run them in Docker:

  ```
  docker run --rm --network none -v "$PWD":/src -v <scratch>/mem.ini:/usr/local/etc/php/conf.d/zz-mem.ini:ro -w /src --entrypoint php mnemon-scheduler:latest artisan test --compact
  ```

  `mem.ini` holds `memory_limit=1G`. Never run phpunit directly or with
  `--no-configuration`. Never migrate, fresh or seed: `.env` points at the
  local dev DB.
- **Lint:** `vendor/bin/pint --test`, run the same way in Docker.
- **Hook tests:** `bash tests/Hooks/run-hook-tests.sh`. They sandbox HOME.
  Never touch the real `~/.mnemon` or `~/.claude` in tests. Real transcripts may
  be read for shapes only, never copied into fixtures.
- **Portability:** bash 3.2 (macOS), Git Bash on Windows, GNU and BSD tools, and
  paths with spaces. No `declare -A`, no `${x,,}`, no `mapfile`, no GNU-only flags
  without a BSD fallback (see `mnemon_mtime` for the pattern), and `jq` 1.6+.
- **Locks:** use `mnemon_lock_take` / `mnemon_lock_release` /
  `mnemon_with_lock`. Never use `mkdir` alone as a lock (uutils).
- **Never print a token or secret.** Backfill output that shows prompt text
  passes it through the redaction function from Task 5 and cuts it to 60
  characters.
- **Wing isolation:** every new MCP surface or argument answers for a hidden
  wing exactly as for a missing one, and gets a row in
  `tests/Feature/Mcp/WingIsolationTest.php`.
- **Compatibility:** with the new env vars unset, live capture's behaviour is
  byte-for-byte unchanged. Plugin 0.3.11 clients and older servers keep
  working: every new argument is optional, and every new result field is
  additive.
- **Commits:** directly on `main`, never pushed. End each message with
  `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- **Plugin version:** goes to **0.3.12** in Task 9, in both
  `plugins/mnemon/.claude-plugin/plugin.json` and
  `.claude-plugin/marketplace.json`.

## Review Focus

1. **Automation looks like work.** A heartbeat session is a single
   first-prompt cluster in another repo, under the threshold on one device. It
   must be listed as skipped, never silently digested. Test it in Task 6 with
   a 5-file cluster and a 4-file one.
2. **A capped or stopped run leaves false banners.** After a run stops
   mid-session, `mnemon_undigested_sessions` must not count that session as
   "sent the next time that session runs". Test it in Task 8.
3. **A session comes alive mid-run.** Its transcript mtime changes between
   calls: the backfill stops touching it and leaves any marker in place. Test
   it in Task 7.
4. **Failures billed with no usage.** A server that answers every call with a
   `Retryable:` error must stop the session after 3 attempts, and must still
   advance the spend total by 3 × the per-call estimate. Test it in Task 7.
5. **Paths with spaces and a cwd that no longer exists.** A transcript under
   `~/.claude/projects/-tmp-my dir/` whose `cwd` is gone is reported
   "unresolved", not filed into `unfiled`. Test it in Task 6.

---

### Task 1: `occurred_at` on drawers

**Files:**
- Create: `database/migrations/2026_10_27_000000_add_occurred_at_to_drawers_table.php`
- Modify: `app/Models/Drawer.php` (fillable, cast to datetime)
- Modify: `app/Services/DrawerWriteService.php` (`createDrawer` gains `?CarbonInterface $occurredAt = null`)
- Modify: `app/Mcp/Tools/DrawerAddTool.php` (`occurred_at` optional ISO-8601 argument)
- Modify: `app/Mcp/Tools/SessionDigestTool.php`, `app/Services/SessionDigestService.php` (`occurred_at` optional; applied to every drawer the digest creates)
- Modify: `app/Services/PalaceSearchService.php:108,181,242,274` (select `occurred_at`; boost on `occurred_at ?? created_at`)
- Modify: `app/Mcp/Tools/WikiCompileTool.php:66` (order by `COALESCE(occurred_at, created_at)` desc)
- Test: `tests/Feature/Mcp/Tools/OccurredAtTest.php`

**Interfaces:**
- Produces:
  - **Column:** `drawers.occurred_at`, a nullable timestamp, indexed.
  - **MCP arguments:** `occurred_at` (string, ISO-8601) on `drawer_add` and on
    `session_digest`. The server clamps a later time to `now()`. An
    unparseable value is a validation error.
  - **Results:** each drawer in `drawer_get` and `drawer_search` reports
    `occurred_at`, or null.

- [ ] **Step 1: Write the failing tests:**
  - `test_drawer_add_stores_occurred_at`
  - `test_a_future_occurred_at_is_clamped_to_now`
  - `test_session_digest_applies_occurred_at_to_its_drawers`, using the fake LLM
    pattern in existing `SessionDigestToolTest`
  - `test_temporal_boost_uses_occurred_at`: two drawers with equal text, one
    created now with `occurred_at` 90 days ago and one created now with none.
    The one without ranks first.
  - `test_wiki_compile_prefers_recent_occurred_at`
  - `test_retention_still_decays_from_created_at`: `mnemon:apply-retention
    --dry-run` reports no soft-delete for a fresh drawer whose `occurred_at` is
    a year ago.
- [ ] **Step 2:** Run them in Docker. Expected: FAIL.
- [ ] **Step 3:** Implement. The migration adds the column and its index on both
  drivers. The search code reads `$row->occurred_at ?? $row->created_at`.
- [ ] **Step 4:** Run the full PHP suite and pint. Expected: all pass.
- [ ] **Step 5:** Commit: `feat: drawers record when the conversation happened (occurred_at)`.

### Task 2: `drawer_add` resolves a `project`

**Files:**
- Modify: `app/Mcp/Tools/DrawerAddTool.php`
- Modify: `app/Services/SessionDigestService.php`. Make `sessionWingSlug` reusable: move it to `public static function wingSlugForProject(string $project, array $tokenWings, ?array $patterns): ?string` on `App\Support\ProjectWings`, a new file, and call it from both tools.
- Create: `app/Support/ProjectWings.php`
- Test: `tests/Feature/Mcp/Tools/DrawerAddProjectTest.php`, plus a new row in `tests/Feature/Mcp/WingIsolationTest.php`

**Interfaces:**
- Produces:
  - **`drawer_add` arguments.** `drawer_add` accepts `project` (string, max
    100) **instead of** `wing`. Exactly one of the two is required.
  - **Resolution.** With `project`, the wing is
    `ProjectWings::wingSlugForProject`. An existing visible wing, aliases
    included, is used. A new `project-<slug>` is created only for an
    unrestricted token.
  - **Errors.** A hidden or missing wing for a restricted token gives the same
    error text as `requireWingAccess` gives for a missing wing.
  - **Result.** It gains `wing` (the slug used) and `created` (bool: a new
    drawer, as opposed to a dedup hit).

- [ ] **Step 1: Write the failing tests:**
  - `test_project_resolves_to_existing_project_wing`
  - `test_project_resolves_through_an_alias`
  - `test_project_creates_wing_for_unrestricted_token`
  - `test_restricted_token_cannot_reach_a_hidden_project_wing` (its error equals
    the missing-wing error)
  - `test_wing_and_project_together_is_an_error`
  - `test_result_reports_created_false_on_dedup`
  - a WingIsolationTest row, "drawer_add by project"
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement. `SessionDigestService` calls the moved helper, with
  its behaviour unchanged.
- [ ] **Step 4:** Run the full PHP suite and pint. Expected: pass.
- [ ] **Step 5:** Commit: `feat: drawer_add files by project into the same wing digests use`.

### Task 3: Digest usage and a completion-token limit

**Files:**
- Modify: `app/Services/Digest/OpenAiDigestDriver.php`
- Modify: `app/Services/SessionDigestService.php`
- Modify: `config/mnemon.php` (the `digest` section)
- Test: `tests/Unit/Services/Digest/OpenAiDigestDriverTest.php` (extend or create with `Http::fake`), `tests/Feature/Mcp/Tools/SessionDigestToolTest.php`

**Interfaces:**
- Produces:
  - **Config:** `config('mnemon.digest.max_completion_tokens')`, from env
    `MNEMON_DIGEST_MAX_COMPLETION_TOKENS`, default `16000`. It is sent as
    `max_completion_tokens` in the chat request.
  - **Driver:** `OpenAiDigestDriver::lastUsage(): ?array`, shaped
    `{prompt_tokens:int, completion_tokens:int, reasoning_tokens:int}` from the
    response's `usage` (and `usage.completion_tokens_details.reasoning_tokens`,
    0 if absent). It is null before a call, or when the response has none.
  - **Result:** `session_digest` adds `usage` (that shape, or null) on a fresh
    digest. A replay has no `usage` key (it already has `replayed: true`).
  - **Service:** it reads usage via `method_exists($this->llm, 'lastUsage')`,
    so other LLM fakes keep working.

- [ ] **Step 1: Write the failing tests:**
  - `test_driver_sends_max_completion_tokens`
  - `test_driver_exposes_usage_including_reasoning_tokens`
  - `test_session_digest_result_carries_usage`
  - `test_replay_result_has_no_usage`
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the full PHP suite and pint. Expected: pass.
- [ ] **Step 5:** Commit: `feat: session_digest reports token usage; cap model output`.

### Task 4: `brain_status` publishes a `digest` block

**Files:**
- Modify: `app/Mcp/Tools/BrainStatusTool.php`
- Modify: `config/mnemon.php`
- Test: `tests/Feature/Mcp/Tools/BrainStatusDigestTest.php`, plus a WingIsolationTest row

**Interfaces:**
- Consumes: `ProjectWings::wingSlugForProject` (Task 2).
- Produces:
  - **New input:** `brain_status` gains an optional `projects` argument, an
    array of strings, max 500.
  - **Result:** it gains

    ```
    digest: {model, price_in_per_mtok, price_out_per_mtok, prompt_overhead_tokens,
             typical_output_tokens, max_completion_tokens,
             projects: {<name>: <wing slug>|null}}
    ```

    `projects` is present only when `projects` was sent. The value is the
    visible wing the project resolves to, or null when the project is new,
    hidden or unresolvable.
  - **Config:** `mnemon.digest.pricing.in_per_mtok` (env
    `MNEMON_DIGEST_PRICE_IN_PER_MTOK`) and `.out_per_mtok` (env
    `MNEMON_DIGEST_PRICE_OUT_PER_MTOK`), both default null.
    `mnemon.digest.typical_output_tokens` (env
    `MNEMON_DIGEST_TYPICAL_OUTPUT_TOKENS`) defaults to `3000`.
  - **Overhead:** `prompt_overhead_tokens` = ceil(characters of the system
    prompt the driver would build for this token / 4), with empty recent
    drawers plus 50 × 200 characters for a full recent list. Expose a
    `public function systemPrompt(array $context): string` on the driver and
    use it in both places, so the figure can't drift from the real prompt.

- [ ] **Step 1: Write the failing tests:**
  - `test_digest_block_reports_model_and_null_prices_by_default`
  - `test_digest_block_reports_configured_prices`
  - `test_prompt_overhead_grows_with_wings_and_rooms`
  - `test_projects_resolve_to_visible_wings_only`: a hidden wing gives null,
    the same as a missing one
  - `test_projects_resolve_through_aliases`
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the full PHP suite and pint. Expected: pass.
- [ ] **Step 5:** Commit: `feat: brain_status publishes digest pricing, overhead and project resolution`.

### Task 5: Worker single-call mode, report, `occurred_at`, shared redaction

**Files:**
- Modify: `plugins/mnemon/hooks/lib/digest-worker.sh`
- Modify: `plugins/mnemon/hooks/lib/common.sh`
- Test: `tests/Hooks/run-hook-tests.sh` (new block "worker report"); the fake server `tests/Hooks/fixtures/server.sh` returns `usage` when `FAKE_USAGE=1`

**Interfaces:**
- Produces:
  - **`MNEMON_DIGEST_MAX_PASSES=<n>`:** overrides `MAX_PASSES` (default 10).
    Values below 1 are treated as 1.
  - **`MNEMON_DIGEST_REPORT=<file>`:** for each call `run_once` makes, append
    one line:

    ```
    {"start":N,"end":N,"outcome":"ok"|"transient"|"payload"|"not_retryable","replayed":bool,"turn_end":N|null,"drawers":[{"id":N,"wing":"slug"}],"dropped":N,"usage":{...}|null}
    ```

    A line is written on success **and** on failure. No call means no line.
  - **`occurred_at`:** `run_once` sends `occurred_at`, taken as the `ts` of the
    last element of the cleaned JSON array in its text file. It is omitted when
    absent or not ISO-8601, and omitted for inline transcripts.
  - **`mnemon_redact_text`:** a new function in `common.sh` that reads stdin and
    writes stdout. It applies exactly the secret rules `mnemon_clean_transcript`
    applies (PEM, rules A/B/C, named token patterns). `mnemon_clean_transcript`
    is refactored to share one jq definition with it (`def mnemon_redact:`),
    with no output change on the fixture.
  - **Temp files:** the worker removes its own `slice.$$`, `input.$$` and
    `digest-error.$$` files on EXIT/INT/TERM, by trap. `mnemon_call` already
    removes its body file on every path; verify this and add a trap if a path
    leaks it.
  - **The `<start>` argument:** a comment at the top of the worker says it is
    ignored when a transcript path is given, because the pointer is re-read
    under the lock.

- [ ] **Step 1: Write the failing hook tests:**
  - "worker: MAX_PASSES=1 sends exactly one chunk of a 3-chunk session"
  - "worker: report line on success carries drawers, usage, turn_end"
  - "worker: report line on a Retryable error has outcome transient and usage null"
  - "worker: occurred_at is the last turn's ts"
  - "worker: unset env vars change nothing", the existing capture tests
    unchanged plus an assertion that no report file is created
  - "redact: mnemon_redact_text redacts what the cleaner redacts", over each
    secret line of `tests/Hooks/fixtures/transcript-shapes.jsonl`
  - "cleaner: output unchanged after the refactor", a byte comparison against
    a snapshot taken before the change
- [ ] **Step 2:** Run the hook suite. Expected: the new tests FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the hook suite twice. Expected: 0 failures both times.
- [ ] **Step 5:** Commit: `feat: digest worker single-call mode and per-call report`.

### Task 6: `mnemon-backfill` scan (read-only)

**Files:**
- Create: `plugins/mnemon/scripts/mnemon-backfill.sh`
- Create: `plugins/mnemon/scripts/lib/backfill-scan.sh`
- Test: `tests/Hooks/run-hook-tests.sh` (block "backfill scan"), with a synthetic `~/.claude/projects` tree built in the test (no real transcripts)

**Interfaces:**
- Consumes:
  - `brain_status` with `projects` (Task 4);
  - `mnemon_redact_text` (Task 5);
  - from `common.sh`: `mnemon_slice_transcript`, `mnemon_digest_text`,
    `mnemon_project_name`, `mnemon_repo_name`, `mnemon_capture_ignored`,
    `mnemon_project_ignored`.
- Produces:
  - **`backfill_scan <projects_root>`:** writes TSV to stdout, one row per
    transcript:

    ```
    session_id  path  project  repo  status  reason  lines  pointer  calls  chars  first_prompt_hash  entrypoint  last_ts
    ```

    `status` is `eligible` or `skip`. `reason` is one of `automation`,
    `capture_ignore`, `unresolved`, `new_project`, `nomemo`, `disabled`,
    `done`, `busy`, `recent`, `id_mismatch`, `not_included`.
  - **`backfill_notes <projects_root>`:** writes TSV to stdout, one row per
    memory note:

    ```
    path  project  status  reason  mtime  bytes
    ```

  - **`mnemon-backfill.sh` with no arguments, or `--dry-run`:** prints the
    per-project table, the skip summary, the automation clusters (count,
    project, hash, 60 redacted characters) and the entrypoint mix per
    project. It also prints the estimate:
    - input tokens as a range;
    - output tokens;
    - dollars, or "unknown (no price published)";
    - wall-clock time at 20–60 s per call.

    It exits 0.
  - **Flags parsed now** (used by Task 7): `--project <name>`, `--all`,
    `--include <project>`, `--include-automation <hash>`,
    `--automation-min <n>` (default 5), `--skip-entrypoint <prefix>`,
    `--quiet-minutes <n>` (default 30), `--since <YYYY-MM-DD>`,
    `--memory-only`, `--max-tokens <n>`, `--max-cost <dollars>`, `--yes`,
    `--projects-root <dir>` (default `$HOME/.claude/projects`, for tests).
- **Rules:**
  - Files are exactly `<root>/<dir>/<uuid>.jsonl`; never recurse.
  - The session id is the file name. A file whose records carry a different
    `sessionId` is `id_mismatch`.
  - **`unresolved`** means the `cwd` is missing or `mnemon_repo_name` returns
    nothing. `--include <name>` makes those sessions eligible under project
    `<name>` when `<name>` equals the project directory's name with its
    leading `-` stripped (e.g. `-tmp-my dir` matches `tmp-my dir`).
  - **`new_project`** means `brain_status`'s `projects` answered null.
    `--include <project>` makes it eligible.
  - Defaults `typical_output_tokens` (3000) and `max_completion_tokens`
    (16000) are provisional. The trial's report prints the measured averages,
    so the operator can set the env vars.
  - The `@nomemo` test is applied to each human user record before any
    merging.
  - State files are read with `jq` directly, never through
    `mnemon_session_state`.
  - `calls` comes from simulating `mnemon_slice_transcript` then
    `mnemon_digest_text` from the pointer to the end, counting chunks without
    sending.
  - The scan writes nothing under `~/.mnemon`.
  - If simulating every file would take more than 120 s (extrapolated from
    the first 20 files), it simulates a size-stratified sample and labels the
    totals "sampled".

- [ ] **Step 1: Write the failing hook tests.** Build a synthetic tree with:
  - 2 real-work sessions in one project;
  - a 5-file identical-first-prompt cluster, and a 4-file one in another
    project;
  - a session with `@nomemo` in its third user record, merged after a
    second;
  - a session whose `cwd` doesn't exist, under a directory name with a space;
  - a `subagents/agent-1.jsonl`;
  - a file modified now;
  - a session whose state file says pointer = line count;
  - a mismatched `sessionId`;
  - two memory notes and a `MEMORY.md`.

  Assert each file's status and reason. Then assert:
  - the 4-cluster is eligible;
  - the subagent file is absent;
  - `MEMORY.md` is absent;
  - `~/.mnemon` is byte-identical before and after;
  - no `session_digest` call reached the fake server, and exactly one
    `brain_status` call did;
  - the estimate line says "unknown" when the fake server publishes no price,
    and shows dollars when it does.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the hook suite twice. Expected: 0 failures.
- [ ] **Step 5:** Commit: `feat: mnemon-backfill scan estimates before anything is spent`.

### Task 7: `mnemon-backfill` run (trial and full)

**Files:**
- Modify: `plugins/mnemon/scripts/mnemon-backfill.sh`
- Create: `plugins/mnemon/scripts/lib/backfill-run.sh`
- Test: `tests/Hooks/run-hook-tests.sh` (block "backfill run")

**Interfaces:**
- Consumes:
  - the Task 6 scan output and flags;
  - the Task 5 worker env vars and report line;
  - `drawer_add` with `project` and `occurred_at` (Tasks 1 and 2);
  - `usage` (Task 3).
- Produces:
  - **The run.** `--project <name>` or `--all` re-runs the scan, prints the
    estimate and asks `Proceed? [y/N]` on the tty (`--yes` skips the prompt).
    Then it runs notes first, then transcripts.
  - **The cap.** It is checked before every call. Either `--max-tokens`, or
    `--max-cost` when a price is known; the default is 1.25 × the estimate's
    high input+output figure. A call that reports `usage` costs
    prompt + completion tokens. Any other attempt costs the per-call estimate.
  - **Before each worker run:**
    - transcript mtime older than `--quiet-minutes`;
    - lock free;
    - `digest_retry_after` passed, otherwise sleep until it does (at most
      `digest_wait`, 1800 s).
  - **After each worker run:** the pointer is compared. No report line and no
    movement means the session stops, as "busy" or "no progress".
  - **Failure limit:** 3 consecutive failures of any kind end that session for
    this run. `not_retryable` stops the whole run.
  - **Marker ownership:** recorded before a session's first call (marker
    existed? mtime). On stopping a session, the marker is taken with
    `mnemon_digest_pending_take` only when it didn't exist before and the mtime
    is unchanged.
  - **Progress file:** `~/.mnemon/backfill/state.json`, written atomically:

    ```
    {sessions:{<sid>:{started_at, pointer, done:bool}}, spent:{in,out,calls,replays}, trial:{project, tokens_per_call}}
    ```

  - **Notes:** each note goes through `drawer_add` with `project`, room
    `memory`, content redacted with `mnemon_redact_text`, `occurred_at` set to
    the file's mtime, and metadata
    `{source_path, mtime, harness:"claude-code", backfill:true}`. A pause of
    0.6 s between calls keeps it under 120/min. HTTP 429 means sleep 30 s and
    retry, up to 3 times. A wing error counts as a drop.
  - **Report:** calls (and replays), input/output tokens against the
    estimate, cost when priced, drawers per wing, drops, skips by reason, and
    the first line of up to 3 drawers from the last three successful calls
    (fetched with `drawer_get`). A trial stores `tokens_per_call`, and a later
    `--all` estimate uses it.
  - **Ctrl-C:** an INT/TERM trap finishes marker ownership for the current
    session, writes progress, prints the report and "resume with the same
    command", and removes only the backfill's own temp files.

- [ ] **Step 1: Write the failing hook tests,** against the fake server:
  - a trial on one project digests only that project's eligible sessions, in
    one-chunk calls;
  - the cap stops before the call that would pass it, overshooting by at
    most one call;
  - a server that answers every call `Retryable:` stops the session after 3,
    and spend rises by 3 × the estimate (Review Focus 4);
  - `not_retryable` stops the whole run with its reason;
  - a session whose mtime changes between calls is left after that call, and
    its marker is kept (Review Focus 3);
  - a stopped session's marker is removed when the backfill caused it;
  - a re-run after Ctrl-C (simulated with `kill -INT` between calls) sends no
    turn twice: the fake server's request log has no repeated range;
  - notes are sent once and a re-run sends `created:false` (dedup), with no
    second spend;
  - a 429 on `drawer_add` is retried;
  - `--yes` without a tty proceeds, and no `--yes` without a tty refuses.
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the hook suite twice. Expected: 0 failures.
- [ ] **Step 5:** Commit: `feat: mnemon-backfill runs a capped, resumable trial and full import`.

### Task 8: Banner, doctor, slash command, enrolment prompt

**Files:**
- Modify: `plugins/mnemon/hooks/lib/common.sh` (`mnemon_undigested_sessions`)
- Modify: `plugins/mnemon/hooks/mnemon-wake.sh`
- Modify: `plugins/mnemon/scripts/mnemon-doctor.sh`
- Modify: `plugins/mnemon/scripts/mnemon-authorize.sh` (the success message)
- Create: `plugins/mnemon/commands/backfill.md`
- Test: `tests/Hooks/run-hook-tests.sh`

**Interfaces:**
- Consumes: `~/.mnemon/backfill/state.json` (Task 7).
- Produces:
  - **Counting.** `mnemon_undigested_sessions` excludes sessions listed in
    the backfill state with `done:false`. A new function,
    `mnemon_backfill_unfinished`, counts them.
  - **The wake banner.** It adds, when that count is > 0:
    `Mnemon: N old session(s) partly imported; run mnemon-backfill to continue.`
    It also adds, **once** per device (a flag file
    `~/.mnemon/backfill/offered`), when no backfill state exists:
    `Mnemon: bring in this device's past history with /mnemon:backfill (a free scan; nothing is spent).`
  - **The doctor** reports the same count as a separate warning line.
  - **`/mnemon:backfill`** runs
    `` !`bash "${CLAUDE_PLUGIN_ROOT}/scripts/mnemon-backfill.sh" --dry-run` ``
    with `allowed-tools: Bash(bash:*)`. It then tells the user the trial
    command to run in a terminal.
  - **`mnemon-authorize.sh` on success** prints two lines: the scan command
    and the trial command. Setup itself can't scan, because the device isn't
    enrolled yet when `install.sh` ends.

- [ ] **Step 1: Write the failing hook tests:**
  - "banner: a stopped backfill session is counted as partly imported, not undigested" (Review Focus 2)
  - "banner: offer shown once"
  - "doctor: partly imported sessions get their own line"
  - "authorize: success names the backfill scan"
- [ ] **Step 2:** Run them. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run the hook suite twice. Expected: pass.
- [ ] **Step 5:** Commit: `feat: banner, doctor and /mnemon:backfill point to the backfill`.

### Task 9: Docs, version, release notes

**Files:**
- Modify: `docs/USERGUIDE.md` (new section "Bring in your history")
- Modify: `README.md` (one line under the plugin features)
- Modify: `CHANGELOG.md` (`[Unreleased]`: Added and Changed entries for Tasks 1–8, plugin 0.3.12)
- Modify: `plugins/mnemon/.claude-plugin/plugin.json`, `.claude-plugin/marketplace.json` (0.3.12)
- Modify: `.env.example` (the four new `MNEMON_DIGEST_*` env vars, commented)

**"Bring in your history" covers:**
- the scan, the trial and the full run;
- the cost model: input = chars ÷ 4 + overhead, output includes reasoning;
  the estimate is a range, and is recalibrated by the trial;
- every skip reason and its override flag;
- resuming;
- original dates (`occurred_at`) and what they change in search;
- that live capture and the backfill share progress, and that resuming an old
  session digests it live;
- that the operator must set `MNEMON_DIGEST_PRICE_*` for dollar figures.

- [ ] **Step 1:** Write the docs. Check every flag and env var against the
  code with grep.
- [ ] **Step 2:** Bump the version. Run the hook suite and the full PHP suite.
  Expected: pass.
- [ ] **Step 3:** Commit: `docs: bring in your history; plugin 0.3.12`.
