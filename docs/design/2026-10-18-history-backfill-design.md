# History backfill: bring a device's past Claude Code history into Mnemon

**Date:** 2026-10-18, rewritten 2026-10-27 on top of v1.0.1 (plugin 0.3.11)
**Status:** passed two rounds of adversarial review on 2026-10-27. Its findings and Cooper's
answers are folded in. Cooper pre-approved the build, conditional on that
review.

## Why

A device that installs the Mnemon plugin only remembers from then on. But a
machine that has used Claude Code for months already holds the history that
matters most. On the Windows device enrolled on 2026-10-18 (vykix) this is:
- 724 session transcripts, about 660 MB raw;
- 63 curated memory notes.

Without a backfill, a new device starts Mnemon empty while its owner's
knowledge sits on that disk. The server-side importers read OpenClaw files on
the server; none reads a device's Claude Code history.

## What success looks like (Cooper's words, 2026-10-18)

**"A cost estimate and a trial on one project first"**, before any money is
spent. The rollout order:
1. Import the memory notes.
2. Trial the transcript backfill on one project.
3. Review its quality and cost.
4. Only then run the rest.

## Goals

- A device-side command, `mnemon-backfill`, that imports a device's past
  Claude Code memory notes and session transcripts into its Mnemon instance.
- **Estimate first.** No run that calls the model starts without an estimate
  the user has confirmed, and no run passes its cap.
- **The trial is one project.** Its report gives:
  - actual tokens, and cost when the price is known, against the estimate;
  - drawers created per wing;
  - drops;
  - sample drawers.
- **Resumable, interruptible and idempotent.** Re-running never pays twice for
  the same turns.
- **Portable.** It works wherever the hooks work: Linux, macOS (bash 3.2), and
  Git Bash on Windows.

## Non-goals

- Other harnesses (Cursor, ChatGPT, OpenClaw).
- A UI beyond a terminal command and a `/mnemon:backfill` slash command that
  runs only the free scan.
- Changing what a digest extracts. The backfill sends exactly what live
  capture would.
- **Repairing pointers set by plugins before 0.3.11** (Cooper, 2026-10-27:
  accept the gaps). Older workers could mark turns done after sending only the
  tail, or after a silent failure. On the author's machine 27 sessions are
  like this. The backfill trusts every existing pointer and sends only turns
  never marked done.
- A server-side spending budget per client. Today, revoking a token is the
  only server-side stop. That belongs to device management.

## Anti-goals (failure even if it "works")

- **Surprise spend.** A model call without a confirmed estimate, or one past
  the cap.
- **Digesting automation.** Heartbeats, cron prompts and scripted SDK runs. In
  2026-10 a heartbeat flood created 518 junk drawers. A transcript doesn't
  record whether its session ran under `MNEMON_DISABLE`. On the author's
  machine, about 850 of 1,045 transcripts are automated sessions sharing a few
  identical first prompts. They are excluded today only because their repo
  happens to be in `capture_ignore`.
- **Leaking what capture keeps out:** tool input and output, thinking, Mnemon's
  own output, or secrets.
- **Ignoring opt-outs:** `capture_ignore`, `@nomemo`, or a session state that
  says `disabled`.
- **Bypassing wing restrictions.**
- **Racing live capture.** Two digests of the same turns, or a rewound pointer.
- **Leaving false alarms.** Hundreds of "undigested" sessions in the wake
  banner or the doctor after a stopped run.

  **Accepted:** an old session the user resumes is digested from its pointer
  by live capture, 10 chunks per Stop, as it already is in 0.3.11. Resuming is
  the user's act. The backfill does not try to stop it.
- **Burying current work.** Months-old history outranking this week's in
  recall.

## How it fits on v1.0.1

Live capture now does the hard parts:
- cleaning (`mnemon_clean_transcript`, conversation text only, redacted);
- oldest-first chunking (`digest_from_pointer`);
- a lock per session;
- a pointer that only moves forward, after the server accepts a chunk;
- retryable failures with backoff;
- replay by start.

**The backfill drives that same worker; it doesn't copy it.** One code path
decides what is sent, and progress is shared: what the backfill digests, live
capture never sends again.

## Decided

### Runs on the device, as a plugin script

`plugins/mnemon/scripts/mnemon-backfill.sh` sources `hooks/lib/common.sh`. It
runs in the foreground, in sequence. It runs the worker in single-call mode, one
call at a time, and checks the cap, Ctrl-C and backoff **before every model
call**.

### Which files count

