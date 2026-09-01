#!/usr/bin/env bash
# Mnemon hook integration tests. Pipe synthetic events into each hook and
# assert on stdout/exit code. Uses a netcat-based fake MCP server.

set -uo pipefail
THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOOKS_DIR="$(cd "$THIS_DIR/../../resources/hooks/claude-code" && pwd)"
# Pick a free port rather than hardcoding one: a collision makes the hooks talk
# to whatever else is listening, which surfaces as confusing jq parse errors
# rather than an honest failure.
if [ -z "${FAKE_PORT:-}" ]; then
    FAKE_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
fi
export FAKE_PORT

# Sandbox MNEMON_DIR so tests don't touch real state.
export MNEMON_DIR="$(mktemp -d)"

# Write a temp config pointing at the fake server.
mkdir -p "$MNEMON_DIR"
cat > "$MNEMON_DIR/config.json" <<EOF
{"endpoint":"http://127.0.0.1:$FAKE_PORT/mcp","bearer_token":"test-token"}
EOF

PASS=0
FAIL=0

assert_ne() {
    if [ "$1" != "$2" ]; then
        PASS=$((PASS+1))
        printf '  ok: %s\n' "$3"
    else
        FAIL=$((FAIL+1))
        printf '  FAIL: %s\n    expected anything but: %s\n' "$3" "$2"
    fi
}

assert_eq() {
    if [ "$1" = "$2" ]; then
        PASS=$((PASS+1))
        printf '  ok: %s\n' "$3"
    else
        FAIL=$((FAIL+1))
        printf '  FAIL: %s\n    expected: %s\n    got:      %s\n' "$3" "$2" "$1"
    fi
}

# Start the fake server in the background.
REQ_LOG="$MNEMON_DIR/requests.log"
: > "$REQ_LOG"
export FAKE_REQUEST_LOG="$REQ_LOG"
"$THIS_DIR/fixtures/server.sh" &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null; rm -rf "$MNEMON_DIR"' EXIT
sleep 0.5

# Test 1: recall hook short-circuits on too-short prompt.
out=$(printf '{"session_id":"s1","prompt":"hi"}' | "$HOOKS_DIR/mnemon-recall.sh" || true)
assert_eq "$out" "" "recall: short prompt → no output"

# Test 2: recall hook short-circuits on stopword.
out=$(printf '{"session_id":"s1","prompt":"thanks"}' | "$HOOKS_DIR/mnemon-recall.sh" || true)
assert_eq "$out" "" "recall: stopword → no output"

# Test 3: recall hook short-circuits on slash command.
out=$(printf '{"session_id":"s1","prompt":"/clear"}' | "$HOOKS_DIR/mnemon-recall.sh" || true)
assert_eq "$out" "" "recall: slash command → no output"

# Test 4: @nomemo enables suppression for the rest of the session.
printf '{"session_id":"s2","prompt":"@nomemo please"}' | "$HOOKS_DIR/mnemon-recall.sh" >/dev/null || true
nm=$(jq -r '.nomemo' "$MNEMON_DIR/sessions/s2.json")
assert_eq "$nm" "true" "recall: @nomemo sets state.nomemo=true"

# Test 5: with nomemo set, real prompts still produce no output.
out=$(printf '{"session_id":"s2","prompt":"a real long prompt about dorothy"}' | "$HOOKS_DIR/mnemon-recall.sh" || true)
assert_eq "$out" "" "recall: nomemo=true → no output even for real prompts"

# Test 6: a substantive prompt with fresh state hits the fake server and renders output.
out=$(printf '{"session_id":"s3","prompt":"what do we know about dorothy vaughan"}' | "$HOOKS_DIR/mnemon-recall.sh")
case "$out" in
    *"<system-reminder>"*"Mnemon recall:"*) PASS=$((PASS+1)); printf '  ok: recall: hits fake server, emits system-reminder\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: recall: expected system-reminder, got: %s\n' "$out";;
esac

# Test 7: recent-fire suppression — second call within 30s returns nothing.
out2=$(printf '{"session_id":"s3","prompt":"another substantive prompt about dorothy"}' | "$HOOKS_DIR/mnemon-recall.sh" || true)
assert_eq "$out2" "" "recall: recent-fire suppression"

