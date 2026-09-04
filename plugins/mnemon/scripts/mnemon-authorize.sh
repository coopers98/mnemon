#!/usr/bin/env bash
# Mnemon device enrolment via the RFC 8628 device authorization grant.
#
# Usage: mnemon-authorize.sh <instance-base-url> [client-id]
#
# The client secret is read from $MNEMON_CLIENT_SECRET or prompted for. It never
# travels in argv, which is visible in ps to every user on the machine.
#
# This is the one script in the system whose stdout is guaranteed to have a
# person behind it, so every terminal state says what happened and exits
# non-zero if it was not success. It is also the only script that replaces a
# working credential, which is why the rollback is printed before the swap
# rather than after it.
set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../hooks/lib/common.sh
. "$SCRIPT_DIR/../hooks/lib/common.sh"

CLAUDE_JSON="${MNEMON_CLAUDE_JSON:-$HOME/.claude.json}"

base="${1:-}"
if [ -z "$base" ]; then
    echo "Usage: mnemon-authorize.sh <instance-base-url> [client-id]" >&2
    exit 2
fi
base="${base%/}"

client_id="${2:-}"
if [ -z "$client_id" ]; then
    printf 'Client id: '
    read -r client_id
fi

client_secret="${MNEMON_CLIENT_SECRET:-}"
if [ -z "$client_secret" ]; then
    printf 'Client secret (input hidden): '
    read -rs client_secret
    echo
fi

mkdir -p "$MNEMON_DIR" && chmod 700 "$MNEMON_DIR"

# Endpoints are derived, not discovered. A setup script that breaks because the
# discovery document is stale is a worse failure than a fixed path on a server
# we control.
device_endpoint="$base/oauth/device/code"
token_endpoint="$base/oauth/token"
mcp_endpoint="$base/mcp"

req=$(mktemp "${TMPDIR:-/tmp}/mnemon-auth.XXXXXX")
trap 'rm -f "$req" "$req.old"' EXIT

enc() { jq -rn --arg v "$1" '$v|@uri'; }

printf 'client_id=%s&client_secret=%s&scope=mcp%%3Ause' \
    "$(enc "$client_id")" "$(enc "$client_secret")" > "$req"

resp=$(curl -s -m 15 -X POST "$device_endpoint" -H 'Accept: application/json' --data @"$req")
if ! printf '%s' "$resp" | jq -e .device_code >/dev/null 2>&1; then
    echo "Could not start authorization. The server said:"
    printf '%s\n' "$resp" | head -c 300
    echo
    echo "Check the client id and secret (on the server: php artisan mnemon:device-client <name>)."
    exit 1
fi

device_code=$(printf '%s' "$resp" | jq -r .device_code)
user_code=$(printf '%s' "$resp" | jq -r .user_code)
uri_complete=$(printf '%s' "$resp" | jq -r '.verification_uri_complete // empty')
uri=$(printf '%s' "$resp" | jq -r '.verification_uri // empty')
interval=$(printf '%s' "$resp" | jq -r '.interval // 5')
expires_in=$(printf '%s' "$resp" | jq -r '.expires_in // 600')
# Grouped, because this code gets read off one screen and typed into another.
pretty_code="$(printf '%s' "$user_code" | sed 's/^\(....\)/\1-/')"

printf '\nOpen:  %s\n' "$uri_complete"
printf 'or go to %s and enter code:  %s\n' "$uri" "$pretty_code"
printf 'Waiting for approval (expires in %s minutes)...\n' "$(( expires_in / 60 ))"

printf 'grant_type=urn%%3Aietf%%3Aparams%%3Aoauth%%3Agrant-type%%3Adevice_code&device_code=%s&client_id=%s&client_secret=%s' \
    "$(enc "$device_code")" "$(enc "$client_id")" "$(enc "$client_secret")" > "$req"

