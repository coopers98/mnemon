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

# Count a failed digest and set when the next attempt may run. Args: <tool
# error text, or "" when the call itself failed>.
#
# One failure is retried on the next Stop; after that the wait starts at
# digest_backoff_base_seconds (60) and doubles to digest_backoff_seconds (1800).
# A failure the server leads with "Not retryable:" waits the full 1800 at once.
# A timeout, an unreadable answer or a failed call also halves the next slice
# (digest_shrink): one that times out every time would otherwise never recover.
record_failure() {
    local err="$1" base cap nr=false shrink=false n wait
    base=$(mnemon_config_value 'digest_backoff_base_seconds' 60)
    cap=$(mnemon_config_value 'digest_backoff_seconds' 1800)
    case "$err" in
        "Not retryable:"*) nr=true ;;
        ""|*"timed out"*|*"not a proposals list"*) shrink=true ;;
    esac
    mnemon_session_state_update "$session_id" \
        '.digest_failures = ((.digest_failures // 0) + 1)
         | .digest_failed_at = (now | floor)
         | (if $nr then $cap
            elif .digest_failures < 2 then 0
            else ([$base * pow(2; ([.digest_failures - 2, 20] | min)), $cap] | min) end) as $wait
         | .digest_retry_after = ((now | floor) + $wait)
         | .digest_wait = $wait
         | (if $shrink then .digest_shrink = ([(.digest_shrink // 0) + 1, 30] | min) else . end)' \
        --argjson nr "$nr" --argjson shrink "$shrink" --argjson base "$base" --argjson cap "$cap"
    read -r n wait < <(mnemon_session_state "$session_id" | jq -r '"\(.digest_failures // 0) \(.digest_wait // 0)"')
    if [ "${wait:-0}" -gt 0 ]; then
        mnemon_log_error "digest for session=$session_id failed $n time(s) in a row; retrying in ${wait}s"
    else
        mnemon_log_error "digest for session=$session_id failed; retrying on the next Stop"
    fi
}

# Send one slice. Args: <start> <end> <text_file>. Returns 0 when the digest ran.
run_once() {
    local sstart="$1" send="$2" tfile="$3" pair recent_ids params result persisted_ids errf err
    mnemon_digest_lock_touch "$session_id"
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
         | .digest_failures = 0
         | del(.digest_failed_at, .digest_retry_after, .digest_wait, .digest_shrink)
         | .recent_drawer_ids = (((.recent_drawer_ids // []) + $ids) | unique | .[-50:])' \
        --argjson t "$send" --argjson ids "$persisted_ids"
    return 0
}

# Digest transcript lines from wherever the pointer is now to <end>, cleaned
# the same way capture cleans. Args: <path> <end> <what, for the log>.
# Returns 0 when the range is done (digested, or nothing to digest), 1 when it
# failed.
digest_from_pointer() {
    local path="$1" end="$2" what="$3" state start slice out rc
    if [ -z "$path" ] || [ ! -r "$path" ]; then
        mnemon_log_error "$what for session=$session_id up to line $end: transcript ${path:-(none)} is not readable; skipped"
        return 0
    fi
    state=$(mnemon_session_state "$session_id")
    start=$(printf '%s' "$state" | jq -r '.last_digest_turn // 0')
    [ "$end" -gt "$start" ] || return 0
    end=$(mnemon_digest_end "$state" "$start" "$end")

    slice="$MNEMON_SESSIONS_DIR/${session_id}.pending-slice.$$.jsonl"
    out="$MNEMON_SESSIONS_DIR/${session_id}.pending-input.$$.txt"
    if ! mnemon_slice_transcript "$path" "$start" "$end" "$slice"; then
        rm -f "$slice"
        mnemon_log_error "$what for session=$session_id: lines $start-$end of the transcript are gone; skipped"
        return 0
    fi
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

run_pending() {
    local marker path end
    marker=$(mnemon_digest_pending_take "$session_id")
    path=$(printf '%s' "$marker" | jq -r '.path // empty' 2>/dev/null)
    end=$(printf '%s' "$marker" | jq -r '.end // 0' 2>/dev/null)
    case "$end" in ''|*[!0-9]*) mnemon_log_error "pending range for session=$session_id is unreadable; skipped"; return 0 ;; esac
    digest_from_pointer "$path" "$end" "pending range"
}

ok=1
# The pointer may have moved since capture read it: another worker digested
# while this one's Stop was slicing. Sending the staged text as-is would digest
# those turns again under a range the server's replay check does not know.
pointer=$(mnemon_session_state "$session_id" | jq -r '.last_digest_turn // 0')
if [ "$pointer" -gt "$turn_start" ]; then
    rm -f "$text_file"
    if [ "$turn_end" -gt "$pointer" ]; then
        if [ -n "$transcript_path" ]; then
            digest_from_pointer "$transcript_path" "$turn_end" "re-slice" || ok=0
        else
            mnemon_log_error "digest for session=$session_id: range $turn_start-$turn_end overlaps turns already digested and has no transcript file to re-slice; skipped"
        fi
    fi
else
    run_once "$turn_start" "$turn_end" "$text_file" || ok=0
fi

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
    # worker got there first, that one handles it. A marker left after this
    # point is picked up by the next Stop or the next SessionStart.
    [ "$ok" = 1 ] && [ -f "$PENDING" ] && [ "$passes" -lt "$MAX_PASSES" ] || break
    mnemon_digest_lock "$session_id" || break
    trap 'mnemon_digest_unlock "$session_id"' EXIT
done

# After a failure the pointer has not moved and the marker is kept: the next
# Stop resends from the same start through its own, later end, and SessionStart
# retries a session that has ended.
exit 0
