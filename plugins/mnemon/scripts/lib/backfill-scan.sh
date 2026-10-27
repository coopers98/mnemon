#!/usr/bin/env bash
# mnemon-backfill: flags and the read-only scan. Sourced by mnemon-backfill.sh;
# each function can also be called on its own once this file is sourced.
#
#   backfill_parse_args "$@"   flags into BACKFILL_* variables (returns 2 on a
#                              bad flag, with the reason on stderr)
#   backfill_scan <root>       one TSV row per transcript (see below)
#   backfill_notes <root>      one TSV row per memory note
#   backfill_estimate <tsv>    totals for the eligible rows, into BACKFILL_EST_*
#   backfill_print_scan <scan-tsv> <notes-tsv>
#                              the report mnemon-backfill prints
#
# The scan writes nothing under ~/.mnemon. common.sh is sourced with MNEMON_DIR
# pointed at a temp directory (it creates its sessions directory when sourced),
# and afterwards:
#   MNEMON_DIR, MNEMON_CONFIG  the real ones: credentials, capture_ignore, the
#                              digest limits, and a token refresh if one is due
#   BACKFILL_STATE_DIR         the real sessions directory, read with jq only
#   MNEMON_SESSIONS_DIR,       the temp directory: the simulated slicing and
#   MNEMON_ERROR_LOG           cleaning write and log only there, and the scan
#                              prints what was logged
# The temp directory is removed on exit. A run that sends (Task 7) points
# MNEMON_SESSIONS_DIR and MNEMON_ERROR_LOG back at the real ones first.
#
# Runs wherever the hooks run: bash 3.2, Git Bash, GNU and BSD tools, paths
# with spaces. Internal files separate fields with \037 (unit separator), which
# no path or JSON-derived field here can hold.

BACKFILL_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKFILL_MNEMON_DIR="${MNEMON_DIR:-$HOME/.mnemon}"
BACKFILL_TMP=$(mktemp -d "${TMPDIR:-/tmp}/mnemon-backfill.XXXXXX") || {
    echo "mnemon-backfill: cannot create a temp directory" >&2
    exit 1
}
backfill_cleanup() { [ -n "${BACKFILL_TMP:-}" ] && rm -rf "$BACKFILL_TMP"; }
trap backfill_cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

MNEMON_DIR="$BACKFILL_TMP"
# shellcheck source=../../hooks/lib/common.sh
. "$BACKFILL_LIB_DIR/../../hooks/lib/common.sh"
MNEMON_DIR="$BACKFILL_MNEMON_DIR"
MNEMON_CONFIG="$MNEMON_DIR/config.json"
BACKFILL_STATE_DIR="$MNEMON_DIR/sessions"

BACKFILL_US=$'\037'
# Fallbacks for a server too old to publish a digest block.
BACKFILL_FALLBACK_OVERHEAD=3000
BACKFILL_FALLBACK_OUTPUT=3000
BACKFILL_FALLBACK_MAX_COMPLETION=16000

backfill_usage() {
    cat <<'EOF'
Usage: mnemon-backfill.sh [--dry-run] [options]

With no arguments (or --dry-run) it scans: what an import would send, what it
would cost, and what it skips and why. The scan sends nothing to the model.

  --project <name>             import (or scan) one project: the trial
  --all                        import every eligible project
  --include <project>          bring in a new project, or an unresolved
                               directory under ~/.claude/projects by its name
                               (without the leading "-")
  --include-automation <hash>  bring back one automation cluster
  --automation-min <n>         sessions sharing a first prompt that make it
                               automation (default 5)
  --skip-entrypoint <prefix>   skip sessions whose entrypoint starts with this
  --quiet-minutes <n>          leave files modified this recently (default 30)
  --since <YYYY-MM-DD>         only sessions active on or after this date
  --memory-only                memory notes only, no transcripts
  --max-tokens <n>             cap on input + output tokens
  --max-cost <dollars>         cap in dollars, when a price is published
  --yes                        do not ask before importing (the cap still holds)
  --projects-root <dir>        default: ~/.claude/projects
EOF
}

# Whether a newline-terminated list holds an item. Args: <list> <item>.
backfill_listed() {
    case $'\n'"$1" in *$'\n'"$2"$'\n'*) return 0 ;; esac
    return 1
}

_backfill_need() {
    if [ "$2" -lt 2 ]; then
        echo "mnemon-backfill: $1 needs a value" >&2
        return 2
    fi
}

