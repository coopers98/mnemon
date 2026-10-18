#!/usr/bin/env bash
# Mnemon doctor: does this device's connection to its Mnemon instance work, and
# if not, why not.
#
# Usage: mnemon-doctor.sh       (or /mnemon:doctor inside Claude Code)
#
# Every check prints one line, "ok", "warn" or "FAIL", and a failure or warning
# is followed by the fix. Exit 0 iff nothing FAILed.
#
# Runs wherever the hooks run -- Linux, macOS, Git Bash on Windows -- so plain
# bash, curl, jq and coreutils only. Output is plain text with no colour: it is
# relayed by Claude and pasted into chats.
#
# It reads the config and never writes it. The one exception is the tool call in
# step 6, which goes through mnemon_call exactly as a hook would, so an expired
# token is renewed the way the hooks renew it -- the doctor exercises the real
# path rather than a lookalike.
set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../hooks/lib/common.sh
. "$SCRIPT_DIR/../hooks/lib/common.sh"

FAILS=0
WARNS=0

# Every secret this device holds, so no line can carry one -- not even a server
# error that happens to echo a token back.
SECRETS=()

#
# Only values of 8+ characters are replaced. Replacement is by substring, so a
# one-letter secret would shred every line it appeared in -- and one that short
# turning up inside ordinary text is a coincidence, not a leak.
redact() {
    local line="$1" s
    for s in "${SECRETS[@]+"${SECRETS[@]}"}"; do
        [ "${#s}" -ge 8 ] && line="${line//"$s"/[redacted]}"
    done
    printf '%s\n' "$line"
}

ok()   { redact "ok    $1"; }
warn() { WARNS=$((WARNS + 1)); redact "warn  $1"; [ -n "${2:-}" ] && redact "      fix: $2"; return 0; }
fail() { FAILS=$((FAILS + 1)); redact "FAIL  $1"; [ -n "${2:-}" ] && redact "      fix: $2"; return 0; }
note() { redact "      $1"; }

finish() {
    if [ "$FAILS" -eq 0 ]; then
        echo "All checks passed."
        exit 0
    fi
    echo "$FAILS problem(s) found."
    exit 1
}

os=$(uname -s 2>/dev/null)
case "$os" in
    MINGW*|MSYS*|CYGWIN*) windows=1 ;;
    *) windows=0 ;;
esac

enrol_cmd() {  # Args: [instance-url] [client-id]
    printf 'bash %s/scripts/mnemon-authorize.sh %s%s' \
        "$MNEMON_PLUGIN_ROOT" "${1:-https://<your-instance>}" "${2:+ $2}"
}

echo "Mnemon doctor"

# --- 1. Tools -----------------------------------------------------------------
missing=""
command -v curl >/dev/null 2>&1 || missing="$missing curl"
command -v jq >/dev/null 2>&1 || missing="$missing jq"
if [ -n "$missing" ]; then
    case "$os" in
        MINGW*|MSYS*|CYGWIN*)
            if [ "$missing" = " curl" ]; then hint="curl ships with Git for Windows; reinstall Git for Windows"
            else hint="winget install jqlang.jq, then reopen Git Bash/Claude Code"; fi ;;
        Darwin) hint="brew install${missing}" ;;
        *) hint="install${missing} with your package manager (e.g. sudo apt install${missing})" ;;
    esac
    fail "tools: bash ${BASH_VERSION%%(*}, missing:${missing}" "$hint"
    finish
fi
ok "tools: bash ${BASH_VERSION%%(*}, curl, jq $(jq --version 2>/dev/null | sed 's/^jq-//')"

# --- 2. Plugin ----------------------------------------------------------------
local_version=$(mnemon_plugin_version)
if [ -n "$local_version" ]; then
    ok "plugin: version $local_version"
else
    warn "plugin: no plugin manifest found next to this script (hooks-only install?)" \
        "claude plugin install mnemon@mnemon"
fi
if mnemon_disabled; then
    warn "plugin: MNEMON_DISABLE is set -- memory is off on purpose in this environment" \
        "unset MNEMON_DISABLE if this session should remember"
fi
# The doctor is not a hook, so CLAUDE_PLUGIN_ROOT may be unset; when this copy
# is a plugin install, its own root stands in.
if [ -n "$local_version" ] && CLAUDE_PLUGIN_ROOT="${CLAUDE_PLUGIN_ROOT:-$MNEMON_PLUGIN_ROOT}" mnemon_double_registration; then
    warn "plugin: hooks are registered twice (plugin and $MNEMON_CLAUDE_SETTINGS); every prompt costs two round trips" \
        "remove the mnemon-* hook entries from $MNEMON_CLAUDE_SETTINGS"
fi

