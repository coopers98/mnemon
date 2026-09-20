#!/usr/bin/env bash
# Mnemon Stop hook: sanitize, slice, dispatch detached digest worker.

set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "$SCRIPT_DIR/lib/common.sh"

mnemon_disabled && exit 0

input=$(cat 2>/dev/null || echo '{}')
session_id=$(printf '%s' "$input" | jq -r '.session_id // empty')
transcript=$(printf '%s' "$input" | jq -c '.transcript // empty')
transcript_path=$(printf '%s' "$input" | jq -r '.transcript_path // empty')

[ -z "$session_id" ] && exit 0

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

    # Even one digest can exceed the limit on a long first run, so cap it.
    # Trim whole records from the front: tail -c can land mid-line, and the
    # leading partial is dropped so the slice stays valid JSONL.
    max_bytes=$(mnemon_config_value 'max_digest_bytes' 262144)
    if [ "$(wc -c < "$slice_file")" -gt "$max_bytes" ]; then
        tail -c "$max_bytes" "$slice_file" | tail -n +2 > "$slice_file.cap" \
            && mv "$slice_file.cap" "$slice_file"
    fi

    transcript=$(jq -s -c '.' "$slice_file" 2>/dev/null)
    rm -f "$slice_file"
    [ -z "$transcript" ] || [ "$transcript" = "null" ] && exit 0
fi

# Structural sanitize: drop tool_use, tool_result, thinking blocks.
# Tool I/O and thinking appear at two levels: as whole records, and -- in real
# Claude Code transcripts -- as blocks nested under .message.content. Filtering
# only the top level lets tool output through.
cleaned=$(printf '%s' "$transcript" | jq -c '
    def strip_blocks: map(select((.type // "") | test("tool_use|tool_result|thinking") | not));
    if type == "array" then
        strip_blocks
        | map(
            if (.message? | type) == "object" and (.message.content? | type) == "array"
            then .message.content |= strip_blocks
            else . end
          )
    else . end
')

# Regex sanitize: redact obvious secret patterns.
text=$(printf '%s' "$cleaned" | sed -E \
    -e 's/AKIA[0-9A-Z]{16}/[REDACTED-AWS-KEY]/g' \
    -e 's/(eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,})/[REDACTED-JWT]/g' \
    -e 's/Bearer [A-Za-z0-9_.-]{20,}/Bearer [REDACTED]/g' \
    -e 's/sk-[A-Za-z0-9]{20,}/[REDACTED-OPENAI-KEY]/g')

# The raw cap above bounds the work; this one bounds what the server is asked
# to accept. `session_digest` validates the payload it receives
# (`transcript` => `required|string|max:200000`), and the cap above is measured
# on the slice *before* sanitization, which only strips about a fifth: a
# 262144-byte raw slice measured 207,613 characters on the wire. The whole
# digest was then rejected, so last_digest_turn never advanced and the next
# Stop re-sent the same oversized tail -- failing identically, forever.
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

# Detach.
nohup "$worker" "$session_id" "$last_turn" "$turn_index" "$text_tmp" \
    >>"$MNEMON_ERROR_LOG" 2>&1 < /dev/null &
disown 2>/dev/null || true

exit 0
