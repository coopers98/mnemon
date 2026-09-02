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

days_left=$(bash -c ". \"$HOOKS_DIR/lib/common.sh\"; mnemon_token_days_left \"$(make_jwt 259200)\"" 2>/dev/null)
assert_eq "$days_left" "3" "token: reads days remaining from the JWT exp"

far=$(bash -c ". \"$HOOKS_DIR/lib/common.sh\"; mnemon_token_days_left \"$(make_jwt 5184000)\"" 2>/dev/null)
assert_eq "$far" "60" "token: reads a distant expiry correctly"

opaque=$(bash -c ". \"$HOOKS_DIR/lib/common.sh\"; mnemon_token_days_left not-a-jwt" 2>/dev/null; echo "rc=$?")
case "$opaque" in
    *rc=1*) PASS=$((PASS+1)); printf '  ok: token: a non-JWT token reports unknown rather than failing\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: token: non-JWT should return non-zero, got [%s]\n' "$opaque";;
esac

# End to end: wake warns when the token is close to expiry.
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.exp.json"
jq --arg t "$(make_jwt 259200)" '.bearer_token=$t' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
warn=$(printf '{"session_id":"s16","cwd":"/tmp","hook_event_name":"SessionStart"}' \
  | "$HOOKS_DIR/mnemon-wake.sh" 2>&1 || true)
case "$warn" in
    *"expire"*) PASS=$((PASS+1)); printf '  ok: wake warns when the token is near expiry\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: wake gave no expiry warning for a token 3 days from expiring\n';;
esac
mv "$MNEMON_DIR/config.exp.json" "$MNEMON_DIR/config.json"

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

# Test 10: capture hook with no token short-circuits.
rm -f "$MNEMON_DIR/config.json"
out=$(printf '{"session_id":"s4","transcript":[],"turn_index":1}' | "$HOOKS_DIR/mnemon-capture.sh" || true)
assert_eq "$out" "" "capture: no token → silent no-op"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" = 0 ]
