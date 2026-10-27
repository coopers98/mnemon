#!/usr/bin/env bash
# mnemon-backfill: the run. Sourced by mnemon-backfill.sh after
# backfill-scan.sh, whose flags, scan and estimate it uses.
#
#   backfill_run   --project <name> (the trial) or --all: re-scan, print the
#                  estimate and the cap, confirm, import the memory notes
#                  (drawer_add), then digest the transcripts (the digest
#                  worker, one call at a time), and report. Returns 0 when it
#                  finished or stopped at the cap, 1 when it was refused,
#                  declined or stopped by failures, 2 on a bad flag, 130/143
#                  when interrupted.
#
# Progress is kept in ~/.mnemon/backfill/state.json, written atomically:
#   {sessions: {<sid>: {started_at, pointer, done}},
#    spent: {in, out, calls, replays, estimated},
#    trial: {project, tokens_per_call}}
# spent.in/out are the tokens calls reported (usage), spent.estimated what was
# charged at the per-call estimate for attempts that reported none; all of it
# summed over every run. trial is the last --project run's measured tokens per
# call, which a later --all estimate uses.
#
# Env, for the tests (defaults in brackets):
#   MNEMON_BACKFILL_TTY          where the confirmation is read [/dev/tty]
#   MNEMON_BACKFILL_MAX_SLEEP    longest wait for a session's retry time [1800]
#   MNEMON_BACKFILL_429_SLEEP    wait after an HTTP 429 on drawer_add [30]
#   MNEMON_BACKFILL_NOTE_PAUSE   pause between drawer_add calls [0.6]

BFR_WORKER="$BACKFILL_LIB_DIR/../../hooks/lib/digest-worker.sh"

# A whole number, or a fallback. Args: <value> <fallback>.
_bfr_whole() {
    case "$1" in
        ''|*[!0-9]*) printf '%s' "$2" ;;
        *) printf '%s' "$((10#$1))" ;;
    esac
}

# Sleep whole seconds, one at a time, so a Ctrl-C stops the wait. Args: <secs>.
_bfr_sleep() {
    local n="$1"
    while [ "$n" -gt 0 ] && [ -z "$BFR_STOP" ]; do
        sleep 1
        n=$((n - 1))
    done
}

# Text safe to print: redacted (withheld whole when redaction fails), on one
# line, cut. Args: <text> <max characters>.
_bfr_safe_text() {
    local err="$BACKFILL_TMP/safe.err" text rc
    text=$(printf '%s' "$1" | mnemon_redact_text 2>"$err")
    rc=$?
    if [ "$rc" -ne 0 ] || [ -s "$err" ]; then
        rm -f "$err"
        printf '[withheld]'
        return 0
    fi
    rm -f "$err"
    printf '%s' "$text" | jq -Rrs --argjson n "$2" 'gsub("[[:space:]]+"; " ") | sub("^ "; "") | .[0:$n]' | tr -d '\r'
}

# --- progress file --------------------------------------------------------

# Create the progress file when there is none (or it is unreadable).
_bfr_state_init() {
    mkdir -p "$BFR_STATE_DIR" 2>/dev/null
    jq -e 'type == "object"' "$BFR_STATE" >/dev/null 2>&1 && return 0
    if [ -e "$BFR_STATE" ]; then
        mv -f "$BFR_STATE" "$BFR_STATE.unreadable" 2>/dev/null
        echo "mnemon-backfill: $BFR_STATE was unreadable; kept as state.json.unreadable, starting a new one" >&2
    fi
    printf '%s\n' '{"sessions":{},"spent":{"in":0,"out":0,"calls":0,"replays":0,"estimated":0}}' > "$BFR_STATE.tmp.$$" \
        && mv -f "$BFR_STATE.tmp.$$" "$BFR_STATE"
}

# Apply a jq filter to the progress file, atomically. Args: <filter> [jq args...].
_bfr_state_update() {
    local filter="$1"
    shift
    if jq -c "$@" "$filter" "$BFR_STATE" > "$BFR_STATE.tmp.$$" 2>/dev/null \
        && mv -f "$BFR_STATE.tmp.$$" "$BFR_STATE"; then
        return 0
    fi
    rm -f "$BFR_STATE.tmp.$$"
    echo "mnemon-backfill: could not write $BFR_STATE" >&2
    return 1
}