# --- 3. Config ----------------------------------------------------------------
config_ok=0
if [ -f "$MNEMON_CONFIG" ]; then
    if jq -e . "$MNEMON_CONFIG" >/dev/null 2>&1; then
        config_ok=1
        SECRETS+=("$(jq -r '.bearer_token // empty' "$MNEMON_CONFIG")")
        SECRETS+=("$(jq -r '.refresh_token // empty' "$MNEMON_CONFIG")")
        SECRETS+=("$(jq -r '.client_secret // empty' "$MNEMON_CONFIG")")
    else
        fail "config: $MNEMON_CONFIG is not valid JSON" \
            "restore a backup ($MNEMON_DIR/config.backup-*.json) or re-enrol: $(enrol_cmd)"
    fi
fi
SECRETS+=("${MNEMON_TOKEN:-}" "${CLAUDE_PLUGIN_OPTION_MNEMON_TOKEN:-}")
if [ -f "$HOME/.claude.json" ]; then
    SECRETS+=("$(jq -r '.mcpServers.mnemon.headers.Authorization // empty' "$HOME/.claude.json" 2>/dev/null | sed 's/^Bearer //')")
fi

env_endpoint="${MNEMON_ENDPOINT:-${CLAUDE_PLUGIN_OPTION_MNEMON_ENDPOINT:-}}"
env_token="${MNEMON_TOKEN:-${CLAUDE_PLUGIN_OPTION_MNEMON_TOKEN:-}}"

if ! pair=$(mnemon_token); then
    if [ "$config_ok" = 1 ] && [ -n "$(mnemon_config_value endpoint '')" ]; then
        fail "config: $MNEMON_CONFIG has an endpoint but no access token" \
            "re-enrol: $(enrol_cmd "$(mnemon_instance_url)")"
    elif [ "$config_ok" = 1 ] || [ ! -f "$MNEMON_CONFIG" ]; then
        fail "config: not connected -- no endpoint and token in $MNEMON_CONFIG or the environment" \
            "ask the Mnemon owner to run \"php artisan mnemon:device-client <name>\", then: $(enrol_cmd)"
    fi
    finish
fi
endpoint="${pair%|*}"
token="${pair#*|}"
SECRETS+=("$token")

if [ -n "$env_endpoint" ] && [ -n "$env_token" ]; then
    source_desc="environment (MNEMON_ENDPOINT/MNEMON_TOKEN)"
elif [ "$config_ok" = 1 ] && [ -n "$(mnemon_config_value bearer_token '')" ]; then
    source_desc="$MNEMON_CONFIG"
else
    source_desc="$HOME/.claude.json (mcpServers.mnemon)"
fi

renewable=0
if mnemon_refresh_available; then
    renewable=1
    ok "config: endpoint $endpoint, credentials from $source_desc; can self-renew"
else
    ok "config: endpoint $endpoint, credentials from $source_desc; cannot self-renew (static token)"
fi

dead=0
if [ "$config_ok" = 1 ] && [ "$(mnemon_config_value auth_state ok)" = "dead" ] \
    && [ "$source_desc" = "$MNEMON_CONFIG" ]; then
    dead=1
    fail "auth: this device's authorization is dead ($(mnemon_config_value auth_dead_reason unknown)) -- memory is off" \
        "re-enrol: $(enrol_cmd "$(mnemon_instance_url)" "$(mnemon_config_value client_id '')")"
fi

# Mode bits mean nothing on Windows; there every file reads as 644.
if [ "$config_ok" = 1 ] && [ "$windows" = 0 ]; then
    mode=$(stat -c '%a' "$MNEMON_CONFIG" 2>/dev/null || stat -f '%Lp' "$MNEMON_CONFIG" 2>/dev/null)
    if [ -n "$mode" ] && [ "$mode" != "600" ] && [ "$mode" != "400" ]; then
        warn "config: $MNEMON_CONFIG has permissions $mode; it holds credentials and should be 600" \
            "chmod 600 $MNEMON_CONFIG"
    fi
fi

# --- 4. Token -----------------------------------------------------------------
if secs=$(mnemon_token_seconds_left "$token"); then
    if [ "$secs" -gt 0 ]; then
        if [ "$secs" -ge 172800 ]; then left="$((secs / 86400)) days"
        elif [ "$secs" -ge 7200 ]; then left="$((secs / 3600)) hours"
        else left="$((secs / 60)) minute(s)"; fi
        warn_days=$(mnemon_config_value token_warn_days 14)
        if [ "$renewable" = 0 ] && [ "$secs" -lt $((warn_days * 86400)) ]; then
            warn "token: access token expires in $left and cannot renew itself" \
                "mint a replacement before then, or enrol with the device flow: $(enrol_cmd "${endpoint%/mcp}")"
        else
            ok "token: access token valid for $left"
        fi
    elif [ "$renewable" = 1 ]; then
        ok "token: access token expired $(( -secs / 60 )) minute(s) ago; will renew on the next call"
    elif [ "$dead" = 0 ]; then
        fail "token: access token expired $(( -secs / 60 )) minute(s) ago and cannot renew itself" \
            "enrol with the device flow: $(enrol_cmd "${endpoint%/mcp}")"
    fi
else
    ok "token: not a JWT, so its expiry cannot be read here; the tool call below is the real test"
fi

