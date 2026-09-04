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
# point at a fixture instead of the real one.
MNEMON_CLAUDE_SETTINGS="${MNEMON_CLAUDE_SETTINGS:-$HOME/.claude/settings.json}"

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
    # has no flock). Deliberately not the digest worker's check-then-write PID
    # file, which has a TOCTOU race. Stale locks are recovered by liveness check;
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
    raw=$(curl -s -m "$timeout_s" -w '\n%{http_code}' -X POST "$endpoint" \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        -H "Accept: application/json, text/event-stream" \
        --data-binary @"$body" 2>/dev/null) || { rm -f "$body"; return 1; }
    rm -f "$body"

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
        return 1
    fi

    # A proxy rejecting the request answers with an HTML page, not JSON. Piping
    # that to jq yields "Invalid numeric literal", which says nothing about what
    # went wrong -- so say it here instead.
    if ! printf '%s' "$response" | jq -e . >/dev/null 2>&1; then
        mnemon_log_error "non-JSON response (HTTP $status) from $endpoint: $(printf '%s' "$response" | head -c 80 | tr -d '\r\n')"
        return 1
    fi

    # A JSON-RPC protocol error.
    local err
    err=$(printf '%s' "$response" | jq -r '.error.message // .error // empty' 2>/dev/null)
    if [ -n "$err" ]; then
        mnemon_log_error "rpc error from $endpoint: $(printf '%s' "$err" | head -c 160)"
        return 1
    fi

    # A tool-level error. These arrive as HTTP 200 with isError on the *result*,
    # not as a JSON-RPC error -- a wing denial is Response::error(), which is
    # exactly this shape. Checking only .error made an authorisation failure
    # indistinguishable from an empty palace, on every hook, logged nowhere.
    if [ "$(printf '%s' "$response" | jq -r '.result.isError // false' 2>/dev/null)" = "true" ]; then
        mnemon_log_error "tool error from $endpoint: $(printf '%s' "$response" \
            | jq -r '[.result.content[]?.text] | join(" ") // "(no message)"' 2>/dev/null | head -c 160)"
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
    mtime=$(stat -c %Y "$claim" 2>/dev/null || stat -f %m "$claim" 2>/dev/null || echo 0)
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
mnemon_session_state_write() {
    local sid="$1"
    local json="$2"
    local f="$MNEMON_SESSIONS_DIR/${sid}.json"
    local tmp="${f}.tmp.$$"
    printf '%s' "$json" > "$tmp" && mv -f "$tmp" "$f"
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
