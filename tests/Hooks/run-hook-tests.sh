#!/usr/bin/env bash
# Mnemon hook integration tests. Pipe synthetic events into each hook and
# assert on stdout/exit code. Uses a netcat-based fake MCP server.

set -uo pipefail
THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOOKS_DIR="$(cd "$THIS_DIR/../../plugins/mnemon/hooks" && pwd)"
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
MNEMON_ERROR_LOG_TEST="$MNEMON_DIR/capture-errors.log"

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

# Test 0: the digest worker ships as a real, version-controlled file rather than
# being written at runtime from a heredoc. Under a plugin the hooks directory is
# a cache that is wholesale-replaced on update and is not a place to write to;
# the lazy write was also guarded by [ ! -x ], so a stale copy shadowed its own
# source indefinitely. Checked against git, not the filesystem: a leftover
# generated copy would otherwise make this pass for the wrong reason.
if git -C "$THIS_DIR/../.." ls-files --error-unmatch "${HOOKS_DIR#"$(cd "$THIS_DIR/../.." && pwd)/"}/lib/digest-worker.sh" >/dev/null 2>&1; then
    PASS=$((PASS+1)); printf '  ok: digest worker is version-controlled, not generated\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: digest worker is not tracked in git; still generated at runtime\n'
fi
if grep -qE "<<'WORKER'|cat > \"\$worker\"" "$HOOKS_DIR/mnemon-capture.sh" 2>/dev/null; then
    FAIL=$((FAIL+1)); printf '  FAIL: capture still writes the worker from a heredoc\n'
else
    PASS=$((PASS+1)); printf '  ok: capture does not generate the worker at runtime\n'
fi

# Test 0b: environment variables take precedence over config.json. A plugin can
# supply credentials to its hooks as env vars, which keeps the token out of a
# world-readable-by-mistake file and out of any conversation transcript. The
# config file remains the fallback for installs that have no plugin.
env_out=$(MNEMON_ENDPOINT="http://env.example/mcp" MNEMON_TOKEN="env-token" \
    bash -c ". \"$HOOKS_DIR/lib/common.sh\"; mnemon_token" 2>/dev/null)
assert_eq "$env_out" "http://env.example/mcp|env-token" "token: environment overrides config.json"

# Test 0c: a zero-byte or unparseable state file must be treated as missing.
# mnemon_session_state only checked existence, so an empty file returned empty
# content -- and the digest worker then passed "" to jq --argjson, which fails.
# Because the failure prevented the state from ever being rewritten, the session
# stayed wedged: observed retrying every 20 minutes for hours against the live
# instance, with the only symptom in a log nothing surfaces.
: > "$MNEMON_DIR/sessions/zerobyte.json"
zb=$(bash -c ". \"$HOOKS_DIR/lib/common.sh\"; mnemon_session_state zerobyte" 2>/dev/null)
if printf '%s' "$zb" | jq -e . >/dev/null 2>&1; then
    PASS=$((PASS+1)); printf '  ok: a zero-byte state file is reinitialised\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: zero-byte state file returned unparseable state: [%s]\n' "$zb"
fi

printf 'not json at all' > "$MNEMON_DIR/sessions/corrupt.json"
cs=$(bash -c ". \"$HOOKS_DIR/lib/common.sh\"; mnemon_session_state corrupt" 2>/dev/null)
if printf '%s' "$cs" | jq -e . >/dev/null 2>&1; then
    PASS=$((PASS+1)); printf '  ok: a corrupt state file is reinitialised\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: corrupt state file returned unparseable state: [%s]\n' "$cs"
fi

# And end to end: capture must recover rather than wedge.
: > "$MNEMON_DIR/sessions/wedged.json"
: > "$MNEMON_DIR/capture-errors.log"
tp8="$MNEMON_DIR/transcript-wedged.jsonl"
printf '%s\n' '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"a normal turn"}]}}' > "$tp8"
printf '{"session_id":"wedged","transcript_path":"%s","hook_event_name":"Stop"}' "$tp8" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
sleep 2
if grep -q 'argjson' "$MNEMON_DIR/capture-errors.log" 2>/dev/null; then
    FAIL=$((FAIL+1)); printf '  FAIL: capture still wedges on a zero-byte state file\n'
else
    PASS=$((PASS+1)); printf '  ok: capture recovers from a zero-byte state file\n'
fi

# Test 0d: the session start warns before the token expires. Passport tokens are
# JWTs carrying their own exp, so this needs no server call. Without it the only
# notice of expiry is a line in capture-errors.log that nothing surfaces, and
# memory simply stops -- which is how this feature has failed every other time.
make_jwt() {  # $1 = seconds from now until exp
    local exp payload
    exp=$(( $(date +%s) + $1 ))
    payload=$(printf '{"aud":"1","jti":"x","iat":0,"nbf":0,"exp":%s,"sub":"1","scopes":["mcp:use"]}' "$exp" \
        | base64 -w0 | tr '+/' '-_' | tr -d '=')
    printf 'eyJhbGciOiJSUzI1NiJ9.%s.sig' "$payload"
}

# Offset by half a day so the assertions are not on a truncation boundary:
# days_left floors (exp - now) / 86400, so a token generated at exactly N days
# reads as N-1 the moment a second elapses. That passed locally and failed in CI.
days_left=$(bash -c ". \"$HOOKS_DIR/lib/common.sh\"; mnemon_token_days_left \"$(make_jwt 302400)\"" 2>/dev/null)
assert_eq "$days_left" "3" "token: reads days remaining from the JWT exp"

far=$(bash -c ". \"$HOOKS_DIR/lib/common.sh\"; mnemon_token_days_left \"$(make_jwt 5227200)\"" 2>/dev/null)
assert_eq "$far" "60" "token: reads a distant expiry correctly"

opaque=$(bash -c ". \"$HOOKS_DIR/lib/common.sh\"; mnemon_token_days_left not-a-jwt" 2>/dev/null; echo "rc=$?")
case "$opaque" in
    *rc=1*) PASS=$((PASS+1)); printf '  ok: token: a non-JWT token reports unknown rather than failing\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: token: non-JWT should return non-zero, got [%s]\n' "$opaque";;
esac

# End to end: wake warns when the token is close to expiry.
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.exp.json"
jq --arg t "$(make_jwt 302400)" '.bearer_token=$t' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
warn=$(printf '{"session_id":"s16","cwd":"/tmp","hook_event_name":"SessionStart"}' \
  | "$HOOKS_DIR/mnemon-wake.sh" 2>&1 || true)
case "$warn" in
    *"expire"*) PASS=$((PASS+1)); printf '  ok: wake warns when the token is near expiry\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: wake gave no expiry warning for a token 3 days from expiring\n';;
esac
mv "$MNEMON_DIR/config.exp.json" "$MNEMON_DIR/config.json"