# --- 5. Reachability ----------------------------------------------------------
base="${endpoint%/}"
base="${base%/mcp}"
reachable=0
http=$(curl -s -o /dev/null -m 10 -w '%{http_code}' "$base/" 2>/dev/null)
rc=$?
if [ "$rc" -ne 0 ]; then
    case "$rc" in
        6)  why="DNS lookup failed (could not resolve the host)"
            fix="check the hostname in the endpoint and this machine's DNS / network" ;;
        7)  why="connection refused or no route to the host"
            fix="check the instance is up and the endpoint's host and port are right" ;;
        28) why="timed out after 10s"
            fix="check the network, VPN or firewall between this machine and the instance" ;;
        35|51|53|54|58|59|60|64|66|77|80|82|83|90|91)
            why="TLS failure (curl exit $rc: certificate or handshake problem)"
            fix="check the instance's certificate; a corporate proxy may be intercepting TLS" ;;
        *)  why="curl exit $rc"
            fix="run: curl -v $base/" ;;
    esac
    fail "reach: cannot reach $base -- $why" "$fix"
else
    reachable=1
    if [ "${http:-0}" -ge 500 ] 2>/dev/null; then
        warn "reach: $base answered HTTP $http (server error)" "check the instance's logs"
    else
        ok "reach: $base answered HTTP $http"
    fi
fi

# --- 6. A real tool call ------------------------------------------------------
# Snapshot the error log so step 7 reports what happened before this run, and
# this step can find exactly the line its own call wrote.
log_lines_before=0
if [ -f "$MNEMON_ERROR_LOG" ]; then
    log_lines_before=$(wc -l < "$MNEMON_ERROR_LOG" | tr -d ' ')
fi

if [ "$reachable" = 0 ]; then
    note "(tool call skipped: the instance is unreachable)"
elif [ "$dead" = 1 ]; then
    note "(tool call skipped: the authorization is dead)"
else
    result=$(mnemon_call "tools/call" \
        "$(jq -n --arg v "$local_version" \
            'if $v == "" then {name:"palace_wake_up",arguments:{}}
             else {name:"palace_wake_up",arguments:{client_version:$v}} end')" \
        10000 "$endpoint" "$token")
    call_rc=$?
    if [ "$call_rc" -eq 0 ] && [ -n "$result" ]; then
        wings=$(printf '%s' "$result" | jq -r '[.structuredContent.active_wings[]?.slug] | join(", ")' 2>/dev/null)
        ok "tool call: palace_wake_up succeeded; wings this device can reach: ${wings:-none with content yet}"
        server_version=$(printf '%s' "$result" | jq -r '.structuredContent.plugin_version // empty' 2>/dev/null)
        if [ -n "$server_version" ] && [ -n "$local_version" ] && [ "$server_version" != "$local_version" ]; then
            warn "plugin: this device runs $local_version, the server ships $server_version" \
                "claude plugin update mnemon"
        fi
    else
        last=$(tail -n +"$((log_lines_before + 1))" "$MNEMON_ERROR_LOG" 2>/dev/null | tail -1 | sed 's/^\[[^]]*\] //')
        case "$last" in
            *"HTTP 401"*)
                fail "tool call: authentication failed (HTTP 401) -- the server rejected this device's token" \
                    "re-enrol: $(enrol_cmd "$base")" ;;
            *"HTTP 403"*)
                fail "tool call: access denied (HTTP 403) -- the token lacks the mcp:use scope or wing access" \
                    "re-enrol and grant access on the consent screen: $(enrol_cmd "$base")" ;;
            *"tool error"*)
                fail "tool call: the server answered with an error: $last" \
                    "check this device's wing restrictions in the Mnemon admin" ;;
            "")
                fail "tool call: palace_wake_up got no answer within 10s (network error or timeout)" \
                    "retry; if it persists, check the instance's load and logs" ;;
            *)
                fail "tool call: palace_wake_up failed: $last" "check the instance's logs" ;;
        esac
    fi
fi

# --- 7. Recent errors ---------------------------------------------------------
now=$(date +%s)
cutoff=$(date -u -d "@$((now - 86400))" +%FT%TZ 2>/dev/null || date -u -r "$((now - 86400))" +%FT%TZ 2>/dev/null)
recent=""
if [ -s "$MNEMON_ERROR_LOG" ] && [ -n "$cutoff" ]; then
    # ISO-8601 UTC timestamps sort as strings. Routine refresh notices are
    # logged here too but are not errors.
    recent=$(head -n "$log_lines_before" "$MNEMON_ERROR_LOG" 2>/dev/null \
        | awk -v c="$cutoff" 'substr($0, 2, 20) >= c' \
        | grep -v 'refreshed access token' | tail -3)
fi
if [ -z "$recent" ]; then
    ok "recent errors: none in the last 24h"
else
    warn "recent errors: from $MNEMON_ERROR_LOG in the last 24h (latest 3):"
    while IFS= read -r line; do note "$line"; done <<< "$recent"
fi

finish
