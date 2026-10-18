#!/usr/bin/env bash
# Mnemon Claude Code hooks — shared helpers.
# Sourced by mnemon-wake.sh, mnemon-recall.sh, mnemon-capture.sh.

set -u

# The plugin root, for reading our own manifest. Derived from this file's
# location (hooks/lib/common.sh) so it holds whichever hook sourced it, and
# overridden by Claude Code's own variable when it is set.
MNEMON_PLUGIN_ROOT="${CLAUDE_PLUGIN_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." 2>/dev/null && pwd)}"

MNEMON_DIR="${MNEMON_DIR:-$HOME/.mnemon}"
MNEMON_CONFIG="$MNEMON_DIR/config.json"
MNEMON_SESSIONS_DIR="$MNEMON_DIR/sessions"
MNEMON_ERROR_LOG="$MNEMON_DIR/capture-errors.log"
# Where Claude Code's own hook registrations live. Overridable so the tests can
# point at a fixture instead of the real one. CLAUDE_CONFIG_DIR is Claude Code's
# own relocation of ~/.claude.
MNEMON_CLAUDE_SETTINGS="${MNEMON_CLAUDE_SETTINGS:-${CLAUDE_CONFIG_DIR:-$HOME/.claude}/settings.json}"

mkdir -p "$MNEMON_SESSIONS_DIR" 2>/dev/null || true

# Whether this session should be left out of the palace entirely.
#
# The hooks are registered globally, so every Claude Code session on a machine
# feeds the palace -- cron-launched ones included, and those produce
# byte-identical transcripts every day. On the live instance that was 17% of all
# stored drawers, the worst single prompt kept seven times.
#
# @nomemo already covers "this conversation, from inside the prompt". This covers
# the other case: automation that does not author its own prompt but knows it has
# nothing worth remembering. Set MNEMON_DISABLE=1 in the wrapper that launches it.
mnemon_disabled() {
    case "${MNEMON_DISABLE:-${CLAUDE_PLUGIN_OPTION_MNEMON_DISABLE:-}}" in
        ''|0|false|no) return 1 ;;
        *) return 0 ;;
    esac
}

# Read endpoint + bearer token. Echoes "<endpoint>|<token>" or empty if not configured.
mnemon_token() {
    # Environment first. A plugin can hand its hooks credentials this way, which
    # keeps the token out of a file on disk and out of any transcript. Both
    # Mnemon's own names and Claude Code's plugin-option names are accepted.
    local env_endpoint env_token
    env_endpoint="${MNEMON_ENDPOINT:-${CLAUDE_PLUGIN_OPTION_MNEMON_ENDPOINT:-}}"
    env_token="${MNEMON_TOKEN:-${CLAUDE_PLUGIN_OPTION_MNEMON_TOKEN:-}}"
    if [ -n "$env_endpoint" ] && [ -n "$env_token" ]; then
        printf '%s|%s' "$env_endpoint" "$env_token"
        return 0
    fi

    if [ -f "$MNEMON_CONFIG" ]; then
        local endpoint token
        endpoint=$(jq -r '.endpoint // empty' "$MNEMON_CONFIG" 2>/dev/null)
        token=$(jq -r '.bearer_token // empty' "$MNEMON_CONFIG" 2>/dev/null)
        if [ -n "$endpoint" ] && [ -n "$token" ]; then
            printf '%s|%s' "$endpoint" "$token"
            return 0
        fi
    fi
    # Fallback: try parsing ~/.claude.json mnemon entry.
    if [ -f "$HOME/.claude.json" ]; then
        local endpoint token
        endpoint=$(jq -r '.mcpServers.mnemon.url // empty' "$HOME/.claude.json" 2>/dev/null)
        token=$(jq -r '.mcpServers.mnemon.headers.Authorization // empty' "$HOME/.claude.json" 2>/dev/null | sed 's/^Bearer //')
        if [ -n "$endpoint" ] && [ -n "$token" ]; then
            printf '%s|%s' "$endpoint" "$token"
            return 0
        fi
    fi
    return 1
}

# Atomically replace config.json with JSON read from stdin. Validates before
# renaming and chmods the temp before the rename (the umask is not trusted), so
# the file on disk is always either the old working state or the new working
# state. A truncated config.json is indistinguishable from an unconfigured
# machine, and a zero-byte state file once wedged a session for hours; this is
# the codified cure, the same shape as mnemon_session_state_write.
mnemon_config_write() {
    local tmp="$MNEMON_CONFIG.tmp.$$"
    cat > "$tmp" 2>/dev/null || { rm -f "$tmp"; return 1; }
    if ! jq -e . "$tmp" >/dev/null 2>&1; then
        mnemon_log_error "refusing to replace config.json with invalid JSON"
        rm -f "$tmp"
        return 1
    fi
    chmod 600 "$tmp" 2>/dev/null
    mv -f "$tmp" "$MNEMON_CONFIG"
}

