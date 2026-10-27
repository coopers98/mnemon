#!/usr/bin/env bash
# Mnemon doctor: does this device's connection to its Mnemon instance work, and
# if not, why not.
#
# Usage: mnemon-doctor.sh [--exit-zero]     (or /mnemon:doctor in Claude Code)
#
# --exit-zero is for the slash command: same report, stderr folded into stdout,
# always exit 0. A failing `!` injection could swallow the report exactly when
# it matters, and "|| true" would make it a compound command that allowed-tools
# may not cover. The verdict is still the last line.
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

exit_zero=0
if [ "${1:-}" = "--exit-zero" ]; then
    exit_zero=1
    exec 2>&1
fi

FAILS=0
WARNS=0

# Every secret this device holds, so no line can carry one -- not even a server
# error that happens to echo a token back.
SECRETS=()

#
# Only values of 8+ characters are replaced. Replacement is by substring, so a
# one-letter secret would shred every line it appeared in -- and one that short
# turning up inside ordinary text is a coincidence, not a leak.
#
# URLs lose their userinfo (user:password@) and query string, either of which
# can carry a credential.
redact() {
    local line="$1" s
    for s in "${SECRETS[@]+"${SECRETS[@]}"}"; do
        [ "${#s}" -ge 8 ] && line="${line//"$s"/[redacted]}"
    done
    case "$line" in
        *://*) line=$(printf '%s\n' "$line" | sed -E \
            -e 's#([A-Za-z][A-Za-z0-9+.-]*://)[^/@[:space:]"]*@#\1#g' \
            -e 's#([A-Za-z][A-Za-z0-9+.-]*://[^[:space:]"?]*)[?][^[:space:]"]*#\1#g') ;;
    esac
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
    [ "$exit_zero" = 1 ] && exit 0
    exit 1
}

os=$(uname -s 2>/dev/null)
case "$os" in
    MINGW*|MSYS*|CYGWIN*) windows=1 ;;
    *) windows=0 ;;
esac

# Paths are quoted: a Windows profile is often C:\Users\First Last.
enrol_cmd() {  # Args: [instance-url] [client-id]
    printf 'bash "%s/scripts/mnemon-authorize.sh" %s%s' \
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

# Plugin auto-update. Off by default for third-party marketplaces, so a device
# otherwise stays on the version it installed. Read with Claude Code's own
# precedence: the extraKnownMarketplaces entry in settings.json, then what the
# /plugin UI writes to known_marketplaces.json, then off. Read only, and user
# settings only: a project's .claude/settings(.local).json can override them, and
# the doctor does not know which project it is being asked about.
# CRs are stripped: a Windows jq ends its lines with CRLF.
if [ -n "$local_version" ]; then
    claude_dir="${CLAUDE_CONFIG_DIR:-$HOME/.claude}"
    known="${MNEMON_KNOWN_MARKETPLACES:-$claude_dir/plugins/known_marketplaces.json}"
    # The marketplace is the cache directory the plugin was installed into:
    # .../plugins/cache/<marketplace>/mnemon/<version>.
    mp_name="mnemon"
    case "$MNEMON_PLUGIN_ROOT" in
        */plugins/cache/*/mnemon/*)
            mp_name="${MNEMON_PLUGIN_ROOT%/mnemon/*}"
            mp_name="${mp_name##*/}" ;;
    esac
    au_jq='(.extraKnownMarketplaces // {})[$n] // {} | if type == "object" and has("autoUpdate") then (.autoUpdate | tostring) else empty end'
    au_val=$(jq -r --arg n "$mp_name" "$au_jq" "$MNEMON_CLAUDE_SETTINGS" 2>/dev/null | tr -d '\r')
    au_src="$MNEMON_CLAUDE_SETTINGS"
    if [ -z "$au_val" ]; then
        au_val=$(jq -r --arg n "$mp_name" '.[$n] // {} | if type == "object" and has("autoUpdate") then (.autoUpdate | tostring) else empty end' "$known" 2>/dev/null | tr -d '\r')
        au_src="$known"
    fi
    au_off_by=""
    for v in DISABLE_UPDATES DISABLE_AUTOUPDATER CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC; do
        case "${!v:-}" in ''|0|false) ;; *) au_off_by="${au_off_by:+$au_off_by, }$v=${!v}" ;; esac
    done
    au_forced=0
    case "${FORCE_AUTOUPDATE_PLUGINS:-}" in ''|0|false) ;; *) au_forced=1 ;; esac
    au_manual="new releases need \"claude plugin update mnemon@$mp_name\""
    if [ "$au_val" != "true" ]; then
        warn "plugin auto-update: off in user settings -- $au_manual" \
            "in Claude Code: /plugin > Marketplaces > $mp_name > Enable auto-update, or re-run the setup script: curl -fsSL $(mnemon_instance_url)/install | bash"
    elif [ -n "$au_off_by" ] && [ "$au_forced" = 0 ]; then
        warn "plugin auto-update: on in $au_src, but disabled in this environment by $au_off_by -- $au_manual" \
            "unset $(printf '%s' "$au_off_by" | sed 's/=[^,]*//g'), or set FORCE_AUTOUPDATE_PLUGINS=1 to keep plugin updates"
    elif [ -n "$au_off_by" ]; then
        ok "plugin auto-update: on ($au_src; FORCE_AUTOUPDATE_PLUGINS overrides $au_off_by; user settings only)"
    else
        ok "plugin auto-update: on ($au_src; user settings only, project or local settings can override)"
    fi
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
elif [ "$config_ok" = 1 ] && [ -n "$(mnemon_config_value bearer_token '')" ] \
    && [ -n "$(mnemon_config_value endpoint '')" ]; then
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

