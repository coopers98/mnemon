#!/usr/bin/env bash
# Mnemon SessionStart hook: call palace_wake_up and inject a <system-reminder>.

set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "$SCRIPT_DIR/lib/common.sh"

input=$(cat 2>/dev/null || echo '{}')
session_id=$(printf '%s' "$input" | jq -r '.session_id // empty')

if [ -z "$session_id" ]; then
    exit 0
fi

pair=$(mnemon_token) || {
    # The device flow is the way in now: the hooks can refresh, so an enrolled
    # device stays connected without anyone minting a long-lived token by hand.
    printf 'Mnemon: not connected. Ask the Mnemon owner to run "php artisan mnemon:device-client <name>", then enrol this machine:\n  bash %s/scripts/mnemon-authorize.sh https://<your-instance>\nAgents that cannot refresh can instead put a personal access token in ~/.mnemon/config.json (chmod 600).\n' \
        "$(dirname "$SCRIPT_DIR")"
    exit 0
}

# A dead credential is the one state that must be impossible to miss. Everything
# else in these hooks degrades quietly by design; this one cannot, because no
# amount of waiting fixes it and the machine has no browser to notice with.
if [ "$(mnemon_config_value 'auth_state' 'ok')" = "dead" ]; then
    printf 'Mnemon: this device'"'"'s authorization is dead (%s) — memory is off.\nRe-enrol: bash %s/scripts/mnemon-authorize.sh %s\n' \
        "$(mnemon_config_value 'auth_dead_reason' 'unknown')" \
        "$(dirname "$SCRIPT_DIR")" \
        "$(mnemon_instance_url)"
    exit 0
fi

# Ensure state exists, but never rewind it. SessionStart also fires on resume
# and after compaction, so resetting last_digest_turn to 0 here would make the
# next Stop re-digest the whole transcript: re-paying the reader for content
# already stored, and duplicating drawers. A genuinely new session has a new
# session_id and so gets fresh defaults anyway.
mnemon_session_state "$session_id" > /dev/null

if mnemon_refresh_available; then
    # Session start is the one place a ~1s refresh is invisible, so spend it
    # here rather than inside the tight per-prompt recall budget. Ten minutes
    # of margin covers a long session without refreshing on every start.
    if secs=$(mnemon_token_seconds_left "${pair#*|}") && [ "$secs" -lt 600 ]; then
        if fresh=$(mnemon_refresh "${pair#*|}"); then
            pair="${pair%|*}|$fresh"
        fi
    fi
else
    # Warn before the token expires rather than after. Expiry is otherwise
    # silent: the hooks simply stop returning anything, and the only trace is a
    # line in capture-errors.log that nothing surfaces.
    #
    # Only for credentials that cannot renew themselves. A device-flow access
    # token lives one hour, so days_left is always 0 and this would open every
    # single session with a false "token has expired" banner.
    warn_days=$(mnemon_config_value 'token_warn_days' 14)
    if days_left=$(mnemon_token_days_left "${pair#*|}"); then
        if [ "$days_left" -le "$warn_days" ]; then
            if [ "$days_left" -le 0 ]; then
                printf 'Mnemon: the access token has expired — memory is off until it is replaced.\n'
            else
                printf 'Mnemon: the access token expires in %s day(s). Mint a replacement before then or memory stops silently.\n' "$days_left"
            fi
        fi
    fi
fi

result=$(mnemon_call "tools/call" \
    "$(jq -n '{name:"palace_wake_up",arguments:{}}')" \
    2000 \
    "${pair%|*}" "${pair#*|}") || exit 0

if [ -z "$result" ]; then
    exit 0
fi

payload=$(printf '%s' "$result" | jq -c '.structuredContent // empty')
if [ -z "$payload" ]; then
    exit 0
fi

printf '%s' "$payload" | mnemon_format_wake