# Whether a 401 is worth trying to repair. The presence of refresh_token and
# client_id is the feature switch, so a PAT device -- the whole installed base --
# takes none of this path.
#
# Credentials from the environment are excluded deliberately. They are static, so
# refreshing would rewrite a file nothing reads: every call would 401, "succeed"
# at refreshing, and 401 again, converging never.
mnemon_refresh_available() {
    [ -n "${MNEMON_TOKEN:-}${CLAUDE_PLUGIN_OPTION_MNEMON_TOKEN:-}" ] && return 1
    [ -f "$MNEMON_CONFIG" ] || return 1
    jq -e '(.refresh_token // "") != "" and (.client_id // "") != ""
           and (.client_secret // "") != "" and (.token_endpoint // "") != ""
           and ((.auth_state // "ok") != "dead")' "$MNEMON_CONFIG" >/dev/null 2>&1
}

# Exchange the refresh token for a new access/refresh pair. Args: the token that
# just got the 401. Echoes a usable access token on success -- its own, or a
# newer one another process wrote while this one waited.
#
# The device is marked dead only on a definitive OAuth rejection: invalid_grant
# (the refresh token is spent or revoked) or invalid_client/unauthorized_client
# (the client was revoked -- what the per-device kill switch actually looks like
# from here). A network error, a 5xx, or a proxy's HTML page is NOT dead: the
# refresh token is kept and the next 401 tries again. Throwing away a 90-day
# credential because a load balancer hiccuped would turn a blip into a
# re-enrolment that needs a browser the device may not have.
#
# Subshell body, so the EXIT trap releases the lock however this returns.
mnemon_refresh() (
    old_token="$1"
    lock="$MNEMON_DIR/refresh.lock.d"

    # Every hook fires on the same events, so an expiry is discovered by several
    # processes at once. Without a lock each spends the same refresh token and
    # all but one are rejected -- which, with rotation on, would have bricked the
    # device outright.
    #
    # mkdir is atomic everywhere these hooks run (jq + curl + coreutils; macOS
    # has no flock). The digest worker's lock uses the same primitive
    # (mnemon_digest_lock). Stale locks are recovered by liveness check;
    # waiters give up after ~5s and fail only this one call.
    waited=0
    while ! mkdir "$lock" 2>/dev/null; do
        pid=$(cat "$lock/pid" 2>/dev/null)
        if [ -n "$pid" ] && ! kill -0 "$pid" 2>/dev/null; then
            rm -rf "$lock"
            continue
        fi
        if [ "$waited" -ge 25 ]; then
            mnemon_log_error "refresh lock busy >5s; skipping refresh for this call"
            exit 1
        fi
        sleep 0.2
        waited=$((waited + 1))
    done
    echo "$$" > "$lock/pid"
    trap 'rm -rf "$lock"' EXIT

    # Another hook may have refreshed while we waited. If the stored token has
    # already moved on, use it and spend nothing. This is the whole anti-stampede
    # mechanism -- the lock only serialises; this is what makes the losers cheap.
    current=$(jq -r '.bearer_token // empty' "$MNEMON_CONFIG" 2>/dev/null)
    if [ -n "$current" ] && [ "$current" != "$old_token" ]; then
        printf '%s' "$current"
        exit 0
    fi

    token_endpoint=$(jq -r '.token_endpoint // empty' "$MNEMON_CONFIG")
    body=$(mktemp "${TMPDIR:-/tmp}/mnemon-refresh.XXXXXX") || exit 1
    # Secrets travel via --data @file, never argv: argv is visible in ps.
    jq -r '"grant_type=refresh_token" +
           "&refresh_token=\(.refresh_token|@uri)" +
           "&client_id=\(.client_id|@uri)" +
           "&client_secret=\(.client_secret|@uri)" +
           "&scope=mcp%3Ause"' "$MNEMON_CONFIG" > "$body"

    raw=$(curl -s -m 5 -w '\n%{http_code}' -X POST "$token_endpoint" \
        -H "Content-Type: application/x-www-form-urlencoded" \
        -H "Accept: application/json" \
        --data @"$body" 2>/dev/null)
    rc=$?
    rm -f "$body"
    if [ $rc -ne 0 ]; then
        mnemon_log_error "refresh failed: curl exit $rc (network); keeping credentials, will retry on next 401"
        exit 1
    fi

    status="${raw##*$'\n'}"
    response="${raw%$'\n'*}"

    if ! printf '%s' "$response" | jq -e . >/dev/null 2>&1; then
        mnemon_log_error "refresh failed: non-JSON response (HTTP $status): $(printf '%s' "$response" | head -c 80 | tr -d '\r\n'); keeping credentials"
        exit 1
    fi

    oauth_error=$(printf '%s' "$response" | jq -r '.error // empty')
    case "$oauth_error" in
        invalid_grant|invalid_client|unauthorized_client)
            jq --arg e "$oauth_error" \
               '. + {auth_state: "dead", auth_dead_reason: $e, auth_dead_at: (now | floor)}' \
               "$MNEMON_CONFIG" | mnemon_config_write
            mnemon_log_error "refresh rejected ($oauth_error): device authorization is dead; wake will show the re-auth banner"
            exit 1
            ;;
    esac

    access=$(printf '%s' "$response" | jq -r '.access_token // empty')
    refresh=$(printf '%s' "$response" | jq -r '.refresh_token // empty')
    if [ "$status" != "200" ] || [ -z "$access" ] || [ -z "$refresh" ]; then
        mnemon_log_error "refresh failed: HTTP $status with token(s) missing; keeping old credentials"
        exit 1
    fi

    jq --arg a "$access" --arg r "$refresh" \
       '. + {bearer_token: $a, refresh_token: $r, refreshed_at: (now | floor), auth_state: "ok"}
        | del(.auth_dead_reason, .auth_dead_at)' \
       "$MNEMON_CONFIG" | mnemon_config_write || exit 1

    mnemon_log_error "refreshed access token; next expiry in ~60 minutes"
    printf '%s' "$access"
)

# JSON-RPC POST to the MCP endpoint.
# Args: <method> <params_json> <timeout_ms> [<endpoint>] [<token>]
# Echoes the result field on success, empty on error/timeout.
#
# A caller that needs to know why a call failed (the digest worker, to tell a
# failure the payload caused from one that retrying fixes) names a file in
# MNEMON_CALL_ERROR_FILE. Each failure writes one line there, led by what
# failed: "transport: ...", "HTTP <status>: ...", "non-JSON ...", "rpc error:
# ...", or a tool error's own text.
_mnemon_call_failed() {
    [ -n "${MNEMON_CALL_ERROR_FILE:-}" ] && printf '%s' "$*" > "$MNEMON_CALL_ERROR_FILE"
    return 1
}

mnemon_call() {
    local method="$1"
    local params="$2"
    local timeout_ms="$3"
    local endpoint token
    if [ "$#" -ge 5 ]; then
        endpoint="$4"
        token="$5"
    else
        local pair
        pair=$(mnemon_token) || return 1
        endpoint="${pair%|*}"
        token="${pair#*|}"
    fi

    local timeout_s
    timeout_s=$(awk "BEGIN{print $timeout_ms/1000}")

    # Build the request body in a file and post it from there. A session
    # transcript runs to megabytes, and both `jq --argjson` and `curl -d` take
    # their value through argv, which dies with "Argument list too long" past
    # MAX_ARG_STRLEN (~128KB on Linux). Shell builtins and redirection have no
    # such limit.
    local body params_file
    body=$(mktemp "${TMPDIR:-/tmp}/mnemon-body.XXXXXX") || return 1
    params_file="$body.params"
    printf '%s' "$params" > "$params_file"

    if ! jq -n --arg m "$method" --slurpfile p "$params_file" \
        '{jsonrpc:"2.0",id:1,method:$m,params:$p[0]}' > "$body" 2>/dev/null; then
        rm -f "$body" "$params_file"
        return 1
    fi
    rm -f "$params_file"

    local response raw status
    # Accept is required by MCP Streamable HTTP, and it is what makes a Laravel
    # instance answer an auth failure with a 401 instead of a 302 redirect to an
    # HTML login page -- without it the token-expired branch below never fires.
    local rc=0
    raw=$(curl -s -m "$timeout_s" -w '\n%{http_code}' -X POST "$endpoint" \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        -H "Accept: application/json, text/event-stream" \
        --data-binary @"$body" 2>/dev/null) || rc=$?
    rm -f "$body"
    [ "$rc" -eq 0 ] || { _mnemon_call_failed "transport: curl exit $rc"; return 1; }

    status="${raw##*$'\n'}"
    response="${raw%$'\n'*}"

    # Check the status before the body. Laravel answers 401 with valid JSON
    # carrying "message" (not "error"), so an expired token otherwise slips
    # through every check below and the call returns empty with exit 0 --
    # indistinguishable from "nothing found", and logged nowhere.
    if [ "${status:-0}" -ge 400 ] 2>/dev/null; then
        # 401 only. A 403 is a scope or wing denial and no new token fixes it.
        # Exactly one retry: bash scopes an assignment preceding a function call
        # to that call, so the guard reaches the recursive call and no further.
        if [ "$status" = "401" ] && [ "${MNEMON_RETRIED:-0}" != "1" ] && mnemon_refresh_available; then
            local fresh
            if fresh=$(mnemon_refresh "$token"); then
                MNEMON_RETRIED=1 mnemon_call "$method" "$params" "$timeout_ms" "$endpoint" "$fresh"
                return $?
            fi
        fi

        case "$status" in
            401|403) mnemon_log_error "HTTP $status from $endpoint - token rejected or expired; re-run the Mnemon setup to refresh it" ;;
            413)     mnemon_log_error "HTTP $status from $endpoint - request too large for the server or its proxy" ;;
            *)       mnemon_log_error "HTTP $status from $endpoint: $(printf '%s' "$response" | head -c 80 | tr -d '\r\n')" ;;
        esac
        _mnemon_call_failed "HTTP $status"
        return 1
    fi

    # A proxy rejecting the request answers with an HTML page, not JSON. Piping
    # that to jq yields "Invalid numeric literal", which says nothing about what
    # went wrong -- so say it here instead.
    if ! printf '%s' "$response" | jq -e . >/dev/null 2>&1; then
        mnemon_log_error "non-JSON response (HTTP $status) from $endpoint: $(printf '%s' "$response" | head -c 80 | tr -d '\r\n')"
        _mnemon_call_failed "non-JSON response (HTTP $status)"
        return 1
    fi

    # A JSON-RPC protocol error.
    local err
    err=$(printf '%s' "$response" | jq -r '.error.message // .error // empty' 2>/dev/null)
    if [ -n "$err" ]; then
        mnemon_log_error "rpc error from $endpoint: $(printf '%s' "$err" | head -c 160)"
        _mnemon_call_failed "rpc error: $err"
        return 1
    fi

    # A tool-level error. These arrive as HTTP 200 with isError on the *result*,
    # not as a JSON-RPC error -- a wing denial is Response::error(), which is
    # exactly this shape. Checking only .error made an authorisation failure
    # indistinguishable from an empty palace, on every hook, logged nowhere.
    if [ "$(printf '%s' "$response" | jq -r '.result.isError // false' 2>/dev/null)" = "true" ]; then
        local tool_err
        tool_err=$(printf '%s' "$response" | jq -r '[.result.content[]?.text] | join(" ") // "(no message)"' 2>/dev/null)
        mnemon_log_error "tool error from $endpoint: $(printf '%s' "$tool_err" | head -c 160)"
        _mnemon_call_failed "$tool_err"
        return 1
    fi

    printf '%s' "$response" | jq -c '.result // empty'
}