# Flags into variables. Args: the command line. Returns 2 on a bad flag.
backfill_parse_args() {
    BACKFILL_DRY_RUN=0
    BACKFILL_HELP=0
    BACKFILL_PROJECT=""
    BACKFILL_ALL=0
    BACKFILL_INCLUDES=""            # newline-terminated list
    BACKFILL_INCLUDE_AUTOMATION=""  # newline-terminated list of hashes
    BACKFILL_AUTOMATION_MIN=5
    BACKFILL_SKIP_ENTRYPOINTS=""    # newline-terminated list of prefixes
    BACKFILL_QUIET_MINUTES=30
    BACKFILL_SINCE=""
    BACKFILL_MEMORY_ONLY=0
    BACKFILL_MAX_TOKENS=""
    BACKFILL_MAX_COST=""
    BACKFILL_YES=0
    BACKFILL_PROJECTS_ROOT="${CLAUDE_CONFIG_DIR:-$HOME/.claude}/projects"
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --dry-run) BACKFILL_DRY_RUN=1; shift ;;
            -h|--help) BACKFILL_HELP=1; shift ;;
            --all) BACKFILL_ALL=1; shift ;;
            --memory-only) BACKFILL_MEMORY_ONLY=1; shift ;;
            --yes) BACKFILL_YES=1; shift ;;
            --project|--include|--include-automation|--automation-min|--skip-entrypoint|--quiet-minutes|--since|--max-tokens|--max-cost|--projects-root)
                _backfill_need "$1" "$#" || return 2
                case "$1" in
                    --project) BACKFILL_PROJECT="$2" ;;
                    --include) BACKFILL_INCLUDES="$BACKFILL_INCLUDES$2"$'\n' ;;
                    --include-automation) BACKFILL_INCLUDE_AUTOMATION="$BACKFILL_INCLUDE_AUTOMATION$2"$'\n' ;;
                    --skip-entrypoint) BACKFILL_SKIP_ENTRYPOINTS="$BACKFILL_SKIP_ENTRYPOINTS$2"$'\n' ;;
                    --projects-root) BACKFILL_PROJECTS_ROOT="$2" ;;
                    --automation-min|--quiet-minutes|--max-tokens)
                        case "$2" in
                            ''|*[!0-9]*) echo "mnemon-backfill: $1 takes a whole number, not '$2'" >&2; return 2 ;;
                        esac
                        case "$1" in
                            --automation-min) BACKFILL_AUTOMATION_MIN=$((10#$2)) ;;
                            --quiet-minutes) BACKFILL_QUIET_MINUTES=$((10#$2)) ;;
                            --max-tokens) BACKFILL_MAX_TOKENS=$((10#$2)) ;;
                        esac ;;
                    --since)
                        if ! [[ "$2" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]]; then
                            echo "mnemon-backfill: --since takes a date as YYYY-MM-DD, not '$2'" >&2; return 2
                        fi
                        BACKFILL_SINCE="$2" ;;
                    --max-cost)
                        if ! [[ "$2" =~ ^[0-9]+(\.[0-9]+)?$ ]]; then
                            echo "mnemon-backfill: --max-cost takes an amount in dollars, not '$2'" >&2; return 2
                        fi
                        BACKFILL_MAX_COST="$2" ;;
                esac
                shift 2 ;;
            *) echo "mnemon-backfill: unknown option: $1" >&2; return 2 ;;
        esac
    done
    if [ -n "$BACKFILL_PROJECT" ] && [ "$BACKFILL_ALL" = 1 ]; then
        echo "mnemon-backfill: --project and --all cannot be combined" >&2
        return 2
    fi
    [ "$BACKFILL_AUTOMATION_MIN" -ge 1 ] || BACKFILL_AUTOMATION_MIN=1
    return 0
}

# One pass over a transcript, in jq: whether a record's sessionId differs from
# the file name, the first cwd and entrypoint, the last timestamp, whether any
# human user record starts a line with @nomemo, and the first human prompt
# with its hash. The hash is of the prompt normalised (system-reminders
# dropped, whitespace collapsed); the prompt itself is kept as typed, its first
# 4000 characters JSON-encoded, so that it is redacted before anything else
# touches it (backfill_display_prompt).
#
# A human user record is what mnemon_clean_transcript keeps as a user turn: a
# user record that is not meta, a compact summary, a sidechain, an API error or
# a <synthetic> reply, or a queued_command attachment a human typed. The
# @nomemo test is mnemon-recall.sh's own (grep -iE '^[[:space:]]*@nomemo\b',
# which is per line), on each such record before the cleaner merges any.
#
# The hash is two 31-bit polynomial hashes in hex: no fork per file, and the
# same in every jq from 1.6 (modulo by subtraction, since `%` truncates).
BACKFILL_META_JQ='
def text_of:
    if type == "string" then .
    elif type == "array" then [.[] | objects | select(.type == "text") | .text | strings] | join("\n\n")
    else "" end;
