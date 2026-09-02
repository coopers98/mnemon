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
    printf 'Mnemon: not connected — run `claude mcp add --transport http mnemon <url>` to enable memory.\n'
    exit 0
}

# Ensure state exists, but never rewind it. SessionStart also fires on resume
# and after compaction, so resetting last_digest_turn to 0 here would make the
# next Stop re-digest the whole transcript: re-paying the reader for content
# already stored, and duplicating drawers. A genuinely new session has a new
# session_id and so gets fresh defaults anyway.
mnemon_session_state "$session_id" > /dev/null

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