access=""
refresh=""
while :; do
    sleep "$interval"
    out=$(curl -s -m 15 -X POST "$token_endpoint" -H 'Accept: application/json' --data @"$req")
    err=$(printf '%s' "$out" | jq -r '.error // empty' 2>/dev/null)
    case "$err" in
        authorization_pending)
            continue
            ;;
        slow_down)
            # RFC 8628 §3.5. The one path that is deliberately quiet.
            interval=$((interval + 5))
            continue
            ;;
        access_denied)
            echo "Authorization was denied on the consent screen. Nothing was changed."
            exit 1
            ;;
        expired_token)
            echo "The code expired before it was approved. Re-run this script for a fresh code."
            exit 1
            ;;
        '')
            ;;
        *)
            echo "The server refused the exchange ($err). Nothing was changed."
            exit 1
            ;;
    esac

    access=$(printf '%s' "$out" | jq -r '.access_token // empty' 2>/dev/null)
    refresh=$(printf '%s' "$out" | jq -r '.refresh_token // empty' 2>/dev/null)
    if [ -n "$access" ] && [ -n "$refresh" ]; then
        break
    fi
    echo "Unexpected response while polling; retrying..."
done

# Back up first, flip second. The token being replaced may be the only plaintext
# copy in existence, so a failure without this backup would mean SSH and tinker
# on the server -- the exact ritual this feature exists to remove.
old_token=""
if [ -f "$MNEMON_CONFIG" ]; then
    old_token=$(jq -r '.bearer_token // empty' "$MNEMON_CONFIG" 2>/dev/null)
    backup="$MNEMON_DIR/config.backup-$(date -u +%Y%m%dT%H%M%SZ).json"
    cp "$MNEMON_CONFIG" "$backup" && chmod 600 "$backup"
    printf 'Backed up the old config. To roll back:\n  cp %s %s\n' "$backup" "$MNEMON_CONFIG"
fi

# Merge onto whatever is there so tuned settings survive; an unreadable config
# is treated as absent rather than aborting the enrolment.
old_json='{}'
if [ -f "$MNEMON_CONFIG" ] && jq -e . "$MNEMON_CONFIG" >/dev/null 2>&1; then
    old_json=$(cat "$MNEMON_CONFIG")
fi
printf '%s' "$old_json" > "$req.old"

jq -n --slurpfile old "$req.old" \
    --arg e "$mcp_endpoint" --arg te "$token_endpoint" \
    --arg ci "$client_id" --arg cs "$client_secret" \
    --arg a "$access" --arg r "$refresh" '
    ($old[0] // {}) + {
      endpoint: $e, token_endpoint: $te, client_id: $ci, client_secret: $cs,
      bearer_token: $a, refresh_token: $r,
      refreshed_at: (now|floor), auth_state: "ok"
    } | del(.auth_dead_reason, .auth_dead_at)' | mnemon_config_write \
    || { echo "FAILED to write $MNEMON_CONFIG - the old config (if any) is untouched."; exit 1; }

# Verify with a real tool call. tools/list would succeed on a token with no
# scope or a deny-all wing restriction, and report a bricked device as working.
echo "Verifying against $mcp_endpoint ..."
result=$(mnemon_call "tools/call" '{"name":"palace_wake_up","arguments":{}}' 10000 "$mcp_endpoint" "$access")
if [ -z "$result" ]; then
    echo "VERIFICATION FAILED: the token was issued but a real tool call did not succeed."
    echo "Details: $(tail -1 "$MNEMON_ERROR_LOG" 2>/dev/null)"
    echo "The new config was written anyway; fix the problem and re-run, or roll back (see above)."
    exit 1
fi

wings=$(printf '%s' "$result" | jq -r '[.structuredContent.active_wings[]?.slug] | join(", ")' 2>/dev/null)
echo "Verified. Wings this device can reach (with content): ${wings:-none yet}"
echo "(A wing with no drawers yet will not appear here even if it is permitted.)"

# The interactive MCP server entry may carry the same token. Revoking it without
# knowing that would break the MCP connection too.
if [ -n "$old_token" ] && [ -f "$CLAUDE_JSON" ]; then
    if grep -qF "$old_token" "$CLAUDE_JSON" 2>/dev/null; then
        echo "NOTE: the old token is also used by your MCP server entry in $CLAUDE_JSON."
        echo "Re-connect that entry before revoking the old token, or leave it to expire."
    fi
fi

echo "Done. This device now renews itself; no more 90-day token ritual."