def human_raw:
    if .type == "attachment" then
        (.attachment
         | if type == "object" and .type == "queued_command" and .commandMode == "prompt"
              and ((.origin | if type == "object" then .kind else null end) == "human")
              and (.isMeta != true)
           then (.prompt | text_of) else null end)
    else
        ((.type // (.message | if type == "object" then .role else null end) // .role) as $role
         | if $role == "user" and (.isMeta != true) and (.isCompactSummary != true)
              and (.isSidechain != true) and (.isApiErrorMessage != true)
              and ((.message | if type == "object" then .model else null end) != "<synthetic>")
           then ((if (.message | type) == "object" then .message.content else .content end) | text_of)
           else null end)
    end;
def norm:
    gsub("<system-reminder>[\\s\\S]*?</system-reminder>"; "") | gsub("\\s+"; " ")
    | sub("^ "; "") | sub(" $"; "");
def nomemo: split("\n") | any(.[]; test("^[[:space:]]*@nomemo\\b"; "i"));
def modhash($m; $p): reduce explode[] as $c (0; (. * $m + $c) as $x | $x - (($x / $p) | floor) * $p);
def hex8:
    [recurse(if . >= 16 then (. / 16 | floor) else empty end) | . - ((. / 16 | floor) * 16)]
    | reverse | map("0123456789abcdef"[.:. + 1]) | join("") | ("0000000" + .)[-8:];
reduce (inputs | fromjson? | if type == "array" then .[] else . end | objects) as $r
    ({mm: false, cwd: "", ep: "", ts: "", nomemo: false, first: "", raw: ""};
     ($r | try human_raw catch null) as $h
     | .mm = (.mm or ((($r.sessionId | type) == "string") and $r.sessionId != $sid))
     | (if .cwd == "" and ($r.cwd | type) == "string" then .cwd = $r.cwd else . end)
     | (if .ep == "" and ($r.entrypoint | type) == "string" then .ep = $r.entrypoint else . end)
     | (if ($r.timestamp | type) == "string" then .ts = $r.timestamp else . end)
     | (if ($h | type) == "string" then
            .nomemo = (.nomemo or ($h | nomemo))
            | (if .first == "" then .first = ($h | norm) | .raw = $h else . end)
        else . end))
| (.first | if . == "" then "" else (modhash(31; 2147483647) | hex8) + (modhash(131; 2147483629) | hex8) end) as $hash
| ([(.mm | tostring), .cwd, .ep, .ts, (.nomemo | tostring), $hash] | map(gsub("[[:cntrl:]]"; " ")))
  + [.raw[0:4000] | tojson]
| join("\u001f")
'

# Pass 1, once per run: every transcript at exactly <root>/<dir>/<id>.jsonl,
# never deeper (a subagent's file carries its parent's sessionId). Writes
# $BACKFILL_TMP/meta, one line per file:
#   sid path dir lines mtime bytes mismatch cwd entrypoint last_ts nomemo hash repo project first_prompt_json
backfill_collect() {
    local root="$1" meta="$BACKFILL_TMP/meta" f dir sid lines mtime bytes out
    local mm cwd ep ts nomemo hash first repo="" project="" last_cwd="" seen=0
    [ -f "$meta" ] && return 0
    : > "$meta.tmp"
    for f in "$root"/*/*.jsonl; do
        [ -f "$f" ] || continue
        dir="${f%/*}"; dir="${dir##*/}"
        sid="${f##*/}"; sid="${sid%.jsonl}"
        lines=$(wc -l < "$f" | tr -d ' ')
        bytes=$(wc -c < "$f" | tr -d ' ')
        mtime=$(mnemon_mtime "$f" 0)
        out=$(jq -nRr --arg sid "$sid" "$BACKFILL_META_JQ" < "$f" 2>/dev/null)
        out="${out//$'\r'/}"   # a Windows jq ends its lines with CRLF
        IFS="$BACKFILL_US" read -r mm cwd ep ts nomemo hash first <<< "$out"
        # Sessions of one directory share their cwd: resolve it once.
        if [ "$seen" = 0 ] || [ "$cwd" != "$last_cwd" ]; then
            repo=""; project=""
            if [ -n "$cwd" ]; then
                repo=$(mnemon_repo_name "$cwd")
                [ -n "$repo" ] && project=$(mnemon_project_name "$cwd")
            fi
            last_cwd="$cwd"; seen=1
        fi
        printf '%s\n' "$sid$BACKFILL_US$f$BACKFILL_US$dir$BACKFILL_US${lines:-0}$BACKFILL_US$mtime$BACKFILL_US${bytes:-0}$BACKFILL_US${mm:-false}$BACKFILL_US$cwd$BACKFILL_US$ep$BACKFILL_US$ts$BACKFILL_US${nomemo:-false}$BACKFILL_US$hash$BACKFILL_US$repo$BACKFILL_US$project$BACKFILL_US$first" >> "$meta.tmp"
    done
    mv -f "$meta.tmp" "$meta"
}

# One brain_status call per run, which costs nothing: the server's digest
# figures, and which of the scan's projects have a visible wing. Cached in
# $BACKFILL_TMP, so the scan and the notes share it.
backfill_resolve() {
    local status="$BACKFILL_TMP/status.json" names params result sid mm cwd nomemo repo project _
    [ -e "$status" ] && return 0
    # Only the names of projects that could be imported: never one from a
    # capture_ignore repository, a mismatched file, or an @nomemo or disabled
    # session, which live capture would never name either. The server takes
    # up to 500 names of up to 100 characters; a name left out is answered by
    # no one, and counts as new.
    names=$(
        while IFS="$BACKFILL_US" read -r sid _ _ _ _ _ mm cwd _ _ nomemo _ repo project _ <&3; do
            [ -n "$project" ] && [ "$mm" != true ] && [ "$nomemo" != true ] || continue
            _backfill_ignored "$repo" "$cwd" && continue
            _backfill_read_state "$sid"
            [ "$BACKFILL_ROW_NOMEMO" = true ] || [ "$BACKFILL_ROW_DISABLED" = true ] && continue
            printf '%s\n' "$project"
        done 3< "$BACKFILL_TMP/meta" \
        | LC_ALL=C sort -u | awk 'length($0) <= 100' | head -n 500 | jq -Rnc '[inputs]')
    params=$(jq -nc --argjson p "${names:-[]}" '{name: "brain_status", arguments: {projects: $p}}')
    result=$(mnemon_call "tools/call" "$params" 15000) || result=""
    printf '%s' "$result" > "$status"
}