# The client id goes into every re-enrol hint, so the command is complete.
client_id=""
[ "$source_desc" = "$MNEMON_CONFIG" ] && client_id=$(mnemon_config_value client_id '')

dead=0
if [ "$config_ok" = 1 ] && [ "$(mnemon_config_value auth_state ok)" = "dead" ] \
    && [ "$source_desc" = "$MNEMON_CONFIG" ]; then
    dead=1
    fail "auth: this device's authorization is dead ($(mnemon_config_value auth_dead_reason unknown)) -- memory is off" \
        "re-enrol: $(enrol_cmd "$(mnemon_instance_url)" "$client_id")"
fi

# Mode bits mean nothing on Windows; there every file reads as 644.
if [ "$config_ok" = 1 ] && [ "$windows" = 0 ]; then
    mode=$(stat -c '%a' "$MNEMON_CONFIG" 2>/dev/null || stat -f '%Lp' "$MNEMON_CONFIG" 2>/dev/null)
    if [ -n "$mode" ] && [ "$mode" != "600" ] && [ "$mode" != "400" ]; then
        warn "config: $MNEMON_CONFIG has permissions $mode; it holds credentials and should be 600" \
            "chmod 600 \"$MNEMON_CONFIG\""
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
# Measured here, printed after the tool call: a failed GET / with a working MCP
# endpoint is a warning, not a failure, and only the call can tell which.
ep="${endpoint%%\?*}"
base="${ep%/}"
base="${base%/mcp}"
http=$(curl -s -o /dev/null -m 10 -w '%{http_code}' "$base/" 2>/dev/null)
reach_rc=$?
if [ "$reach_rc" -ne 0 ]; then
    case "$reach_rc" in
        5)  why="could not resolve the proxy (curl exit 5)"
            fix="check the https_proxy / http_proxy settings in this environment" ;;
        6)  why="DNS lookup failed (could not resolve the host)"
            fix="check the hostname in the endpoint and this machine's DNS / network" ;;
        7)  why="connection refused or no route to the host"
            fix="check the instance is up and the endpoint's host and port are right" ;;
        28) why="timed out after 10s"
            fix="check the network, VPN or firewall between this machine and the instance" ;;
        52) why="the server closed the connection without answering (curl exit 52)"
            fix="check the instance's web server and any proxy in front of it" ;;
        56) why="the connection broke while receiving (curl exit 56)"
            fix="check the network, and any proxy or firewall that may be cutting connections" ;;
        35|51|53|54|58|59|60|64|66|77|80|82|83|90|91)
            why="TLS failure (curl exit $reach_rc: certificate or handshake problem)"
            fix="check the instance's certificate; a corporate proxy may be intercepting TLS" ;;
        *)  why="curl exit $reach_rc"
            fix="run: curl -v \"$base/\"" ;;
    esac
