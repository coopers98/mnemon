#!/usr/bin/env bash
# Mnemon hook integration tests. Pipe synthetic events into each hook and
# assert on stdout/exit code. Uses a netcat-based fake MCP server.

set -uo pipefail
THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOOKS_DIR="$(cd "$THIS_DIR/../../resources/hooks/claude-code" && pwd)"
FAKE_PORT="${FAKE_PORT:-9876}"

# Sandbox MNEMON_DIR so tests don't touch real state.
export MNEMON_DIR="$(mktemp -d)"

# Write a temp config pointing at the fake server.
mkdir -p "$MNEMON_DIR"
cat > "$MNEMON_DIR/config.json" <<EOF
{"endpoint":"http://127.0.0.1:$FAKE_PORT/mcp","bearer_token":"test-token"}
EOF

PASS=0
FAIL=0

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

# Test 8: capture hook with no token short-circuits.
rm -f "$MNEMON_DIR/config.json"
out=$(printf '{"session_id":"s4","transcript":[],"turn_index":1}' | "$HOOKS_DIR/mnemon-capture.sh" || true)
assert_eq "$out" "" "capture: no token → silent no-op"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" = 0 ]