# Load what backfill_resolve stored into variables:
#   BACKFILL_SERVER_OK      1 when brain_status answered
#   BACKFILL_HAS_DIGEST     1 when the answer had a digest block
#   BACKFILL_RESOLVED       1 when it said which projects have a wing
#   BACKFILL_KNOWN_PROJECTS newline-terminated names that have one
#   BACKFILL_MODEL, BACKFILL_PRICE_IN, BACKFILL_PRICE_OUT (empty: unknown),
#   BACKFILL_OVERHEAD, BACKFILL_TYPICAL_OUT, BACKFILL_MAX_COMPLETION
backfill_load_status() {
    local status="$BACKFILL_TMP/status.json" fields
    BACKFILL_SERVER_OK=0 BACKFILL_HAS_DIGEST=0 BACKFILL_RESOLVED=0 BACKFILL_KNOWN_PROJECTS=""
    BACKFILL_MODEL="" BACKFILL_PRICE_IN="" BACKFILL_PRICE_OUT=""
    BACKFILL_OVERHEAD=$BACKFILL_FALLBACK_OVERHEAD
    BACKFILL_TYPICAL_OUT=$BACKFILL_FALLBACK_OUTPUT
    BACKFILL_MAX_COMPLETION=$BACKFILL_FALLBACK_MAX_COMPLETION
    [ -s "$status" ] || return 0
    BACKFILL_SERVER_OK=1
    fields=$(jq -r '
        def num: if type == "number" then tostring else "" end;
        def whole: if type == "number" and . >= 0 then (floor | tostring) else "" end;
        (.structuredContent.digest // null) as $d
        | if ($d | type) != "object" then "0"
          else ["1", ($d.model // "" | tostring), ($d.price_in_per_mtok | num), ($d.price_out_per_mtok | num),
                ($d.prompt_overhead_tokens | whole), ($d.typical_output_tokens | whole),
                ($d.max_completion_tokens | whole), (if ($d.projects | type) == "object" then "1" else "0" end)]
               | map(gsub("[[:cntrl:]]"; " ")) | join("\u001f") end' "$status" 2>/dev/null)
    local has model pin pout over typ maxc res
    fields="${fields//$'\r'/}"
    IFS="$BACKFILL_US" read -r has model pin pout over typ maxc res <<< "$fields"
    [ "$has" = 1 ] || return 0
    BACKFILL_HAS_DIGEST=1
    BACKFILL_MODEL="$model" BACKFILL_PRICE_IN="$pin" BACKFILL_PRICE_OUT="$pout"
    [ -n "$over" ] && BACKFILL_OVERHEAD="$over"
    [ -n "$typ" ] && BACKFILL_TYPICAL_OUT="$typ"
    [ -n "$maxc" ] && BACKFILL_MAX_COMPLETION="$maxc"
    if [ "$res" = 1 ]; then
        BACKFILL_RESOLVED=1
        BACKFILL_KNOWN_PROJECTS=$(jq -r '.structuredContent.digest.projects | to_entries[]
            | select(.value != null) | .key' "$status" 2>/dev/null | tr -d '\r')
        [ -n "$BACKFILL_KNOWN_PROJECTS" ] && BACKFILL_KNOWN_PROJECTS="$BACKFILL_KNOWN_PROJECTS"$'\n'
    fi
    return 0
}

_backfill_now_us() {
    if [ -n "${EPOCHREALTIME:-}" ]; then
        printf '%s' "${EPOCHREALTIME//[!0-9]/}"
    else
        printf '%s000000' "$(date +%s)"
    fi
}

# Count the calls a digest of lines (from, to] would make, sending nothing:
# the same slice, clean and chunk steps as the digest worker, from the pointer
# to the end. Args: <path> <from> <to>. Echoes "<calls> <chars>", chars being
# the characters of the payloads those calls would carry.
backfill_simulate() {
    local path="$1" p="$2" to="$3" calls=0 chars=0 sliced_to chunk_to rc n
    local slice="$BACKFILL_TMP/sim.slice" out="$BACKFILL_TMP/sim.out"
    while [ "$p" -lt "$to" ]; do
        sliced_to=$(mnemon_slice_transcript "$path" "$p" "$to" "$slice") || break
        chunk_to=$(mnemon_digest_text "$slice" "$out" "$p")
        rc=$?
        case "$chunk_to" in ''|all) chunk_to="$sliced_to" ;; esac
        case "$rc" in
            0) calls=$((calls + 1))
               n=$(jq -Rs 'length' < "$out" 2>/dev/null)
               n="${n//$'\r'/}"
               chars=$((chars + ${n:-0})) ;;
            1) ;;
            # Cleaning failed (logged to the scan's own log): the worker
            # would stop here too.
            *) break ;;
        esac
        [ "$chunk_to" -gt "$p" ] 2>/dev/null || break
        p="$chunk_to"
    done
    rm -f "$slice" "$out"
    printf '%s %s' "$calls" "$chars"
}

# Whether a session's repository is in capture_ignore. Args: <repo> <cwd>.
# When the repository cannot be resolved (its checkout is gone), the cwd's own
# name is the repository name live capture would have used, and is checked
# instead: --include must not bring back a repository the user opted out.
_backfill_ignored() {
    local repo="$1" name="${2%/}"
    if [ -n "$repo" ]; then
        mnemon_capture_ignored "$repo"
        return
    fi
    name="${name%\\}"
    name="${name##*[/\\]}"
    mnemon_capture_ignored "$name"
}

# A session's stored state, read with jq and never created. Args: <sid>.
# Sets BACKFILL_ROW_POINTER, BACKFILL_ROW_NOMEMO, BACKFILL_ROW_DISABLED.
_backfill_read_state() {
    local fields
    BACKFILL_ROW_POINTER=0 BACKFILL_ROW_NOMEMO=false BACKFILL_ROW_DISABLED=false
    [ -f "$BACKFILL_STATE_DIR/$1.json" ] || return 0
    fields=$(jq -r '[((.last_digest_turn // 0) | if type == "number" then floor else 0 end),
                     (.nomemo == true), (.disabled == true)] | map(tostring) | join(" ")' \
        "$BACKFILL_STATE_DIR/$1.json" 2>/dev/null)
    fields="${fields//$'\r'/}"
    read -r BACKFILL_ROW_POINTER BACKFILL_ROW_NOMEMO BACKFILL_ROW_DISABLED <<< "$fields"
    case "$BACKFILL_ROW_POINTER" in ''|*[!0-9]*) BACKFILL_ROW_POINTER=0 ;; esac
    return 0
}

# Whether an entrypoint starts with one of --skip-entrypoint's prefixes.
_backfill_skipped_entrypoint() {
    local ep="$1" prefix
    [ -n "$BACKFILL_SKIP_ENTRYPOINTS" ] || return 1
    while IFS= read -r prefix; do
        [ -n "$prefix" ] || continue
        case "$ep" in "$prefix"*) return 0 ;; esac
    done <<< "$BACKFILL_SKIP_ENTRYPOINTS"
    return 1
}