# Test 0e: a tool-level error must be reported. A wing denial is
# Response::error() -> isError on the *result* with HTTP 200, not a JSON-RPC
# .error -- so mnemon_call's `.error // empty` check misses it entirely and the
# hook reads a denial as "found nothing". Silent authorisation failures are the
# worst version of this project's signature bug.
TE_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
FAKE_PORT="$TE_PORT" FAKE_TOOL_ERROR=1 "$THIS_DIR/fixtures/server.sh" &
TE_PID=$!
sleep 0.5
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.te.json"
jq --arg e "http://127.0.0.1:$TE_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
: > "$MNEMON_DIR/capture-errors.log"

printf '{"session_id":"s17","cwd":"/tmp","prompt":"a substantive prompt that reaches the server"}' \
  | "$HOOKS_DIR/mnemon-recall.sh" >/dev/null 2>&1 || true
if grep -qiE 'does not have access|tool error|isError' "$MNEMON_DIR/capture-errors.log" 2>/dev/null; then
    PASS=$((PASS+1)); printf '  ok: a tool-level error is logged, not read as "found nothing"\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: tool-level error was silent: [%s]\n' "$(head -1 "$MNEMON_DIR/capture-errors.log" 2>/dev/null)"
fi
kill $TE_PID 2>/dev/null
mv "$MNEMON_DIR/config.te.json" "$MNEMON_DIR/config.json"

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

# Test 10: capture survives a transcript larger than the argv limit. Passing the
# transcript as a jq --arg makes the worker die with "Argument list too long"
# once it exceeds MAX_ARG_STRLEN (~128KB on Linux) -- and real sessions run to
# megabytes, so every genuine capture failed while the tests, with their
# two-line fixtures, passed.
#
# Runs against its own server on its own port: the fixture serves one connection
# per loop iteration, so sharing it with the preceding capture test makes this a
# race that a slow runner loses.
BIG_PORT2=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
BIG_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
BIG_LOG="$MNEMON_DIR/requests-big.log"
: > "$BIG_LOG"
FAKE_PORT="$BIG_PORT" FAKE_REQUEST_LOG="$BIG_LOG" "$THIS_DIR/fixtures/server.sh" &
BIG_PID=$!
sleep 0.5
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.big.json"
jq --arg e "http://127.0.0.1:$BIG_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"

tp3="$MNEMON_DIR/transcript-big.jsonl"
: > "$tp3"
big=$(head -c 200000 /dev/zero | tr '\0' 'x')
for i in 1 2 3; do
    printf '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"%s"}]}}\n' "$big" >> "$tp3"
done
printf '{"session_id":"s9","transcript_path":"%s","hook_event_name":"Stop"}' "$tp3" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
for _ in $(seq 1 120); do
    grep -q 'session_digest' "$BIG_LOG" 2>/dev/null && break
    sleep 0.25
done
if grep -q 'session_digest' "$BIG_LOG" 2>/dev/null; then
    PASS=$((PASS+1)); printf '  ok: capture: handles a transcript larger than the argv limit\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: capture: a >128KB transcript never reached the server\n'
    if [ -s "$MNEMON_DIR/capture-errors.log" ]; then
        printf '    worker log: %s\n' "$(tail -3 "$MNEMON_DIR/capture-errors.log")"
    else
        printf '    worker log: (empty)\n'
    fi
fi
kill $BIG_PID 2>/dev/null
mv "$MNEMON_DIR/config.big.json" "$MNEMON_DIR/config.json"

# Test 10a: capture sends only the turns added since the last digest. The
# protocol carries turn_range{start,end} and the state tracks last_digest_turn,
# but sending the whole transcript every time re-digests content already stored
# and grows without bound.
: > "$BIG_LOG"
FAKE_PORT="$BIG_PORT2" FAKE_REQUEST_LOG="$BIG_LOG" "$THIS_DIR/fixtures/server.sh" &
SLICE_PID=$!
sleep 0.5
jq --arg e "http://127.0.0.1:$BIG_PORT2/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"

tp4="$MNEMON_DIR/transcript-slice.jsonl"
{
  printf '%s\n' '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"OLD-ALREADY-DIGESTED"}]}}'
  printf '%s\n' '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"OLD-ALREADY-DIGESTED"}]}}'
  printf '%s\n' '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"NEW-SINCE-LAST-DIGEST"}]}}'
} > "$tp4"
printf '{"last_digest_turn":2,"last_recall_at":0,"recent_drawer_ids":[],"nomemo":false,"disabled":false}' \
  > "$MNEMON_DIR/sessions/s10.json"
printf '{"session_id":"s10","transcript_path":"%s","hook_event_name":"Stop"}' "$tp4" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
for _ in $(seq 1 60); do
    grep -q 'session_digest' "$BIG_LOG" 2>/dev/null && break
    sleep 0.25
done
if ! grep -q 'NEW-SINCE-LAST-DIGEST' "$BIG_LOG" 2>/dev/null; then
    FAIL=$((FAIL+1)); printf '  FAIL: capture: the new turn never reached the server\n'
elif grep -q 'OLD-ALREADY-DIGESTED' "$BIG_LOG" 2>/dev/null; then
    FAIL=$((FAIL+1)); printf '  FAIL: capture: re-sent turns that were already digested\n'
else
    PASS=$((PASS+1)); printf '  ok: capture: sends only turns added since the last digest\n'
fi

# Test 10b: the request body stays under a reverse proxy's default 1MiB limit
# even for a huge first digest. A 413 comes back as an HTML page, which the
# caller feeds to jq -- surfacing as "Invalid numeric literal", not as a size
# problem, which is what made this hard to see.
: > "$BIG_LOG"
tp5="$MNEMON_DIR/transcript-huge.jsonl"
: > "$tp5"
chunk=$(head -c 100000 /dev/zero | tr '\0' 'y')
for i in $(seq 1 40); do
    printf '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"%s"}]}}\n' "$chunk" >> "$tp5"
done
rm -f "$MNEMON_DIR/sessions/s11.json"
printf '{"session_id":"s11","transcript_path":"%s","hook_event_name":"Stop"}' "$tp5" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
for _ in $(seq 1 60); do
    grep -q 'session_digest' "$BIG_LOG" 2>/dev/null && break
    sleep 0.25
done
body_bytes=$(wc -c < "$BIG_LOG" 2>/dev/null || echo 0)
if ! grep -q 'session_digest' "$BIG_LOG" 2>/dev/null; then
    FAIL=$((FAIL+1)); printf '  FAIL: capture: a 4MB transcript produced no request at all\n'
elif [ "$body_bytes" -gt 1048576 ]; then
    FAIL=$((FAIL+1)); printf '  FAIL: capture: request body %s bytes exceeds the 1MiB proxy limit\n' "$body_bytes"
else
    PASS=$((PASS+1)); printf '  ok: capture: caps the request body below the 1MiB proxy limit (%s bytes)\n' "$body_bytes"
fi
kill $SLICE_PID 2>/dev/null

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

