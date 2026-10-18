#!/usr/bin/env bash
# Mnemon digest worker: sends one cleaned transcript slice to session_digest,
# then any range a Stop left while it ran. Started detached by mnemon-capture.sh.
# Args: <session_id> <start> <end> <text_file> [<project>] [<filing>] [<transcript_path>]
set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$SCRIPT_DIR/common.sh"

session_id="$1"
turn_start="$2"
turn_end="$3"
text_file="$4"
project="${5:-}"
filing="${6:-}"
transcript_path="${7:-}"

PENDING="$MNEMON_SESSIONS_DIR/${session_id}.digest.pending"
MAX_PASSES=5

# One worker per session at a time (see mnemon_digest_lock). The loser hands
# its range to the winner rather than dropping it: when the user sends one
# more reply during a digest and then quits, there is no next Stop to cover it.
if ! mnemon_digest_lock "$session_id"; then
    [ -n "$transcript_path" ] && mnemon_digest_pending_add "$session_id" "$transcript_path" "$turn_end"
    rm -f "$text_file"
    exit 0
fi
trap 'mnemon_digest_unlock "$session_id"' EXIT

# Count a failed digest, and say so when the session starts backing off
# (mnemon-capture.sh skips Stops while it does). Args: <tool error text or "">.
record_failure() {
    local err="$1" max nr=false n
    max=$(mnemon_config_value 'digest_max_failures' 3)
    # The server leads a failure that retrying cannot fix with "Not retryable:";
    # it backs off at once.
    case "$err" in "Not retryable:"*) nr=true ;; esac
    mnemon_session_state_update "$session_id" \
        '.digest_failures = (((.digest_failures // 0) + 1) | if $nr then ([., $max] | max) else . end)
         | .digest_failed_at = (now | floor)' \
        --argjson nr "$nr" --argjson max "$max"
    n=$(mnemon_session_state "$session_id" | jq -r '.digest_failures // 0')
    if [ "$n" -ge "$max" ]; then
        mnemon_log_error "digest for session=$session_id failed $n times in a row; retrying at most every $(mnemon_config_value 'digest_backoff_seconds' 1800)s"
    fi
}

# Send one slice. Args: <start> <end> <text_file>. Returns 0 when the digest ran.
run_once() {
    local sstart="$1" send="$2" tfile="$3" pair recent_ids params result persisted_ids errf err
    pair=$(mnemon_token) || { rm -f "$tfile"; return 1; }
    recent_ids=$(mnemon_session_state "$session_id" | jq -c '.recent_drawer_ids // []' 2>/dev/null)
    [ -n "$recent_ids" ] || recent_ids='[]'

    # --rawfile, not --arg: a transcript passed through argv dies with
    # "Argument list too long" past MAX_ARG_STRLEN (~128KB on Linux), and real
    # sessions run to megabytes.
    params=$(jq -n \
        --arg sid "$session_id" \
        --argjson ts "$sstart" \
        --argjson te "$send" \
        --rawfile t "$tfile" \
        --argjson r "$recent_ids" \
        --arg p "$project" \
        --arg f "$filing" \
        '{name:"session_digest",arguments:({session_id:$sid,harness:"claude-code",turn_range:{start:$ts,end:$te},transcript:$t,recent_drawer_ids:$r}
            + (if $p == "" then {} else {project:$p} end)
            + (if $f == "" then {} else {project_filing:$f} end))}')

    rm -f "$tfile"

    # 90s, above the server's own 60s budget for the model call, so a slow
    # digest that the server completes is not abandoned by the client.
    errf="$MNEMON_SESSIONS_DIR/${session_id}.digest-error.$$"
    rm -f "$errf"
    if ! result=$(MNEMON_CALL_ERROR_FILE="$errf" mnemon_call "tools/call" "$params" 90000 "${pair%|*}" "${pair#*|}") \
        || [ -z "$result" ]; then
        err=$(cat "$errf" 2>/dev/null)
        rm -f "$errf"
        mnemon_log_error "digest call failed for session=$session_id range=$sstart-$send"
        record_failure "$err"
        return 1
    fi
    rm -f "$errf"

    # A result without structuredContent still counts as digested; it just
    # names no drawers. An empty value here once made --argjson fail and the
    # session state be overwritten with nothing.
    persisted_ids=$(printf '%s' "$result" | jq -c '[.structuredContent.persisted[]?.id | numbers]' 2>/dev/null)
    [ -n "$persisted_ids" ] || persisted_ids='[]'

    # Applied to the state on disk, and last_digest_turn only moves forward
    # (mnemon_session_state_write).
    mnemon_session_state_update "$session_id" \
        '.last_digest_turn = $t
         | .digest_failures = 0 | del(.digest_failed_at)
         | .recent_drawer_ids = (((.recent_drawer_ids // []) + $ids) | unique | .[-50:])' \
        --argjson t "$send" --argjson ids "$persisted_ids"
    return 0
}

# Digest the range a Stop left while this worker ran: from wherever the pointer
# is now to the end that Stop saw, cleaned the same way capture cleans.
run_pending() {
    local marker path end start slice out rc
    marker=$(cat "$PENDING" 2>/dev/null)
    rm -f "$PENDING"
    path=$(printf '%s' "$marker" | jq -r '.path // empty' 2>/dev/null)
    end=$(printf '%s' "$marker" | jq -r '.end // 0' 2>/dev/null)
    case "$end" in ''|*[!0-9]*) return 0 ;; esac
    [ -n "$path" ] && [ -r "$path" ] || return 0
    start=$(mnemon_session_state "$session_id" | jq -r '.last_digest_turn // 0')
    [ "$end" -gt "$start" ] || return 0

    slice="$MNEMON_SESSIONS_DIR/${session_id}.pending-slice.$$.jsonl"
    out="$MNEMON_SESSIONS_DIR/${session_id}.pending-input.$$.txt"
    mnemon_slice_transcript "$path" "$start" "$end" "$slice" || { rm -f "$slice"; return 0; }
    mnemon_digest_text "$slice" "$out"
    rc=$?
    rm -f "$slice"
    case "$rc" in
        0) run_once "$start" "$end" "$out" ;;
        # Nothing to digest, and this worker holds the lock: move past it.
        1) mnemon_session_state_update "$session_id" '.last_digest_turn = $t' --argjson t "$end" ;;
        *) rm -f "$out"; return 1 ;;
    esac
}

ok=1
run_once "$turn_start" "$turn_end" "$text_file" || ok=0

passes=0
while :; do
    while [ "$ok" = 1 ] && [ -f "$PENDING" ] && [ "$passes" -lt "$MAX_PASSES" ]; do
        passes=$((passes + 1))
        run_pending || ok=0
    done
    mnemon_digest_unlock "$session_id"
    trap - EXIT
    # A worker that lost the lock after the check above and before the release
    # left its range in the marker. Take the lock back for it; if another
    # worker got there first, that one handles it.
    [ "$ok" = 1 ] && [ -f "$PENDING" ] && [ "$passes" -lt "$MAX_PASSES" ] || break
    mnemon_digest_lock "$session_id" || break
    trap 'mnemon_digest_unlock "$session_id"' EXIT
done

# After a failure the pointer has not moved, so the next Stop resends from the
# same start through its own, later end -- which covers any pending range.
[ "$ok" = 1 ] || rm -f "$PENDING"
exit 0
