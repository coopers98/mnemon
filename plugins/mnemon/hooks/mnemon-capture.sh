#!/usr/bin/env bash
# Mnemon Stop hook: slice, clean, dispatch detached digest worker.

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
    [ -n "$transcript_path" ] && [ -r "$transcript_path" ] || exit 0
    # No turn index is supplied; the transcript's line count is monotonic, so it
    # serves as one. The content itself is read later, once the last digested
    # turn is known, so only the new turns are loaded.
    turn_index=$(wc -l < "$transcript_path" | tr -d ' ')
else
    turn_index=$(printf '%s' "$input" | jq -r '.turn_index // 0')
    [ -z "$transcript" ] || [ "$transcript" = "null" ] && exit 0
fi

mnemon_token > /dev/null || exit 0

state=$(mnemon_session_state "$session_id")
[ "$(printf '%s' "$state" | jq -r '.nomemo')" = "true" ] && exit 0
[ "$(printf '%s' "$state" | jq -r '.disabled')" = "true" ] && exit 0

last_turn=$(printf '%s' "$state" | jq -r '.last_digest_turn')
[ "$turn_index" -le "$last_turn" ] && exit 0

# Where this session's transcript is, so SessionStart can retry a session that
# ended with turns undigested (see mnemon-wake.sh).
if [ -n "$transcript_path" ]; then
    mnemon_session_state_update "$session_id" '.transcript_path = $p | .cwd = $c' \
        --arg p "$transcript_path" --arg c "$cwd"
fi

# Back off after failures (the worker sets digest_retry_after: the next Stop
# after one failure, then 60s doubling to 30 minutes; 30 minutes at once for a
# failure the server says retrying cannot fix). A Stop inside the wait still
# records its range, so the end of a session that finishes while backing off
# is retried -- by a later Stop, or by the next SessionStart.
retry_after=$(printf '%s' "$state" | jq -r '.digest_retry_after // 0 | floor')
if [ "$(date +%s)" -lt "$retry_after" ]; then
    [ -n "$transcript_path" ] && mnemon_digest_pending_add "$session_id" "$transcript_path" "$turn_index"
    exit 0
fi

# After a timeout, a smaller slice (see mnemon_digest_end). Only a transcript
# file can be sliced; an inline transcript goes whole.
[ -n "$transcript_path" ] && turn_index=$(mnemon_digest_end "$state" "$last_turn" "$turn_index")

worker="$SCRIPT_DIR/lib/digest-worker.sh"
if [ ! -r "$worker" ]; then
    mnemon_log_error "digest worker missing at $worker - reinstall the Mnemon hooks"
    exit 0
fi

# Send only the turns added since the last digest. Re-sending the whole
# transcript every time re-digests content already stored and grows without
# bound: a reverse proxy rejects the request with 413 once the body passes its
# limit (1MiB by default), and the HTML error page comes back to the caller as
# a jq parse error rather than anything resembling "too big".
#
# Only the conversation goes to the digest: user and assistant text, with tool
# I/O, thinking, attachments, hook output (Mnemon's own recall and wake text
# among it) and harness wrappers removed and secrets redacted. See
# mnemon_clean_transcript.
text_tmp="$MNEMON_SESSIONS_DIR/${session_id}.digest-input.$$.txt"
raw_tmp="$MNEMON_SESSIONS_DIR/${session_id}.slice.$$.jsonl"
if [ -n "$transcript_path" ]; then
    mnemon_slice_transcript "$transcript_path" "$last_turn" "$turn_index" "$raw_tmp" \
        || { rm -f "$raw_tmp"; exit 0; }
else
    printf '%s' "$transcript" > "$raw_tmp"
fi
mnemon_digest_text "$raw_tmp" "$text_tmp"
rc=$?
rm -f "$raw_tmp"

case "$rc" in
    0) ;;
    1)
        # No conversation in the slice: tool calls and hook output only. It
        # starts at last_digest_turn, so nothing before it is undigested, and
        # moving past it keeps a tool-only stretch from making every Stop
        # re-read an ever-growing tail. Unless a digest is running: it may fail
        # and must be able to resend from its own start, which an advanced
        # pointer would skip. The range goes to that digest instead.
        if [ -d "$MNEMON_SESSIONS_DIR/${session_id}.digest.lock.d" ]; then
            [ -n "$transcript_path" ] && mnemon_digest_pending_add "$session_id" "$transcript_path" "$turn_index"
        else
            mnemon_session_state_update "$session_id" '.last_digest_turn = $t' --argjson t "$turn_index"
        fi
        exit 0
        ;;
    *)
        # Cleaning failed and was logged. The pointer stays, so the slice is
        # tried again rather than skipped.
        rm -f "$text_tmp"
        exit 0
        ;;
esac

# The digest worker ships alongside this script. It used to be written here from
# a heredoc on first run, guarded by [ ! -x ], which meant a copy from an older
# release shadowed its own source forever -- and under a plugin the hooks
# directory is a replaced-on-update cache that must not be written to at all.
# (Checked above, before any work is done.)

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