# Simulate the eligible sessions listed in a file ("path pointer lines bytes"
# per line), appending "path calls chars" lines to $BACKFILL_TMP/sim. When
# simulating every file would take more than MNEMON_BACKFILL_SIM_SECONDS
# (120), extrapolated from the first MNEMON_BACKFILL_SIM_PROBE (20), the rest
# are sorted by size into strata, one file per stratum is simulated and the
# others are scaled from it by size; $BACKFILL_TMP/sampled then says how many
# were simulated.
_backfill_simulate_all() {
    local list="$1" sim="$BACKFILL_TMP/sim" probe="${MNEMON_BACKFILL_SIM_PROBE:-20}"
    local budget="${MNEMON_BACKFILL_SIM_SECONDS:-120}" path ptr lines bytes res t0 t1
    local probe_bytes=0 n_rest rest_bytes k simulated strata="$BACKFILL_TMP/strata" reps="$BACKFILL_TMP/reps"
    case "$probe" in ''|*[!0-9]*) probe=20 ;; esac
    case "$budget" in ''|*[!0-9]*) budget=120 ;; esac
    [ "$probe" -ge 1 ] || probe=1
    : > "$sim"
    t0=$(_backfill_now_us)
    while IFS="$BACKFILL_US" read -r path ptr lines bytes <&3; do
        res=$(backfill_simulate "$path" "$ptr" "$lines")
        printf '%s\n' "$path$BACKFILL_US${res% *}$BACKFILL_US${res#* }" >> "$sim"
        probe_bytes=$((probe_bytes + bytes))
    done 3< <(head -n "$probe" "$list")
    t1=$(_backfill_now_us)

    tail -n +"$((probe + 1))" "$list" > "$list.rest"
    n_rest=$(wc -l < "$list.rest" | tr -d ' ')
    [ "$n_rest" -gt 0 ] || return 0
    rest_bytes=$(awk -F "$BACKFILL_US" '{ b += $4 } END { printf "%d", b }' "$list.rest")
    # Microseconds the rest would take at the probe's rate, against the budget.
    k=$(awk -v e="$((t1 - t0))" -v pb="$probe_bytes" -v rb="$rest_bytes" -v n="$n_rest" -v b="$budget" 'BEGIN {
        est = (pb > 0) ? e * rb / pb : e * n;
        if (est <= b * 1000000) { print 0; exit }
        if (b <= 0) { print n; exit }
        k = est / (b * 1000000); k = (k == int(k)) ? k : int(k) + 1;
        print (k > n) ? n : k }')
    if [ "$k" -le 1 ]; then
        while IFS="$BACKFILL_US" read -r path ptr lines bytes <&3; do
            res=$(backfill_simulate "$path" "$ptr" "$lines")
            printf '%s\n' "$path$BACKFILL_US${res% *}$BACKFILL_US${res#* }" >> "$sim"
        done 3< "$list.rest"
        return 0
    fi

    # Strata of k files by size; the middle file of each is simulated.
    LC_ALL=C sort -t "$BACKFILL_US" -k4,4n "$list.rest" | awk -F "$BACKFILL_US" -v OFS="$BACKFILL_US" -v k="$k" -v n="$n_rest" '{
        s = int((NR - 1) / k); size = ((s + 1) * k <= n) ? k : n - s * k;
        print s, (((NR - 1) - s * k) == int((size - 1) / 2)) ? 1 : 0, $0 }' > "$strata"
    : > "$reps"
    simulated=$(wc -l < "$sim" | tr -d ' ')
    local s isrep
    while IFS="$BACKFILL_US" read -r s isrep path ptr lines bytes <&3; do
        [ "$isrep" = 1 ] || continue
        res=$(backfill_simulate "$path" "$ptr" "$lines")
        printf '%s\n' "$path$BACKFILL_US${res% *}$BACKFILL_US${res#* }" >> "$sim"
        printf '%s\n' "$s$BACKFILL_US${res% *}$BACKFILL_US${res#* }$BACKFILL_US$bytes" >> "$reps"
        simulated=$((simulated + 1))
    done 3< "$strata"
    awk -F "$BACKFILL_US" -v OFS="$BACKFILL_US" -v first="$reps" 'FILENAME == first { c[$1] = $2; ch[$1] = $3; b[$1] = $4; next }
        $2 == 1 { next }
        { r = (b[$1] > 0) ? $6 / b[$1] : 1;
          calls = int(c[$1] * r + 0.5); if (c[$1] > 0 && calls < 1) calls = 1;
          print $3, calls, int(ch[$1] * r + 0.5) }' "$reps" "$strata" >> "$sim"
    printf '%s %s' "$simulated" "$(wc -l < "$list" | tr -d ' ')" > "$BACKFILL_TMP/sampled"
}