# The plugin version this device is running, or empty when there is no manifest
# (a hooks-only install, or credentials supplied by environment alone).
#
# Claude Code caches a copy of these hooks per version and refreshes it only when
# the version string changes, so a device can sit on an old copy indefinitely
# with nothing to say so. Since 0.3.0 that copy carries credential-refresh
# logic — a stale one fails in exactly the ways the current one prevents.
mnemon_plugin_version() {
    [ -n "${MNEMON_PLUGIN_ROOT:-}" ] || return 0
    jq -r '.version // empty' "$MNEMON_PLUGIN_ROOT/.claude-plugin/plugin.json" 2>/dev/null
}

# Read a scalar from config.json. Args: <key> <default>. Echoes the value.
mnemon_config_value() {
    local key="$1" fallback="$2" value
    value=$(jq -r --arg k "$key" '.[$k] // empty' "$MNEMON_CONFIG" 2>/dev/null)
    if [ -z "$value" ] || [ "$value" = "null" ]; then
        printf '%s' "$fallback"
    else
        printf '%s' "$value"
    fi
}

# Epoch expiry of a Passport JWT. Args: <token>. Echoes the exp claim, or
# returns 1 when the token carries no readable expiry.
#
# Passport issues JWTs, so the expiry travels with the token and no request is
# needed. A token that is not a JWT is not an error -- it just cannot be checked.
mnemon_token_exp() {
    local token="$1" payload pad exp
    case "$token" in
        *.*.*) ;;
        *) return 1 ;;
    esac

    payload=$(printf '%s' "$token" | cut -d. -f2 | tr '_-' '/+')
    # base64 needs the padding a JWT omits.
    pad=$(( (4 - ${#payload} % 4) % 4 ))
    while [ "$pad" -gt 0 ]; do payload="${payload}="; pad=$((pad - 1)); done

    exp=$(printf '%s' "$payload" | base64 -d 2>/dev/null | jq -r '.exp // empty' 2>/dev/null)

    # Passport issues a fractional expiry -- a real token carries
    # exp: 1796078075.216503 -- so truncate rather than reject. Rejecting it is
    # what the previous version did, which meant no real token's expiry could be
    # read at all: the 14-day countdown never fired once in production, and
    # proactive refresh could never trigger on an enrolled device. The tests
    # passed throughout because the fixture emitted a clean integer.
    exp="${exp%%.*}"

    case "$exp" in
        ''|*[!0-9]*) return 1 ;;
    esac

    printf '%s' "$exp"
}

# Seconds remaining before a token expires. Args: <token>.
mnemon_token_seconds_left() {
    local exp
    exp=$(mnemon_token_exp "$1") || return 1
    printf '%s' "$(( exp - $(date +%s) ))"
}

# Days remaining before a token expires. Args: <token>. Floors, so a token with
# less than a day left reads 0 -- which is why refresh-capable devices must not
# use this for a warning: their access token lives one hour.
mnemon_token_days_left() {
    local exp
    exp=$(mnemon_token_exp "$1") || return 1
    printf '%s' "$(( (exp - $(date +%s)) / 86400 ))"
}

# The instance base URL, for printing in a banner. Falls back to a placeholder
# only when there is no config at all -- a banner telling someone to run a
# command with "<your-instance>" still in it is only half loud.
mnemon_instance_url() {
    local endpoint
    endpoint=$(mnemon_config_value 'endpoint' '')
    if [ -z "$endpoint" ]; then
        printf '%s' 'https://<your-instance>'
        return 0
    fi
    printf '%s' "${endpoint%/mcp}"
}

# A path's modification time in epoch seconds. Args: <path> [fallback]; the
# fallback (default: now) when it cannot be read.
#
# GNU stat takes -c %Y and BSD stat -f %m, and the usual "try one, then the
# other" goes wrong on Linux when the path vanishes in between: GNU stat reads
# -f as "file system status" and prints a block of text, which then lands in
# shell arithmetic and kills the hook. Only a number is accepted.
mnemon_mtime() {
    local m
    m=$(stat -c %Y "$1" 2>/dev/null) || m=$(stat -f %m "$1" 2>/dev/null) || m=""
    case "$m" in
        ''|*[!0-9]*) printf '%s' "${2:-$(date +%s)}" ;;
        *) printf '%s' "$m" ;;
    esac
}

# Deliver an event once. Args: <session_id> <event> [ttl_seconds].
# Returns 0 to the first caller inside the window and 1 to every other.
#
# The hooks can end up registered twice -- once by the plugin, once by leftover
# settings.json entries from the pre-plugin installer. Both copies then fire on
# the same event, concurrently. The recent-fire suppression in recall does not
# help: both processes read the session state before either writes it, so both
# pass the check. The result is the same context injected twice for two round
# trips.
#
# mkdir is the atomic primitive here, as it is for the refresh lock; nothing
# else is available everywhere these hooks run.
mnemon_claim_once() {
    local sid="$1" event="$2" ttl="${3:-10}"
    local claim="$MNEMON_SESSIONS_DIR/${sid}.${event}.claim"

    if mkdir "$claim" 2>/dev/null; then
        return 0
    fi

    # The claim has to expire. SessionStart fires again on resume and after
    # compaction, and a hook killed mid-run must not silence its session for
    # good -- silence is the failure mode this whole file exists to avoid.
    local mtime now
    mtime=$(mnemon_mtime "$claim" 0)
    now=$(date +%s)
    if [ $(( now - mtime )) -ge "$ttl" ]; then
        rm -rf "$claim" 2>/dev/null
        mkdir "$claim" 2>/dev/null && return 0
    fi

    return 1
}

