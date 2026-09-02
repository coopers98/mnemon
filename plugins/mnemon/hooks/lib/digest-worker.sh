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