# The read-only scan. Args: <projects_root>. Writes TSV to stdout, one row per
# transcript:
#   session_id path project repo status reason lines pointer calls chars
#   first_prompt_hash entrypoint last_ts
# status is eligible or skip; reason (empty when eligible) is one of
# automation, capture_ignore, unresolved, new_project, nomemo, disabled, done,
# busy, recent, id_mismatch, not_included. calls and chars are simulated for
# eligible rows only. Flags come from backfill_parse_args.
backfill_scan() {
    local root="$1" meta cls="$BACKFILL_TMP/cls" elig="$BACKFILL_TMP/elig" auto now since_cut
    local sid path dir lines mtime bytes mm cwd ep ts nomemo hash repo project first
    local status reason ptr st_nomemo st_disabled include_name
    backfill_collect "$root"
    backfill_resolve
    backfill_load_status
    meta="$BACKFILL_TMP/meta"
    rm -f "$BACKFILL_TMP/sampled"
    # First prompts shared by --automation-min sessions or more.
    auto=$(awk -F "$BACKFILL_US" -v min="$BACKFILL_AUTOMATION_MIN" '$12 != "" { n[$12]++ }
        END { for (h in n) if (n[h] >= min) print h }' "$meta")
    [ -n "$auto" ] && auto="$auto"$'\n'
    now=$(date +%s)
    since_cut=$((now - BACKFILL_QUIET_MINUTES * 60))
    : > "$cls"; : > "$elig"
    while IFS="$BACKFILL_US" read -r sid path dir lines mtime bytes mm cwd ep ts nomemo hash repo project first <&3; do
        status=skip reason=""
        _backfill_read_state "$sid"
        ptr="$BACKFILL_ROW_POINTER" st_nomemo="$BACKFILL_ROW_NOMEMO" st_disabled="$BACKFILL_ROW_DISABLED"
        # An unresolved directory comes in under the name --include gives it.
        include_name="${dir#-}"
        if [ -z "$repo" ] && backfill_listed "$BACKFILL_INCLUDES" "$include_name"; then
            project="$include_name"
        fi

        if [ "$mm" = true ]; then reason=id_mismatch
        elif [ "$mtime" -gt "$since_cut" ]; then reason=recent
        elif [ -d "$BACKFILL_STATE_DIR/$sid.digest.lock.d" ]; then reason=busy
        elif [ "$st_disabled" = true ]; then reason=disabled
        elif [ "$st_nomemo" = true ] || [ "$nomemo" = true ]; then reason=nomemo
        elif _backfill_ignored "$repo" "$cwd"; then reason=capture_ignore
        elif [ -z "$repo" ] && [ -z "$project" ]; then reason=unresolved
        elif [ "$BACKFILL_MEMORY_ONLY" = 1 ] \
            || { [ -n "$BACKFILL_PROJECT" ] && [ "$project" != "$BACKFILL_PROJECT" ]; } \
            || { [ -n "$BACKFILL_SINCE" ] && [ -n "$ts" ] && [[ "${ts:0:10}" < "$BACKFILL_SINCE" ]]; }; then
            reason=not_included
        elif { [ -n "$hash" ] && backfill_listed "$auto" "$hash" \
                && ! backfill_listed "$BACKFILL_INCLUDE_AUTOMATION" "$hash"; } \
            || _backfill_skipped_entrypoint "$ep"; then
            reason=automation
        elif [ -n "$repo" ] && [ -n "$project" ] && ! backfill_listed "$BACKFILL_KNOWN_PROJECTS" "$project" \
            && ! backfill_listed "$BACKFILL_INCLUDES" "$project"; then
            reason=new_project
        elif [ "$ptr" -ge "$lines" ]; then reason=done
        else status=eligible
        fi
        printf '%s\n' "$sid$BACKFILL_US$path$BACKFILL_US$project$BACKFILL_US$repo$BACKFILL_US$status$BACKFILL_US$reason$BACKFILL_US$lines$BACKFILL_US$ptr$BACKFILL_US$hash$BACKFILL_US${ep}$BACKFILL_US$ts" >> "$cls"
        [ "$status" = eligible ] && printf '%s\n' "$path$BACKFILL_US$ptr$BACKFILL_US$lines$BACKFILL_US$bytes" >> "$elig"
    done 3< "$meta"

    _backfill_simulate_all "$elig"

    awk -F "$BACKFILL_US" -v OFS='\t' -v first="$BACKFILL_TMP/sim" 'FILENAME == first { c[$1] = $2; ch[$1] = $3; next }
        { for (i = 1; i <= NF; i++) gsub(/\t/, " ", $i)
          print $1, $2, $3, $4, $5, $6, $7, $8, (($2 in c) ? c[$2] : 0), (($2 in ch) ? ch[$2] : 0), $9, $10, $11 }' \
        "$BACKFILL_TMP/sim" "$cls"
}

# The memory notes. Args: <projects_root>. Writes TSV to stdout, one row per
# note at <root>/<dir>/memory/*.md other than MEMORY.md (the index):
#   path project status reason mtime bytes
# A note's project is its directory's: the first of the directory's sessions
# whose cwd resolves. A directory with none, or whose repository is in
# project_ignore (a note has no model to file it), is unresolved unless
# --include names it. --since and --memory-only concern sessions only.
backfill_notes() {
    local root="$1" dirs="$BACKFILL_TMP/dirs" f dir mtime bytes repo project cwd status reason since_cut row _
    backfill_collect "$root"
    backfill_resolve
    backfill_load_status
    # Per directory: the first session whose repository resolves, else the
    # first with a cwd (whose name _backfill_ignored checks).
    awk -F "$BACKFILL_US" -v OFS="$BACKFILL_US" '
        $13 != "" && !($3 in r) { r[$3] = $13; p[$3] = $14; c[$3] = $8; d[$3] = 1 }
        $8 != "" && !($3 in d) { c[$3] = $8; d[$3] = 1 }
        END { for (k in d) print k, r[k], p[k], c[k] }' "$BACKFILL_TMP/meta" > "$dirs"
    since_cut=$(( $(date +%s) - BACKFILL_QUIET_MINUTES * 60 ))
    for f in "$root"/*/memory/*.md; do
        [ -f "$f" ] || continue
        [ "${f##*/}" = MEMORY.md ] && continue
        dir="${f%/memory/*}"; dir="${dir##*/}"
        mtime=$(mnemon_mtime "$f" 0)
        bytes=$(wc -c < "$f" | tr -d ' ')
        row=$(awk -F "$BACKFILL_US" -v d="$dir" '$1 == d { print; exit }' "$dirs")
        repo=""; project=""; cwd=""
        [ -n "$row" ] && IFS="$BACKFILL_US" read -r _ repo project cwd <<< "$row"
        if [ -z "$project" ] && backfill_listed "$BACKFILL_INCLUDES" "${dir#-}"; then
            project="${dir#-}"; repo=""
        fi
        status=skip reason=""
        if [ "$mtime" -gt "$since_cut" ]; then reason=recent
        elif [ -n "$repo$cwd" ] && _backfill_ignored "$repo" "$cwd"; then reason=capture_ignore
        elif [ -z "$project" ]; then reason=unresolved
        elif [ -n "$BACKFILL_PROJECT" ] && [ "$project" != "$BACKFILL_PROJECT" ]; then reason=not_included
        elif [ -n "$repo" ] && ! backfill_listed "$BACKFILL_KNOWN_PROJECTS" "$project" \
            && ! backfill_listed "$BACKFILL_INCLUDES" "$project"; then reason=new_project
        else status=eligible
        fi
        printf '%s\t%s\t%s\t%s\t%s\t%s\n' "${f//$'\t'/ }" "${project//$'\t'/ }" "$status" "$reason" "$mtime" "${bytes:-0}"
    done
}