- **Transcripts:** `~/.claude/projects/<dir>/<uuid>.jsonl`, at exactly that
  depth. Never recurse: `<uuid>/subagents/agent-*.jsonl` carries its parent's
  `sessionId`, and mixing two files' line numbers under one pointer would
  corrupt it. The session id is the file name. On the author's machine it
  matches the records' `sessionId` in every file; one that doesn't is skipped
  and reported.
- **Memory notes:** `~/.claude/projects/<dir>/memory/*.md`, excluding
  `MEMORY.md`.
- **Recent files:** any file modified in the last 30 minutes
  (`--quiet-minutes`) is skipped. It may be a live session, owned by its hooks.

### Who decides what is skipped: the scan, listed, overridable

Every skip is listed in the scan with its reason. These are the reasons:

1. **Automation.** By default, the scan hashes each transcript's first human
   prompt. A prompt shared by 5 or more sessions (`--automation-min`) marks
   all of them as automation.
   - The scan lists each cluster: its count, project and the first 60
     characters of the prompt, redacted.
   - `--include-automation <hash>` brings one cluster back in.
   - **The entrypoint is not a default signal.** Real work sessions can be
     `sdk-py` too (several on the author's machine are). The scan shows each
     project's entrypoint mix, and `--skip-entrypoint <prefix>` skips by it
     when asked.
   - My call (Cooper wasn't asked): prompt clusters only, threshold 5. A
     common opening, such as a slash command, can form a cluster, but it is
     listed and can be overridden.
2. **`capture_ignore`.**
3. **Unresolved or new projects** (Cooper, 2026-10-27: skip unless included).
   Resolution works as follows:
   - The project comes from the records' `cwd` via `mnemon_project_name`.
   - When that `cwd` no longer exists or isn't a repo, the repo name can't be
     resolved. `capture_ignore` can then no longer be checked, so the session
     is **unresolved** and skipped.
   - A resolvable project with no wing yet on the server is **new** and
     skipped. The server decides this, so aliases count: `brain_status`'s
     `digest` block takes the scan's project names and answers which ones
     resolve to a visible wing, using `Wing::forProject` and the same
     visibility check as `session_digest`.
   - `--include <project>` brings a project in. For a new project, live capture's
     rules create its wing.
   - For an unresolved session, `--include` maps it by the directory name under
     `~/.claude/projects/`.
   - The scan asks the server which wings exist (`brain_status`), which costs
     nothing.
4. **`@nomemo`.** Any human user record whose text starts with `@nomemo`, by
   the hook's own test (`^[[:space:]]*@nomemo\b`, case-insensitive). It is
   checked on each record **before** the cleaner merges consecutive turns. A
   state file saying `nomemo` or `disabled` also skips the session.
5. **Done.** `last_digest_turn` ≥ the line count.
6. **Busy.** The session's digest lock is held, because live capture is
   digesting it.

**The scan writes nothing.** It reads state files with jq directly, not with
`mnemon_session_state`, which creates the file it reads.

### Transcripts go through the digest worker, one call at a time

For each eligible session, repeatedly:

```
MNEMON_DIGEST_MAX_PASSES=1 MNEMON_DIGEST_REPORT=<file> \
  digest-worker.sh <session_id> 0 <line_count> "" <project> <filing> <transcript_path>
```

**Worker changes.** Both are backwards compatible; unset, live capture behaves
exactly as today.
- `MNEMON_DIGEST_MAX_PASSES` overrides `MAX_PASSES` (10). The backfill sets 1,
  so each run makes at most one model call.
- `MNEMON_DIGEST_REPORT=<file>` makes the worker append one JSON line per call:
  - range;
  - outcome: ok, `transient`, `payload` or `not_retryable`;
  - whether it was a replay;
  - persisted drawer ids with their wings;
  - `dropped_outside_wings`;
  - `usage`.

  When a run writes no line, the backfill reads that as "busy" (the worker
  lost the lock and handed its range to the marker) or "nothing to send".

**The `<start>` argument is ignored when a path is given.** The worker
re-reads the pointer under the lock. The backfill passes 0, and a comment says
why, so nobody "fixes" it.

**Every run is checked before it starts and after it ends.**
- **Before each run:** the transcript's mtime must still be older than
  `--quiet-minutes`, and the session's digest lock must be free. If a session
  comes alive during a run that lasts hours, it is left to its hooks from that
  call on.
- **After each run:** the pointer is compared with its value before. A run
  that wrote no report line and didn't move the pointer stops that session for
  this run, and is reported as "busy" or "no progress". This covers lines that
  are gone, an unreadable file and a lost lock, so the loop always ends.

**Every attempt counts against the cap and a failure limit.**
- **Charging.** A call that reports `usage` is charged its actual tokens.
  Every other attempt is charged the per-call estimate: a failure, or a call
  with no report line. Some failures are still billed by the model, such as an
  unparseable answer or a timeout after the model ran.
- **Failure limit.** Three failures of any kind in a row stop that session for
  this run. This counts both payload failures on a multi-line chunk, which the
  worker never skips, and transient ones. So "overshoots by at most one call"
  holds.

**The pending marker is owned while the backfill runs.** The worker writes a
marker whenever a chunk ends before `<end>`. Live capture relies on that
marker. But a backfill that stops would leave one on every session it touched,
and each would then show in the wake banner and doctor as "sent the next time
that session runs". That's false for dead sessions, and it means live capture
would spend on a resumed old session without an estimate.
- The marker records only `{path, end}`, so it can't say who caused it.
  Ownership is therefore decided by the backfill. It notes whether a marker
  existed before it first touched the session, and the transcript's mtime at
  that point.
- When it stops a session (it finishes, hits the cap, a failure stops it, or
  Ctrl-C), it takes the marker under the lock **only if** none existed before
  and the transcript's mtime is unchanged. Otherwise a live Stop may have added
  a range, and the marker is left for live capture.
- It records its own progress in `~/.mnemon/backfill/state.json`: the sessions
  started, and the pointer each reached.
- The banner and the doctor count backfill-started sessions apart from live
  ones. They say "N old sessions partly imported; run `mnemon-backfill` to
  continue" rather than "sent the next time that session runs".

**Failures** (within the limit above):
- **transient:** wait out the session's `digest_retry_after` (the worker's own
  backoff), at most 3 times in a row, then stop the whole run with "resume with
  the same command".
- **not_retryable** (for example, no API key on the server): stop at once and
  say why.
- **payload:** the worker's skip-after-3 rule applies, as it does live.
- **HTTP 429:** back off and retry. The MCP limit is 120/min per token, shared
  with that device's live hooks.

**Ctrl-C** finishes or abandons the current call and then stops. The pointer
only ever reflects accepted chunks, and replay by start covers a call the
server committed while the client was interrupted. An INT/TERM trap removes the
backfill's own temp files. The worker and `mnemon_call` remove their own
slice, input and `mnemon-body.*` files on exit, by trap. The backfill never
globs `$TMPDIR`, because live hooks may have bodies in flight there.

### Memory notes go through `drawer_add`

- Each note is one verbatim drawer in room `memory`, with no model call. Its
  metadata records the source path, the mtime and `harness: claude-code`.
- Content-hash dedup makes a re-run harmless. An edited note becomes a new
  drawer, and the scan counts new notes.
- **The same wing as the project's digests.** Today `drawer_add` slugs a raw
  wing name and runs `firstOrCreate`, so a note could land in `mnemon` while
  digests go to `project-mnemon`. So `drawer_add` gains an optional `project`
  argument, resolved server-side with `Wing::forProject` (aliases included).
  It goes through the same visibility check as `session_digest`'s session
  wing, so a hidden wing answers exactly like a missing one. A project with no wing follows the same skip-unless-
  included rule.
- **Same opt-outs as transcripts.** A note from a `capture_ignore` or
  unresolved project is skipped.
- **Redacted on the device** with the same rules as transcripts. The redaction
  is factored out of `mnemon_clean_transcript` into one function both call. The
  server's ContentSanitizer is the second layer.
- **A wing denial is a drop, counted.** It doesn't stop the run.
- **Throttled** below the rate limit.

### Original dates (Cooper, 2026-10-27: original date)

- `session_digest` and `drawer_add` take an optional `occurred_at`, an
  ISO-8601 time, and store it on the drawer in a new nullable, indexed
  `occurred_at` column.
  - For a digest it is the timestamp of the chunk's last turn (the cleaner's
    `ts`).
  - For a note it is the file's mtime.
  - Live capture sends it too, which costs nothing.