# Whether this device registers the hooks twice.
#
# Only detectable from inside a plugin run: CLAUDE_PLUGIN_ROOT is set by Claude
# Code for plugin hooks, so finding our scripts in settings.json as well means
# both are live.
mnemon_double_registration() {
    [ -n "${CLAUDE_PLUGIN_ROOT:-}" ] || return 1
    [ -f "$MNEMON_CLAUDE_SETTINGS" ] || return 1
    grep -qE 'mnemon-(wake|recall|capture)\.sh' "$MNEMON_CLAUDE_SETTINGS" 2>/dev/null
}

# Read or initialize session state. Args: <session_id>. Echoes JSON.
mnemon_session_state() {
    local sid="$1"
    local f="$MNEMON_SESSIONS_DIR/${sid}.json"

    # Existence is not validity. A zero-byte or unparseable file used to be
    # returned as-is, and the digest worker then handed "" to jq --argjson,
    # which fails -- and because the failure stopped the state from ever being
    # rewritten, the session stayed wedged, retrying forever. Reinitialise
    # instead, writing atomically so an interrupted write cannot leave the
    # truncated file that causes this in the first place.
    if [ ! -s "$f" ] || ! jq -e . "$f" >/dev/null 2>&1; then
        local tmp="${f}.init.$$"
        printf '{"last_digest_turn":0,"last_recall_at":0,"recent_drawer_ids":[],"nomemo":false,"disabled":false}' > "$tmp" \
            && mv -f "$tmp" "$f"
    fi

    cat "$f"
}