fi

# --- 6. A real tool call ------------------------------------------------------
# Snapshot the error log so step 7 reports what happened before this run, and
# this step reads exactly the lines its own call wrote.
log_lines_before=0
if [ -f "$MNEMON_ERROR_LOG" ]; then
    log_lines_before=$(wc -l < "$MNEMON_ERROR_LOG" | tr -d ' ')
fi

call_ok=0
call_msg=""
call_fix=""
if [ "$dead" = 0 ]; then
    result=$(mnemon_call "tools/call" \
        "$(jq -n --arg v "$local_version" \
            'if $v == "" then {name:"palace_wake_up",arguments:{}}
             else {name:"palace_wake_up",arguments:{client_version:$v}} end')" \
        10000 "$endpoint" "$token")
    call_rc=$?
    # A refresh during the call may have put new tokens in the config.
    if [ "$config_ok" = 1 ]; then
        SECRETS+=("$(jq -r '.bearer_token // empty' "$MNEMON_CONFIG" 2>/dev/null)")
        SECRETS+=("$(jq -r '.refresh_token // empty' "$MNEMON_CONFIG" 2>/dev/null)")
    fi
    if [ "$call_rc" -eq 0 ] && [ -n "$result" ]; then
        call_ok=1
    else
        # Every line this run wrote, not just the last: a 401 whose refresh
        # failed logs the refresh failure first and the 401 after it, and the
        # two need opposite fixes.
        mine=$(tail -n +"$((log_lines_before + 1))" "$MNEMON_ERROR_LOG" 2>/dev/null | sed 's/^\[[^]]*\] //')
        last=$(printf '%s\n' "$mine" | grep -v '^$' | tail -1)
        rejected=$(printf '%s\n' "$mine" | sed -n 's/.*refresh rejected (\([^)]*\)).*/\1/p' | tail -1)
        if [ -n "$rejected" ]; then
            call_msg="authorization is now dead ($rejected): the token expired and the server refused to renew it"
            call_fix="re-enrol: $(enrol_cmd "$base" "$client_id")"
        elif printf '%s\n' "$mine" | grep -qE 'refresh failed|refresh lock busy'; then
            call_msg="token expired and renewal failed transiently (credentials kept): $(printf '%s\n' "$mine" | grep -E 'refresh failed|refresh lock busy' | tail -1)"
            call_fix="retry in a minute; check $(mnemon_config_value token_endpoint "$base/oauth/token") is reachable"
        else
            case "$last" in
                *"HTTP 401"*)
                    call_msg="authentication failed (HTTP 401) -- the server rejected this device's token"
                    call_fix="re-enrol: $(enrol_cmd "$base" "$client_id")" ;;
                *"HTTP 403"*)
                    call_msg="access denied (HTTP 403) -- the token lacks the mcp:use scope or wing access"
                    call_fix="re-enrol and grant access on the consent screen: $(enrol_cmd "$base" "$client_id")" ;;
                *"tool error"*)
                    call_msg="the server answered with an error: $last"
                    call_fix="check this device's wing restrictions in the Mnemon admin" ;;
                "")
                    call_msg="palace_wake_up got no answer (network error, or no reply within 10s)"
                    call_fix="retry; if it persists, check the instance's load and logs" ;;
                *)
                    call_msg="palace_wake_up failed: $last"
                    call_fix="check the instance's logs" ;;
            esac
        fi
    fi