# Totals for the eligible rows of a scan. Args: <scan-tsv-file>. Sets
# BACKFILL_EST_SESSIONS, _CALLS, _CHARS, _IN_LOW, _IN_HIGH (input tokens:
# characters / 4 plus the prompt overhead, to 1.5x the overhead), _OUT (calls
# x typical output), and _COST_LOW / _COST_HIGH in dollars (empty when no
# price is published). Needs backfill_load_status first.
backfill_estimate() {
    local totals
    totals=$(awk -F'\t' '$5 == "eligible" { n++; c += $9; ch += $10 } END { printf "%d %d %d", n, c, ch }' "$1")
    read -r BACKFILL_EST_SESSIONS BACKFILL_EST_CALLS BACKFILL_EST_CHARS <<< "$totals"
    local base=$(( (BACKFILL_EST_CHARS + 3) / 4 ))
    BACKFILL_EST_IN_LOW=$(( base + BACKFILL_EST_CALLS * BACKFILL_OVERHEAD ))
    BACKFILL_EST_IN_HIGH=$(( base + BACKFILL_EST_CALLS * ((BACKFILL_OVERHEAD * 3 + 1) / 2) ))
    BACKFILL_EST_OUT=$(( BACKFILL_EST_CALLS * BACKFILL_TYPICAL_OUT ))
    BACKFILL_EST_COST_LOW="" BACKFILL_EST_COST_HIGH=""
    if [ -n "$BACKFILL_PRICE_IN" ] && [ -n "$BACKFILL_PRICE_OUT" ]; then
        read -r BACKFILL_EST_COST_LOW BACKFILL_EST_COST_HIGH <<< "$(awk -v lo="$BACKFILL_EST_IN_LOW" \
            -v hi="$BACKFILL_EST_IN_HIGH" -v out="$BACKFILL_EST_OUT" -v pin="$BACKFILL_PRICE_IN" -v pout="$BACKFILL_PRICE_OUT" \
            'BEGIN { printf "%.2f %.2f", (lo * pin + out * pout) / 1000000, (hi * pin + out * pout) / 1000000 }')"
    fi
}

# A duration in seconds, for people. Args: <seconds>.
_backfill_duration() {
    awk -v s="$1" 'BEGIN { if (s < 3600) printf "%d min", int((s + 59) / 60); else printf "%.1f hours", s / 3600 }'
}

# Text from a transcript, safe to print. Args: <the text, JSON-encoded>.
# Redacted as typed, then system-reminders dropped, whitespace collapsed and
# cut to 60 characters. Fails closed: when redaction fails or says a pattern
# failed, "[withheld]" is printed instead.
backfill_display_prompt() {
    local err="$BACKFILL_TMP/redact.err" text rc
    text=$(printf '%s' "$1" | jq -j '.' 2>/dev/null | mnemon_redact_text 2>"$err")
    rc=$?
    if [ "$rc" -ne 0 ] || [ -s "$err" ]; then
        rm -f "$err"
        printf '[withheld]'
        return 0
    fi
    rm -f "$err"
    printf '%s' "$text" | jq -Rrs 'gsub("<system-reminder>[\\s\\S]*?</system-reminder>"; "")
        | gsub("[[:space:]]+"; " ") | sub("^ "; "") | .[0:60]' | tr -d '\r'
}