# Atomically write session state. Args: <session_id> <json>.
#
# Only a JSON object is written. An empty or unparseable value used to land as
# an empty or garbage file, and the next read reinitialised the state --
# rewinding last_digest_turn to 0 and clearing @nomemo. It is refused and
# logged now, and the stored state kept.
#
# last_digest_turn only moves forward: the value written is the larger of the
# one passed and the one on disk, so a writer holding an older copy cannot
# rewind it and have turns digested twice. Prefer mnemon_session_state_update,
# which applies a change to what is on disk rather than replacing it.
mnemon_session_state_write() {
    local sid="$1"
    local json="$2"
    local f="$MNEMON_SESSIONS_DIR/${sid}.json"
    local tmp="${f}.tmp.$$"
    local old
    old=$(jq -r '.last_digest_turn // 0' "$f" 2>/dev/null)
    case "$old" in ''|*[!0-9]*) old=0 ;; esac
    if ! printf '%s' "$json" | jq -ce --argjson old "$old" '
        if type == "object"
        then .last_digest_turn = ([(.last_digest_turn // 0), $old] | max)
        else error("not an object") end' > "$tmp" 2>/dev/null; then
        rm -f "$tmp"
        mnemon_log_error "refusing to write session state for session=$sid that is not a JSON object; keeping the stored state"
        return 1
    fi
    mv -f "$tmp" "$f"
}

# Run a command holding a short mkdir lock. Args: <lockdir> <command...>.
#
# For read-modify-write of small files several hooks touch at once (session
# state, the pending marker). The wait is wall-clock: MNEMON_LOCK_WAIT seconds
# (default 5). When it runs out, MNEMON_LOCK_BUSY says what to do: "run" (the
# default) runs the command anyway, logged -- losing an update is better than
# a hook that hangs -- and "skip" does not run it and returns 1, for a write
# that does not matter enough to wait for (recall's timestamp).
#
# A lock 10s old was left by a process that died (owners hold these for
# milliseconds) and is taken over: renamed aside first, so of several waiters
# only the one whose rename succeeds removes it. Checking the owner's pid
# instead lost updates under load: the pid read can be an owner that just
# released normally, and by the time it is found dead the lock at this path is
# a new owner's. Released only by the process whose pid it holds.
mnemon_with_lock() {
    local lock="$1" wait="${MNEMON_LOCK_WAIT:-5}" busy="${MNEMON_LOCK_BUSY:-run}" since=$SECONDS rc
    shift
    while ! mkdir "$lock" 2>/dev/null; do
        if [ $(( $(date +%s) - $(mnemon_mtime "$lock") )) -ge 10 ]; then
            mv "$lock" "$lock.stale.$$" 2>/dev/null && rm -rf "$lock.stale.$$"
            continue
        fi
        if [ $(( SECONDS - since )) -ge "$wait" ]; then
            if [ "$busy" = "skip" ]; then
                mnemon_log_error "lock $lock busy for ${wait}s; skipped: $*"
                return 1
            fi
            mnemon_log_error "lock $lock busy for ${wait}s; going ahead without it"
            "$@"
            return $?
        fi
        sleep 0.05
    done
    echo "$$" > "$lock/pid"
    "$@"
    rc=$?
    [ "$(cat "$lock/pid" 2>/dev/null)" = "$$" ] && rm -rf "$lock"
    return $rc
}

# Apply a jq filter to a session's stored state and write the result.
# Args: <session_id> <filter> [jq args...], e.g.
#   mnemon_session_state_update "$sid" '.last_recall_at = $n' --argjson n 5
#
# The change is applied to the state on disk at write time, under a lock.
# Writing back a copy read earlier -- before a server call that takes seconds --
# undid whatever another hook changed meanwhile: an @nomemo set (a privacy
# flag), drawer ids a digest recorded.
mnemon_session_state_update() {
    mnemon_with_lock "$MNEMON_SESSIONS_DIR/${1}.state.lock.d" _mnemon_session_state_update "$@"
}

_mnemon_session_state_update() {
    local sid="$1" filter="$2" cur new
    shift 2
    cur=$(mnemon_session_state "$sid")
    if ! new=$(printf '%s' "$cur" | jq -c "$@" "$filter" 2>/dev/null); then
        mnemon_log_error "session state update failed for session=$sid: $filter"
        return 1
    fi
    mnemon_session_state_write "$sid" "$new"
}

# Take a session's digest lock. Args: <session_id>. Returns 0 when this process
# now holds it, 1 when a live worker does.
#
# mkdir is atomic, the same primitive as the refresh lock. The lock before this
# was a PID file checked and then written, so two workers started together
# could both find it absent and both digest the same turns.
#
# A lock is stale when its owner is gone, or when it is older than any digest
# could take (the call itself times out at 90s) -- a recycled PID must not hold
# a session's capture hostage. A lock with no pid yet is one being taken right
# now, so it is left alone until it ages out.
MNEMON_DIGEST_LOCK_STALE_SECONDS="${MNEMON_DIGEST_LOCK_STALE_SECONDS:-600}"
mnemon_digest_lock() {
    local lock="$MNEMON_SESSIONS_DIR/${1}.digest.lock.d" pid mtime tries=0
    while ! mkdir "$lock" 2>/dev/null; do
        [ "$tries" -ge 3 ] && return 1
        tries=$((tries + 1))
        pid=$(cat "$lock/pid" 2>/dev/null)
        # A lock whose age cannot be read counts as new: reading it as 1970
        # made any such lock stale at once, and a live worker's was taken.
        mtime=$(mnemon_mtime "$lock")
        # A dead owner counts only once its lock is 2s old: the pid read
        # above may be a worker that just released, and the lock now at this
        # path a new worker's (see mnemon_with_lock).
        if [ $(( $(date +%s) - mtime )) -ge "$MNEMON_DIGEST_LOCK_STALE_SECONDS" ] \
            || { [ -n "$pid" ] && [ $(( $(date +%s) - mtime )) -ge 2 ] && ! kill -0 "$pid" 2>/dev/null; }; then
            rm -rf "$lock"
            continue
        fi
        return 1
    done
    echo "$$" > "$lock/pid"
    return 0
}

# Release a lock taken by mnemon_digest_lock. Args: <session_id>. Only a lock
# this process holds: one that went stale and was taken over belongs to the
# worker that took it.
mnemon_digest_unlock() {
    local lock="$MNEMON_SESSIONS_DIR/${1}.digest.lock.d"
    [ "$(cat "$lock/pid" 2>/dev/null)" = "$$" ] && rm -rf "$lock"
    return 0
}

# Mark a held digest lock as fresh. Args: <session_id>. A worker running several
# 90s passes would otherwise approach the stale limit and be taken over.
mnemon_digest_lock_touch() {
    touch "$MNEMON_SESSIONS_DIR/${1}.digest.lock.d" 2>/dev/null || true
}

# Hand a range to the digest that is running for this session.
# Args: <session_id> <transcript_path> <end>.
#
# A Stop that arrives while a digest runs cannot digest its turns itself (one
# worker per session), and "the next Stop will cover it" is false when the
# user quits after that reply. The running worker digests this range when it
# finishes. Several Stops in a row keep the furthest end.
mnemon_digest_pending_add() {
    mnemon_with_lock "$MNEMON_SESSIONS_DIR/${1}.pending.lock.d" _mnemon_digest_pending_add "$@"
}

_mnemon_digest_pending_add() {
    local f="$MNEMON_SESSIONS_DIR/${1}.digest.pending" path="$2" end="$3" old
    old=$(jq -r '.end // 0' "$f" 2>/dev/null)
    case "$old" in ''|*[!0-9]*) old=0 ;; esac
    [ "$old" -gt "$end" ] && end="$old"
    if ! { jq -cn --arg p "$path" --argjson e "$end" '{path: $p, end: $e}' > "$f.tmp.$$" 2>/dev/null \
            && mv -f "$f.tmp.$$" "$f"; }; then
        rm -f "$f.tmp.$$"
        mnemon_log_error "could not record pending digest range for session=$1 (end $end); the next Stop covers it"
        return 1
    fi
}

# Read the pending marker and remove it, under its lock. Args: <session_id>.
# Echoes the marker JSON (empty when there is none).
mnemon_digest_pending_take() {
    mnemon_with_lock "$MNEMON_SESSIONS_DIR/${1}.pending.lock.d" _mnemon_digest_pending_take "$1"
}

_mnemon_digest_pending_take() {
    local f="$MNEMON_SESSIONS_DIR/${1}.digest.pending"
    cat "$f" 2>/dev/null
    rm -f "$f"
}

# How many sessions have turns left undigested after failures. Args:
# [--tidy]. Echoes the count.
#
# A session counts only when it has real work: a pending range ending past its
# pointer, or failed digests with transcript lines past its pointer -- and a
# transcript that still exists, and no @nomemo. Candidates come from one grep
# over the state directory and the pending markers, never a fork per state
# file, and the scan stops after one to two seconds.
#
# --tidy (SessionStart) also clears what can never be retried: a marker at or
# below the pointer is removed, and a session whose transcript is gone is
# logged once and its failure state cleared. Without that the banner said
# "undigested" for ever.
mnemon_undigested_sessions() {
    local tidy=false n=0 since=$SECONDS sid f fields nomemo ptr fails tp pend marker
    [ "${1:-}" = "--tidy" ] && tidy=true
    local candidates=""
    for f in $(grep -lE '"digest_failures": *[1-9]' "$MNEMON_SESSIONS_DIR"/*.json 2>/dev/null); do
        sid="${f##*/}"; candidates="$candidates ${sid%.json}"
    done
    for f in "$MNEMON_SESSIONS_DIR"/*.digest.pending; do
        [ -e "$f" ] || continue
        sid="${f##*/}"; sid="${sid%.digest.pending}"
        case " $candidates " in *" $sid "*) ;; *) candidates="$candidates $sid" ;; esac
    done
    for sid in $candidates; do
        # $SECONDS ticks on whole seconds: ">= 2" is a budget of 1-2s, where
        # ">= 1" could end the scan a few milliseconds in.
        [ $(( SECONDS - since )) -ge 2 ] && break
        f="$MNEMON_SESSIONS_DIR/${sid}.json"
        marker="$MNEMON_SESSIONS_DIR/${sid}.digest.pending"
        [ -d "$MNEMON_SESSIONS_DIR/${sid}.digest.lock.d" ] && continue
        fields=$(jq -r '[(.nomemo // false), (.last_digest_turn // 0), (.digest_failures // 0), (.transcript_path // "")] | @tsv' "$f" 2>/dev/null) || continue
        IFS=$'\t' read -r nomemo ptr fails tp <<< "$fields"
        [ "$nomemo" = "true" ] && continue
        pend=$(jq -r '.end // 0' "$marker" 2>/dev/null); pend=${pend:-0}
        if [ "$tidy" = true ] && [ -e "$marker" ] && [ "$pend" -le "$ptr" ]; then
            rm -f "$marker"; pend=0
        fi
        [ -n "$tp" ] || tp=$(jq -r '.path // empty' "$marker" 2>/dev/null)
        if [ -z "$tp" ] || [ ! -r "$tp" ]; then
            if [ "$tidy" = true ] && { [ "$fails" -gt 0 ] || [ -e "$marker" ]; }; then
                mnemon_log_error "session=$sid has undigested turns but its transcript ${tp:-(unknown)} is gone; giving up on them"
                rm -f "$marker"
                mnemon_session_state_update "$sid" '.digest_failures = 0 | del(.digest_failed_at, .digest_retry_after, .digest_wait, .digest_failed_start)'
            fi
            continue
        fi
        if [ "$pend" -gt "$ptr" ] \
            || { [ "$fails" -gt 0 ] && [ "$(wc -l < "$tp" | tr -d ' ')" -gt "$ptr" ]; }; then
            n=$((n + 1))
        fi
    done
    printf '%s' "$n"
}