# Test 8: recall honours recall_timeout_ms. Against a deliberately slow server
# the budget is the only variable: below the round trip it must suppress output,
# above it must let recall through. A hardcoded budget ignores both.
SLOW_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
FAKE_PORT="$SLOW_PORT" FAKE_DELAY=1 "$THIS_DIR/fixtures/server.sh" &
SLOW_PID=$!
sleep 0.5
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.orig.json"

set_budget() {
    jq --arg e "http://127.0.0.1:$SLOW_PORT/mcp" --argjson b "$1" \
       '.endpoint=$e | .recall_timeout_ms=$b' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
       && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
}

set_budget 200
out=$(printf '{"session_id":"s6a","prompt":"a substantive prompt against a slow server"}' | "$HOOKS_DIR/mnemon-recall.sh" || true)
assert_eq "$out" "" "recall: a budget below the round trip suppresses output"

# The fixture serves one connection per loop iteration; give it a moment to
# re-bind after the timed-out request above.
sleep 1.5

set_budget 5000
out=$(printf '{"session_id":"s6b","prompt":"a substantive prompt against a slow server"}' | "$HOOKS_DIR/mnemon-recall.sh" || true)
case "$out" in
    *"Mnemon recall:"*) PASS=$((PASS+1)); printf '  ok: recall: a budget above the round trip allows it\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: recall: budget above round trip should allow it, got: %s\n' "$out";;
esac

kill $SLOW_PID 2>/dev/null
mv "$MNEMON_DIR/config.orig.json" "$MNEMON_DIR/config.json"

# Test 9: capture accepts the payload Claude Code actually sends. The Stop hook
# receives `transcript_path` (a path to a JSONL file) and no `turn_index` --
# reading a non-existent `.transcript` key makes capture a permanent no-op.
tp="$MNEMON_DIR/transcript.jsonl"
printf '%s\n' \
  '{"type":"user","message":{"role":"user","content":"what did we decide about retries"}}' \
  '{"type":"assistant","message":{"role":"assistant","content":"we chose exponential backoff"}}' > "$tp"
printf '{"session_id":"s7","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$tp" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
ldt=0
for _ in $(seq 1 40); do
    ldt=$(jq -r '.last_digest_turn // 0' "$MNEMON_DIR/sessions/s7.json" 2>/dev/null || echo 0)
    [ "$ldt" != "0" ] && break
    sleep 0.25
done
assert_ne "$ldt" "0" "capture: real Stop payload (transcript_path) dispatches a digest"

# Test 10: capture strips tool I/O nested inside message.content. Real Claude
# Code records are {"type":"assistant","message":{"content":[{"type":"tool_result",...}]}},
# so a filter that only inspects the top-level .type lets tool output through --
# and the guide promises tool I/O is stripped.
tp2="$MNEMON_DIR/transcript2.jsonl"
{
  printf '%s\n' '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"run the deploy"}]}}'
  printf '%s\n' '{"type":"assistant","message":{"role":"assistant","content":[{"type":"tool_result","content":"KEEPOUT-TOOL-OUTPUT-MARKER"},{"type":"text","text":"deploy finished"}]}}'
} > "$tp2"
printf '{"session_id":"s8","transcript_path":"%s","hook_event_name":"Stop"}' "$tp2" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
for _ in $(seq 1 40); do
    grep -q 'session_digest' "$REQ_LOG" 2>/dev/null && break
    sleep 0.25
done
# Both halves matter: the digest must actually have been sent (otherwise a
# capture that no-ops would "pass" for the wrong reason), and the tool output
# must not be in it.
if ! grep -q 'session_digest' "$REQ_LOG" 2>/dev/null; then
    FAIL=$((FAIL+1)); printf '  FAIL: capture: no digest reached the server at all\n'
elif grep -q 'KEEPOUT-TOOL-OUTPUT-MARKER' "$REQ_LOG" 2>/dev/null; then
    FAIL=$((FAIL+1)); printf '  FAIL: capture: nested tool_result content reached the server\n'
else
    PASS=$((PASS+1)); printf '  ok: capture: strips tool I/O nested in message.content\n'
fi

# Test 10: capture hook with no token short-circuits.
rm -f "$MNEMON_DIR/config.json"
out=$(printf '{"session_id":"s4","transcript":[],"turn_index":1}' | "$HOOKS_DIR/mnemon-capture.sh" || true)
assert_eq "$out" "" "capture: no token → silent no-op"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" = 0 ]