# Test 10c: a non-JSON error response is reported as such. A proxy 413 returns
# an HTML page; piping that to jq yields "Invalid numeric literal at line 1,
# column 7", which says nothing about the request being too large and cost real
# hours to trace.
ERR_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
FAKE_PORT="$ERR_PORT" FAKE_STATUS=413 "$THIS_DIR/fixtures/server.sh" &
ERR_PID=$!
sleep 0.5
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.err.json"
jq --arg e "http://127.0.0.1:$ERR_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
: > "$MNEMON_DIR/capture-errors.log"

tp6="$MNEMON_DIR/transcript-err.jsonl"
printf '%s\n' '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"anything at all"}]}}' > "$tp6"
rm -f "$MNEMON_DIR/sessions/s12.json"
printf '{"session_id":"s12","transcript_path":"%s","hook_event_name":"Stop"}' "$tp6" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
for _ in $(seq 1 40); do
    [ -s "$MNEMON_DIR/capture-errors.log" ] && break
    sleep 0.25
done
if grep -qiE 'non-JSON|HTTP [0-9]{3}|too large' "$MNEMON_DIR/capture-errors.log" 2>/dev/null; then
    PASS=$((PASS+1)); printf '  ok: a proxy error is logged with its status, not as a jq parse error\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: non-JSON response not reported clearly: %s\n' "$(head -1 "$MNEMON_DIR/capture-errors.log" 2>/dev/null)"
fi
kill $ERR_PID 2>/dev/null
mv "$MNEMON_DIR/config.err.json" "$MNEMON_DIR/config.json"

# Test 10f: requests carry the Accept header MCP Streamable HTTP requires.
# Without it a Laravel instance answers an auth failure with a 302 redirect to
# an HTML login page rather than a 401, so the token-expired branch can never
# fire against a real server no matter how correct it looks against a fixture.
# Self-contained: earlier tests repoint the endpoint and do not all restore it.
HDR_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
HDR_LOG="$MNEMON_DIR/headers.log"
: > "$HDR_LOG"
FAKE_PORT="$HDR_PORT" FAKE_HEADER_LOG="$HDR_LOG" "$THIS_DIR/fixtures/server.sh" &
HDR_PID=$!
sleep 0.5
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.hdr.json"
jq --arg e "http://127.0.0.1:$HDR_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"

printf '{"session_id":"s15","cwd":"/tmp","hook_event_name":"SessionStart"}' \
  | "$HOOKS_DIR/mnemon-wake.sh" >/dev/null 2>&1 || true
for _ in $(seq 1 20); do
    [ -s "$HDR_LOG" ] && break
    sleep 0.25
done
if grep -qi '^Accept:.*application/json' "$HDR_LOG" 2>/dev/null; then
    PASS=$((PASS+1)); printf '  ok: requests send an Accept header for JSON\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: no JSON Accept header sent (auth failures come back as 302 HTML)\n'
fi
kill $HDR_PID 2>/dev/null
mv "$MNEMON_DIR/config.hdr.json" "$MNEMON_DIR/config.json"

# Test 10d: an expired token is reported. Laravel answers 401 with valid JSON
# carrying "message", so `.error // empty` is empty and `.result // empty` is
# empty -- the call returns nothing with exit 0 and nothing is ever logged. The
# USERGUIDE's "401 errors in capture log" cannot happen while that is true.
AUTH_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
FAKE_PORT="$AUTH_PORT" FAKE_STATUS=401 "$THIS_DIR/fixtures/server.sh" &
AUTH_PID=$!
sleep 0.5
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.auth.json"
jq --arg e "http://127.0.0.1:$AUTH_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
: > "$MNEMON_DIR/capture-errors.log"

tp7="$MNEMON_DIR/transcript-auth.jsonl"
printf '%s\n' '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"anything"}]}}' > "$tp7"
rm -f "$MNEMON_DIR/sessions/s13.json"
printf '{"session_id":"s13","transcript_path":"%s","hook_event_name":"Stop"}' "$tp7" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
for _ in $(seq 1 40); do
    [ -s "$MNEMON_DIR/capture-errors.log" ] && break
    sleep 0.25
done
if grep -qiE '401|unautheni?ticated|expired|auth' "$MNEMON_DIR/capture-errors.log" 2>/dev/null; then
    PASS=$((PASS+1)); printf '  ok: an expired token (401) is logged\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: 401 produced no log entry (silent auth failure)\n'
fi
kill $AUTH_PID 2>/dev/null
mv "$MNEMON_DIR/config.auth.json" "$MNEMON_DIR/config.json"

# Test 10e: SessionStart must not rewind the digest pointer. It fires on resume
# and after compaction too, so resetting last_digest_turn to 0 makes the next
# Stop re-digest the entire transcript -- re-paying the reader for content
# already stored, and re-creating drawers.
printf '{"last_digest_turn":5,"last_recall_at":0,"recent_drawer_ids":[7,8],"nomemo":false,"disabled":false}' \
  > "$MNEMON_DIR/sessions/s14.json"
printf '{"session_id":"s14","cwd":"/tmp","hook_event_name":"SessionStart"}' \
  | "$HOOKS_DIR/mnemon-wake.sh" >/dev/null 2>&1 || true
kept=$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/s14.json" 2>/dev/null)
assert_eq "$kept" "5" "wake: does not rewind last_digest_turn on SessionStart"

# --- Refresh tests -----------------------------------------------------------
# A device enrolled through the device grant holds a one-hour access token and a
# 90-day refresh token. Nothing renews it interactively -- there is no browser on
# the machine at 3am -- so a 401 has to be repaired in-band or the device goes
# quiet in exactly the way every earlier defect in this file went quiet.
#
# Each mode needs its own server: the failure being modelled is fixed by
# environment at start-up.

RF_SERVERS=""
rf_free_port() {
    python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()'
}

# Args: <port> <request-log> <state-dir> [VAR=value ...]
rf_start() {
    local port="$1" log="$2" state="$3"; shift 3
    : > "$log"
    env FAKE_PORT="$port" FAKE_REQUEST_LOG="$log" FAKE_STATE_DIR="$state" "$@" \
        "$THIS_DIR/fixtures/server.sh" &
    RF_SERVERS="$RF_SERVERS $!"
    sleep 0.5
}

rf_stop_all() {
    # shellcheck disable=SC2086
    [ -n "$RF_SERVERS" ] && kill $RF_SERVERS 2>/dev/null
    RF_SERVERS=""
}

# A refresh-capable config: the presence of refresh_token + client_id is the
# feature switch, so a PAT config is simply one without these keys.
rf_config() {
    local port="$1"
    jq -n --arg e "http://127.0.0.1:$port/mcp" --arg t "http://127.0.0.1:$port/oauth/token" \
        '{endpoint:$e, token_endpoint:$t, bearer_token:"token-1", refresh_token:"refresh-1",
          client_id:"cid", client_secret:"csec", recall_timeout_ms:5000}' \
        > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
    chmod 600 "$MNEMON_DIR/config.json"
}