# The scan report. Args: <scan-tsv-file> <notes-tsv-file>. Needs
# backfill_load_status first.
backfill_print_scan() {
    local scan="$1" notes="$2" line hash count projects state prompt
    # How a row is grouped: its project, else its repository (project_ignore),
    # else the directory name --include would take.
    local label_awk='function label(p, r, path,   n, a) {
        if (p != "") return p
        if (r != "") return r " (project_ignore)"
        n = split(path, a, "/"); return "(unresolved) " substr(a[n - 1], (substr(a[n - 1], 1, 1) == "-") ? 2 : 1) }'

    echo "Per project (sessions found, eligible, simulated calls; memory notes eligible)"
    printf '  %-32s %8s %8s %7s %6s\n' "project" "sessions" "eligible" "calls" "notes"
    awk -F'\t' -v first="$notes" "$label_awk"'
        FILENAME == first { if ($3 == "eligible") { n = split($1, a, "/"); d = a[n - 2]
                        k = ($2 != "") ? $2 : "(unresolved) " substr(d, (substr(d, 1, 1) == "-") ? 2 : 1)
                        notes[k]++; keys[k] = 1 }
                    next }
        { k = label($3, $4, $2); keys[k] = 1; s[k]++; if ($5 == "eligible") { e[k]++; c[k] += $9 } }
        END { for (k in keys) printf "  %-32s %8d %8d %7d %6d\n", k, s[k], e[k], c[k], notes[k] }' \
        "$notes" "$scan" | LC_ALL=C sort

    echo
    echo "Skipped (reason: sessions, notes)"
    awk -F'\t' -v first="$notes" 'FILENAME == first { if ($3 == "skip") { n[$4]++; r[$4] = 1 } next }
        $5 == "skip" { s[$6]++; r[$6] = 1 }
        END { for (k in r) printf "  %-16s %6d %6d\n", k, s[k], n[k] }' "$notes" "$scan" | LC_ALL=C sort
    if ! awk -F'\t' '$5 == "skip" { f = 1 } END { exit !f }' "$scan" \
        && ! awk -F'\t' '$3 == "skip" { f = 1 } END { exit !f }' "$notes"; then
        echo "  (nothing)"
    fi
    local unresolved new
    unresolved=$(awk -F'\t' "$label_awk"'$6 == "unresolved" { print substr(label("", "", $2), 14) }' "$scan" | LC_ALL=C sort -u)
    new=$(awk -F'\t' '$6 == "new_project" { print $3 }' "$scan" | LC_ALL=C sort -u)
    if [ -n "$unresolved" ]; then
        echo "  unresolved (cwd gone or not a repository): --include <name> files them under that name:"
        printf '%s\n' "$unresolved" | sed 's/^/    /'
    fi
    if [ -n "$new" ]; then
        echo "  new projects (no wing on the server yet): --include <project> brings one in:"
        printf '%s\n' "$new" | sed 's/^/    /'
    fi

    echo
    echo "Repeated first prompts (${BACKFILL_AUTOMATION_MIN}+ sessions are skipped as automation; --include-automation <hash> brings one back)"
    printf '  %5s  %-12s  %-16s  %-30s  %s\n' "count" "status" "hash" "projects" "first prompt"
    local clusters
    # A session that opted out (@nomemo, capture_ignore) is never listed.
    clusters=$(awk -F'\t' "$label_awk"'$11 != "" && $6 != "nomemo" && $6 != "capture_ignore" { n[$11]++; k = label($3, $4, $2)
            if (index(SUBSEP p[$11] SUBSEP, SUBSEP k SUBSEP) == 0) p[$11] = (p[$11] == "") ? k : p[$11] SUBSEP k }
        END { for (h in n) if (n[h] >= 2) { gsub(SUBSEP, ",", p[h]); printf "%d\t%s\t%s\n", n[h], h, p[h] } }' "$scan" \
        | LC_ALL=C sort -t "$(printf '\t')" -k1,1nr -k2,2)
    if [ -z "$clusters" ]; then
        echo "  (none)"
    else
        while IFS=$'\t' read -r count hash projects; do
            if backfill_listed "$BACKFILL_INCLUDE_AUTOMATION" "$hash"; then state="included"
            elif [ "$count" -ge "$BACKFILL_AUTOMATION_MIN" ]; then state="skipped"
            else state="eligible"
            fi
            prompt=$(awk -F "$BACKFILL_US" -v h="$hash" '$12 == h { print $15; exit }' "$BACKFILL_TMP/meta")
            printf '  %5s  %-12s  %-16s  %-30s  "%s"\n' "$count" "$state" "$hash" "$projects" "$(backfill_display_prompt "$prompt")"
        done <<< "$clusters"
    fi

    echo
    echo "Entrypoints per project (--skip-entrypoint <prefix> skips by it)"
    awk -F'\t' "$label_awk"'{ k = label($3, $4, $2); e = ($12 == "") ? "unknown" : $12; n[k SUBSEP e]++ }
        END { for (x in n) { split(x, a, SUBSEP); print a[1] "\t" a[2] "\t" n[x] } }' "$scan" \
        | LC_ALL=C sort | awk -F'\t' '$1 != last { if (last != "") print line; line = "  " $1 ": " $2 " " $3; last = $1; next }
            { line = line ", " $2 " " $3 } END { if (last != "") print line }'

    backfill_estimate "$scan"
    echo
    if [ -f "$BACKFILL_TMP/sampled" ]; then
        read -r line count < "$BACKFILL_TMP/sampled"
        echo "Estimate (sampled: $line of $count eligible sessions simulated, the rest extrapolated by size)"
    else
        echo "Estimate (eligible sessions: $BACKFILL_EST_SESSIONS)"
    fi
    echo "  calls:         $BACKFILL_EST_CALLS"
    echo "  input tokens:  $BACKFILL_EST_IN_LOW to $BACKFILL_EST_IN_HIGH (prompt overhead $BACKFILL_OVERHEAD to 1.5x per call)"
    echo "  output tokens: $BACKFILL_EST_OUT ($BACKFILL_TYPICAL_OUT per call typical; at most $BACKFILL_MAX_COMPLETION)"
    if [ -n "$BACKFILL_EST_COST_LOW" ]; then
        echo "  cost:          \$$BACKFILL_EST_COST_LOW to \$$BACKFILL_EST_COST_HIGH (${BACKFILL_MODEL:-digest model} at \$$BACKFILL_PRICE_IN in / \$$BACKFILL_PRICE_OUT out per million tokens)"
    else
        echo "  cost:          unknown (no price published)"
    fi
    echo "  time:          $(_backfill_duration $((BACKFILL_EST_CALLS * 20))) to $(_backfill_duration $((BACKFILL_EST_CALLS * 60))) (20-60 s per call)"
    if [ "$BACKFILL_SERVER_OK" = 0 ]; then
        echo "  The server did not answer brain_status: every project counts as new and the price is unknown."
    elif [ "$BACKFILL_RESOLVED" = 0 ]; then
        echo "  The server is too old to say which projects have a wing: every project counts as new."
    fi
    if [ -s "$MNEMON_ERROR_LOG" ]; then
        echo
        echo "Notes from the scan"
        # The log's own timestamp is dropped; what follows is redacted
        # (withheld whole if redaction fails) and cut to 60 characters.
        local notes_text rc
        notes_text=$(head -n 20 "$MNEMON_ERROR_LOG" | sed 's/^\[[^]]*\] //' \
            | mnemon_redact_text 2>"$BACKFILL_TMP/redact.err")
        rc=$?
        if [ "$rc" -ne 0 ] || [ -s "$BACKFILL_TMP/redact.err" ]; then
            echo "  [withheld] (redaction failed)"
        else
            printf '%s\n' "$notes_text" | tr -d '\r' | awk '{ print "  " substr($0, 1, 60) }'
        fi
        rm -f "$BACKFILL_TMP/redact.err"
        local more
        more=$(( $(wc -l < "$MNEMON_ERROR_LOG") - 20 ))
        [ "$more" -gt 0 ] && echo "  ... and $more more"
    fi
    return 0
}
