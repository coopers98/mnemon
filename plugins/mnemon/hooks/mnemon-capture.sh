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

# Send only the turns added since the last digest. Re-sending the whole
# transcript every time re-digests content already stored and grows without
# bound: a reverse proxy rejects the request with 413 once the body passes its
# limit (1MiB by default), and the HTML error page comes back to the caller as
# a jq parse error rather than anything resembling "too big".
if [ -n "$transcript_path" ]; then
    slice_file="$MNEMON_SESSIONS_DIR/${session_id}.slice.$$.jsonl"
    tail -n +"$((last_turn + 1))" "$transcript_path" > "$slice_file" 2>/dev/null || exit 0

    # Bound the work on a long first run. Trim whole records from the front:
    # tail -c can land mid-line, and the leading partial is dropped so the
    # slice stays valid JSONL. Cleaning keeps a few percent of a raw slice, so
    # this cap is sized for the work, not for the server's limit -- that is
    # max_digest_chars, below. At the old 256KiB a long slice cleaned down to a
    # few KB and most of its conversation was never digested.
    max_bytes=$(mnemon_config_value 'max_digest_bytes' 2097152)
    if [ "$(wc -c < "$slice_file")" -gt "$max_bytes" ]; then
        tail -c "$max_bytes" "$slice_file" | tail -n +2 > "$slice_file.cap" \
            && mv "$slice_file.cap" "$slice_file"
    fi

    text=$(mnemon_clean_transcript "$slice_file" 2>/dev/null)
    rm -f "$slice_file"
else
    text=$(printf '%s' "$transcript" | mnemon_clean_transcript 2>/dev/null)
fi

# Only the conversation goes to the digest: user and assistant text, with tool
# I/O, thinking, attachments, hook output (Mnemon's own recall and wake text
# among it) and harness wrappers removed and secrets redacted. See
# mnemon_clean_transcript. A slice with no conversation in it -- tool calls and
# hook output only -- sends nothing and leaves the pointer alone, so its turns
# are looked at again next time rather than skipped past a digest still in
# flight for an earlier range.
[ -z "$text" ] || [ "$text" = "[]" ] && exit 0

# The final guard: what the server accepts. `session_digest` validates the
# payload it receives (`transcript` => `required|string|max:200000`), and a
# rejected digest never advances last_digest_turn, so the next Stop would
# re-send the same oversized slice and fail identically, forever. Measured on
# the cleaned text, because that is what arrives. With cleaning this rarely
# triggers.
max_chars=$(mnemon_config_value 'max_digest_chars' 190000)
if [ "$(printf '%s' "$text" | wc -c)" -gt "$max_chars" ]; then
    # Drop whole leading records first, so what is sent stays parseable and the
    # turns that survive are the most recent ones.
    trimmed=$(printf '%s' "$text" | jq -c --argjson max "$max_chars" '
        if type == "array"
        then until((tojson | length) <= $max or length <= 1; .[1:])
        else . end
    ' 2>/dev/null)
    [ -n "$trimmed" ] && text="$trimmed"

    # One record can still exceed the budget alone. The tool takes `transcript`
    # as a string and sanitizes it as text rather than parsing it as JSON, so a
    # character truncation is safe here and strictly better than the whole
    # digest being refused.
    if [ "$(printf '%s' "$text" | wc -c)" -gt "$max_chars" ]; then
        text=$(printf '%s' "$text" | head -c "$max_chars")
    fi
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

# Stage cleaned text into a temp file so argv stays small.
text_tmp="$MNEMON_SESSIONS_DIR/${session_id}.digest-input.$$.txt"
printf '%s' "$text" > "$text_tmp"

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
nohup "$worker" "$session_id" "$last_turn" "$turn_index" "$text_tmp" "$project" "$filing" \
    >>"$MNEMON_ERROR_LOG" 2>&1 < /dev/null &
disown 2>/dev/null || true

exit 0