rf_recall() {  # Args: <session-id>
    printf '{"session_id":"%s","prompt":"a substantive prompt to drive the refresh path"}' "$1" \
        | "$HOOKS_DIR/mnemon-recall.sh" 2>/dev/null
}

# R1: 401 -> refresh -> retry once -> success, with the config rewritten intact.
R1_PORT=$(rf_free_port); R1_LOG="$MNEMON_DIR/r1.log"; R1_STATE=$(mktemp -d)
rf_start "$R1_PORT" "$R1_LOG" "$R1_STATE" FAKE_EXPIRE_FIRST=1
rf_config "$R1_PORT"
: > "$MNEMON_ERROR_LOG_TEST"
out=$(rf_recall r1)
case "$out" in
    *"Mnemon recall:"*) PASS=$((PASS+1)); printf '  ok: refresh: 401 is refreshed and retried once\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: refresh: expected recall output after refresh, got: %s\n' "$out";;
esac
assert_eq "$(jq -r '.bearer_token' "$MNEMON_DIR/config.json")" "token-2" "refresh: new access token stored"
assert_eq "$(jq -r '.refresh_token' "$MNEMON_DIR/config.json")" "refresh-2" "refresh: new refresh token stored"
assert_eq "$(jq -r '.recall_timeout_ms' "$MNEMON_DIR/config.json")" "5000" "refresh: tunables survive the rewrite"
assert_eq "$(stat -c '%a' "$MNEMON_DIR/config.json")" "600" "refresh: config stays 0600"
assert_eq "$(grep -c 'refreshed access token' "$MNEMON_ERROR_LOG_TEST")" "1" "refresh: success is logged"
assert_eq "$(grep -c '^OAUTH_TOKEN' "$R1_LOG")" "1" "refresh: exactly one token exchange"

# R2: three concurrent 401s must produce exactly one refresh. Every hook fires
# on the same events, so an expiry is discovered by several processes at once;
# without a lock each would spend the same refresh token and all but one would
# be rejected.
rf_config "$R1_PORT"
: > "$R1_LOG"
# Wait on these PIDs specifically: a bare `wait` would also wait on the fake
# servers, which never exit.
RF_KIDS=""
for s in rs1 rs2 rs3; do
    rf_recall "$s" >/dev/null 2>&1 &
    RF_KIDS="$RF_KIDS $!"
done
# shellcheck disable=SC2086
wait $RF_KIDS
assert_eq "$(grep -c '^OAUTH_TOKEN' "$R1_LOG")" "1" "refresh: three concurrent 401s cause exactly one refresh"
assert_eq "$(jq -r '.bearer_token' "$MNEMON_DIR/config.json")" "token-2" "refresh: stampede converges on the new token"
rf_stop_all

# R3: invalid_grant is definitive. The refresh token is gone and no amount of
# retrying brings it back, so the device is marked dead and stops asking.
R3_PORT=$(rf_free_port); R3_LOG="$MNEMON_DIR/r3.log"; R3_STATE=$(mktemp -d)
rf_start "$R3_PORT" "$R3_LOG" "$R3_STATE" FAKE_EXPIRE_FIRST=1 FAKE_REFRESH_FAIL=invalid_grant
rf_config "$R3_PORT"
rf_recall r3a >/dev/null
assert_eq "$(jq -r '.auth_state' "$MNEMON_DIR/config.json")" "dead" "refresh: invalid_grant sets auth_state=dead"
assert_eq "$(jq -r '.auth_dead_reason' "$MNEMON_DIR/config.json")" "invalid_grant" "refresh: the reason is recorded"
rf_recall r3b >/dev/null
assert_eq "$(grep -c '^OAUTH_TOKEN' "$R3_LOG")" "1" "refresh: a dead device does not keep retrying"
rf_stop_all

# R4: invalid_client is what a revoked client returns -- and client revocation is
# the per-device kill switch. Treating only invalid_grant as fatal would have had
# every killed device retry the kill switch every prompt, forever.
R4_PORT=$(rf_free_port); R4_LOG="$MNEMON_DIR/r4.log"; R4_STATE=$(mktemp -d)
rf_start "$R4_PORT" "$R4_LOG" "$R4_STATE" FAKE_EXPIRE_FIRST=1 FAKE_REFRESH_FAIL=invalid_client
rf_config "$R4_PORT"
rf_recall r4a >/dev/null
assert_eq "$(jq -r '.auth_state' "$MNEMON_DIR/config.json")" "dead" "refresh: invalid_client sets auth_state=dead"
assert_eq "$(jq -r '.auth_dead_reason' "$MNEMON_DIR/config.json")" "invalid_client" "refresh: a revoked client is recorded as such"
rf_recall r4b >/dev/null
assert_eq "$(grep -c '^OAUTH_TOKEN' "$R4_LOG")" "1" "refresh: a revoked device stops asking"
rf_stop_all

# R5: a proxy page or a 5xx is transient. Discarding a 90-day refresh token
# because a load balancer hiccuped would turn a blip into a re-enrolment.
for mode in garbage 500; do
    R5_PORT=$(rf_free_port); R5_LOG="$MNEMON_DIR/r5.log"; R5_STATE=$(mktemp -d)
    rf_start "$R5_PORT" "$R5_LOG" "$R5_STATE" FAKE_EXPIRE_FIRST=1 FAKE_REFRESH_FAIL="$mode"
    rf_config "$R5_PORT"
    : > "$MNEMON_ERROR_LOG_TEST"
    rf_recall "r5$mode" >/dev/null
    assert_ne "$(jq -r '.auth_state // "ok"' "$MNEMON_DIR/config.json")" "dead" "refresh: $mode is transient, not dead"
    assert_eq "$(jq -r '.refresh_token' "$MNEMON_DIR/config.json")" "refresh-1" "refresh: $mode keeps the refresh token"
    assert_ne "$(grep -c 'refresh failed' "$MNEMON_ERROR_LOG_TEST")" "0" "refresh: $mode is logged as a failure"
    rf_stop_all
done

# R6: a PAT-shaped config must never attempt a refresh. PAT devices are the
# installed base; this release must be inert for them.
R6_PORT=$(rf_free_port); R6_LOG="$MNEMON_DIR/r6.log"; R6_STATE=$(mktemp -d)
rf_start "$R6_PORT" "$R6_LOG" "$R6_STATE" FAKE_STATUS=401
jq -n --arg e "http://127.0.0.1:$R6_PORT/mcp" \
    '{endpoint:$e, bearer_token:"pat-token", recall_timeout_ms:5000}' \
    > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
: > "$MNEMON_ERROR_LOG_TEST"
rf_recall r6 >/dev/null
assert_eq "$(grep -c '^OAUTH_TOKEN' "$R6_LOG")" "0" "refresh: a PAT config never calls the token endpoint"
assert_ne "$(grep -c 'token rejected or expired' "$MNEMON_ERROR_LOG_TEST")" "0" "refresh: a PAT 401 still logs the existing message"