- **Search recency uses `occurred_at`** when it is set and `created_at` when it
  isn't. This is the 7-day temporal boost in `PalaceSearchService`; the queries
  that feed it must select `occurred_at`. Old history then gets no recency
  boost.
- **Wiki compile** picks its newest drawers by
  `COALESCE(occurred_at, created_at)` (`WikiCompileTool`), so imported history
  doesn't crowd out current work.
- **The server clamps** a client-sent `occurred_at` to no later than now. A
  future date would otherwise keep the boost indefinitely.
- **Retention is unchanged.** It keeps decaying from `created_at`, the import
  date, so imported history isn't soft-deleted the week it arrives.
- The palace browser shows `occurred_at` when it is present.

### Estimate first

`mnemon-backfill` with no arguments is the **free scan**. It makes no model
call.

**Calls are simulated, not divided.** The count is not `cleaned_chars / 60000`.
Each pass first slices at most `max_digest_bytes` (2 MB) of raw lines, then
chunks the cleaned text. At about 2.5% cleaned, the raw slice often decides the
count, and every session with any conversation costs at least one call. So the
scan runs the same slice-then-clean-then-chunk steps per file (`mnemon_slice_transcript`
and `mnemon_digest_text`, without sending anything) and counts the calls.

**Per-call tokens:**
- **Input** = the chunk's characters ÷ 4, plus `prompt_overhead`.
- **Prompt overhead.** It is not small: every call carries every visible wing
  slug, every room slug, and up to 50 recent drawers at 200 characters each.
  That's about 1.5k–5k tokens, and it grows as the run creates rooms. The
  server computes the current figure, and the estimate uses a range from that
  figure to 1.5×.