# A session's pointer, read without creating its state. Args: <sid>.
_bfr_pointer() {
    local p
    p=$(jq -r '.last_digest_turn // 0 | if type == "number" then floor else 0 end' \
        "$MNEMON_SESSIONS_DIR/$1.json" 2>/dev/null | tr -d '\r')
    _bfr_whole "$p" 0
}

# --- the plan: per-call estimate and cap ---------------------------------

# From the scan's estimate (backfill_estimate) and, for --all, a stored trial:
# BFR_PC_IN / BFR_PC_OUT, what an attempt that reports no usage is charged;
# BFR_HIGH, the estimate's high input+output figure; and the cap,
# BFR_CAP_TOKENS or BFR_CAP_COST. Prints them. Returns 2 when --max-cost is
# asked for and no price is published.
_bfr_plan() {
    local calls="$BACKFILL_EST_CALLS" in_hi="$BACKFILL_EST_IN_HIGH" out="$BACKFILL_EST_OUT" tpc="" trial_project=""
    if [ "$calls" -gt 0 ]; then
        BFR_PC_IN=$(( (in_hi + calls - 1) / calls ))
        BFR_PC_OUT=$(( (out + calls - 1) / calls ))
    else
        BFR_PC_IN=$(( (BACKFILL_OVERHEAD * 3 + 1) / 2 ))
        BFR_PC_OUT=$BACKFILL_TYPICAL_OUT
    fi
    BFR_HIGH=$(( in_hi + out ))
    if [ "$BACKFILL_ALL" = 1 ] && [ -f "$BFR_STATE" ]; then
        IFS="$BACKFILL_US" read -r trial_project tpc <<< "$(jq -r '[(.trial.project // "" | tostring | gsub("[[:cntrl:]]"; " ")),
            (.trial.tokens_per_call // "" | tostring)] | join("\u001f")' "$BFR_STATE" 2>/dev/null | tr -d '\r')"
        tpc=$(_bfr_whole "$tpc" "")
    fi
    echo
    echo "Run plan"
    if [ -n "$tpc" ] && [ "$tpc" -gt 0 ]; then
        BFR_HIGH=$(( calls * tpc ))
        if [ $((BFR_PC_IN + BFR_PC_OUT)) -gt 0 ]; then
            BFR_PC_IN=$(( tpc * BFR_PC_IN / (BFR_PC_IN + BFR_PC_OUT) ))
        else
            BFR_PC_IN=$tpc
        fi
        BFR_PC_OUT=$(( tpc - BFR_PC_IN ))
        echo "  recalibrated from the trial on $trial_project: $tpc tokens per call, $BFR_HIGH tokens for $calls calls"
    fi
    echo "  per call:      $((BFR_PC_IN + BFR_PC_OUT)) tokens, charged for any attempt that reports no usage"
    BFR_CAP_TOKENS="" BFR_CAP_COST=""
    if [ -n "$BACKFILL_MAX_TOKENS" ]; then
        BFR_CAP_TOKENS=$BACKFILL_MAX_TOKENS
        echo "  cap:           $BFR_CAP_TOKENS input + output tokens (--max-tokens)"
    elif [ -n "$BACKFILL_MAX_COST" ]; then
        if [ -z "$BACKFILL_PRICE_IN" ] || [ -z "$BACKFILL_PRICE_OUT" ]; then
            echo "mnemon-backfill: --max-cost needs a published price, and the server publishes none; use --max-tokens" >&2
            return 2
        fi
        BFR_CAP_COST=$BACKFILL_MAX_COST
        echo "  cap:           \$$BFR_CAP_COST (--max-cost, at \$$BACKFILL_PRICE_IN in / \$$BACKFILL_PRICE_OUT out per million tokens)"
    else
        BFR_CAP_TOKENS=$(( (BFR_HIGH * 5 + 3) / 4 ))
        echo "  cap:           $BFR_CAP_TOKENS input + output tokens (the estimate's high figure plus 25%; --max-tokens sets it)"
    fi
    echo "  The cap is checked before every call; a run passes it by one call at most."
    return 0
}

# Whether one more call fits under the cap, charged at the estimate.
_bfr_cap_allows() {
    if [ -n "$BFR_CAP_COST" ]; then
        awk -v ci="$BFR_CH_IN" -v co="$BFR_CH_OUT" -v pi="$BFR_PC_IN" -v po="$BFR_PC_OUT" \
            -v pin="$BACKFILL_PRICE_IN" -v pout="$BACKFILL_PRICE_OUT" -v cap="$BFR_CAP_COST" \
            'BEGIN { exit !(((ci + pi) * pin + (co + po) * pout) / 1000000 <= cap) }'
        return
    fi
    [ $((BFR_CH_IN + BFR_CH_OUT + BFR_PC_IN + BFR_PC_OUT)) -le "$BFR_CAP_TOKENS" ]
}

# --- confirmation ----------------------------------------------------------

# Ask "Proceed? [y/N]": on stdin when it is a terminal, else on the terminal
# (MNEMON_BACKFILL_TTY). With no terminal at all, refuse: --yes is the way to
# run unattended. Returns 0 to go ahead.
_bfr_confirm() {
    local answer="" tty="${MNEMON_BACKFILL_TTY:-/dev/tty}"
    [ "$BACKFILL_YES" = 1 ] && return 0
    echo
    if [ -t 0 ]; then
        printf 'Proceed? [y/N] '
        read -r answer || answer=""
    elif ( : < "$tty" ) 2>/dev/null; then
        printf 'Proceed? [y/N] '
        read -r answer < "$tty" || answer=""
    else
        echo "mnemon-backfill: no terminal to ask on; add --yes to import without asking (the cap still holds)" >&2
        return 1
    fi
    case "$answer" in
        y|Y|yes|Yes|YES) return 0 ;;
    esac
    echo "Not started."
    return 1
}