# R6b: the feature switch is refresh_token + client_id, and only a config that
# still has a reachable token endpoint can prove it. A plain PAT config has no
# token_endpoint either, so removing the switch from mnemon_refresh_available
# would produce no request from it regardless -- the test above cannot tell the
# guard from the missing URL.
: > "$R6_LOG"
jq -n --arg e "http://127.0.0.1:$R6_PORT/mcp" --arg t "http://127.0.0.1:$R6_PORT/oauth/token" \
    '{endpoint:$e, token_endpoint:$t, bearer_token:"token-1", client_id:"cid",
      client_secret:"csec", recall_timeout_ms:5000}' \
    > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
rf_recall r6b >/dev/null
assert_eq "$(grep -c '^OAUTH_TOKEN' "$R6_LOG")" "0" "refresh: no refresh_token means no refresh, even with a reachable token endpoint"
rf_stop_all

# R7: credentials from the environment are static. Refreshing would rewrite a
# file nothing reads, so every call would 401, "succeed" at refreshing, and 401
# again -- permanent double round trips that converge never.
R7_PORT=$(rf_free_port); R7_LOG="$MNEMON_DIR/r7.log"; R7_STATE=$(mktemp -d)
rf_start "$R7_PORT" "$R7_LOG" "$R7_STATE" FAKE_EXPIRE_FIRST=1
rf_config "$R7_PORT"
# The env token deliberately matches the config's stored token. With a different
# one, mnemon_refresh would take its "someone else already refreshed" shortcut
# and return without any request -- so the test would pass even with the env
# guard removed, proving nothing.
MNEMON_ENDPOINT="http://127.0.0.1:$R7_PORT/mcp" MNEMON_TOKEN="token-1" rf_recall r7 >/dev/null
assert_eq "$(grep -c '^OAUTH_TOKEN' "$R7_LOG")" "0" "refresh: env credentials disable refresh entirely"
rf_stop_all

# R8: a 200 carrying no usable token must not overwrite working credentials.
R8_PORT=$(rf_free_port); R8_LOG="$MNEMON_DIR/r8.log"; R8_STATE=$(mktemp -d)
rf_start "$R8_PORT" "$R8_LOG" "$R8_STATE" FAKE_EXPIRE_FIRST=1 FAKE_REFRESH_FAIL=empty
rf_config "$R8_PORT"
: > "$MNEMON_ERROR_LOG_TEST"
rf_recall r8 >/dev/null
assert_eq "$(jq -r '.bearer_token' "$MNEMON_DIR/config.json")" "token-1" "refresh: an empty token response is refused"
assert_eq "$(jq -r '.refresh_token' "$MNEMON_DIR/config.json")" "refresh-1" "refresh: an empty token response keeps the old refresh token"
assert_ne "$(grep -c 'refresh failed' "$MNEMON_ERROR_LOG_TEST")" "0" "refresh: an empty token response is logged"
rf_stop_all

# R10: one retry, never a loop. If the retried call 401s too -- a server that
# rejects every token, a clock skew, a scope problem -- an unguarded retry would
# refresh and recurse forever, because each refresh writes a token that then
# matches on the next pass. Bounded by `timeout` so a regression fails the suite
# instead of hanging it.
R10_PORT=$(rf_free_port); R10_LOG="$MNEMON_DIR/r10.log"; R10_STATE=$(mktemp -d)
rf_start "$R10_PORT" "$R10_LOG" "$R10_STATE" FAKE_STATUS=401
rf_config "$R10_PORT"
timeout 25 bash -c "printf '{\"session_id\":\"r10\",\"prompt\":\"a substantive prompt that never succeeds\"}' | '$HOOKS_DIR/mnemon-recall.sh'" >/dev/null 2>&1
r10_rc=$?
assert_ne "$r10_rc" "124" "refresh: a permanently rejecting server does not hang the hook"
assert_eq "$(grep -c '^OAUTH_TOKEN' "$R10_LOG")" "1" "refresh: retries exactly once, never in a loop"
rf_stop_all

# R9: the config rewrite is the riskiest thing these hooks do -- a truncated
# config.json is indistinguishable from an unconfigured machine. A zero-byte
# session-state file already wedged a session for hours once.
# Exact match on the exit code, not a glob: an earlier draft accepted *rc=1*,
# which also matches the 127 bash returns when the function does not exist --
# so the test passed before the function was written.
bad=$(printf 'not json' | bash -c ". \"$HOOKS_DIR/lib/common.sh\"; mnemon_config_write >/dev/null 2>&1; echo \$?" 2>/dev/null | tail -1)
assert_eq "$bad" "1" "config write refuses invalid JSON"
assert_eq "$(jq -e . "$MNEMON_DIR/config.json" >/dev/null 2>&1 && echo valid)" "valid" "config: old file untouched after a refused write"

# --- Wake tests --------------------------------------------------------------

# W1: a dead credential banners at every session start, with the exact command,
# and makes no server call at all. Everything else in these hooks degrades
# quietly by design; this one cannot, because no amount of waiting fixes it.
W1_PORT=$(rf_free_port); W1_LOG="$MNEMON_DIR/w1.log"; W1_STATE=$(mktemp -d)
rf_start "$W1_PORT" "$W1_LOG" "$W1_STATE"
rf_config "$W1_PORT"
jq '. + {auth_state:"dead", auth_dead_reason:"invalid_grant"}' "$MNEMON_DIR/config.json" \
    > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
out=$(printf '{"session_id":"w1","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$out" in
    *"authorization is dead"*) PASS=$((PASS+1)); printf '  ok: wake: a dead credential banners\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: wake: no dead banner, got: %s\n' "$out";;
esac
case "$out" in
    *"mnemon-authorize"*) PASS=$((PASS+1)); printf '  ok: wake: the dead banner names the re-enrolment command\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: wake: dead banner does not say how to fix it\n';;
esac
case "$out" in
    *"<your-instance>"*) FAIL=$((FAIL+1)); printf '  FAIL: wake: dead banner still has a placeholder URL in it\n';;
    *) PASS=$((PASS+1)); printf '  ok: wake: the dead banner names the real instance\n';;
esac
assert_eq "$(wc -c < "$W1_LOG" | tr -d ' ')" "0" "wake: a dead credential makes no server call"
rf_stop_all

# W2: a refresh-capable device must never show the PAT countdown. Its access
# token lives one hour, so days_left is always 0 and every single session would
# open with a false "token has expired" banner. The same JWT with the refresh
# fields removed must still warn -- that contrast is the whole test.
W2_PORT=$(rf_free_port); W2_LOG="$MNEMON_DIR/w2.log"; W2_STATE=$(mktemp -d)
rf_start "$W2_PORT" "$W2_LOG" "$W2_STATE"
rf_config "$W2_PORT"
jq --arg t "$(make_jwt 1800)" '.bearer_token=$t' "$MNEMON_DIR/config.json" \
    > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
