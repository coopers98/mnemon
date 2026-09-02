#!/usr/bin/env bash
# Mnemon Claude Code hooks — shared helpers.
# Sourced by mnemon-wake.sh, mnemon-recall.sh, mnemon-capture.sh.

set -u

MNEMON_DIR="${MNEMON_DIR:-$HOME/.mnemon}"
MNEMON_CONFIG="$MNEMON_DIR/config.json"
MNEMON_SESSIONS_DIR="$MNEMON_DIR/sessions"
MNEMON_ERROR_LOG="$MNEMON_DIR/capture-errors.log"

mkdir -p "$MNEMON_SESSIONS_DIR" 2>/dev/null || true

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

    local err
    err=$(printf '%s' "$response" | jq -r '.error // empty' 2>/dev/null)
    if [ -n "$err" ]; then
        return 1
    fi

    printf '%s' "$response" | jq -c '.result // empty'
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

# Read or initialize session state. Args: <session_id>. Echoes JSON.
mnemon_session_state() {
    local sid="$1"
    local f="$MNEMON_SESSIONS_DIR/${sid}.json"
    if [ ! -f "$f" ]; then
        printf '{"last_digest_turn":0,"last_recall_at":0,"recent_drawer_ids":[],"nomemo":false,"disabled":false}' > "$f"
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