fi

if [ "$reach_rc" -ne 0 ]; then
    if [ "$call_ok" = 1 ]; then
        warn "reach: GET $base/ failed -- $why -- but the MCP endpoint answered" "$fix"
    else
        fail "reach: cannot reach $base -- $why" "$fix"
    fi
elif [ "${http:-0}" -ge 500 ] 2>/dev/null; then
    warn "reach: $base answered HTTP $http (server error)" "check the instance's logs"
else
    ok "reach: $base answered HTTP $http"
fi

if [ "$dead" = 1 ]; then
    note "(tool call skipped: the authorization is dead)"
elif [ "$call_ok" = 1 ]; then
    wings=$(printf '%s' "$result" | jq -r '[.structuredContent.active_wings[]?.slug] | join(", ")' 2>/dev/null)
    ok "tool call: palace_wake_up succeeded; wings this device can reach: ${wings:-none with content yet}"
    server_version=$(printf '%s' "$result" | jq -r '.structuredContent.plugin_version // empty' 2>/dev/null)
    if [ -n "$server_version" ] && [ -n "$local_version" ] && [ "$server_version" != "$local_version" ]; then
        warn "plugin: this device runs $local_version, the server ships $server_version" \
            "claude plugin update mnemon"
    fi
elif [ "$reach_rc" -ne 0 ]; then
    # Not counted again: it follows from the reachability failure above.
    note "(tool call also failed, as expected with the instance unreachable: $call_msg)"
else
    fail "tool call: $call_msg" "$call_fix"
fi

# --- 7. Recent errors ---------------------------------------------------------
now=$(date +%s)
cutoff=$(date -u -d "@$((now - 86400))" +%FT%TZ 2>/dev/null || date -u -r "$((now - 86400))" +%FT%TZ 2>/dev/null)
recent=""
if [ -s "$MNEMON_ERROR_LOG" ] && [ -n "$cutoff" ]; then
    # ISO-8601 UTC timestamps sort as strings. A line without one (stray
    # stderr) has no date, so it is never recent. Routine refresh notices are
    # logged here too but are not errors.
    recent=$(head -n "$log_lines_before" "$MNEMON_ERROR_LOG" 2>/dev/null \
        | awk -v c="$cutoff" '/^\[[0-9][0-9][0-9][0-9]-/ && substr($0, 2, 20) >= c' \
        | grep -v 'refreshed access token' | tail -3)
fi
if [ -z "$recent" ]; then
    ok "recent errors: none in the last 24h"
else
    warn "recent errors: from $MNEMON_ERROR_LOG in the last 24h (latest 3):"
    while IFS= read -r line; do note "$line"; done <<< "$recent"
fi
# Sessions with undigested turns -- after failures, or left pending when a
# run stopped after its ten chunks. Each is sent by its own next Stop (for one
# that has ended, when it is resumed).
undigested=$(mnemon_undigested_sessions)
undigested_n="${undigested%+}"
if [ "${undigested_n:-0}" -gt 0 ] 2>/dev/null; then
    warn "recent errors: $([ "$undigested" != "$undigested_n" ] && printf 'at least ')$undigested_n session(s) have undigested turns; each is sent the next time that session runs" \
        "see $MNEMON_ERROR_LOG for any digests that failed"
fi
# Old sessions a backfill started and did not finish: its to resume, not live
# capture's, so they are reported apart from the undigested ones above.
bf_unfinished=$(mnemon_backfill_unfinished)
if [ "${bf_unfinished:-0}" -gt 0 ] 2>/dev/null; then
    warn "backfill: $bf_unfinished old session(s) partly imported" \
        "run mnemon-backfill to continue"
fi

finish