out=$(printf '{"session_id":"w2","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$out" in
    *expire*) FAIL=$((FAIL+1)); printf '  FAIL: wake: refresh-capable device showed the expiry countdown\n';;
    *) PASS=$((PASS+1)); printf '  ok: wake: a refresh-capable device shows no expiry countdown\n';;
esac
# Same token, no refresh fields: the countdown must still fire.
jq --arg t "$(make_jwt 1800)" \
    '{endpoint, bearer_token:$t, recall_timeout_ms}' "$MNEMON_DIR/config.json" \
    > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
out=$(printf '{"session_id":"w2b","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$out" in
    *expire*) PASS=$((PASS+1)); printf '  ok: wake: the same token still warns a PAT device\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: wake: PAT countdown regressed, got: %s\n' "$out";;
esac
rf_stop_all

# W4: wake refreshes proactively inside the last 10 minutes. Session start is the
# one place a ~1s refresh is invisible; the per-prompt recall budget is not.
W4_PORT=$(rf_free_port); W4_LOG="$MNEMON_DIR/w4.log"; W4_STATE=$(mktemp -d)
W4_HDR="$MNEMON_DIR/w4-headers.log"; : > "$W4_HDR"
rf_start "$W4_PORT" "$W4_LOG" "$W4_STATE" FAKE_HEADER_LOG="$W4_HDR"
rf_config "$W4_PORT"
jq --arg t "$(make_jwt 300)" '.bearer_token=$t' "$MNEMON_DIR/config.json" \
    > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
printf '{"session_id":"w4","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | "$HOOKS_DIR/mnemon-wake.sh" >/dev/null 2>&1
assert_eq "$(grep -c '^OAUTH_TOKEN' "$W4_LOG")" "1" "wake: refreshes proactively near expiry"
assert_ne "$(grep -c 'Authorization: Bearer token-2' "$W4_HDR")" "0" "wake: the wake call carries the refreshed token"
rf_stop_all

# W4b: a token with plenty of life left is not refreshed at session start.
W4B_PORT=$(rf_free_port); W4B_LOG="$MNEMON_DIR/w4b.log"; W4B_STATE=$(mktemp -d)
rf_start "$W4B_PORT" "$W4B_LOG" "$W4B_STATE"
rf_config "$W4B_PORT"
jq --arg t "$(make_jwt 3000)" '.bearer_token=$t' "$MNEMON_DIR/config.json" \
    > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
printf '{"session_id":"w4b","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | "$HOOKS_DIR/mnemon-wake.sh" >/dev/null 2>&1
assert_eq "$(grep -c '^OAUTH_TOKEN' "$W4B_LOG")" "0" "wake: a healthy token is not refreshed every session"
rf_stop_all

# W5: the unconfigured message points at the device flow, which is now the way in.
mv "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.w5.json"
out=$(printf '{"session_id":"w5","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | env -u MNEMON_ENDPOINT -u MNEMON_TOKEN "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$out" in
    *"mnemon-authorize"*) PASS=$((PASS+1)); printf '  ok: wake: the unconfigured message points at the device flow\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: wake: unconfigured message does not mention enrolment, got: %s\n' "$out";;
esac
mv "$MNEMON_DIR/config.w5.json" "$MNEMON_DIR/config.json"

# --- Enrolment tests ---------------------------------------------------------
# mnemon-authorize.sh is the one place in this system where stdout is guaranteed
# to have a human behind it, so every terminal state has to say what happened and
# exit non-zero. It is also the only script that replaces a working credential,
# which is why the rollback path is tested as carefully as the happy one.
AUTH_SH="$HOOKS_DIR/../scripts/mnemon-authorize.sh"

# A1: the happy path, over an existing PAT config.
A1_PORT=$(rf_free_port); A1_LOG="$MNEMON_DIR/a1.log"; A1_STATE=$(mktemp -d)
rf_start "$A1_PORT" "$A1_LOG" "$A1_STATE" FAKE_DEVICE_MODE=success
jq -n --arg e "http://127.0.0.1:$A1_PORT/mcp" \
    '{endpoint:$e, bearer_token:"old-pat-token", recall_timeout_ms:3000}' \
    > "$MNEMON_DIR/config.json"
chmod 600 "$MNEMON_DIR/config.json"
# The interactive MCP entry may carry the same token; revoking it blindly would
# break that too, so enrolment has to say so.
printf '{"mcpServers":{"mnemon":{"url":"x","headers":{"Authorization":"Bearer old-pat-token"}}}}' \
    > "$MNEMON_DIR/claude.json"
rm -f "$MNEMON_DIR"/config.backup-*.json
a1_out=$(MNEMON_CLIENT_SECRET=csec MNEMON_CLAUDE_JSON="$MNEMON_DIR/claude.json" \
    "$AUTH_SH" "http://127.0.0.1:$A1_PORT" cid 2>&1)
a1_rc=$?
assert_eq "$a1_rc" "0" "authorize: a successful enrolment exits 0"
case "$a1_out" in
    *"BCDF-GHJK"*) PASS=$((PASS+1)); printf '  ok: authorize: the user code is shown grouped for reading aloud\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: authorize: no readable user code in output\n';;
esac
case "$a1_out" in
    *"Waiting for approval"*) PASS=$((PASS+1)); printf '  ok: authorize: says it is waiting\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: authorize: no waiting message\n';;
esac
case "$a1_out" in
    *"Verified"*) PASS=$((PASS+1)); printf '  ok: authorize: reports verification against a real tool call\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: authorize: no verification line\n';;
esac
case "$a1_out" in
    *"cp $MNEMON_DIR/config.backup-"*) PASS=$((PASS+1)); printf '  ok: authorize: prints a copy-pasteable rollback\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: authorize: no rollback line before replacing a working config\n';;
esac
case "$a1_out" in
    *"also used by your MCP server entry"*) PASS=$((PASS+1)); printf '  ok: authorize: warns the old token is shared with the MCP entry\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: authorize: no warning about the shared token\n';;
esac
assert_eq "$(jq -r '.bearer_token' "$MNEMON_DIR/config.json")" "token-2" "authorize: stores the new access token"
assert_eq "$(jq -r '.refresh_token' "$MNEMON_DIR/config.json")" "refresh-2" "authorize: stores the refresh token"
assert_eq "$(jq -r '.client_id' "$MNEMON_DIR/config.json")" "cid" "authorize: stores the client id"
assert_eq "$(jq -r '.auth_state' "$MNEMON_DIR/config.json")" "ok" "authorize: marks the device live"
assert_ne "$(jq -r '.token_endpoint' "$MNEMON_DIR/config.json")" "null" "authorize: stores the token endpoint"
assert_eq "$(jq -r '.recall_timeout_ms' "$MNEMON_DIR/config.json")" "3000" "authorize: preserves existing tunables"
assert_eq "$(stat -c '%a' "$MNEMON_DIR/config.json")" "600" "authorize: the new config is 0600"
a1_backup=$(ls "$MNEMON_DIR"/config.backup-*.json 2>/dev/null | head -1)
assert_eq "$(jq -r '.bearer_token' "$a1_backup" 2>/dev/null)" "old-pat-token" "authorize: the backup holds the replaced token"
assert_eq "$(stat -c '%a' "$a1_backup" 2>/dev/null)" "600" "authorize: the backup is 0600"
rf_stop_all

