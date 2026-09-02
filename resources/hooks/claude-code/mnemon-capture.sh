#!/usr/bin/env bash
# Mnemon Stop hook: sanitize, slice, dispatch detached digest worker.

set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "$SCRIPT_DIR/lib/common.sh"

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
    transcript=$(jq -s -c '.' "$transcript_path" 2>/dev/null) || exit 0
    # No turn index is supplied; the transcript's line count is monotonic, so it
    # serves as one and lets the digest advance only over what is new.
    turn_index=$(wc -l < "$transcript_path" | tr -d ' ')
else
    turn_index=$(printf '%s' "$input" | jq -r '.turn_index // 0')
fi

[ -z "$transcript" ] || [ "$transcript" = "null" ] && exit 0

mnemon_token > /dev/null || exit 0

state=$(mnemon_session_state "$session_id")
[ "$(printf '%s' "$state" | jq -r '.nomemo')" = "true" ] && exit 0
[ "$(printf '%s' "$state" | jq -r '.disabled')" = "true" ] && exit 0

last_turn=$(printf '%s' "$state" | jq -r '.last_digest_turn')
[ "$turn_index" -le "$last_turn" ] && exit 0

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

# Spawn detached worker. First run lazily writes the worker script.
worker="$SCRIPT_DIR/lib/digest-worker.sh"
if [ ! -x "$worker" ]; then
    cat > "$worker" <<'WORKER'
#!/usr/bin/env bash
set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$SCRIPT_DIR/common.sh"

session_id="$1"
turn_start="$2"
turn_end="$3"
text_file="$4"

LOCK="$MNEMON_SESSIONS_DIR/${session_id}.digest.lock"
PENDING="$MNEMON_SESSIONS_DIR/${session_id}.digest.pending"

# Stale-lock recovery via PID liveness.
if [ -f "$LOCK" ]; then
    pid=$(cat "$LOCK" 2>/dev/null)
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
        # Live worker: leave a pending-rerun marker and exit.
        : > "$PENDING"
        rm -f "$text_file"
        exit 0
    fi
    rm -f "$LOCK"
fi
echo "$$" > "$LOCK"
trap 'rm -f "$LOCK"' EXIT

run_once() {
    local sstart="$1" send="$2" tfile="$3"
    pair=$(mnemon_token) || return 1
    state=$(mnemon_session_state "$session_id")
    recent_ids=$(printf '%s' "$state" | jq -c '.recent_drawer_ids')

    # --rawfile, not --arg: a transcript passed through argv dies with
    # "Argument list too long" past MAX_ARG_STRLEN (~128KB on Linux), and real
    # sessions run to megabytes.
    params=$(jq -n \
        --arg sid "$session_id" \
        --argjson ts "$sstart" \
        --argjson te "$send" \
        --rawfile t "$tfile" \
        --argjson r "$recent_ids" \
        '{name:"session_digest",arguments:{session_id:$sid,harness:"claude-code",turn_range:{start:$ts,end:$te},transcript:$t,recent_drawer_ids:$r}}')

    rm -f "$tfile"

    result=$(mnemon_call "tools/call" "$params" 60000 "${pair%|*}" "${pair#*|}") \
        || { mnemon_log_error "digest call failed for session=$session_id"; return 1; }
    [ -z "$result" ] && return 1

    payload=$(printf '%s' "$result" | jq -c '.structuredContent // empty')
    persisted_ids=$(printf '%s' "$payload" | jq -c '[.persisted[]?.id]')

    state=$(mnemon_session_state "$session_id")
    state=$(printf '%s' "$state" | jq \
        --argjson new_ids "$persisted_ids" \
        --argjson last_turn "$send" \
        '.last_digest_turn=$last_turn |
         .recent_drawer_ids=((.recent_drawer_ids + $new_ids) | unique | (if length > 50 then .[(length-50):] else . end))')
    mnemon_session_state_write "$session_id" "$state"
    return 0
}

run_once "$turn_start" "$turn_end" "$text_file" || true

# If a re-run was requested while we worked, do exactly one more pass.
if [ -f "$PENDING" ]; then
    rm -f "$PENDING"
    # Re-running with empty text would be a no-op upstream (no new turns since
    # last_digest_turn just advanced); the next Stop fires the next real digest.
    :
fi
WORKER
    chmod +x "$worker"
fi

# Stage cleaned text into a temp file so argv stays small.
text_tmp="$MNEMON_SESSIONS_DIR/${session_id}.digest-input.$$.txt"
printf '%s' "$text" > "$text_tmp"

# Detach.
nohup "$worker" "$session_id" "$last_turn" "$turn_index" "$text_tmp" \
    >>"$MNEMON_ERROR_LOG" 2>&1 < /dev/null &
disown 2>/dev/null || true

exit 0
