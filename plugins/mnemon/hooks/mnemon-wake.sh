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
    # Point at a personal access token, not the OAuth flow: hooks store the
    # token once with no refresh, and OAuth access tokens expire in an hour.
    printf 'Mnemon: not connected. Create ~/.mnemon/config.json with {"endpoint":"https://<your-instance>/mcp","bearer_token":"<personal access token>"} (chmod 600), or set MNEMON_ENDPOINT and MNEMON_TOKEN.\n'
    exit 0
}

# Ensure state exists, but never rewind it. SessionStart also fires on resume
# and after compaction, so resetting last_digest_turn to 0 here would make the
# next Stop re-digest the whole transcript: re-paying the reader for content
# already stored, and duplicating drawers. A genuinely new session has a new
# session_id and so gets fresh defaults anyway.
mnemon_session_state "$session_id" > /dev/null

# Warn before the token expires rather than after. Expiry is otherwise silent:
# the hooks simply stop returning anything, and the only trace is a line in
# capture-errors.log that nothing surfaces.
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