# A2: a denial changes nothing. The device keeps whatever credential it had.
A2_PORT=$(rf_free_port); A2_LOG="$MNEMON_DIR/a2.log"; A2_STATE=$(mktemp -d)
rf_start "$A2_PORT" "$A2_LOG" "$A2_STATE" FAKE_DEVICE_MODE=denied
jq -n --arg e "http://127.0.0.1:$A2_PORT/mcp" \
    '{endpoint:$e, bearer_token:"keep-me", recall_timeout_ms:3000}' > "$MNEMON_DIR/config.json"
a2_before=$(cat "$MNEMON_DIR/config.json")
a2_out=$(MNEMON_CLIENT_SECRET=csec "$AUTH_SH" "http://127.0.0.1:$A2_PORT" cid 2>&1); a2_rc=$?
assert_eq "$a2_rc" "1" "authorize: a denial exits non-zero"
case "$a2_out" in
    *"denied on the consent screen"*) PASS=$((PASS+1)); printf '  ok: authorize: a denial says so plainly\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: authorize: denial message unclear, got: %s\n' "$a2_out";;
esac
assert_eq "$(cat "$MNEMON_DIR/config.json")" "$a2_before" "authorize: a denial leaves the config byte-identical"
rf_stop_all

# A3: an expired code is recoverable, and the message has to say how.
A3_PORT=$(rf_free_port); A3_LOG="$MNEMON_DIR/a3.log"; A3_STATE=$(mktemp -d)
rf_start "$A3_PORT" "$A3_LOG" "$A3_STATE" FAKE_DEVICE_MODE=expired
a3_before=$(cat "$MNEMON_DIR/config.json")
a3_out=$(MNEMON_CLIENT_SECRET=csec "$AUTH_SH" "http://127.0.0.1:$A3_PORT" cid 2>&1); a3_rc=$?
assert_eq "$a3_rc" "1" "authorize: an expired code exits non-zero"
case "$a3_out" in
    *expired*) PASS=$((PASS+1)); printf '  ok: authorize: an expired code says it expired\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: authorize: expiry message unclear, got: %s\n' "$a3_out";;
esac
case "$a3_out" in
    *"Re-run"*|*"re-run"*) PASS=$((PASS+1)); printf '  ok: authorize: an expired code says how to recover\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: authorize: no recovery instruction for an expired code\n';;
esac
assert_eq "$(cat "$MNEMON_DIR/config.json")" "$a3_before" "authorize: an expired code leaves the config untouched"
rf_stop_all

# A4: a fresh install has nothing to back up and should not pretend otherwise.
A4_PORT=$(rf_free_port); A4_LOG="$MNEMON_DIR/a4.log"; A4_STATE=$(mktemp -d)
rf_start "$A4_PORT" "$A4_LOG" "$A4_STATE" FAKE_DEVICE_MODE=success
rm -f "$MNEMON_DIR/config.json" "$MNEMON_DIR"/config.backup-*.json
a4_out=$(MNEMON_CLIENT_SECRET=csec "$AUTH_SH" "http://127.0.0.1:$A4_PORT" cid 2>&1); a4_rc=$?
assert_eq "$a4_rc" "0" "authorize: a fresh install enrols cleanly"
assert_eq "$(jq -r '.bearer_token' "$MNEMON_DIR/config.json")" "token-2" "authorize: a fresh install writes a complete config"
assert_eq "$(ls "$MNEMON_DIR"/config.backup-*.json 2>/dev/null | wc -l | tr -d ' ')" "0" "authorize: nothing to back up means no backup file"
case "$a4_out" in
    *"cp $MNEMON_DIR/config.backup-"*) FAIL=$((FAIL+1)); printf '  FAIL: authorize: printed a rollback line with no backup to roll back to\n';;
    *) PASS=$((PASS+1)); printf '  ok: authorize: no rollback line when there was nothing to replace\n';;
esac
case "$a4_out" in
    *"ings this device can reach"*) PASS=$((PASS+1)); printf '  ok: authorize: reports the wings the device can reach\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: authorize: no wing report\n';;
esac
rf_stop_all

# A5: a token that is issued but cannot actually do anything must not be reported
# as success. tools/list would pass on a deny-all token; only a real tool call
# distinguishes a working device from a bricked one.
A5_PORT=$(rf_free_port); A5_LOG="$MNEMON_DIR/a5.log"; A5_STATE=$(mktemp -d)
rf_start "$A5_PORT" "$A5_LOG" "$A5_STATE" FAKE_DEVICE_MODE=success FAKE_TOOL_ERROR=1
rm -f "$MNEMON_DIR/config.json"
a5_out=$(MNEMON_CLIENT_SECRET=csec "$AUTH_SH" "http://127.0.0.1:$A5_PORT" cid 2>&1); a5_rc=$?
assert_eq "$a5_rc" "1" "authorize: a token that cannot call a tool is not a success"
case "$a5_out" in
    *"VERIFICATION FAILED"*) PASS=$((PASS+1)); printf '  ok: authorize: a failed verification says so loudly\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: authorize: no verification failure message, got: %s\n' "$a5_out";;
esac
rf_stop_all

# --- Version handshake -------------------------------------------------------
# Nothing told a device it was running old hooks. This machine sat two versions
# behind for days and only manual inspection caught it -- which matters more now
# that the hooks carry credential-refresh logic, because a stale copy fails in
# exactly the ways the new one was written to prevent.
LOCAL_VERSION=$(jq -r .version "$HOOKS_DIR/../.claude-plugin/plugin.json")

# V1: a server on a different version says so, once, with the fix.
V1_PORT=$(rf_free_port); V1_LOG="$MNEMON_DIR/v1.log"; V1_STATE=$(mktemp -d)
rf_start "$V1_PORT" "$V1_LOG" "$V1_STATE" FAKE_PLUGIN_VERSION=9.9.9
jq -n --arg e "http://127.0.0.1:$V1_PORT/mcp" \
    '{endpoint:$e, bearer_token:"t", recall_timeout_ms:5000}' > "$MNEMON_DIR/config.json"
v1_out=$(printf '{"session_id":"v1","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$v1_out" in
    *"9.9.9"*) PASS=$((PASS+1)); printf '  ok: wake: a version mismatch is reported\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: wake: no version mismatch warning, got: %s\n' "$v1_out";;
esac
case "$v1_out" in
    *"claude plugin update mnemon"*) PASS=$((PASS+1)); printf '  ok: wake: the mismatch names the fix\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: wake: mismatch warning does not say how to fix it\n';;