# Copy transcript lines (from, to] into a file, at most max_digest_bytes of
# them, oldest first. Args: <path> <from> <to> <out>. Echoes the last line
# copied; returns 1 when there is nothing to copy.
#
# The cap bounds the work per pass. It keeps the oldest lines, so what is left
# over is carried forward from the pointer rather than lost: the old cap kept
# the newest lines and the oldest were never digested. At least one line is
# always copied, so a single huge line still makes progress.
mnemon_slice_transcript() {
    local path="$1" from="$2" to="$3" out="$4" max_bytes got
    max_bytes=$(mnemon_config_value 'max_digest_bytes' 2097152)
    tail -n +"$((from + 1))" "$path" 2>/dev/null | head -n "$((to - from))" \
        | LC_ALL=C awk -v max="$max_bytes" '{ b += length($0) + 1; if (NR > 1 && b > max) exit; print }' > "$out"
    [ -s "$out" ] || return 1
    got=$(wc -l < "$out" | tr -d ' ')
    printf '%s' "$((from + got))"
}

# Clean a transcript slice into the payload a digest sends, oldest turns first.
# Args: <in.jsonl> <out> [<from>].
#
# With <from> (the line the slice starts after), the payload is the oldest
# chunk of the cleaned slice that fits digest_chunk_chars (60000, and never
# more than max_digest_chars), and the line its last turn ends on is echoed --
# the caller advances only that far and carries the rest. Chunk boundaries are
# a function of the file, the pointer and the constant alone, so a retry after
# any failure sends exactly the same range, and the server's replay check
# recognises it. "all" is echoed when the whole slice fit. Without <from> (an
# inline transcript, which cannot be resumed part-way) the whole slice is sent.
#
# Returns 0 with the payload in <out>, 1 when the slice holds no conversation,
# 2 when cleaning failed (logged). A cleaning failure used to be
# indistinguishable from "no conversation": the slice was dropped silently.
mnemon_digest_text() {
    local in="$1" out="$2" from="${3:-}" err="$2.err" cleaned rc max_chars chunk size result end
    cleaned=$(mnemon_clean_transcript --lines "$in" 2>"$err")
    rc=$?
    if [ "$rc" -ne 0 ]; then
        mnemon_log_error "transcript cleaning failed (jq exit $rc): $(head -c 160 "$err" 2>/dev/null | tr -d '\r\n')"
        rm -f "$err"
        return 2
    fi
    rm -f "$err"
    { [ -z "$cleaned" ] || [ "$cleaned" = "[]" ]; } && return 1

    max_chars=$(mnemon_config_value 'max_digest_chars' 190000)
    chunk=$(mnemon_config_value 'digest_chunk_chars' 60000)
    [ "$chunk" -gt "$max_chars" ] && chunk="$max_chars"
    [ -z "$from" ] && chunk="$max_chars"

    # Take turns while they fit; always at least one.
    result=$(printf '%s' "$cleaned" | jq -c --argjson max "$chunk" '
        reduce .[] as $t ({n: 0, size: 2, done: false, out: []};
            if .done then .
            elif (.n > 0) and (.size + ($t | del(.ln) | tojson | length) + 1 > $max) then .done = true
            else .out += [$t] | .n += 1 | .size += ($t | del(.ln) | tojson | length) + 1 end)
        | {end: (if .done then .out[-1].ln else "all" end), payload: (.out | map(del(.ln)))}')
    end=$(printf '%s' "$result" | jq -r '.end')
    printf '%s' "$result" | jq -c '.payload' > "$out"

    # The final guard: what the server accepts (`transcript` =>
    # `required|string|max:200000`). A rejected digest never advances, so an
    # oversized payload would fail identically for ever. One turn alone is
    # capped well below this, so it only triggers for an inline transcript or
    # a lowered max_digest_chars.
    size=$(wc -c < "$out" | tr -d ' ')
    if [ "$size" -gt "$max_chars" ]; then
        head -c "$max_chars" "$out" > "$out.cap" && mv -f "$out.cap" "$out"
        mnemon_log_error "digest payload of $size characters is over max_digest_chars=$max_chars; its end was cut"
    fi

    if [ -n "$from" ] && [ "$end" != "all" ]; then
        printf '%s' "$((from + end))"
    else
        printf 'all'
    fi
    return 0
}

# The directory a Claude Code scratchpad belongs to, or nothing for any other
# directory. Args: <dir>.
#
# A session can run in its scratchpad, <tmp>/claude-<uid>/<cwd-slug>/<session>/
# scratchpad, which is no repository, so it named no project and its drawers
# went to the wrong wing. The slug is the owning cwd with every character
# outside [A-Za-z0-9] turned into "-", which cannot be reversed by string
# alone: final-commit and final/commit slug the same. So the path is rebuilt by
# walking the filesystem from /, matching each entry's slug.
mnemon_scratchpad_owner() {
    [[ "$1" =~ /claude-[0-9]+/(-[A-Za-z0-9-]+)/[0-9a-f-]{36}/scratchpad(/|$) ]] || return 0
    _mnemon_unslug "" "${BASH_REMATCH[1]#-}" || true
}

# Args: <path so far> <slug left to match>. Backtracks on a dead end.
_mnemon_unslug() {
    local path="$1" rest="$2" entry name
    [ -n "$rest" ] || { printf '%s' "$path"; return 0; }
    while IFS= read -r entry; do
        [ -d "$path/$entry" ] || continue
        name="${entry//[^A-Za-z0-9]/-}"
        if [ "$rest" = "$name" ]; then
            printf '%s' "$path/$entry"
            return 0
        fi
        if [ "${rest#"$name"-}" != "$rest" ]; then
            _mnemon_unslug "$path/$entry" "${rest#"$name"-}" && return 0
        fi
    done < <(ls -A "${path:-/}" 2>/dev/null)
    return 1
}

# The repository a working directory belongs to: the origin remote's name, else
# the checkout's directory name. A scratchpad counts as the directory it belongs
# to. Echoes nothing outside a git repository. Args: <dir>.
mnemon_repo_name() {
    local dir="$1" top url owner
    owner=$(mnemon_scratchpad_owner "$dir")
    [ -n "$owner" ] && dir="$owner"
    [ -n "$dir" ] && [ -d "$dir" ] || return 0
    top=$(git -C "$dir" rev-parse --show-toplevel 2>/dev/null) || return 0
    [ -n "$top" ] || return 0
    url=$(git -C "$top" remote get-url origin 2>/dev/null)
    if [ -n "$url" ]; then
        url="${url%/}"
        url="${url%.git}"
        printf '%s' "${url##*[/:]}"
    else
        printf '%s' "${top##*/}"
    fi
}

# Whether a repository name is listed in config.json's `project_ignore`.
# Args: <name>.
mnemon_project_ignored() {
    [ -n "$1" ] || return 1
    jq -e --arg n "$1" '(.project_ignore // []) | index($n)' "$MNEMON_CONFIG" >/dev/null 2>&1
}

# Whether a repository name is listed in config.json's `capture_ignore`.
# Args: <name>.
#
# A repository whose sessions are mostly machine-driven -- an assistant's
# workspace answering a heartbeat every 20 minutes -- digests the same
# checklist into near-identical drawers each time. Listing it here stops
# capture for it outright; recall still runs.
mnemon_capture_ignored() {
    [ -n "$1" ] || return 1
    jq -e --arg n "$1" '(.capture_ignore // []) | index($n)' "$MNEMON_CONFIG" >/dev/null 2>&1
}

# The project a working directory belongs to, named for its repository.
# Echoes nothing outside a git repository, or for a name listed in
# config.json's `project_ignore`. Args: <dir>.
#
# The server files a session's project-specific drawers under this project's
# wing. Without it the digest model guessed the wing from the transcript, and
# most drawers in the busiest wings belonged to other projects. A repository
# whose sessions span many projects -- an assistant's workspace -- is not one
# project; list it in `project_ignore` and the model's choice stands for it.
mnemon_project_name() {
    local name
    name=$(mnemon_repo_name "$1")
    mnemon_project_ignored "$name" && return 0
    printf '%s' "$name"
}

# Reduce a Claude Code transcript to the conversation. Args: [<file>]; reads
# stdin without one. Input is JSONL -- one record per line -- and a line may
# also hold an array of records (the inline `.transcript` a Stop payload can
# carry). Echoes a compact JSON array of {"role","ts","text"}.
#
# A whitelist, not a blacklist. Most of a transcript is not conversation: the
# old filter dropped records whose .type was tool_use|tool_result|thinking --
# which no top-level record has -- and 93% of transcript bytes got through it:
# system-prompt and environment snapshots, CLAUDE.md, attached and edited
# files, toolUseResult, and hook output, including Mnemon's own recall and wake
# text, which the digest then filed back into the palace as new drawers.
#
# Kept:
#   - user and assistant records that are not meta, compact summaries,
#     sidechains, synthetic API errors or other `<synthetic>` replies; of
#     those, only the text of `text` blocks (string content counts as one);
#   - messages the user typed while the assistant was still working, which
#     Claude Code stores as a queued_command attachment (origin human, mode
#     prompt) and often never as a user record. One that is delivered again as
#     a user record is kept once.
# In user text, harness-injected wrappers are cut (<system-reminder>, command
# output, task notifications, a Mnemon block opening the text) and a slash
# command reads as "/name args". Assistant text is left as written: it may
# quote those tags. Secrets are redacted in both; empty turns are dropped and
# consecutive turns of one role merged -- an assistant reply split around tool
# calls reads as one reply. The result is still JSON, so the server's "Read a
# Claude Code transcript" prompt and its string-level sanitizer keep working.
#
# Long texts keep their start and their end -- the decision is usually at the
# end of a long reply -- with "[... N characters cut]" between. Two limits:
#   - before any regex, each text is cut to MNEMON_CLEAN_SCAN_MAX (32000):
#     jq's gsub is quadratic in the number of matches, and one hostile text of
#     50,000 closed tags took minutes, longer than the Stop hook may run;
#   - after the harness wrappers are cut, user text is held to
#     MNEMON_CLEAN_TEXT_MAX (16000), so a long injected block does not push
#     the user's own words out. Assistant text is only redacted and keeps the
#     scan limit (3 of 12,179 real assistant texts exceed it).
# A wrapper or a private key that a cut splits is removed from both sides.
#
# A queued message is de-duplicated by exact text within one slice. One
# delivered again in a later slice is kept twice.
#
# One jq process for the whole slice: a fork costs 20-60ms on Git Bash, so
# per-record work outside jq would scale with the transcript. A line that does
# not parse (the record being written as the hook runs) and a record of a shape
# the filter does not expect are skipped, never fatal: one bad record used to
# empty the whole slice.
#
# The redaction set is the union of the earlier client set and the server's
# ContentSanitizer, plus GitHub fine-grained and app tokens, Slack tokens and
# PEM private keys, with minimum lengths so a prefix mentioned in prose stays.
# The server still sanitizes what arrives.
MNEMON_CLEAN_SCAN_MAX="${MNEMON_CLEAN_SCAN_MAX:-32000}"
MNEMON_CLEAN_TEXT_MAX="${MNEMON_CLEAN_TEXT_MAX:-16000}"
mnemon_clean_transcript() {
    # --lines: each turn also carries "ln", the input line its text ends on
    # (for chunking, see mnemon_digest_text).
    local lines=false
    [ "${1:-}" = "--lines" ] && { lines=true; shift; }
    jq -nRc --argjson scan "$MNEMON_CLEAN_SCAN_MAX" --argjson usermax "$MNEMON_CLEAN_TEXT_MAX" --argjson lines "$lines" '
        def text_of:
            if type == "string" then .
            elif type == "array" then [.[] | objects | select(.type == "text") | .text | strings] | join("\n\n")
            else "" end;

        # A text as {h, t, len}: its start and end around a cut, t empty when
        # it fits. 60% of the budget goes to the start.
        def split_at($n):
            length as $l
            | if $l <= $n then {h: ., t: "", len: $l}
              else ($n * 0.6 | floor) as $hn
                   | {h: .[0:$hn], t: .[($l - ($n - $hn)):], len: $l} end;

        # Context the harness or a hook injected into a user turn, not typed.
        # $head: this is the start of the text. $cut: the text was cut here, so
        # a span may be open at the end of the start or closed at the beginning
        # of the end.
        def unwrap_harness($head; $cut):
            (if $cut and ($head | not)
             then sub("\\A(?:(?!<system-reminder>)[\\s\\S])*?</system-reminder>"; "") else . end)
            | gsub("<system-reminder>[\\s\\S]*?</system-reminder>"; "")
            | if $cut and $head then gsub("<system-reminder>[\\s\\S]*\\z"; "") else . end
            | gsub("<(command-message|local-command-stdout|local-command-stderr|local-command-caveat|bash-stdout|bash-stderr|task-notification|user-prompt-submit-hook)>[\\s\\S]*?</\\1>"; "")
            | gsub("<command-name>(?<n>[^<]*)</command-name>\\s*<command-args>(?<a>[\\s\\S]*?)</command-args>"; "\(.n) \(.a)")
            | gsub("<command-args>(?<a>[\\s\\S]*?)</command-args>\\s*<command-name>(?<n>[^<]*)</command-name>"; "\(.n) \(.a)")
            | gsub("</?(command-name|command-args|bash-input)>"; "")
            # A Mnemon recall/wake block that arrives without a wrapper opens
            # the text: its header and the indented lines under it.
            | if $head then sub("\\A\\s*Mnemon (recall|palace state):[^\\n]*(\\n {2}[^\\n]*)*"; "") else . end
            | gsub("\\[Request interrupted by user[^\\]]*\\]"; "");

        # A regex that fails (Oniguruma has a retry limit) keeps the text it
        # was given; it never empties it or drops the turn.
        def keep(f): . as $in | try f catch $in;

        def redact:
            # PEM private keys. With an END line, everything from BEGIN to it,
            # whatever lies between -- Proc-Type/DEK-Info headers of an
            # encrypted key, line breaks escaped as a literal \n. Non-greedy
            # and bounded, so a stray BEGIN cannot run away.
            keep(gsub("-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----[\\s\\S]{0,20000}?-----END [A-Z0-9 ]*PRIVATE KEY-----"; "[REDACTED-PRIVATE-KEY]"))
            # Without one (a key cut off), from BEGIN to the end of the text
            # when a body follows: a 20+ character base64 run, after any
            # header lines, with real or escaped (\n, \r\n) line breaks. A
            # header named in prose has no body and is left alone.
            | keep(sub("-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----(?:(?:\\s|\\\\[rn])*(?:Proc-Type|DEK-Info):[^\\r\\n\\\\]*)*(?:\\s|\\\\[rn])*[A-Za-z0-9+/=]{20,}[\\s\\S]*\\z"; "[REDACTED-PRIVATE-KEY]"))
            | keep(gsub("AKIA[0-9A-Z]{16}"; "[REDACTED-AWS-KEY]"))
            | keep(gsub("eyJ[A-Za-z0-9_-]{10,}\\.[A-Za-z0-9_-]{10,}\\.[A-Za-z0-9_-]{10,}"; "[REDACTED-JWT]"))
            | keep(gsub("Bearer\\s+[A-Za-z0-9._~+/=-]{20,}"; "Bearer [REDACTED]"))
            | keep(gsub("\\bsk-[A-Za-z0-9_-]{20,}"; "[REDACTED-OPENAI-KEY]"))
            | keep(gsub("\\bgithub_pat_[A-Za-z0-9_]{60,}"; "[REDACTED-GITHUB-TOKEN]"))
            | keep(gsub("\\bgh[pousr]_[A-Za-z0-9]{36,}"; "[REDACTED-GITHUB-TOKEN]"))
            | keep(gsub("\\bxox[abprs]-[A-Za-z0-9-]{10,}"; "[REDACTED-SLACK-TOKEN]"))
            | keep(gsub("\\bAVNS_[A-Za-z0-9]+"; "[REDACTED-API-KEY]"))
            # The name starts a word or follows a prefix ending in "_"
            # (DB_PASSWORD); NOSECRET= is not a secret.
            | keep(gsub("(?<![A-Za-z0-9])(?<p>(?:[A-Za-z0-9]+_)*)(?<k>PASSWORD|SECRET|API_SECRET|API_KEY|AUTH_TOKEN|PRIVATE_KEY)\\s*=\\s*[^=\\s]\\S*"; "\(.p)\(.k)=[REDACTED]"))
            # scheme://user:pass@ -- the scheme tells admin:123456@ (a
            # password) from a bare //host:8080@ or //TODO:fix@.
            | keep(gsub("\\b(?<s>[A-Za-z][A-Za-z0-9+.-]*)://[^\\s/@:]+:[^\\s/@]+@"; "\(.s)://[REDACTED-CREDENTIALS]@"));

        def turn:
            if .type == "attachment" then
                .timestamp as $ts
                | .attachment | objects
                | select(.type == "queued_command" and .commandMode == "prompt"
                         and ((.origin | objects | .kind) == "human") and (.isMeta != true))
                | {role: "user", ts: ($ts // .timestamp), raw: (.prompt | text_of), queued: true}
            else
                (.type // (.message | objects | .role) // .role) as $role
                | select($role == "user" or $role == "assistant")
                | select((.isMeta != true) and (.isCompactSummary != true)
                         and (.isSidechain != true) and (.isApiErrorMessage != true))
                | select((.message | objects | .model) != "<synthetic>")
                | {role: $role, ts: .timestamp,
                   raw: ((if (.message | type) == "object" then .message.content else .content end) | text_of)}
            end
            | (.role == "user") as $user
            | (.raw | split_at($scan)) as $p
            | ($p.t != "") as $cut
            | ($p.h | if $user then unwrap_harness(true; $cut) else . end | redact) as $h
            | ($p.t | if $user then unwrap_harness(false; true) else . end | redact
                    # The end of a key the cut split: body characters, header
                    # lines and (escaped) line breaks up to its END line.
                    | keep(sub("\\A[A-Za-z0-9+/=\\s\\\\:,-]{0,20000}?-----END [A-Z0-9 ]*PRIVATE KEY-----"; "[REDACTED-PRIVATE-KEY]"))) as $t
            | (if $user then $usermax else $scan end) as $max
            | ($max * 0.6 | floor) as $hn
            | (if $cut then {h: $h, t: $t}
               elif ($h | length) > $max then ($h | split_at($max) | {h, t})
               else {h: $h, t: ""} end) as $k
            | ($k.h | if $k.t != "" and length > $hn then .[0:$hn] else . end) as $kh
            | ($k.t | if length > ($max - $hn) then .[(length - ($max - $hn)):] else . end) as $kt
            | .text = (if ($kh + $kt | test("\\S") | not) then ""
                       elif ($cut | not) and $k.t == "" then $kh
                       else $kh + "\n[... \($p.len - ($kh | length) - ($kt | length)) characters cut]\n" + $kt end
                | gsub("\\n{3,}"; "\n\n") | sub("^\\s+"; "") | sub("\\s+$"; ""))
            | del(.raw)
            | .ts |= (if type == "string" then sub("\\.[0-9]+Z$"; "Z") else null end)
            | select(.text != "");

        [ inputs
          | input_line_number as $ln
          | fromjson?
          | if type == "array" then .[] else . end
          | objects
          | try turn catch empty
          | .ln = $ln
        ]
        # A queued message is often delivered again as the user turn itself.
        | ([.[] | select(.role == "user" and .queued != true) | .text]) as $delivered
        | map(select(.queued != true or (.text as $t | $delivered | index([$t]) | not)))
        | reduce .[] as $r ([];
            # Merged only up to the user cap, so no one turn outgrows a chunk.
            if length > 0 and .[-1].role == $r.role
               and ((.[-1].text | length) + ($r.text | length) <= $usermax)
            then .[-1].text += "\n\n" + $r.text | .[-1].ln = $r.ln
            else . + [$r | del(.queued)] end)
        | map(if .ts == null then del(.ts) else . end
              | if $lines then . else del(.ln) end)
    ' ${1:+"$1"}
}

# Append an error message to the capture-errors log.
mnemon_log_error() {
    printf '[%s] %s\n' "$(date -u +%FT%TZ)" "$*" >> "$MNEMON_ERROR_LOG"
}

# Format a recall payload as a system-reminder block.
mnemon_format_recall() {
    jq -r '
        if .found then
            "<system-reminder>\nMnemon recall:\n" +
            (if (.wiki | length) > 0 then
                "  Wiki: " + (.wiki | map("[\(.title)] — \(.content[:300])") | join("\n        ")) + "\n"
             else "" end) +
            (if (.drawers | length) > 0 then
                "  Drawers:\n" +
                (.drawers | to_entries | map("    \(.key+1)) \(.value.snippet) (\(.value.wing)/\(.value.room))") | join("\n")) + "\n"
             else "" end) +
            "</system-reminder>"
        else "" end
    '
}

# Format a palace_wake_up payload as a system-reminder block.
mnemon_format_wake() {
    jq -r '
        "<system-reminder>\nMnemon palace state:\n" +
        (if (.recent_drawers | length) > 0 then
            "  Recent drawers:\n" +
            (.recent_drawers[:5] | to_entries | map("    \(.key+1)) \(.value.content) (\(.value.wing_slug)/\(.value.room_slug))") | join("\n")) + "\n"
         else "" end) +
        (if (.active_wings | length) > 0 then
            "  Active wings: " + (.active_wings[:5] | map(.slug) | join(", ")) + "\n"
         else "" end) +
        (if (.pending_update_pages | length) > 0 then
            "  Wiki pages with pending updates: " + (.pending_update_pages[:3] | map(.name) | join(", ")) + "\n"
         else "" end) +
        "</system-reminder>"
    '
}
