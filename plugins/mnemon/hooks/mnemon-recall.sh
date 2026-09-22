#!/usr/bin/env bash
# Mnemon UserPromptSubmit hook: gate, then call recall, inject context.

set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "$SCRIPT_DIR/lib/common.sh"

mnemon_disabled && exit 0

input=$(cat 2>/dev/null || echo '{}')
session_id=$(printf '%s' "$input" | jq -r '.session_id // empty')
prompt=$(printf '%s' "$input" | jq -r '.prompt // empty')

[ -z "$session_id" ] && exit 0
[ -z "$prompt" ] && exit 0

pair=$(mnemon_token) || exit 0

state=$(mnemon_session_state "$session_id")

# @nomemo toggle: case-insensitive, must START the prompt.
if printf '%s' "$prompt" | grep -qiE '^[[:space:]]*@nomemo\b'; then
    state=$(printf '%s' "$state" | jq '.nomemo=true')
    mnemon_session_state_write "$session_id" "$state"
    exit 0
fi

if [ "$(printf '%s' "$state" | jq -r '.nomemo')" = "true" ]; then exit 0; fi
if [ "$(printf '%s' "$state" | jq -r '.disabled')" = "true" ]; then exit 0; fi

# Length gate.
if [ "${#prompt}" -lt 15 ]; then exit 0; fi

# Stopword + slash-command gate.
trimmed=$(printf '%s' "$prompt" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')
case "$(printf '%s' "$trimmed" | tr '[:upper:]' '[:lower:]')" in
    "ok"|"yes"|"no"|"thanks"|"continue"|"go"|"do it"|"stop"|"wait")
        exit 0
        ;;
esac
case "$trimmed" in
    /*)
        exit 0
        ;;
esac

# Recent-fire suppression.
last=$(printf '%s' "$state" | jq -r '.last_recall_at')
now=$(date +%s)
if [ "$((now - last))" -lt 30 ]; then
    exit 0
fi

# Update recall timestamp.
state=$(printf '%s' "$state" | jq --arg n "$now" '.last_recall_at=($n|tonumber)')
mnemon_session_state_write "$session_id" "$state"

# One recall per prompt, however many copies of the hook are registered. Claimed
# here rather than at the top so every gate above behaves exactly as before; only
# the round trip and the injected context are deduplicated.
mnemon_claim_once "$session_id" recall 5 || exit 0

# Call recall.
#
# `RecallTool` validates `prompt` => `required|string|max:4000`. This hook used
# to send it uncapped, so a long prompt was rejected whole and the session
# silently got no memory -- the same shape as the transcript cap in
# mnemon-capture.sh: client sends unbounded, server validates a ceiling, and
# nothing surfaces because the tool error is logged where nobody reads it.
#
# The slice happens inside jq rather than with `cut`/`head -c` because jq
# slices by codepoint: a byte-wise cut can split a multi-byte character and
# produce invalid UTF-8, which jq would then refuse to encode. Recall is a
# search query, so the leading text carries the intent and the tail is what
# costs least to drop.
max_prompt=$(mnemon_config_value 'max_recall_prompt_chars' 3800)
params=$(jq -n --arg p "$prompt" --argjson n "$max_prompt" \
    '{name:"recall",arguments:{prompt:($p[0:$n]),token_budget:1500}}')
# Budget for the recall round trip. 800ms suits a localhost instance; a remote
# one needs more (a hosted instance measures ~1.2s), and a budget that is too
# tight makes recall a silent no-op. Override with recall_timeout_ms in config.
budget=$(mnemon_config_value 'recall_timeout_ms' 800)
result=$(mnemon_call "tools/call" "$params" "$budget" "${pair%|*}" "${pair#*|}") || exit 0
[ -z "$result" ] && exit 0

payload=$(printf '%s' "$result" | jq -c '.structuredContent // empty')
[ -z "$payload" ] && exit 0

found=$(printf '%s' "$payload" | jq -r '.found // false')
[ "$found" != "true" ] && exit 0

printf '%s' "$payload" | mnemon_format_recall
