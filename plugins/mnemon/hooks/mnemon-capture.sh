#!/usr/bin/env bash
# Mnemon Stop hook: decide whether there is anything to digest, then dispatch
# the detached digest worker, which slices, cleans and sends.

set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "$SCRIPT_DIR/lib/common.sh"

mnemon_disabled && exit 0

input=$(cat 2>/dev/null || echo '{}')
session_id=$(printf '%s' "$input" | jq -r '.session_id // empty')
transcript=$(printf '%s' "$input" | jq -c '.transcript // empty')
transcript_path=$(printf '%s' "$input" | jq -r '.transcript_path // empty')
cwd=$(printf '%s' "$input" | jq -r '.cwd // empty')

[ -z "$session_id" ] && exit 0
mnemon_capture_ignored "$(mnemon_repo_name "$cwd")" && exit 0

# Claude Code's Stop payload carries `transcript_path` -- a path to the session
# JSONL -- and neither an inline transcript nor a turn index. Reading a
# `.transcript` key that is never sent makes capture a permanent silent no-op.
# An inline array is still accepted so callers that supply one keep working.
if [ -z "$transcript" ] || [ "$transcript" = "null" ]; then
    [ -n "$transcript_path" ] || exit 0
    if [ ! -r "$transcript_path" ]; then
        mnemon_log_error "capture for session=$session_id: transcript $transcript_path is not readable; nothing sent"
        exit 0
    fi
    # No turn index is supplied; the transcript's line count is monotonic, so it
    # serves as one.
    turn_index=$(wc -l < "$transcript_path" | tr -d ' ')
else
    turn_index=$(printf '%s' "$input" | jq -r '.turn_index // 0')
fi

mnemon_token > /dev/null || exit 0

state=$(mnemon_session_state "$session_id")
IFS=$'\t' read -r nomemo disabled last_turn retry_after stored_path <<< \
    "$(printf '%s' "$state" | jq -r '[(.nomemo // false), (.disabled // false), (.last_digest_turn // 0),
        ((.digest_retry_after // 0) | floor), (.transcript_path // "")] | @tsv')"
[ "$nomemo" = "true" ] && exit 0
[ "$disabled" = "true" ] && exit 0

# A range handed to a digest that has since moved past it is spent.
mnemon_digest_pending_drop_spent "$session_id" "$last_turn"

[ "$turn_index" -le "$last_turn" ] && exit 0

# Where this session's transcript is, so SessionStart can tell whether a
# session left with undigested turns still has them (mnemon_undigested_sessions).
# Written only when it changes.
if [ -n "$transcript_path" ] && [ "$transcript_path" != "$stored_path" ]; then
    mnemon_session_state_update "$session_id" '.transcript_path = $p' --arg p "$transcript_path"
fi

# Back off after failures (the worker sets digest_retry_after: the next Stop
# after one failure, then 60s doubling to 30 minutes; 30 minutes at once for a
# failure the server says retrying cannot fix). A Stop inside the wait still
# records its range, so the end of a session that finishes while backing off
# is retried by the next Stop -- including when the session is resumed.
if [ "$(date +%s)" -lt "$retry_after" ]; then
    [ -n "$transcript_path" ] && mnemon_digest_pending_add "$session_id" "$transcript_path" "$turn_index"
    exit 0
fi

# The digest worker ships alongside this script. It used to be written here from
# a heredoc on first run, guarded by [ ! -x ], which meant a copy from an older
# release shadowed its own source forever -- and under a plugin the hooks
# directory is a replaced-on-update cache that must not be written to at all.
worker="$SCRIPT_DIR/lib/digest-worker.sh"
if [ ! -r "$worker" ]; then
    mnemon_log_error "digest worker missing at $worker - reinstall the Mnemon hooks"
    exit 0
fi

# A transcript file is sliced, cleaned and sent by the worker, holding the
# session's lock: chunk boundaries then follow from the pointer it reads under
# that lock, and the Stop hook does none of the work. An inline transcript has
# no file to come back to, so it is cleaned here and sent whole.
#
# Only the conversation goes to the digest: user and assistant text, with tool
# I/O, thinking, attachments, hook output (Mnemon's own recall and wake text
# among it) and harness wrappers removed and secrets redacted. See
# mnemon_clean_transcript.
text_tmp=""
if [ -z "$transcript_path" ]; then
    text_tmp="$MNEMON_SESSIONS_DIR/${session_id}.digest-input.$$.txt"
    raw_tmp="$MNEMON_SESSIONS_DIR/${session_id}.inline.$$.jsonl"
    printf '%s' "$transcript" > "$raw_tmp"
    mnemon_digest_text "$raw_tmp" "$text_tmp" > /dev/null
    rc=$?
    rm -f "$raw_tmp"
    if [ "$rc" -ne 0 ]; then
        # 1: no conversation; 2: cleaning failed (logged).
        rm -f "$text_tmp"
        [ "$rc" -eq 1 ] && mnemon_session_state_update "$session_id" '.last_digest_turn = $t' --argjson t "$turn_index"
        exit 0
    fi
fi

project=$(mnemon_project_name "$cwd")

# With no project, the server files project-specific drawers as unfiled rather
# than trust the model's guess -- unless the repository is in project_ignore,
# where the guess is wanted. Say which, so the server can tell the two apart
# from a client too old to send a project at all.
filing=""
if [ -z "$project" ] && mnemon_project_ignored "$(mnemon_repo_name "$cwd")"; then
    filing="model"
fi

# Detach.
nohup "$worker" "$session_id" "$last_turn" "$turn_index" "$text_tmp" "$project" "$filing" "$transcript_path" \
    >>"$MNEMON_ERROR_LOG" 2>&1 < /dev/null &
disown 2>/dev/null || true

exit 0