- **Output** includes reasoning tokens, since the digest model is a reasoning
  model. The server publishes a typical figure. The driver also gets a
  `max_completion_tokens` limit (config, with a generous default), so one call
  can't run away.

**Price.** `brain_status` gains a `digest` block:
- `model`;
- `price_in_per_mtok` and `price_out_per_mtok`, from `config('mnemon.digest.pricing')`
  via env, defaulting to null;
- `prompt_overhead_tokens`, computed now for this token's visible wings and
  rooms;
- `typical_output_tokens`.

A null price, or a server too old to publish the block, means the scan shows
tokens and calls, and says the dollar figure is unknown.

**Actuals.** `session_digest` returns `usage` (prompt, completion and
reasoning tokens). A replay returns none, and the backfill reports replays
separately. The full-run estimate is recalibrated from the trial's actual tokens
per call.

**Time.** A call takes about 20–60 s. The estimate shows the wall-clock range:
on vykix, roughly 1,100 calls is 6–18 hours. It runs in a terminal and can be
resumed.

**Speed.** If simulating every file takes more than a couple of minutes, the
scan samples and extrapolates, and says so on screen.

### The trial

`mnemon-backfill --project <name>`:
1. prints that project's estimate;
2. asks to confirm (`--yes` skips the prompt for scripted use, never the cap);
3. imports the project's memory notes, then digests its sessions;
4. reports:
   - calls made, and how many were replays;
   - input and output tokens, and cost when priced, against the estimate;
   - drawers per wing;
   - drops;
   - skips by reason;
   - the first line of 3 drawers from the last three successful calls.

### The full run

- `mnemon-backfill --all` re-estimates what's left, recalibrated from any
  trial, asks to confirm, then runs.
- **The cap covers input + output tokens.** `--max-tokens <n>` sets it, and so
  does `--max-cost <dollars>` when priced. The default is the estimate plus
  25%. The cap is checked before every call, so a run overshoots by at most one
  call.
- **Other flags:**
  - `--since <date>` and `--project` narrow the scope;
  - `--dry-run` is the scan;
  - `--memory-only` imports notes only;
  - `--include`, `--include-automation`, `--automation-min` and
    `--skip-entrypoint` adjust the skips.
- **Progress:** one line per call (project, session, chunk, drawers, tokens) and
  a running total. It can be resumed with the same command.

### When it's offered

**My call** (Cooper wasn't asked):
- The `/install` setup script runs the free scan at the end. It prints a
  two-line summary and the trial command. It never spends.
- The first SessionStart banner after enrolment mentions `/mnemon:backfill`
  once.
- `/mnemon:backfill` runs only the scan. Spending runs happen in a terminal,
  where the confirmation prompt works.

### Docs

- The user guide gets a "Bring in your history" section:
  - the scan, trial and full run;
  - the cost model and its error bars;
  - every skip reason and how to override it;
  - resuming;
  - original dates;
  - that live capture and the backfill share progress.
- A README line and a CHANGELOG entry.
- `WingIsolationTest` gets a row for `drawer_add`'s `project` argument.

## Left to the builder

- **Report format.** The exact JSON of the worker's report line.
- **Default figures.** `typical_output_tokens` and `max_completion_tokens`:
  measure them on the live instance with a few real digests and state what you
  found.
- **Scan speed.** Over 660 MB, measure first, then decide whether to sample.
- **The migration** for `occurred_at` and the change to the temporal boost.
  Both PostgreSQL and SQLite paths need tests.
- **Portability.** Git Bash on Windows, macOS bash 3.2, and paths with spaces.
  The `mnemon-doctor` review found real breakage there.