esac
# Parsed, not grepped: mnemon_call writes pretty-printed JSON, so the key and
# its value never share a line.
assert_eq "$(jq -r '.params.arguments.client_version // empty' "$V1_LOG" 2>/dev/null | head -1)" \
    "$LOCAL_VERSION" "wake: the device reports its own version to the server"
rf_stop_all

# V2: matching versions must stay silent. A warning every session start would be
# noise, and noise is how the real warnings stop being read.
V2_PORT=$(rf_free_port); V2_LOG="$MNEMON_DIR/v2.log"; V2_STATE=$(mktemp -d)
rf_start "$V2_PORT" "$V2_LOG" "$V2_STATE" FAKE_PLUGIN_VERSION="$LOCAL_VERSION"
jq -n --arg e "http://127.0.0.1:$V2_PORT/mcp" \
    '{endpoint:$e, bearer_token:"t", recall_timeout_ms:5000}' > "$MNEMON_DIR/config.json"
v2_out=$(printf '{"session_id":"v2","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$v2_out" in
    *"plugin update"*) FAIL=$((FAIL+1)); printf '  FAIL: wake: warned about a version that matches\n';;
    *) PASS=$((PASS+1)); printf '  ok: wake: matching versions stay silent\n';;
esac
rf_stop_all

# V3: an instance too old to report a version must not produce a warning. It
# cannot be judged, so there is nothing honest to say.
V3_PORT=$(rf_free_port); V3_LOG="$MNEMON_DIR/v3.log"; V3_STATE=$(mktemp -d)
rf_start "$V3_PORT" "$V3_LOG" "$V3_STATE"
jq -n --arg e "http://127.0.0.1:$V3_PORT/mcp" \
    '{endpoint:$e, bearer_token:"t", recall_timeout_ms:5000}' > "$MNEMON_DIR/config.json"
v3_out=$(printf '{"session_id":"v3","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$v3_out" in
    *"plugin update"*) FAIL=$((FAIL+1)); printf '  FAIL: wake: warned although the server reported no version\n';;
    *) PASS=$((PASS+1)); printf '  ok: wake: a server that reports no version produces no warning\n';;
esac
rf_stop_all

# --- Double registration -----------------------------------------------------
# A device can end up with the hooks registered twice: once by the plugin and
# once by leftover ~/.claude/settings.json entries from the pre-plugin installer.
# Capture is idempotent so the database shrugs, but recall and wake are not: both
# copies fire concurrently, both read the session state before either writes, and
# the agent gets the same context injected twice for two round trips.
C_PORT=$(rf_free_port); C_LOG="$MNEMON_DIR/c.log"; C_STATE=$(mktemp -d)
rf_start "$C_PORT" "$C_LOG" "$C_STATE"
jq -n --arg e "http://127.0.0.1:$C_PORT/mcp" \
    '{endpoint:$e, bearer_token:"t", recall_timeout_ms:5000}' > "$MNEMON_DIR/config.json"

# C1: two recalls on one prompt deliver context once.
: > "$C_LOG"
C_KIDS=""
for i in 1 2; do
    printf '{"session_id":"dup1","prompt":"a substantive prompt delivered twice over"}' \
        | "$HOOKS_DIR/mnemon-recall.sh" > "$MNEMON_DIR/c-out.$i" 2>/dev/null &
    C_KIDS="$C_KIDS $!"
done
# shellcheck disable=SC2086
wait $C_KIDS
assert_eq "$(grep -l 'Mnemon recall' "$MNEMON_DIR"/c-out.* 2>/dev/null | wc -l | tr -d ' ')" "1" \
    "collision: a doubly-registered recall injects context once"
assert_eq "$(grep -c '"jsonrpc"' "$C_LOG" 2>/dev/null || echo 0)" "1" \
    "collision: a doubly-registered recall makes one round trip"
rm -f "$MNEMON_DIR"/c-out.*

# C2: two wakes on one session start speak once.
C_KIDS=""
for i in 1 2; do
    printf '{"session_id":"dup2","cwd":"/tmp","hook_event_name":"SessionStart"}' \
        | "$HOOKS_DIR/mnemon-wake.sh" > "$MNEMON_DIR/c-wout.$i" 2>/dev/null &
    C_KIDS="$C_KIDS $!"
done
# shellcheck disable=SC2086
wait $C_KIDS
assert_eq "$(grep -l 'Mnemon palace state' "$MNEMON_DIR"/c-wout.* 2>/dev/null | wc -l | tr -d ' ')" "1" \
    "collision: a doubly-registered wake speaks once"
rm -f "$MNEMON_DIR"/c-wout.*

# C3: the claim must expire. SessionStart fires again on resume and after
# compaction, and a hook killed mid-run must not silence the session for good.
find "$MNEMON_DIR/sessions" -maxdepth 1 -name 'dup2.*.claim' -exec touch -d '@1' {} + 2>/dev/null
c3_out=$(printf '{"session_id":"dup2","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$c3_out" in
    *"Mnemon palace state"*) PASS=$((PASS+1)); printf '  ok: collision: a stale claim does not silence the session\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: collision: a stale claim silenced a later legitimate wake\n';;
esac

# C4: preventing the symptom is not the same as reporting the cause. A device
# paying for two round trips per prompt should be told, once, how to stop.
printf '{"hooks":{"SessionStart":[{"hooks":[{"type":"command","command":"/x/mnemon-wake.sh"}]}]}}' \
    > "$MNEMON_DIR/settings-dup.json"
c4_out=$(printf '{"session_id":"dup3","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | env CLAUDE_PLUGIN_ROOT="$HOOKS_DIR/.." MNEMON_CLAUDE_SETTINGS="$MNEMON_DIR/settings-dup.json" \
      "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$c4_out" in
    *"registered twice"*) PASS=$((PASS+1)); printf '  ok: collision: a double registration is reported\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: collision: no warning about a double registration, got: %s\n' "$c4_out";;
esac
printf '{"hooks":{"SessionStart":[{"hooks":[{"type":"command","command":"/x/other.sh"}]}]}}' \
    > "$MNEMON_DIR/settings-clean.json"
c4b_out=$(printf '{"session_id":"dup4","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | env CLAUDE_PLUGIN_ROOT="$HOOKS_DIR/.." MNEMON_CLAUDE_SETTINGS="$MNEMON_DIR/settings-clean.json" \
      "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$c4b_out" in
    *"registered twice"*) FAIL=$((FAIL+1)); printf '  FAIL: collision: warned about a double registration that does not exist\n';;
    *) PASS=$((PASS+1)); printf '  ok: collision: a single registration is not reported\n';;
esac
rf_stop_all

# Test 10: capture hook with no token short-circuits.
rm -f "$MNEMON_DIR/config.json"
out=$(printf '{"session_id":"s4","transcript":[],"turn_index":1}' | "$HOOKS_DIR/mnemon-capture.sh" || true)
assert_eq "$out" "" "capture: no token → silent no-op"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" = 0 ]