# --- memory notes ------------------------------------------------------------

# One note through drawer_add. Args: <path> <project> <mtime>. Counts into
# BFR_NOTES_*; a wing refusal is a drop. An HTTP 429 waits and retries, 3
# times at most.
_bfr_note() {
    local path="$1" project="$2" mtime="$3" content="$BACKFILL_TMP/note.txt" err="$BACKFILL_TMP/note.err"
    local errf="$BACKFILL_TMP/note.callerr" params result fail created wing retries=0 name="${1##*/}"
    if ! mnemon_redact_text < "$path" > "$content" 2> "$err" || [ -s "$err" ]; then
        rm -f "$content" "$err"
        BFR_NOTES_FAILED=$((BFR_NOTES_FAILED + 1))
        echo "  note $project/$name: not sent (redaction failed)"
        return 0
    fi
    rm -f "$err"
    if ! [ -s "$content" ]; then
        rm -f "$content"
        echo "  note $project/$name: empty, not sent"
        return 0
    fi
    params=$(jq -n --rawfile c "$content" --arg p "$project" --arg src "$path" --argjson m "$mtime" \
        '{name: "drawer_add", arguments: {project: $p, room: "memory", content: $c, occurred_at: ($m | todate),
          metadata: {source_path: $src, mtime: $m, harness: "claude-code", backfill: true}}}')
    rm -f "$content"
    while :; do
        rm -f "$errf"
        if result=$(MNEMON_CALL_ERROR_FILE="$errf" mnemon_call "tools/call" "$params" 15000) && [ -n "$result" ]; then
            break
        fi
        # Interrupted: not counted; the next run sends it again, and the
        # server's dedup answers if it was stored after all.
        [ -n "$BFR_STOP" ] && return 0
        fail=$(cat "$errf" 2>/dev/null)
        rm -f "$errf"
        case "$fail" in
            "HTTP 429"*)
                if [ "$retries" -lt 3 ]; then
                    retries=$((retries + 1))
                    _bfr_sleep "$(_bfr_whole "${MNEMON_BACKFILL_429_SLEEP:-30}" 30)"
                    [ -n "$BFR_STOP" ] && return 0
                    continue
                fi ;;
            *"access to wing"*)
                BFR_NOTES_DROPPED=$((BFR_NOTES_DROPPED + 1))
                echo "  note $project/$name: dropped (no wing this token can reach)"
                return 0 ;;
        esac
        BFR_NOTES_FAILED=$((BFR_NOTES_FAILED + 1))
        echo "  note $project/$name: failed ($(_bfr_safe_text "$fail" 60))"
        return 0
    done
    BFR_NOTES_SENT=$((BFR_NOTES_SENT + 1))
    read -r created wing <<< "$(printf '%s' "$result" | jq -r '.structuredContent // {}
        | [(.created == true | tostring), (.wing // "?" | tostring | gsub("[[:space:]]"; "_"))] | join(" ")' 2>/dev/null | tr -d '\r')"
    if [ "$created" = true ]; then
        BFR_NOTES_NEW=$((BFR_NOTES_NEW + 1))
        printf '%s\n' "$wing" >> "$BFR_WINGS"
        echo "  note $project/$name: stored in $wing"
    else
        BFR_NOTES_KNOWN=$((BFR_NOTES_KNOWN + 1))
        echo "  note $project/$name: already stored"
    fi
}

# Every eligible note of the scan, 0.6 s apart: under the server's 120 calls a
# minute per token, which the device's live hooks share. Notes cost no model
# tokens, so the cap never holds them back.
_bfr_notes() {
    local notes="$1" path project mtime first=1 pause="${MNEMON_BACKFILL_NOTE_PAUSE:-0.6}"
    awk -F'\t' '$3 == "eligible"' "$notes" | grep -q . || return 0
    echo
    echo "Memory notes"
    while IFS="$BACKFILL_US" read -r path project mtime <&3; do
        [ -n "$BFR_STOP" ] && break
        [ "$first" = 1 ] || sleep "$pause"
        first=0
        _bfr_note "$path" "$project" "$(_bfr_whole "$mtime" 0)"
    done 3< <(awk -F'\t' -v OFS="$BACKFILL_US" '$3 == "eligible" { print $1, $2, $5 }' "$notes")
}

# --- transcripts -----------------------------------------------------------

# Charge one attempt. Args: <in> <out> <counted at the estimate: 0|1>.
_bfr_charge() {
    BFR_CH_IN=$((BFR_CH_IN + $1))
    BFR_CH_OUT=$((BFR_CH_OUT + $2))
    if [ "$3" = 1 ]; then
        BFR_EST_CHARGED=$((BFR_EST_CHARGED + $1 + $2))
    else
        BFR_USED_IN=$((BFR_USED_IN + $1))
        BFR_USED_OUT=$((BFR_USED_OUT + $2))
    fi
}

# Wait out a session's digest_retry_after (the worker's own backoff), at most
# its digest_wait and never more than 1800 s. Args: <sid>.
_bfr_wait_retry() {
    local f="$MNEMON_SESSIONS_DIR/$1.json" ra dw wait cap max
    [ -f "$f" ] || return 0
    read -r ra dw <<< "$(jq -r '[(.digest_retry_after // 0), (.digest_wait // 0)]
        | map(if type == "number" then floor else 0 end | tostring) | join(" ")' "$f" 2>/dev/null | tr -d '\r')"
    ra=$(_bfr_whole "$ra" 0)
    dw=$(_bfr_whole "$dw" 0)
    wait=$(( ra - $(date +%s) ))
    [ "$wait" -gt 0 ] || return 0
    cap=1800
    [ "$dw" -gt 0 ] && [ "$dw" -lt "$cap" ] && cap=$dw
    max=$(_bfr_whole "${MNEMON_BACKFILL_MAX_SLEEP:-1800}" 1800)
    [ "$max" -lt "$cap" ] && cap=$max
    [ "$wait" -gt "$cap" ] && wait=$cap
    [ "$wait" -gt 0 ] || return 0
    echo "  waiting ${wait}s: the session's last call failed and it is backing off"
    _bfr_sleep "$wait"
}

# Digest one session, a call at a time, until it is done or something stops
# it. Args: <sid> <path> <project> <end>. Records why it stopped in
# $BFR_STOPS, and sets BFR_RUN_STOP when the whole run must stop.
#
# The pending marker is owned here. The worker writes one whenever a chunk
# ends before <end>; live capture would read a stopped backfill's marker as
# "sent the next time that session runs". So whether one existed before the
# first call, and the transcript's mtime then, are noted; on stopping, the
# marker is taken only when there was none before and the mtime is unchanged.
# Otherwise a live Stop may have added a range, and it is left to live capture.
_bfr_session() {
    local sid="$1" path="$2" project="$3" end="$4" filing="" label short
    local marker="$MNEMON_SESSIONS_DIR/$1.digest.pending" lock="$MNEMON_SESSIONS_DIR/$1.digest.lock.d"
    local had_marker=0 mtime0 mtime ran=0 fails=0 transients=0 stop="" before after line logsize fields
    local outcome replayed has_usage u_in u_out n_drawers dropped r_start r_end first_id first_wing tokens what
    # A project_ignore repository has no project: the model files it, as in
    # live capture.
    [ -z "$project" ] && filing=model
    label="${project:-(model files it)}"
    short="${sid:0:8}"
    [ -e "$marker" ] && had_marker=1
    mtime0=$(mnemon_mtime "$path" 0)

    while :; do
        [ -n "$BFR_STOP" ] && { stop=interrupted; break; }
        before=$(_bfr_pointer "$sid")
        [ "$before" -ge "$end" ] && { stop=done; break; }
        if ! _bfr_cap_allows; then
            BFR_RUN_STOP=cap
            stop=cap
            break
        fi
        _bfr_wait_retry "$sid"
        [ -n "$BFR_STOP" ] && { stop=interrupted; break; }
        # Still dead? A session that came alive is left to its hooks from here.
        mtime=$(mnemon_mtime "$path" 0)
        if [ "$mtime" != "$mtime0" ] || [ "$mtime" -gt $(( $(date +%s) - BACKFILL_QUIET_MINUTES * 60 )) ]; then
            stop=modified
            break
        fi
        if [ -d "$lock" ]; then
            stop=busy
            break
        fi
        if [ "$ran" = 0 ]; then
            _bfr_state_update '.sessions[$s] = ((.sessions[$s] // {started_at: $now}) + {pointer: $p, done: false})' \
                --arg s "$sid" --arg now "$(date -u +%Y-%m-%dT%H:%M:%SZ)" --argjson p "$before"
        fi
        ran=1
        : > "$BFR_REPORT"
        logsize=0
        [ -f "$MNEMON_ERROR_LOG" ] && logsize=$(wc -c < "$MNEMON_ERROR_LOG" | tr -d ' ')
        # <start> is 0 on purpose, not the pointer read above: with a
        # transcript path the worker ignores it and re-reads the pointer under
        # the session's lock, which is the only read that can be trusted.
        MNEMON_DIGEST_MAX_PASSES=1 MNEMON_DIGEST_REPORT="$BFR_REPORT" \
            bash "$BFR_WORKER" "$sid" 0 "$end" "" "$project" "$filing" "$path" \
            >> "$MNEMON_ERROR_LOG" 2>&1 < /dev/null
        after=$(_bfr_pointer "$sid")
        line=$(head -n 1 "$BFR_REPORT" 2>/dev/null)

        if [ -z "$line" ]; then
            # No report line: no call was made (nothing to send, the lock was
            # lost), or one was and its line is lost. It may have been billed,
            # so it is charged the estimate.
            BFR_NOLINE=$((BFR_NOLINE + 1))
            _bfr_charge "$BFR_PC_IN" "$BFR_PC_OUT" 1
            _bfr_state_update '.spent.estimated = ((.spent.estimated // 0) + $e)' --argjson e $((BFR_PC_IN + BFR_PC_OUT))
            if [ "$after" -le "$before" ]; then
                [ -n "$BFR_STOP" ] && { stop=interrupted; break; }
                if [ -d "$lock" ]; then stop=busy; else stop=no_progress; fi
                echo "  $label $short: no call made and no progress (${stop//_/ }); left for this run"
                break
            fi
            echo "  $label $short lines $before-$after: nothing to send; charged $((BFR_PC_IN + BFR_PC_OUT)) at the estimate (run total $((BFR_CH_IN + BFR_CH_OUT)))"
        else
            fields=$(printf '%s' "$line" | jq -r '[.outcome, (.replayed == true),
                (.usage | type == "object"), (.usage.prompt_tokens // 0 | floor), (.usage.completion_tokens // 0 | floor),
                (.drawers | length), (.dropped // 0), .start, .end,
                (.drawers[0].id // ""), (.drawers[0].wing // "" | gsub("[[:space:]]"; "_"))] | map(tostring) | join(" ")' 2>/dev/null | tr -d '\r')
            read -r outcome replayed has_usage u_in u_out n_drawers dropped r_start r_end first_id first_wing <<< "$fields"
            BFR_CALLS=$((BFR_CALLS + 1))
            if [ "$has_usage" = true ]; then
                tokens=$(( $(_bfr_whole "$u_in" 0) + $(_bfr_whole "$u_out" 0) ))
                _bfr_charge "$(_bfr_whole "$u_in" 0)" "$(_bfr_whole "$u_out" 0)" 0
                BFR_USAGE_CALLS=$((BFR_USAGE_CALLS + 1))
                _bfr_state_update '.spent.in = ((.spent.in // 0) + $i) | .spent.out = ((.spent.out // 0) + $o)' \
                    --argjson i "$(_bfr_whole "$u_in" 0)" --argjson o "$(_bfr_whole "$u_out" 0)"
                what="$tokens tokens"
            else
                tokens=$((BFR_PC_IN + BFR_PC_OUT))
                _bfr_charge "$BFR_PC_IN" "$BFR_PC_OUT" 1
                _bfr_state_update '.spent.estimated = ((.spent.estimated // 0) + $e)' --argjson e "$tokens"
                what="charged $tokens at the estimate"
            fi
            _bfr_state_update '.spent.calls = ((.spent.calls // 0) + 1)
                | .spent.replays = ((.spent.replays // 0) + (if $r then 1 else 0 end))' --argjson r "${replayed:-false}"
            case "$outcome" in
                ok)
                    fails=0 transients=0
                    [ "$replayed" = true ] && BFR_REPLAYS=$((BFR_REPLAYS + 1))
                    BFR_DROPPED=$((BFR_DROPPED + $(_bfr_whole "$dropped" 0)))
                    printf '%s' "$line" | jq -r '.drawers[]?.wing // "?" | tostring' 2>/dev/null | tr -d '\r' >> "$BFR_WINGS"
                    [ -n "$first_id" ] && printf '%s %s\n' "$first_id" "$first_wing" >> "$BFR_SAMPLES"
                    echo "  $label $short lines $r_start-$r_end: ok$([ "$replayed" = true ] && echo ' (replay)'), $n_drawers drawers, $what (run total $((BFR_CH_IN + BFR_CH_OUT)))"
                    if [ "$after" -le "$before" ]; then
                        stop=no_progress
                        echo "  $label $short: the pointer did not move; left for this run"
                        break
                    fi ;;
                not_retryable)
                    BFR_FAILED=$((BFR_FAILED + 1))
                    BFR_RUN_STOP=not_retryable
                    BFR_REASON=$(tail -c +"$(( $(_bfr_whole "$logsize" 0) + 1 ))" "$MNEMON_ERROR_LOG" 2>/dev/null \
                        | grep -o 'Not retryable:.*' | head -n 1)
                    BFR_REASON=$(_bfr_safe_text "${BFR_REASON:-Not retryable: (no reason given)}" 160)
                    echo "  $label $short lines $r_start-$r_end: the server says retrying cannot help; $what"
                    stop=not_retryable
                    break ;;
                *)
                    BFR_FAILED=$((BFR_FAILED + 1))
                    fails=$((fails + 1))
                    if [ "$outcome" = transient ]; then transients=$((transients + 1)); else transients=0; fi
                    echo "  $label $short lines $r_start-$r_end: ${outcome:-unknown} failure ($fails in a row), $what (run total $((BFR_CH_IN + BFR_CH_OUT)))"
                    if [ "$fails" -ge 3 ]; then
                        stop=failures
                        # Three transient failures in a row: the server or the
                        # network is down, not this session. Stop the run.
                        [ "$transients" -ge 3 ] && BFR_RUN_STOP=transient
                        break
                    fi ;;
            esac
        fi
        _bfr_state_update '.sessions[$s].pointer = $p | .sessions[$s].done = ($p >= $e)' \
            --arg s "$sid" --argjson p "$after" --argjson e "$end"
        [ -n "$BFR_STOP" ] && { stop=interrupted; break; }
    done

    after=$(_bfr_pointer "$sid")
    if [ "$ran" = 1 ]; then
        if [ "$had_marker" = 0 ] && [ "$(mnemon_mtime "$path" 0)" = "$mtime0" ]; then
            mnemon_digest_pending_take "$sid" > /dev/null
        fi
        _bfr_state_update '.sessions[$s].pointer = $p | .sessions[$s].done = ($p >= $e)' \
            --arg s "$sid" --argjson p "$after" --argjson e "$end"
    fi
    if [ "$stop" = done ] || [ "$after" -ge "$end" ]; then
        BFR_DONE=$((BFR_DONE + 1))
    else
        printf '%s\n' "$stop" >> "$BFR_STOPS"
        case "$stop" in
            modified) echo "  $label $short: modified since the run began; left to its hooks" ;;
            busy) echo "  $label $short: live capture is digesting it; left to its hooks" ;;
        esac
    fi
}

# Every eligible session of the scan, in order, until one stops the run.
_bfr_sessions() {
    local scan="$1" sid path project lines
    awk -F'\t' '$5 == "eligible"' "$scan" | grep -q . || return 0
    echo
    echo "Sessions"
    while IFS="$BACKFILL_US" read -r sid path project lines <&3; do
        [ -n "$BFR_STOP" ] && break
        [ -n "$BFR_RUN_STOP" ] && break
        _bfr_session "$sid" "$path" "$project" "$(_bfr_whole "$lines" 0)"
    done 3< <(awk -F'\t' -v OFS="$BACKFILL_US" '$5 == "eligible" { print $1, $2, $3, $7 }' "$scan")
}

# --- report ------------------------------------------------------------------

_bfr_report() {
    local scan="$1" notes="$2" id wing content text cost
    echo
    echo "Report"
    echo "  calls:         $BFR_CALLS ($BFR_REPLAYS replays, $BFR_FAILED failed)"
    [ "$BFR_NOLINE" -gt 0 ] && echo "  worker runs that reported no call: $BFR_NOLINE"
    echo "  input tokens:  $BFR_USED_IN reported (estimate $BACKFILL_EST_IN_LOW to $BACKFILL_EST_IN_HIGH)"
    echo "  output tokens: $BFR_USED_OUT reported (estimate $BACKFILL_EST_OUT)"
    echo "  charged at the per-call estimate: $BFR_EST_CHARGED tokens (attempts that reported no usage)"
    echo "  charged against the cap: $((BFR_CH_IN + BFR_CH_OUT)) tokens"
    if [ -n "$BACKFILL_PRICE_IN" ] && [ -n "$BACKFILL_PRICE_OUT" ]; then
        cost=$(awk -v i="$BFR_USED_IN" -v o="$BFR_USED_OUT" -v pin="$BACKFILL_PRICE_IN" -v pout="$BACKFILL_PRICE_OUT" \
            'BEGIN { printf "%.2f", (i * pin + o * pout) / 1000000 }')
        echo "  cost:          \$$cost reported (estimate \$$BACKFILL_EST_COST_LOW to \$$BACKFILL_EST_COST_HIGH)"
    else
        echo "  cost:          unknown (no price published)"
    fi
    echo "  drawers per wing:"
    if [ -s "$BFR_WINGS" ]; then
        LC_ALL=C sort "$BFR_WINGS" | uniq -c | awk '{ printf "    %-32s %6d\n", $2, $1 }'
    else
        echo "    (none)"
    fi
    echo "  dropped outside the token's wings: $BFR_DROPPED"
    echo "  notes: $BFR_NOTES_SENT sent, $BFR_NOTES_NEW new, $BFR_NOTES_KNOWN already stored, $BFR_NOTES_DROPPED dropped, $BFR_NOTES_FAILED failed"
    echo "  sessions finished: $BFR_DONE"
    if [ -s "$BFR_STOPS" ]; then
        echo "  sessions left this run (reason: sessions):"
        LC_ALL=C sort "$BFR_STOPS" | uniq -c | awk '{ printf "    %-16s %6d\n", $2, $1 }'
    fi
    echo "  skipped by the scan (reason: sessions, notes):"
    awk -F'\t' -v first="$notes" 'FILENAME == first { if ($3 == "skip") { n[$4]++; r[$4] = 1 } next }
        $5 == "skip" { s[$6]++; r[$6] = 1 }
        END { for (k in r) printf "    %-16s %6d %6d\n", k, s[k], n[k] }' "$notes" "$scan" | LC_ALL=C sort
    # One drawer from each of the last three calls that stored any.
    if [ -s "$BFR_SAMPLES" ]; then
        echo "  latest drawers (first line):"
        tail -n 3 "$BFR_SAMPLES" | awk '!seen[$1]++' | while read -r id wing; do
            case "$id" in ''|*[!0-9]*) continue ;; esac
            content=$(mnemon_call "tools/call" "$(jq -nc --argjson id "$id" '{name: "drawer_get", arguments: {id: $id}}')" 15000 \
                | jq -r '.structuredContent.content // empty | split("\n") | map(select(test("\\S"))) | .[0] // ""' 2>/dev/null)
            if [ -n "$content" ]; then
                text=$(_bfr_safe_text "$content" 60)
            else
                text="(not readable)"
            fi
            echo "    #$id $wing: $text"
        done
    fi
}

# --- the run -----------------------------------------------------------------

backfill_run() {
    local scan="$BACKFILL_TMP/scan.tsv" notes="$BACKFILL_TMP/notes.tsv" rc n_notes
    BFR_STATE_DIR="$MNEMON_DIR/backfill"
    BFR_STATE="$BFR_STATE_DIR/state.json"
    if [ -n "$BACKFILL_PROJECT" ]; then
        echo "Mnemon backfill: trial import of project $BACKFILL_PROJECT"
    else
        echo "Mnemon backfill: import of every eligible project"
    fi
    echo "Projects: $BACKFILL_PROJECTS_ROOT"
    if ! mnemon_token > /dev/null; then
        echo "mnemon-backfill: this device has no Mnemon credentials; run the Mnemon setup first" >&2
        return 1
    fi
    if [ ! -d "$BACKFILL_PROJECTS_ROOT" ]; then
        echo "No Claude Code projects found there; nothing to import."
        return 0
    fi
    echo

    backfill_scan "$BACKFILL_PROJECTS_ROOT" > "$scan"
    backfill_notes "$BACKFILL_PROJECTS_ROOT" > "$notes"
    backfill_load_status
    backfill_print_scan "$scan" "$notes"
    _bfr_plan || return $?

    n_notes=$(awk -F'\t' '$3 == "eligible" { n++ } END { print n + 0 }' "$notes")
    if [ "$BACKFILL_EST_SESSIONS" -eq 0 ] && [ "$n_notes" -eq 0 ]; then
        echo
        echo "Nothing to import."
        return 0
    fi
    _bfr_confirm || return 1

    # From here the run writes: the worker's session state and log are the
    # real ones again (the scan pointed them at its temp directory), and the
    # worker, a process of its own, must find the same MNEMON_DIR.
    MNEMON_SESSIONS_DIR="$BACKFILL_STATE_DIR"
    MNEMON_ERROR_LOG="$MNEMON_DIR/capture-errors.log"
    export MNEMON_DIR
    mkdir -p "$MNEMON_SESSIONS_DIR"
    _bfr_state_init || return 1

    # Ctrl-C (or TERM) ends the run between calls: the call in flight
    # finishes, or the worker abandons it -- its pointer only moves after the
    # server accepts a chunk -- and then the session's marker ownership is
    # settled, progress written and the report printed, as at any other stop.
    # The scan's EXIT trap removes its temp directory, where every temp file of
    # the run lives; the progress file's own temp copy is removed too.
    BFR_STOP=""
    trap 'BFR_STOP=130' INT
    trap 'BFR_STOP=143' TERM
    trap 'backfill_cleanup; rm -f "$BFR_STATE.tmp.$$"' EXIT

    BFR_RUN_STOP="" BFR_REASON=""
    BFR_CH_IN=0 BFR_CH_OUT=0 BFR_EST_CHARGED=0 BFR_USED_IN=0 BFR_USED_OUT=0 BFR_USAGE_CALLS=0
    BFR_CALLS=0 BFR_REPLAYS=0 BFR_FAILED=0 BFR_NOLINE=0 BFR_DROPPED=0 BFR_DONE=0
    BFR_NOTES_SENT=0 BFR_NOTES_NEW=0 BFR_NOTES_KNOWN=0 BFR_NOTES_DROPPED=0 BFR_NOTES_FAILED=0
    BFR_REPORT="$BACKFILL_TMP/report.jsonl"
    BFR_WINGS="$BACKFILL_TMP/wings"
    BFR_STOPS="$BACKFILL_TMP/stops"
    BFR_SAMPLES="$BACKFILL_TMP/samples"
    : > "$BFR_WINGS"; : > "$BFR_STOPS"; : > "$BFR_SAMPLES"

    _bfr_notes "$notes"
    _bfr_sessions "$scan"

    # A trial measures tokens per call, for a later --all estimate.
    if [ -n "$BACKFILL_PROJECT" ] && [ "$BFR_USAGE_CALLS" -gt 0 ]; then
        _bfr_state_update '.trial = {project: $p, tokens_per_call: $t}' --arg p "$BACKFILL_PROJECT" \
            --argjson t $(( (BFR_USED_IN + BFR_USED_OUT + BFR_USAGE_CALLS / 2) / BFR_USAGE_CALLS ))
    fi

    _bfr_report "$scan" "$notes"
    echo
    rc=0
    if [ -n "$BFR_STOP" ]; then
        echo "Interrupted; resume with the same command."
        rc=$BFR_STOP
    else
        case "$BFR_RUN_STOP" in
            cap)
                echo "Stopped at the cap before the next call; resume with the same command (--max-tokens raises the cap)." ;;
            not_retryable)
                echo "Stopped: $BFR_REASON"
                echo "Fix that on the server, then resume with the same command."
                rc=1 ;;
            transient)
                echo "Stopped: three failures in a row that retrying may fix (the server or the network); resume with the same command."
                rc=1 ;;
            *)
                if [ -s "$BFR_STOPS" ]; then
                    echo "Done, with sessions left (above); resume with the same command to try them again."
                else
                    echo "Done."
                fi ;;
        esac
    fi
    return "$rc"
}
