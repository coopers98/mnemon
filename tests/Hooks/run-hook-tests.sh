#!/usr/bin/env bash
# Mnemon hook integration tests. Pipe synthetic events into each hook and
# assert on stdout/exit code. Uses a netcat-based fake MCP server.

set -uo pipefail
# Claude Code's relocation of ~/.claude. Inherited, it would point the setup
# script and the doctor at a real config directory instead of the sandboxes.
unset CLAUDE_CONFIG_DIR
# Credentials in the environment win over the sandboxed config.json
# (mnemon_token), so inherited ones -- a plugin session sets them -- would send
# the hooks under test to a real server.
unset MNEMON_ENDPOINT MNEMON_TOKEN CLAUDE_PLUGIN_OPTION_MNEMON_ENDPOINT CLAUDE_PLUGIN_OPTION_MNEMON_TOKEN
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

# Poll until a command succeeds. Args: <seconds> <command...>. Fixed sleeps
# made the suite flaky under load: a worker that had not finished, or a fake
# server not yet listening, read as a failure.
wait_until() {
    local secs="$1" end=$((SECONDS + $1))
    shift
    until "$@"; do
        if [ "$SECONDS" -ge "$end" ]; then
            printf '  note: timed out after %ss waiting for: %s\n' "$secs" "$*"
            return 1
        fi
        sleep 0.05
    done
}
# Whether a jq filter is true of a file. Args: <filter> <file>.
jq_true() { jq -e "$1" "$2" >/dev/null 2>&1; }
# Wait for a fake server to answer. Args: <port>.
wait_server() { wait_until 10 curl -s -m 2 -o /dev/null "http://127.0.0.1:$1/"; }
# Stop a fake server and wait until it has exited, so the next one can bind
# its port.
stop_server() { kill "$@" 2>/dev/null; wait "$@" 2>/dev/null; return 0; }
# Wait until a session has no digest worker running. Args: <session_id>.
#
# Capture starts the worker with nohup ... &, and for a moment after capture
# exits the forked child has not yet exec'd: its command line is still
# capture's, so looking for the worker alone could find nothing and return
# early. A process that still runs mnemon-capture.sh with this suite's
# MNEMON_DIR is that child (Linux /proc; elsewhere only the worker is seen).
_suite_procs() {
    local p
    for p in $(pgrep -f "$1" 2>/dev/null); do
        tr '\0' '\n' < "/proc/$p/environ" 2>/dev/null | grep -qxF "MNEMON_DIR=$MNEMON_DIR" && return 0
    done
    return 1
}
_no_worker() { ! pgrep -f "digest-worker.sh $1 " >/dev/null 2>&1 && ! _suite_procs mnemon-capture.sh; }
wait_worker() { wait_until 30 _no_worker "$1"; }

# Start the fake server in the background.
REQ_LOG="$MNEMON_DIR/requests.log"
: > "$REQ_LOG"
export FAKE_REQUEST_LOG="$REQ_LOG"
"$THIS_DIR/fixtures/server.sh" &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null; rm -rf "$MNEMON_DIR"' EXIT
wait_server "$FAKE_PORT"

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
wait_worker wedged
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
    # Fractional, because that is what Passport actually issues: a real token
    # carries exp like 1796078075.216503. This helper emitted a clean integer,
    # so every expiry test passed while the feature could not read a single real
    # token -- the countdown had never once fired in production.
    exp="$(( $(date +%s) + $1 )).216503"
    payload=$(printf '{"aud":"1","jti":"x","iat":0,"nbf":0,"exp":%s,"sub":"1","scopes":["mcp:use"]}' "$exp" \
        | base64 -w0 | tr '+/' '-_' | tr -d '=')
    printf 'eyJhbGciOiJSUzI1NiJ9.%s.sig' "$payload"
}

# Offset by half a day so the assertions are not on a truncation boundary:
# days_left floors (exp - now) / 86400, so a token generated at exactly N days
# reads as N-1 the moment a second elapses. That passed locally and failed in CI.
days_left=$(bash -c ". \"$HOOKS_DIR/lib/common.sh\"; mnemon_token_days_left \"$(make_jwt 302400)\"" 2>/dev/null)
assert_eq "$days_left" "3" "token: reads days remaining from the JWT exp"

# An integer exp must keep working: the fix truncates, it does not require a dot.
int_exp=$(printf '{"aud":"1","jti":"x","iat":0,"nbf":0,"exp":%s,"sub":"1"}' "$(( $(date +%s) + 302400 ))" \
    | base64 -w0 | tr '+/' '-_' | tr -d '=')
int_days=$(bash -c ". \"$HOOKS_DIR/lib/common.sh\"; mnemon_token_days_left \"h.${int_exp}.s\"" 2>/dev/null)
assert_eq "$int_days" "3" "token: an integer exp still parses"

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
wait_server "$TE_PORT"
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
stop_server $TE_PID
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
wait_server "$SLOW_PORT"
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

stop_server $SLOW_PID
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
wait_worker s7
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
wait_server "$BIG_PORT"
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
wait_worker s9
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
stop_server $BIG_PID
mv "$MNEMON_DIR/config.big.json" "$MNEMON_DIR/config.json"

# Test 10a: capture sends only the turns added since the last digest. The
# protocol carries turn_range{start,end} and the state tracks last_digest_turn,
# but sending the whole transcript every time re-digests content already stored
# and grows without bound.
: > "$BIG_LOG"
FAKE_PORT="$BIG_PORT2" FAKE_REQUEST_LOG="$BIG_LOG" "$THIS_DIR/fixtures/server.sh" &
SLICE_PID=$!
wait_server "$BIG_PORT2"
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
wait_worker s10
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

# Test 10a-project: capture names the project the session ran in. Without it the
# server's digest model guessed the wing from the transcript alone, and most
# drawers in the busiest wings turned out to belong to other projects.
proj_repo="$MNEMON_DIR/checkouts/some-dir-name"
mkdir -p "$proj_repo/sub"
git -C "$proj_repo" init -q 2>/dev/null
git -C "$proj_repo" remote add origin "https://github.com/acme/widget-repo.git"
tp_proj="$MNEMON_DIR/transcript-project.jsonl"
printf '%s\n' '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"PROJECT-TURN"}]}}' > "$tp_proj"

: > "$BIG_LOG"
rm -f "$MNEMON_DIR/sessions/s12.json"
printf '{"session_id":"s12","transcript_path":"%s","hook_event_name":"Stop","cwd":"%s"}' "$tp_proj" "$proj_repo/sub" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s12
for _ in $(seq 1 60); do
    grep -q 'session_digest' "$BIG_LOG" 2>/dev/null && break
    sleep 0.25
done
# Request bodies are logged pretty-printed, one JSON document after another.
sent_project=$(jq -rs '[.[] | select(.params.name? == "session_digest")][0].params.arguments.project // "none"' "$BIG_LOG" 2>/dev/null)
assert_eq "$sent_project" "widget-repo" "capture: sends the repository name as the project"

# A session can run in its own scratchpad, /tmp/claude-<uid>/<cwd-slug>/<id>/
# scratchpad, which is not a repository. The slug is the cwd the scratchpad
# belongs to, with every character outside [A-Za-z0-9] turned into "-" -- here
# both the dashes of some-dir-name and the dot of mktemp's tmp.XXXX.
proj_slug=$(printf '%s' "$proj_repo" | sed 's/[^A-Za-z0-9]/-/g')
scratch="$MNEMON_DIR/tmp/claude-1000/$proj_slug/0b98d350-dd77-46ce-8b07-ccb157830ba5/scratchpad/spike"
mkdir -p "$scratch"
: > "$BIG_LOG"
rm -f "$MNEMON_DIR/sessions/s15.json"
printf '{"session_id":"s15","transcript_path":"%s","hook_event_name":"Stop","cwd":"%s"}' "$tp_proj" "$scratch" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s15
for _ in $(seq 1 60); do
    grep -q 'session_digest' "$BIG_LOG" 2>/dev/null && break
    sleep 0.25
done
sent_project=$(jq -rs '[.[] | select(.params.name? == "session_digest")][0].params.arguments.project // "none"' "$BIG_LOG" 2>/dev/null)
assert_eq "$sent_project" "widget-repo" "capture: a scratchpad session sends the project its scratchpad belongs to"

# Slugs are ambiguous: final/commit and final-commit both slug to final-commit.
# A directory that matches first but leads nowhere must not end the search.
amb="$MNEMON_DIR/amb"
mkdir -p "$amb/final" "$amb/final-commit"
amb_slug=$(printf '%s' "$amb/final-commit" | sed 's/[^A-Za-z0-9]/-/g')
owner=$(bash -c '. "$1/lib/common.sh"; mnemon_scratchpad_owner "$2"' _ "$HOOKS_DIR" \
    "/tmp/claude-1000/$amb_slug/0b98d350-dd77-46ce-8b07-ccb157830ba5/scratchpad")
assert_eq "$owner" "$amb/final-commit" "scratchpad owner: backtracks past a directory that matches a prefix"

# Outside a repository there is no project to name, so none is sent, and no
# project_filing either: the server files project-specific drawers as unfiled.
plain_dir="$MNEMON_DIR/not-a-repo"
mkdir -p "$plain_dir"
: > "$BIG_LOG"
rm -f "$MNEMON_DIR/sessions/s13.json"
printf '{"session_id":"s13","transcript_path":"%s","hook_event_name":"Stop","cwd":"%s"}' "$tp_proj" "$plain_dir" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s13
for _ in $(seq 1 60); do
    grep -q 'session_digest' "$BIG_LOG" 2>/dev/null && break
    sleep 0.25
done
sent_project=$(jq -rs '[.[] | select(.params.name? == "session_digest")][0].params.arguments | if has("project") then "present" else "absent" end' "$BIG_LOG" 2>/dev/null)
assert_eq "$sent_project" "absent" "capture: outside a repository, no project is sent"
sent_filing=$(jq -rs '[.[] | select(.params.name? == "session_digest")][0].params.arguments | if has("project_filing") then "present" else "absent" end' "$BIG_LOG" 2>/dev/null)
assert_eq "$sent_filing" "absent" "capture: outside a repository, no project_filing is sent"

# A repository whose sessions span many projects -- an assistant's workspace --
# is not one project. Filing all of it under one wing would be worse than the
# model's guess, so config.json can name repositories to send no project for.
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.proj-orig.json"
jq '.project_ignore=["widget-repo"]' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
: > "$BIG_LOG"
rm -f "$MNEMON_DIR/sessions/s14.json"
printf '{"session_id":"s14","transcript_path":"%s","hook_event_name":"Stop","cwd":"%s"}' "$tp_proj" "$proj_repo" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s14
for _ in $(seq 1 60); do
    grep -q 'session_digest' "$BIG_LOG" 2>/dev/null && break
    sleep 0.25
done
sent_project=$(jq -rs '[.[] | select(.params.name? == "session_digest")][0].params.arguments | if has("project") then "present" else "absent" end' "$BIG_LOG" 2>/dev/null)
assert_eq "$sent_project" "absent" "capture: a repository in project_ignore sends no project"
# ...and says the omission is deliberate, so the server keeps the model's wing
# instead of filing the drawers as unfiled.
sent_filing=$(jq -rs '[.[] | select(.params.name? == "session_digest")][0].params.arguments.project_filing // "none"' "$BIG_LOG" 2>/dev/null)
assert_eq "$sent_filing" "model" "capture: a repository in project_ignore sends project_filing=model"
mv "$MNEMON_DIR/config.proj-orig.json" "$MNEMON_DIR/config.json"

# A repository in capture_ignore is not digested at all: an assistant's
# workspace answering a heartbeat filed the same checklist every 20 minutes.
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.cap-orig.json"
jq '.capture_ignore=["widget-repo"]' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
: > "$BIG_LOG"
rm -f "$MNEMON_DIR/sessions/s15.json"
printf '{"session_id":"s15","transcript_path":"%s","hook_event_name":"Stop","cwd":"%s"}' "$tp_proj" "$proj_repo" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
sleep 2
digests=$(grep -c 'session_digest' "$BIG_LOG" 2>/dev/null)
assert_eq "${digests:-0}" "0" "capture: a repository in capture_ignore is not digested"
mv "$MNEMON_DIR/config.cap-orig.json" "$MNEMON_DIR/config.json"

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
wait_worker s11
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
stop_server $SLICE_PID

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
wait_worker s8
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
wait_server "$ERR_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.err.json"
jq --arg e "http://127.0.0.1:$ERR_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
: > "$MNEMON_DIR/capture-errors.log"

tp6="$MNEMON_DIR/transcript-err.jsonl"
printf '%s\n' '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"anything at all"}]}}' > "$tp6"
rm -f "$MNEMON_DIR/sessions/s12.json"
printf '{"session_id":"s12","transcript_path":"%s","hook_event_name":"Stop"}' "$tp6" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s12
for _ in $(seq 1 40); do
    grep -qiE 'non-JSON|HTTP [0-9]{3}|too large' "$MNEMON_DIR/capture-errors.log" 2>/dev/null && break
    sleep 0.25
done
if grep -qiE 'non-JSON|HTTP [0-9]{3}|too large' "$MNEMON_DIR/capture-errors.log" 2>/dev/null; then
    PASS=$((PASS+1)); printf '  ok: a proxy error is logged with its status, not as a jq parse error\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: non-JSON response not reported clearly: %s\n' "$(head -1 "$MNEMON_DIR/capture-errors.log" 2>/dev/null)"
fi
stop_server $ERR_PID
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
wait_server "$HDR_PORT"
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
stop_server $HDR_PID
mv "$MNEMON_DIR/config.hdr.json" "$MNEMON_DIR/config.json"

# Test 10d: an expired token is reported. Laravel answers 401 with valid JSON
# carrying "message", so `.error // empty` is empty and `.result // empty` is
# empty -- the call returns nothing with exit 0 and nothing is ever logged. The
# USERGUIDE's "401 errors in capture log" cannot happen while that is true.
AUTH_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
FAKE_PORT="$AUTH_PORT" FAKE_STATUS=401 "$THIS_DIR/fixtures/server.sh" &
AUTH_PID=$!
wait_server "$AUTH_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.auth.json"
jq --arg e "http://127.0.0.1:$AUTH_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
: > "$MNEMON_DIR/capture-errors.log"

tp7="$MNEMON_DIR/transcript-auth.jsonl"
printf '%s\n' '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"anything"}]}}' > "$tp7"
rm -f "$MNEMON_DIR/sessions/s13.json"
printf '{"session_id":"s13","transcript_path":"%s","hook_event_name":"Stop"}' "$tp7" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s13
for _ in $(seq 1 40); do
    [ -s "$MNEMON_DIR/capture-errors.log" ] && break
    sleep 0.25
done
if grep -qiE '401|unautheni?ticated|expired|auth' "$MNEMON_DIR/capture-errors.log" 2>/dev/null; then
    PASS=$((PASS+1)); printf '  ok: an expired token (401) is logged\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: 401 produced no log entry (silent auth failure)\n'
fi
stop_server $AUTH_PID
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

# Test 10g: a digest that did not run must not advance the pointer. The server
# answers a failed model call (a timeout, a 429, a 5xx) with a tool error;
# before 1.0.1 it answered with an empty success, the worker advanced
# last_digest_turn, and those turns were never digested. The worker has to
# treat the error as a failure and leave the pointer where it was, so the next
# Stop sends the same range again.
DE_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
DE_LOG="$MNEMON_DIR/requests-de.log"
: > "$DE_LOG"
FAKE_PORT="$DE_PORT" FAKE_REQUEST_LOG="$DE_LOG" \
  FAKE_TOOL_ERROR="The digest did not run: the model call failed with HTTP 503. Nothing was stored. This is retryable: send the same turn range again." \
  "$THIS_DIR/fixtures/server.sh" &
DE_PID=$!
wait_server "$DE_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.de.json"
jq --arg e "http://127.0.0.1:$DE_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
: > "$MNEMON_DIR/capture-errors.log"

tp_de="$MNEMON_DIR/transcript-digest-error.jsonl"
{
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"already digested"}}'
  printf '%s\n' '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"already digested too"}]}}'
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"a new question about retries"}}'
  printf '%s\n' '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"a new answer"}]}}'
} > "$tp_de"
printf '{"last_digest_turn":2,"last_recall_at":0,"recent_drawer_ids":[],"nomemo":false,"disabled":false}' \
  > "$MNEMON_DIR/sessions/s20.json"
printf '{"session_id":"s20","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$tp_de" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s20
if ! grep -q 'session_digest' "$DE_LOG" 2>/dev/null; then
    FAIL=$((FAIL+1)); printf '  FAIL: capture: the digest never reached the server\n'
else
    assert_eq "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/s20.json")" "2" \
        "capture: a digest that did not run leaves last_digest_turn where it was"
fi
stop_server $DE_PID

# ...and the next Stop sends the same range again, to a server that is back.
: > "$DE_LOG"
FAKE_PORT="$DE_PORT" FAKE_REQUEST_LOG="$DE_LOG" "$THIS_DIR/fixtures/server.sh" &
DE_PID=$!
wait_server "$DE_PORT"
printf '{"session_id":"s20","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$tp_de" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s20
for _ in $(seq 1 40); do
    [ "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/s20.json")" = "4" ] && break
    sleep 0.25
done
retried=$(jq -rs '[.[] | select(.params.name? == "session_digest")][0].params.arguments.turn_range | "\(.start)-\(.end)"' "$DE_LOG" 2>/dev/null)
assert_eq "$retried" "2-4" "capture: after a failed digest the next Stop re-sends the same range"
assert_eq "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/s20.json")" "4" \
    "capture: the retried digest advances last_digest_turn once it succeeds"
stop_server $DE_PID
mv "$MNEMON_DIR/config.de.json" "$MNEMON_DIR/config.json"

# Test 10h: the digest pointer only moves forward. It was written unconditionally,
# so anything holding an older copy of the state -- a recall that read it before
# a digest finished, a worker for an earlier range, SessionStart before its fix
# -- could rewind it, and the next Stop re-digested turns already stored.
bash -c '. "$1/lib/common.sh"
    mnemon_session_state_write mono "{\"last_digest_turn\":50,\"recent_drawer_ids\":[]}"
    mnemon_session_state_write mono "{\"last_digest_turn\":30,\"recent_drawer_ids\":[9]}"' _ "$HOOKS_DIR"
assert_eq "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/mono.json")" "50" \
    "state: writing a lower last_digest_turn does not rewind it"
assert_eq "$(jq -c '.recent_drawer_ids' "$MNEMON_DIR/sessions/mono.json")" "[9]" \
    "state: the rest of a lower-pointer write still lands"
bash -c '. "$1/lib/common.sh"
    mnemon_session_state_write mono "{\"last_digest_turn\":70,\"recent_drawer_ids\":[]}"' _ "$HOOKS_DIR"
assert_eq "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/mono.json")" "70" \
    "state: a higher last_digest_turn still advances"

# Test 10i: two workers for one session make one digest call. The lock was a
# PID file checked and then written -- two workers started together both saw no
# lock, both wrote one, and both digested. mkdir is atomic.
WK_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
WK_LOG="$MNEMON_DIR/requests-wk.log"
: > "$WK_LOG"
FAKE_PORT="$WK_PORT" FAKE_REQUEST_LOG="$WK_LOG" FAKE_DELAY=1 "$THIS_DIR/fixtures/server.sh" &
WK_PID=$!
wait_server "$WK_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.wk.json"
jq --arg e "http://127.0.0.1:$WK_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
rm -rf "$MNEMON_DIR/sessions/s21".*
# Started one after another they rarely collide, which is how the race went
# unnoticed. Each waits on a FIFO instead, and closing it releases all at once.
wk_gate="$MNEMON_DIR/wk.gate"
rm -f "$wk_gate"; mkfifo "$wk_gate"
wk_pids=""
for i in 1 2 3 4 5 6; do
    printf '[{"role":"user","text":"turn"}]' > "$MNEMON_DIR/sessions/s21.in.$i"
    bash -c 'read -r _ < "$1"; shift; exec "$@"' _ "$wk_gate" \
        "$HOOKS_DIR/lib/digest-worker.sh" s21 0 4 "$MNEMON_DIR/sessions/s21.in.$i" "" "" >/dev/null 2>&1 &
    wk_pids="$wk_pids $!"
done
sleep 0.5
: > "$wk_gate"
for p in $wk_pids; do wait "$p" 2>/dev/null; done
rm -f "$wk_gate"
assert_eq "$(grep -c '"session_digest"' "$WK_LOG" 2>/dev/null)" "1" \
    "worker: six workers started together for one session make one digest call"
assert_eq "$(ls -d "$MNEMON_DIR/sessions/s21.digest.lock"* 2>/dev/null | wc -l | tr -d ' ')" "0" \
    "worker: the lock is released when the worker exits"

# A live worker's lock is honoured: a second worker leaves a pending marker and
# makes no call of its own.
: > "$WK_LOG"
mkdir -p "$MNEMON_DIR/sessions/s24.digest.lock.d"
echo "$$" > "$MNEMON_DIR/sessions/s24.digest.lock.d/pid"
printf '[{"role":"user","text":"turn"}]' > "$MNEMON_DIR/sessions/s24.in"
"$HOOKS_DIR/lib/digest-worker.sh" s24 0 4 "$MNEMON_DIR/sessions/s24.in" "" "" >/dev/null 2>&1
assert_eq "$(grep -c '"session_digest"' "$WK_LOG" 2>/dev/null)" "0" \
    "worker: a lock held by a live worker stops a second digest"
assert_eq "$([ -e "$MNEMON_DIR/sessions/s24.in" ] && echo kept || echo removed)" "removed" \
    "worker: the losing worker discards its staged input"
rm -rf "$MNEMON_DIR/sessions/s24.digest.lock.d" "$MNEMON_DIR/sessions/s24.digest.pending"

# A lock left by a worker that died is recovered rather than blocking capture
# for that session forever.
: > "$WK_LOG"
mkdir -p "$MNEMON_DIR/sessions/s22.digest.lock.d"
sh -c 'exit 0' & dead_pid=$!; wait "$dead_pid"
echo "$dead_pid" > "$MNEMON_DIR/sessions/s22.digest.lock.d/pid"
touch -d '5 seconds ago' "$MNEMON_DIR/sessions/s22.digest.lock.d"
printf '[{"role":"user","text":"turn"}]' > "$MNEMON_DIR/sessions/s22.in"
"$HOOKS_DIR/lib/digest-worker.sh" s22 0 4 "$MNEMON_DIR/sessions/s22.in" "" "" >/dev/null 2>&1
assert_eq "$(grep -c '"session_digest"' "$WK_LOG" 2>/dev/null)" "1" \
    "worker: a lock whose owner is gone is recovered"

# So is one too old to belong to a live digest, whoever holds the PID now.
: > "$WK_LOG"
mkdir -p "$MNEMON_DIR/sessions/s23.digest.lock.d"
echo "$$" > "$MNEMON_DIR/sessions/s23.digest.lock.d/pid"
touch -d '30 minutes ago' "$MNEMON_DIR/sessions/s23.digest.lock.d"
printf '[{"role":"user","text":"turn"}]' > "$MNEMON_DIR/sessions/s23.in"
"$HOOKS_DIR/lib/digest-worker.sh" s23 0 4 "$MNEMON_DIR/sessions/s23.in" "" "" >/dev/null 2>&1
assert_eq "$(grep -c '"session_digest"' "$WK_LOG" 2>/dev/null)" "1" \
    "worker: a lock older than the stale limit is recovered"
stop_server $WK_PID
mv "$MNEMON_DIR/config.wk.json" "$MNEMON_DIR/config.json"

# Test 10j: capture sends the conversation and nothing else. The old filter
# dropped records whose .type was tool_use|tool_result|thinking -- which no
# top-level record has -- so 93% of transcript bytes survived it: system-prompt
# snapshots, hook output (Mnemon's own recall and wake text, which was digested
# back into new drawers), CLAUDE.md, attached files, toolUseResult. The fixture
# copies the shapes of real records; the content is invented.
SHAPES="$THIS_DIR/fixtures/transcript-shapes.jsonl"
cleaned=$(bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript "$2"' _ "$HOOKS_DIR" "$SHAPES")
# One record of a shape the cleaner does not expect -- here a .message that is
# a string -- used to make jq exit 5 with no output, which capture read as "no
# conversation": the slice was never sent, the pointer never moved, and
# nothing was logged.
cl_rc=$(bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript "$2" >/dev/null 2>&1; echo $?' _ "$HOOKS_DIR" "$SHAPES")
assert_eq "$cl_rc" "0" "cleaner: a record of an unexpected shape does not fail the slice"
if printf '%s' "$cleaned" | jq -e 'type == "array" and length > 0' >/dev/null 2>&1; then
    PASS=$((PASS+1)); printf '  ok: cleaner: emits a JSON array\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: cleaner: output is not a non-empty JSON array: %s\n' "$(printf '%s' "$cleaned" | head -c 200)"
fi
leaks=$(printf '%s' "$cleaned" | grep -oE '[A-Z]+(-[A-Z]+)*-LEAK' | sort -u | tr '\n' ' ')
assert_eq "$leaks" "" "cleaner: no tool output, attachment, hook output, meta, compact, sidechain or harness text survives"
for kept in USER-WORDS-ONE USER-WORDS-TWO ASSISTANT-WORDS-ONE ASSISTANT-WORDS-TWO ASSISTANT-WORDS-THREE \
    USER-WORDS-AFTER-KEYS QUEUED-WORDS-KEEP QUEUED-BLOCK-WORDS-KEEP KEEP-MID-QUOTE \
    '<system-reminder>QUOTED-TAG-KEEP</system-reminder>' '/widget:deploy COMMAND-ARGS-KEEP to staging' \
    'ghp_short' 'github_pat_short' 'xoxb-1,' 'PASSWORD==expected' '//host:8080@x' \
    'NOTAPASSWORD=visible' '//TODO:fix@later' 'the -----BEGIN RSA PRIVATE KEY----- header'; do
    case "$cleaned" in
        *"$kept"*) PASS=$((PASS+1)); printf '  ok: cleaner: keeps %s\n' "$kept";;
        *) FAIL=$((FAIL+1)); printf '  FAIL: cleaner: lost %s\n' "$kept";;
    esac
done
assert_eq "$(printf '%s' "$cleaned" | grep -o 'DUP-QUEUED-ONCE' | wc -l | tr -d ' ')" "1" \
    "cleaner: a queued message delivered again as the user's turn is kept once"
case "$(printf '%s' "$cleaned" | jq -r '[.[] | select(.role == "user") | .text] | join(" ")')" in
    *"Mnemon recall:"$'\n'*|*"Mnemon palace state"*|*"<system-reminder>"*)
        FAIL=$((FAIL+1)); printf '  FAIL: cleaner: Mnemon recall/wake text reaches the digest\n';;
    *) PASS=$((PASS+1)); printf '  ok: cleaner: Mnemon recall/wake text never reaches the digest\n';;
esac
secrets=$(printf '%s' "$cleaned" | grep -oE 'SECRET[A-Za-z0-9_]*|AKIAABCDEFGHIJKLMNOP|eyJhbGciOiJIUzI1NiJ9|abcdefghijklmnopqrstuvwxyz0123|987654321|b3BlbnNzaC1rZXkt' | sort -u | tr '\n' ' ')
assert_eq "$secrets" "" "cleaner: every secret family is redacted"
for family in AWS-KEY JWT 'Bearer \[REDACTED\]' OPENAI-KEY GITHUB-TOKEN SLACK-TOKEN API-KEY 'PASSWORD=\[REDACTED\]' CREDENTIALS PRIVATE-KEY; do
    if printf '%s' "$cleaned" | grep -qE "$family"; then
        PASS=$((PASS+1)); printf '  ok: cleaner: redaction marker %s present\n' "$family"
    else
        FAIL=$((FAIL+1)); printf '  FAIL: cleaner: no redaction marker %s\n' "$family"
    fi
done
assert_eq "$(printf '%s' "$cleaned" | jq -c '[.[] | keys] | add | unique' 2>/dev/null)" '["role","text","ts"]' \
    "cleaner: each entry carries only role, ts and text"
assert_eq "$(printf '%s' "$cleaned" | jq -c '[.[] | .role]' 2>/dev/null)" \
    '["user","assistant","user","assistant","user","assistant","user"]' \
    "cleaner: consecutive turns of one role are merged; empty ones dropped"

# Common secret forms that passed both sides: a suffix after the keyword, a
# prefix glued to it, lowercase, --flag=, and JSON or YAML keys. The server's
# ContentSanitizer has the same patterns (ContentSanitizerTest).
sa_in="$MNEMON_DIR/transcript-assignments.jsonl"
python3 -c '
import json
texts = [
    "export AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYzzSECRET1 now",
    "PGPASSWORD=pgS3cretValue psql -h db",
    "MYSQL_PWD=myS3cretValue mysql",
    "SECRET_KEY_BASE=a1b2c3d4e5f6railsSecret",
    "GITHUB_TOKEN=v1.0123456789abcdefInstallTok",
    "password=lowerS3cret",
    "mysqldump --password=dashS3cret db",
    "DB_PASSWORD=\"quotedS3cret\"",
    "{\"password\": \"jsonS3cret\", \"user\": \"x\"}",
    "{\"api_key\":\"jsonKeyS3cret\"}",
    "auth:\n  token: yamlTokS3cret\n",
    "Secret: yamlS3cret",
    "KEEP-LOOKALIKES NOSECRET=visible PWD=/root/projects/mnemon OLDPWD=/tmp max_tokens: 4096 \"token_type\": \"Bearer\"",
    # Prose that names a secret without holding one: 24 of the 26 texts the
    # broad rule hit in a real corpus. Each stays exactly as written.
    "token: the refresh flow works like this", "Secret: keep it out of git", "**Token:** rotated hourly",
    "Password: must be 12 chars", "the api_key: is required", "sort by token: asc",
    "next_page_token: none", "TOKEN_LIMIT=4096", "secret=true", "access_token=$ACCESS_TOKEN",
    "token=${GITHUB_TOKEN}", "Token = refresh.token", "MNEMON_TOKEN= (unset)", "max_tokens=4096",
    "MAX_TOKENS=8000", "tokens: 1234 used", "assert PASSWORD==expected",
    "DB_PASSWORD=Ab3}xYz9]q", "DB_PASSWORD=p@ss,w0rd!x",
]
for t in texts:
    print(json.dumps({"type": "user", "message": {"role": "user", "content": "SA-BEFORE " + t}}))
    print(json.dumps({"type": "assistant", "message": {"role": "assistant", "content": [{"type": "text", "text": "ok"}]}}))' > "$sa_in"
sa_out=$(bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript "$2"' _ "$HOOKS_DIR" "$sa_in")
sa_left=$(printf '%s' "$sa_out" | grep -oE 'wJalrXUtnFEMI|pgS3cretValue|myS3cretValue|a1b2c3d4e5f6railsSecret|v1\.0123456789abcdefInstallTok|lowerS3cret|dashS3cret|quotedS3cret|jsonS3cret|jsonKeyS3cret|yamlTokS3cret|yamlS3cret|xYz9|w0rd!x' | sort -u | tr '\n' ' ')
assert_eq "$sa_left" "" "cleaner: suffixed, glued, lowercase, --flag, JSON and YAML secrets are redacted"
assert_eq "$(printf '%s' "$sa_out" | jq '[.[] | select(.role == "user") | .text | test("^SA-BEFORE ")] | all')" "true" \
    "cleaner: redacting an assignment keeps the text around it"
for kept in AWS_SECRET_ACCESS_KEY= PGPASSWORD= MYSQL_PWD= SECRET_KEY_BASE= GITHUB_TOKEN= '--password=' '"password": "' \
    'NOSECRET=visible' 'PWD=/root/projects/mnemon' 'OLDPWD=/tmp' 'max_tokens: 4096' '"token_type": "Bearer"' \
    'token: the refresh flow works like this' 'Secret: keep it out of git' '**Token:** rotated hourly' \
    'Password: must be 12 chars' 'the api_key: is required' 'sort by token: asc' 'next_page_token: none' \
    'TOKEN_LIMIT=4096' 'secret=true' 'access_token=$ACCESS_TOKEN' 'token=${GITHUB_TOKEN}' 'Token = refresh.token' \
    'MNEMON_TOKEN= (unset)' 'max_tokens=4096' 'MAX_TOKENS=8000' 'tokens: 1234 used' 'assert PASSWORD==expected'; do
    case "$(printf '%s' "$sa_out" | jq -r '.[].text')" in
        *"$kept"*) PASS=$((PASS+1)); printf '  ok: cleaner: keeps %s\n' "$kept";;
        *) FAIL=$((FAIL+1)); printf '  FAIL: cleaner: lost %s\n' "$kept";;
    esac
done

# Rule C runs in linear time, and a turn ending in a quoted key stays valid JSON.
sa_big=$(mktemp)
python3 -c '
import json
print(json.dumps({"type": "user", "message": {"role": "user", "content": "token:" * 33334}}))
print(json.dumps({"type": "assistant", "message": {"role": "assistant", "content": [{"type": "text", "text": "ok"}]}}))' > "$sa_big"
sa_t0=$(date +%s.%N)
bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript "$2"' _ "$HOOKS_DIR" "$sa_big" > /dev/null
sa_el=$(awk -v a="$sa_t0" -v b="$(date +%s.%N)" 'BEGIN{print (b-a < 1) ? "fast" : "slow"}')
assert_eq "$sa_el" "fast" "cleaner: 200k characters of repeated 'token:' finish in under a second"
rm -f "$sa_big"
sa_q=$(mktemp)
printf '%s\n' '{"type":"user","message":{"role":"user","content":"the \"token\":"}}' \
    '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"ok"}]}}' > "$sa_q"
assert_eq "$(bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript "$2"' _ "$HOOKS_DIR" "$sa_q" | jq -r '.[0].text')" 'the "token":' \
    "cleaner: a turn ending in a quoted key is unchanged"
rm -f "$sa_q"

# Stdin works too, and a line cut off mid-record (a transcript still being
# written) is skipped rather than failing the whole slice.
partial=$( { cat "$SHAPES"; printf '{"type":"user","message":{"role":"us'; } \
    | bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript' _ "$HOOKS_DIR")
assert_eq "$partial" "$cleaned" "cleaner: reads stdin and skips a truncated last line"

# Regexes over one huge text are quadratic in jq: 4,000 closed system-reminder
# spans took 2s, and 50,000 took minutes -- past the Stop hook's timeout, which
# would wedge the slice for good. Each text is capped before any regex runs,
# and a span the cap cut open is dropped rather than half kept.
huge="$MNEMON_DIR/transcript-huge-text.jsonl"
python3 -c '
import json
t = "HUGE-HEAD-KEEP " + "<system-reminder>CAP-RECALL-LEAK</system-reminder>" * 20000
print(json.dumps({"type": "user", "message": {"role": "user", "content": t}}))' > "$huge"
huge_start=$(date +%s)
huge_out=$(bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript "$2"' _ "$HOOKS_DIR" "$huge")
huge_secs=$(( $(date +%s) - huge_start ))
if [ "$huge_secs" -le 5 ]; then
    PASS=$((PASS+1)); printf '  ok: cleaner: a hostile 1MB text cleans in %ss\n' "$huge_secs"
else
    FAIL=$((FAIL+1)); printf '  FAIL: cleaner: a hostile 1MB text took %ss\n' "$huge_secs"
fi
case "$huge_out" in
    *CAP-RECALL-LEAK*|*system-reminder*) FAIL=$((FAIL+1)); printf '  FAIL: cleaner: capped text leaked a span\n';;
    *HUGE-HEAD-KEEP*"characters cut"*) PASS=$((PASS+1)); printf '  ok: cleaner: a capped text keeps its head and says it was cut\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: cleaner: capped text lost its head or the cut marker: %s\n' "$(printf '%s' "$huge_out" | head -c 120)";;
esac

# A long text keeps its end as well as its start: the decision is usually at
# the end of a long reply. User text is capped after the harness wrappers are
# cut, so a long injected block does not push the user's own words out; and
# assistant text, which is only redacted, has a higher cap.
cap_in="$MNEMON_DIR/transcript-caps.jsonl"
python3 -c '
import json
recs = [
    {"type": "user", "message": {"role": "user", "content": "<system-reminder>" + "r" * 30000 + "</system-reminder>USER-AFTER-INJECTED-BLOCK"}},
    {"type": "assistant", "message": {"role": "assistant", "content": [{"type": "text", "text": "ASSISTANT-START " + "a" * 20000 + " ASSISTANT-UNCUT-END"}]}},
    {"type": "user", "message": {"role": "user", "content": "USER-LONG-START " + "u" * 40000 + " USER-LONG-DECISION-AT-END"}},
]
for r in recs: print(json.dumps(r))' > "$cap_in"
cap_out=$(bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript "$2"' _ "$HOOKS_DIR" "$cap_in")
for kept in USER-AFTER-INJECTED-BLOCK ASSISTANT-UNCUT-END USER-LONG-START USER-LONG-DECISION-AT-END; do
    case "$cap_out" in
        *"$kept"*) PASS=$((PASS+1)); printf '  ok: cleaner cap: keeps %s\n' "$kept";;
        *) FAIL=$((FAIL+1)); printf '  FAIL: cleaner cap: lost %s\n' "$kept";;
    esac
done
assert_eq "$(printf '%s' "$cap_out" | jq '[.[] | select(.role == "assistant") | .text | test("characters cut")] | any')" "false" \
    "cleaner cap: a 20k assistant reply is not cut"

# Private keys in every shape a transcript carries them: plain, encrypted
# (Proc-Type/DEK-Info headers before the body), JSON-escaped (a literal \n for
# each line break), and cut off with no END line. A header named in prose
# stays. Narrowing the pattern to "40 base64 characters right after BEGIN" let
# the encrypted and escaped shapes through.
pem_in="$MNEMON_DIR/transcript-pem.jsonl"
python3 -c '
import json
line = "MIIEowIBAAKCAQEAu1SU1LfVLPHCozMxH2Mo4lgOEePzNm0tRgeLezV6ffAt0gun"
cases = {
    "PEM-PLAIN": "-----BEGIN RSA PRIVATE KEY-----\n%s\n%s\nVGFpbA==\n-----END RSA PRIVATE KEY----- AFTER-PEM-PLAIN" % (line, line),
    "PEM-ENCRYPTED": "-----BEGIN RSA PRIVATE KEY-----\nProc-Type: 4,ENCRYPTED\nDEK-Info: AES-128-CBC,0123456789ABCDEF\n\n%s\nVGFpbA==\n-----END RSA PRIVATE KEY----- AFTER-PEM-ENCRYPTED" % line,
    "PEM-ESCAPED": "-----BEGIN OPENSSH PRIVATE KEY-----\\n%s\\n%s\\n-----END OPENSSH PRIVATE KEY----- AFTER-PEM-ESCAPED" % (line, line),
    "PEM-ESCAPED-CUT": "-----BEGIN OPENSSH PRIVATE KEY-----\\r\\n%s\\r\\n%s" % (line, line),
    "PEM-TRUNCATED": "-----BEGIN EC PRIVATE KEY-----\n%s\n%s" % (line, line),
    "PEM-PROSE": "the -----BEGIN RSA PRIVATE KEY----- header goes first, then the body PROSE-KEPT",
}
for name, text in cases.items():
    for role, body in (("user", name + " " + text), ("assistant", "ok")):
        content = body if role == "user" else [{"type": "text", "text": body}]
        print(json.dumps({"type": role, "message": {"role": role, "content": content}}))
' > "$pem_in"
pem_out=$(bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript "$2"' _ "$HOOKS_DIR" "$pem_in")
pem_leaks=$(printf '%s' "$pem_out" | jq -r '.[] | select(.role == "user") | .text' | grep -cE 'MIIEowIBAAKCAQEA|VGFpbA|DEK-Info')
assert_eq "$pem_leaks" "0" "cleaner: no private key body survives, in any shape"
for kept in PEM-PLAIN AFTER-PEM-PLAIN PEM-ENCRYPTED AFTER-PEM-ENCRYPTED PEM-ESCAPED AFTER-PEM-ESCAPED \
    PEM-ESCAPED-CUT PEM-TRUNCATED 'the -----BEGIN RSA PRIVATE KEY----- header goes first, then the body PROSE-KEPT'; do
    case "$pem_out" in
        *"$kept"*) PASS=$((PASS+1)); printf '  ok: cleaner pem: keeps %s\n' "$kept";;
        *) FAIL=$((FAIL+1)); printf '  FAIL: cleaner pem: lost %s\n' "$kept";;
    esac
done
# A megabyte after a BEGIN line finishes quickly and comes back, not empty.
python3 -c '
import json
print(json.dumps({"type": "user", "message": {"role": "user", "content": "MB-HEAD -----BEGIN RSA PRIVATE KEY----- " + "just some words " * 65536}}))' > "$pem_in"
pem_start=$(date +%s)
pem_big=$(bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript "$2"' _ "$HOOKS_DIR" "$pem_in")
pem_secs=$(( $(date +%s) - pem_start ))
case "$pem_big" in
    *MB-HEAD*) [ "$pem_secs" -le 3 ] && { PASS=$((PASS+1)); printf '  ok: cleaner pem: a megabyte after BEGIN is kept, in %ss\n' "$pem_secs"; } \
        || { FAIL=$((FAIL+1)); printf '  FAIL: cleaner pem: a megabyte after BEGIN took %ss\n' "$pem_secs"; };;
    *) FAIL=$((FAIL+1)); printf '  FAIL: cleaner pem: a megabyte after BEGIN came back empty\n';;
esac

# An inline transcript cannot be resumed part-way, so turns that do not fit
# max_digest_chars are dropped -- and that is logged now. The limit is the
# server's, counted in characters: a byte cut (head -c) split a multi-byte
# character or the JSON itself. Whole turns are trimmed; a lone turn too long
# for the limit has its text cut, and the payload stays valid JSON.
m1_dir=$(mktemp -d); mkdir -p "$m1_dir/sessions"
printf '{"endpoint":"http://127.0.0.1:1/mcp","bearer_token":"t","max_digest_chars":400}' > "$m1_dir/config.json"
python3 -c '
import json
for i in range(1, 7):
    role = "user" if i % 2 else "assistant"
    text = "M1-TURN-%d " % i + "héllo wörld ✓ " * 6
    content = text if role == "user" else [{"type": "text", "text": text}]
    print(json.dumps({"type": role, "message": {"role": role, "content": content}}))' > "$m1_dir/inline.jsonl"
MNEMON_DIR="$m1_dir" bash -c '. "$1/lib/common.sh"; mnemon_digest_text "$2" "$3"' _ "$HOOKS_DIR" "$m1_dir/inline.jsonl" "$m1_dir/out.json" >/dev/null
assert_eq "$(jq -r 'type' "$m1_dir/out.json" 2>/dev/null)" "array" "inline: a payload over max_digest_chars is still a JSON array"
assert_eq "$(jq -r 'tojson | length <= 400' "$m1_dir/out.json" 2>/dev/null)" "true" "inline: the payload fits max_digest_chars, counted in characters"
assert_eq "$(jq -r '[.[].text] | join(" ") | test("M1-TURN-1 ") and (test("M1-TURN-6") | not)' "$m1_dir/out.json" 2>/dev/null)" "true" \
    "inline: the oldest turns are kept and the newest dropped"
if grep -qE 'inline transcript: [0-9]+ newest turn\(s\) over max_digest_chars=400 were not sent' "$m1_dir/capture-errors.log" 2>/dev/null; then
    PASS=$((PASS+1)); printf '  ok: inline: dropped turns are logged\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: inline: dropped turns were not logged: %s\n' "$(cat "$m1_dir/capture-errors.log" 2>/dev/null | head -c 200)"
fi
python3 -c '
import json
print(json.dumps({"type": "user", "message": {"role": "user", "content": "M1-LONE-START " + "ünïcødé ✓ " * 300}}))' > "$m1_dir/lone.jsonl"
: > "$m1_dir/capture-errors.log"
MNEMON_DIR="$m1_dir" bash -c '. "$1/lib/common.sh"; mnemon_digest_text "$2" "$3"' _ "$HOOKS_DIR" "$m1_dir/lone.jsonl" "$m1_dir/out2.json" >/dev/null
assert_eq "$(jq -r 'type' "$m1_dir/out2.json" 2>/dev/null)" "array" "inline: one turn over max_digest_chars is cut, not broken"
assert_eq "$(jq -r '(tojson | length <= 400) and (.[0].text | startswith("M1-LONE-START"))' "$m1_dir/out2.json" 2>/dev/null)" "true" \
    "inline: the cut turn fits and keeps its start"
grep -q 'was cut to fit max_digest_chars=400' "$m1_dir/capture-errors.log" 2>/dev/null \
    && { PASS=$((PASS+1)); printf '  ok: inline: cutting a turn is logged\n'; } \
    || { FAIL=$((FAIL+1)); printf '  FAIL: inline: cutting a turn was not logged\n'; }

# A redaction pattern that fails keeps its text (the turn is not dropped), but
# says so; and the private-key patterns fail closed: a text that still holds a
# key is withheld. MNEMON_CLEAN_FAIL forces a named pattern to fail.
printf '%s\n' '{"type":"user","message":{"role":"user","content":"M2-WORDS eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.c2lnbmF0dXJlLXNlY3JldA"}}' > "$m1_dir/m2.jsonl"
: > "$m1_dir/capture-errors.log"
MNEMON_DIR="$m1_dir" MNEMON_CLEAN_FAIL=jwt bash -c '. "$1/lib/common.sh"; mnemon_digest_text "$2" "$3"' _ "$HOOKS_DIR" "$m1_dir/m2.jsonl" "$m1_dir/out3.json" >/dev/null
assert_eq "$(jq -r '.[0].text | startswith("M2-WORDS")' "$m1_dir/out3.json" 2>/dev/null)" "true" "cleaner: a failed pattern keeps the turn"
grep -q 'redaction pattern jwt failed' "$m1_dir/capture-errors.log" 2>/dev/null \
    && { PASS=$((PASS+1)); printf '  ok: cleaner: a failed pattern is logged\n'; } \
    || { FAIL=$((FAIL+1)); printf '  FAIL: cleaner: a failed pattern was not logged: %s\n' "$(head -c 200 "$m1_dir/capture-errors.log" 2>/dev/null)"; }
python3 -c '
import json
key = "-----BEGIN RSA PRIVATE KEY-----\nMIIEowIBAAKCAQEAu1SU1LfVLPHCozMxH2Mo4lgOEePzNm0tRgeLezV6ffAt0gun\n-----END RSA PRIVATE KEY-----"
print(json.dumps({"type": "user", "message": {"role": "user", "content": "M2-PEM-WORDS " + key + " after"}}))' > "$m1_dir/m2pem.jsonl"
m2_out=$(MNEMON_CLEAN_FAIL=pem bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript "$2" 2>/dev/null' _ "$HOOKS_DIR" "$m1_dir/m2pem.jsonl")
case "$m2_out" in
    *MIIEow*|*"PRIVATE KEY-----"*) FAIL=$((FAIL+1)); printf '  FAIL: cleaner: a failed private-key pattern let the key through\n';;
    *) PASS=$((PASS+1)); printf '  ok: cleaner: a failed private-key pattern withholds the text\n';;
esac
case "$m1_dir" in /tmp/*|"${TMPDIR:-/tmp}"/*) rm -rf "${m1_dir:?}" ;; esac

# A key with no END line whose body runs past the 32,000-character scan cut:
# the start of the text is redacted from BEGIN on, and the end -- more key
# body -- has to be as well. It kept 12,800 characters of it.
m3_in="$MNEMON_DIR/transcript-pem-split.jsonl"
python3 -c '
import json
line = "MIIEowIBAAKCAQEAu1SU1LfVLPHCozMxH2Mo4lgOEePzNm0tRgeLezV6ffAt0gun\n"
text = "M3-PREFIX-KEEP -----BEGIN RSA PRIVATE KEY-----\n" + line * 750
print(json.dumps({"type": "assistant", "message": {"role": "assistant", "content": [{"type": "text", "text": text}]}}))' > "$m3_in"
m3_out=$(bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript "$2"' _ "$HOOKS_DIR" "$m3_in")
case "$m3_out" in
    *MIIEow*) FAIL=$((FAIL+1)); printf '  FAIL: cleaner pem: an END-less key split by the cut leaks its end\n';;
    *M3-PREFIX-KEEP*) PASS=$((PASS+1)); printf '  ok: cleaner pem: an END-less key split by the cut is redacted on both sides\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: cleaner pem: lost the text before the key\n';;
esac

# End to end: what capture actually sends.
CL_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
CL_LOG="$MNEMON_DIR/requests-cl.log"
: > "$CL_LOG"
FAKE_PORT="$CL_PORT" FAKE_REQUEST_LOG="$CL_LOG" "$THIS_DIR/fixtures/server.sh" &
CL_PID=$!
wait_server "$CL_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.cl.json"
jq --arg e "http://127.0.0.1:$CL_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
rm -f "$MNEMON_DIR/sessions/s25.json"
printf '{"session_id":"s25","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$SHAPES" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s25
# The state itself, not the request: the worker writes the pointer only once
# the reply is in.
wait_until 30 jq_true ".last_digest_turn == $(wc -l < "$SHAPES" | tr -d ' ')" "$MNEMON_DIR/sessions/s25.json"
sent=$(jq -rs '[.[] | select(.params.name? == "session_digest")][0].params.arguments.transcript // ""' "$CL_LOG" 2>/dev/null)
assert_eq "$sent" "$cleaned" "capture: sends exactly the cleaned transcript"
assert_eq "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/s25.json")" "$(wc -l < "$SHAPES" | tr -d ' ')" \
    "capture: the pointer still counts raw transcript lines"
stop_server $CL_PID
mv "$MNEMON_DIR/config.cl.json" "$MNEMON_DIR/config.json"

# A slice with nothing left after cleaning -- only tool calls and hook output --
# sends nothing and moves the pointer past it. It starts at last_digest_turn,
# so nothing before it is undigested; leaving the pointer made every Stop in a
# tool-only stretch re-read an ever-growing tail.
: > "$CL_LOG"
FAKE_PORT="$CL_PORT" FAKE_REQUEST_LOG="$CL_LOG" "$THIS_DIR/fixtures/server.sh" &
CL_PID=$!
wait_server "$CL_PORT"
jq --arg e "http://127.0.0.1:$CL_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
tp_empty="$MNEMON_DIR/transcript-noconv.jsonl"
grep -E '"type":"(attachment|queue-operation|last-prompt)"' "$SHAPES" | grep -v queued_command > "$tp_empty"
rm -f "$MNEMON_DIR/sessions/s26.json"
printf '{"session_id":"s26","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$tp_empty" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s26
sleep 1.5
assert_eq "$(grep -c 'session_digest' "$CL_LOG" 2>/dev/null)" "0" "capture: a slice with no conversation sends no digest"
assert_eq "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/s26.json" 2>/dev/null)" "$(wc -l < "$tp_empty" | tr -d ' ')" \
    "capture: ...and moves the pointer past it"

# ...unless a digest is in flight: it may fail and must resend from where it
# started, so the empty range is handed to it instead of skipped past.
rm -f "$MNEMON_DIR/sessions/s27.json" "$MNEMON_DIR/sessions/s27.digest.pending"
mkdir -p "$MNEMON_DIR/sessions/s27.digest.lock.d"; echo "$$" > "$MNEMON_DIR/sessions/s27.digest.lock.d/pid"
printf '{"session_id":"s27","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$tp_empty" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
for _ in $(seq 1 40); do [ -e "$MNEMON_DIR/sessions/s27.digest.pending" ] && break; sleep 0.1; done
assert_eq "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/s27.json" 2>/dev/null)" "0" \
    "capture: an empty slice does not move the pointer while a digest is in flight"
assert_eq "$(jq -r '.end' "$MNEMON_DIR/sessions/s27.digest.pending" 2>/dev/null)" "$(wc -l < "$tp_empty" | tr -d ' ')" \
    "capture: ...it leaves the range for that digest to finish"
rm -rf "$MNEMON_DIR/sessions/s27".*
stop_server $CL_PID
mv "$MNEMON_DIR/config.cl.json" "$MNEMON_DIR/config.json" 2>/dev/null

# Test 10k: a state write that is not a JSON object is refused. An empty $json
# made jq exit 0 with no output and an empty file was moved into place; garbage
# was written raw. Either way the next read reinitialised the state, which
# reset last_digest_turn to 0 and cleared @nomemo.
printf '{"last_digest_turn":40,"last_recall_at":0,"recent_drawer_ids":[3],"nomemo":true,"disabled":false}' \
  > "$MNEMON_DIR/sessions/s28.json"
bash -c '. "$1/lib/common.sh"; mnemon_session_state_write s28 ""; mnemon_session_state_write s28 "not json"; mnemon_session_state_write s28 "[1,2]"' _ "$HOOKS_DIR"
assert_eq "$(jq -c '[.last_digest_turn, .nomemo]' "$MNEMON_DIR/sessions/s28.json" 2>/dev/null)" "[40,true]" \
    "state: an empty, garbage or non-object write leaves last_digest_turn and nomemo intact"

# mnemon_session_state_update applies a change to the state on disk at write
# time, so a writer's stale copy cannot undo another's change.
bash -c '. "$1/lib/common.sh"; mnemon_session_state_update s28 ".recent_drawer_ids += \$ids" --argjson ids "[9]"' _ "$HOOKS_DIR"
assert_eq "$(jq -c '[.last_digest_turn, .nomemo, .recent_drawer_ids]' "$MNEMON_DIR/sessions/s28.json")" "[40,true,[3,9]]" \
    "state: an update changes only what it names"

# A digest whose result has no structuredContent used to leave persisted_ids
# empty, so --argjson failed and the state written was "".
NS_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
FAKE_PORT="$NS_PORT" FAKE_NO_STRUCTURED=1 "$THIS_DIR/fixtures/server.sh" &
NS_PID=$!
wait_server "$NS_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.ns.json"
jq --arg e "http://127.0.0.1:$NS_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
printf '{"last_digest_turn":0,"last_recall_at":0,"recent_drawer_ids":[5],"nomemo":false,"disabled":false}' \
  > "$MNEMON_DIR/sessions/s29.json"
printf '[{"role":"user","text":"turn"}]' > "$MNEMON_DIR/sessions/s29.in"
"$HOOKS_DIR/lib/digest-worker.sh" s29 0 4 "$MNEMON_DIR/sessions/s29.in" "" "" >/dev/null 2>&1
assert_eq "$(jq -c '[.last_digest_turn, .recent_drawer_ids]' "$MNEMON_DIR/sessions/s29.json" 2>/dev/null)" "[4,[5]]" \
    "worker: a result without structuredContent still advances and keeps the state"
stop_server $NS_PID
mv "$MNEMON_DIR/config.ns.json" "$MNEMON_DIR/config.json"

# Test 10l: a Stop that arrives while a digest runs is not thrown away. The
# losing worker used to exit and trust "the next Stop" -- and when the user had
# one more reply and then quit, there was none, and the last turns were never
# digested.
PD_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
PD_LOG="$MNEMON_DIR/requests-pd.log"
: > "$PD_LOG"
FAKE_PORT="$PD_PORT" FAKE_REQUEST_LOG="$PD_LOG" FAKE_DELAY=2 "$THIS_DIR/fixtures/server.sh" &
PD_PID=$!
wait_server "$PD_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.pd.json"
jq --arg e "http://127.0.0.1:$PD_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
tp_pd="$MNEMON_DIR/transcript-pending.jsonl"
{
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"FIRST-RANGE question"}}'
  printf '%s\n' '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"FIRST-RANGE answer"}]}}'
} > "$tp_pd"
rm -rf "$MNEMON_DIR/sessions/s30".*
printf '{"session_id":"s30","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$tp_pd" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_until 10 test -d "$MNEMON_DIR/sessions/s30.digest.lock.d"
{
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"FINAL-RANGE one more thing"}}'
  printf '%s\n' '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"FINAL-RANGE done"}]}}'
} >> "$tp_pd"
printf '{"session_id":"s30","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$tp_pd" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s30
# ...and then the session ends: no further Stop.
for _ in $(seq 1 60); do
    [ "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/s30.json" 2>/dev/null)" = "4" ] && break
    sleep 0.25
done
pd_ranges=$(jq -rs '[.[] | select(.params.name? == "session_digest") | .params.arguments.turn_range | "\(.start)-\(.end)"] | join(",")' "$PD_LOG" 2>/dev/null)
assert_eq "$pd_ranges" "0-2,2-4" "worker: a range left while a digest ran is digested when it finishes"
assert_eq "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/s30.json" 2>/dev/null)" "4" \
    "worker: ...and the pointer reaches the end of the session"
stop_server $PD_PID
mv "$MNEMON_DIR/config.pd.json" "$MNEMON_DIR/config.json"

# Test 10m: a slice too big for one digest goes in chunks, oldest first, with
# no gap and nothing dropped. The old caps kept the newest records and dropped
# the rest; then a failure-triggered "shrink" lost the tail of a session that
# ended. Each chunk ends on a line, the next starts there, and the last ends at
# the end of the transcript -- the same boundaries on every retry.
TR_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
TR_LOG="$MNEMON_DIR/requests-tr.log"
: > "$TR_LOG"
FAKE_PORT="$TR_PORT" FAKE_REQUEST_LOG="$TR_LOG" "$THIS_DIR/fixtures/server.sh" &
TR_PID=$!
wait_server "$TR_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.tr.json"
jq --arg e "http://127.0.0.1:$TR_PORT/mcp" '.endpoint=$e | .digest_chunk_chars=1000' \
  "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
tp_tr="$MNEMON_DIR/transcript-chunks.jsonl"
: > "$tp_tr"
for i in $(seq 1 20); do
    printf '{"type":"user","message":{"role":"user","content":"CHUNK-TURN-%s %s"}}\n' "$i" "$(head -c 150 /dev/zero | tr '\0' 'z')" >> "$tp_tr"
    printf '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"reply %s"}]}}\n' "$i" >> "$tp_tr"
done
rm -f "$MNEMON_DIR/sessions/s31".*
printf '{"session_id":"s31","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$tp_tr" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s31
for _ in $(seq 1 80); do
    [ "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/s31.json" 2>/dev/null)" = "40" ] && break
    sleep 0.25
done
tr_ranges=$(jq -rs '[.[] | select(.params.name? == "session_digest") | .params.arguments.turn_range] | map("\(.start)-\(.end)") | join(",")' "$TR_LOG")
tr_ok=$(jq -rs '[.[] | select(.params.name? == "session_digest") | .params.arguments.turn_range]
    | (length > 1) and (.[0].start == 0) and (.[-1].end == 40)
      and ([range(1; length) as $i | .[$i].start == .[$i - 1].end] | all)' "$TR_LOG")
assert_eq "$tr_ok" "true" "chunking: oldest first, contiguous, to the end ($tr_ranges)"
tr_turns=$(jq -rs '[.[] | select(.params.name? == "session_digest") | .params.arguments.transcript | fromjson | .[] | .text | scan("CHUNK-TURN-[0-9]+")] | unique | length' "$TR_LOG")
assert_eq "$tr_turns" "20" "chunking: every turn is sent, none dropped"
assert_eq "$(jq -rs '[.[] | select(.params.name? == "session_digest") | .params.arguments.transcript | length] | max <= 1000' "$TR_LOG")" "true" \
    "chunking: no chunk is over digest_chunk_chars"
stop_server $TR_PID
mv "$MNEMON_DIR/config.tr.json" "$MNEMON_DIR/config.json"

# A cleaning failure is logged, not read as "no conversation".
: > "$MNEMON_DIR/capture-errors.log"
dt_rc=$(bash -c '. "$1/lib/common.sh"; mnemon_digest_text "$2/does-not-exist.jsonl" "$2/dt.out"; echo $?' _ "$HOOKS_DIR" "$MNEMON_DIR")
assert_eq "$dt_rc" "2" "capture: a cleaning failure is reported as a failure"
if grep -q 'cleaning failed' "$MNEMON_DIR/capture-errors.log" 2>/dev/null; then
    PASS=$((PASS+1)); printf '  ok: capture: a cleaning failure is logged\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: capture: a cleaning failure was silent\n'
fi

# Test 10n: repeated failures back off, exponentially. Every Stop used to retry
# a digest that kept failing; then a fixed 30 minutes after three failures lost
# the end of any session that finished inside the window.
BO_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
BO_LOG="$MNEMON_DIR/requests-bo.log"
: > "$BO_LOG"
FAKE_PORT="$BO_PORT" FAKE_REQUEST_LOG="$BO_LOG" \
  FAKE_TOOL_ERROR="Retryable: the digest did not run (the model call failed with HTTP 429). Nothing was stored." \
  "$THIS_DIR/fixtures/server.sh" &
BO_PID=$!
wait_server "$BO_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.bo.json"
jq --arg e "http://127.0.0.1:$BO_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
tp_bo="$MNEMON_DIR/transcript-backoff.jsonl"
# Two lines: a one-line chunk that keeps failing is skipped (see below).
printf '%s\n' '{"type":"user","message":{"role":"user","content":"a turn that will not digest"}}' \
    '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"nor this"}]}}' > "$tp_bo"
rm -rf "$MNEMON_DIR/sessions/s32".*
: > "$MNEMON_DIR/capture-errors.log"
bo_stop() {  # $1 = session, $2 = transcript
    printf '{"session_id":"%s","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$1" "$2" \
      | "$HOOKS_DIR/mnemon-capture.sh" || true
    # The worker is detached, and already started when capture exits (a Stop
    # inside a backoff wait starts none): wait for it to finish.
    wait_worker "$1"
}
bo_wait_retry() {  # seconds the session waits before its next attempt
    jq -r '((.digest_retry_after // 0) - now) | round' "$MNEMON_DIR/sessions/$1.json"
}
bo_stop s32 "$tp_bo"
assert_eq "$(grep -c session_digest "$BO_LOG")" "1" "backoff: the first attempt is sent"
bo_stop s32 "$tp_bo"
assert_eq "$(grep -c session_digest "$BO_LOG")" "2" "backoff: one failure is retried on the next Stop"
w=$(bo_wait_retry s32)
if [ "$w" -ge 55 ] && [ "$w" -le 60 ]; then
    PASS=$((PASS+1)); printf '  ok: backoff: a second failure waits about a minute (%ss)\n' "$w"
else
    FAIL=$((FAIL+1)); printf '  FAIL: backoff: a second failure should wait about 60s, waits %s\n' "$w"
fi
bo_stop s32 "$tp_bo"
assert_eq "$(grep -c session_digest "$BO_LOG")" "2" "backoff: a Stop inside the wait does not retry"
assert_eq "$(jq -r '.end' "$MNEMON_DIR/sessions/s32.digest.pending" 2>/dev/null)" "2" \
    "backoff: ...but records its range, so the end of the session is not lost"
if grep -q 'retrying in 60s' "$MNEMON_DIR/capture-errors.log" 2>/dev/null; then
    PASS=$((PASS+1)); printf '  ok: backoff: the wait is logged\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: backoff: the wait was silent\n'
fi
jq '.digest_retry_after = 0' "$MNEMON_DIR/sessions/s32.json" > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/sessions/s32.json"
bo_stop s32 "$tp_bo"
assert_eq "$(grep -c session_digest "$BO_LOG")" "3" "backoff: after the wait it retries"
w=$(bo_wait_retry s32)
if [ "$w" -ge 115 ] && [ "$w" -le 120 ]; then
    PASS=$((PASS+1)); printf '  ok: backoff: the wait doubles (%ss)\n' "$w"
else
    FAIL=$((FAIL+1)); printf '  FAIL: backoff: the third failure should wait about 120s, waits %s\n' "$w"
fi
jq '.digest_failures = 12' "$MNEMON_DIR/sessions/s32.json" > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/sessions/s32.json"
jq '.digest_retry_after = 0' "$MNEMON_DIR/sessions/s32.json" > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/sessions/s32.json"
bo_stop s32 "$tp_bo"
w=$(bo_wait_retry s32)
if [ "$w" -ge 1795 ] && [ "$w" -le 1800 ]; then
    PASS=$((PASS+1)); printf '  ok: backoff: the wait is capped at 30 minutes\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: backoff: the wait should cap at 1800s, waits %s\n' "$w"
fi
stop_server $BO_PID

# A failure that will not clear by retrying waits the full 30 minutes at once.
: > "$BO_LOG"
FAKE_PORT="$BO_PORT" FAKE_REQUEST_LOG="$BO_LOG" \
  FAKE_TOOL_ERROR="Not retryable: the digest did not run (OPENAI_API_KEY is not set on the server). Nothing was stored." \
  "$THIS_DIR/fixtures/server.sh" &
BO_PID=$!
wait_server "$BO_PORT"
rm -rf "$MNEMON_DIR/sessions/s32".*
bo_stop s32 "$tp_bo"; bo_stop s32 "$tp_bo"
assert_eq "$(grep -c session_digest "$BO_LOG")" "1" "backoff: a not-retryable failure is not retried on the next Stop"
w=$(bo_wait_retry s32)
[ "$w" -ge 1795 ] && PASS=$((PASS+1)) && printf '  ok: backoff: a not-retryable failure waits 30 minutes\n' \
    || { FAIL=$((FAIL+1)); printf '  FAIL: backoff: a not-retryable failure waits %s\n' "$w"; }
stop_server $BO_PID

# A retry after a failure sends exactly the same range: chunk boundaries come
# from the file, the pointer and the chunk size alone. (The server recognises a
# range it already stored from its start, so a call that timed out here but
# committed there is not digested twice.)
stop_server $BO_PID
: > "$BO_LOG"
FAKE_PORT="$BO_PORT" FAKE_REQUEST_LOG="$BO_LOG" \
  FAKE_TOOL_ERROR="Retryable: the digest did not run (the model call timed out or could not connect). Nothing was stored." \
  "$THIS_DIR/fixtures/server.sh" &
BO_PID=$!
wait_server "$BO_PORT"
jq '.digest_chunk_chars = 400' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
tp_sh="$MNEMON_DIR/transcript-retry.jsonl"
: > "$tp_sh"
for i in 1 2 3 4; do
    printf '{"type":"user","message":{"role":"user","content":"retry turn %s %s"}}\n' "$i" "$(head -c 120 /dev/zero | tr '\0' 'r')" >> "$tp_sh"
    printf '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"reply %s %s"}]}}\n' "$i" "$(head -c 120 /dev/zero | tr '\0' 'a')" >> "$tp_sh"
done
rm -f "$MNEMON_DIR/sessions/s34".*
bo_stop s34 "$tp_sh"
jq '.digest_retry_after = 0' "$MNEMON_DIR/sessions/s34.json" > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/sessions/s34.json"
bo_stop s34 "$tp_sh"
sh_ranges=$(jq -rs '[.[] | select(.params.name? == "session_digest") | .params.arguments.turn_range | "\(.start)-\(.end)"] | join(",")' "$BO_LOG" 2>/dev/null)
case "$sh_ranges" in
    0-[0-9]*,0-[0-9]*) [ "${sh_ranges%%,*}" = "${sh_ranges##*,}" ] \
        && { PASS=$((PASS+1)); printf '  ok: retry: a failed chunk is resent as exactly the same range (%s)\n' "$sh_ranges"; } \
        || { FAIL=$((FAIL+1)); printf '  FAIL: retry: the retried range changed: %s\n' "$sh_ranges"; };;
    *) FAIL=$((FAIL+1)); printf '  FAIL: retry: expected two sends of one range, got %s\n' "$sh_ranges";;
esac
case "$sh_ranges" in
    *-8,*|*-8) FAIL=$((FAIL+1)); printf '  FAIL: retry: the chunk should stop short of the end with chunk size 400\n';;
    *) PASS=$((PASS+1)); printf '  ok: retry: the chunk is the oldest part only\n';;
esac
assert_eq "$(jq -r '.end' "$MNEMON_DIR/sessions/s34.digest.pending" 2>/dev/null)" "8" \
    "retry: the rest of the range is carried in the pending marker"

# A one-line chunk that fails only because the server or the model is down is
# never skipped: an outage must not cost a line of the conversation. Three
# timeouts in a row, then the server recovers: the line is sent.
: > "$MNEMON_DIR/capture-errors.log"
tp_one="$MNEMON_DIR/transcript-one-bad-line.jsonl"
{
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"BAD-LINE that always fails"}}'
  printf '%s\n' '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"GOOD-LINE after it"}]}}'
} > "$tp_one"
jq '.digest_chunk_chars = 10' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
rm -f "$MNEMON_DIR/sessions/s35".*
for _ in 1 2 3; do
    bo_stop s35 "$tp_one"
    jq '.digest_retry_after = 0' "$MNEMON_DIR/sessions/s35.json" > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/sessions/s35.json"
done
assert_eq "$(jq -r '.digest_failed_kind' "$MNEMON_DIR/sessions/s35.json")" "transient" \
    "skip: a model timeout is recorded as a transient failure"
assert_eq "$(jq -r '.digest_payload_failures // 0' "$MNEMON_DIR/sessions/s35.json")" "0" \
    "skip: a transient failure does not count towards skipping the line"
stop_server $BO_PID
: > "$BO_LOG"
FAKE_PORT="$BO_PORT" FAKE_REQUEST_LOG="$BO_LOG" "$THIS_DIR/fixtures/server.sh" &
BO_PID=$!
wait_server "$BO_PORT"
bo_stop s35 "$tp_one"
for _ in $(seq 1 40); do [ "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/s35.json")" = "2" ] && break; sleep 0.1; done
skip_sent=$(jq -rs '[.[] | select(.params.name? == "session_digest") | "\(.params.arguments.turn_range.start)-\(.params.arguments.turn_range.end)"] | join(",")' "$BO_LOG")
assert_eq "$skip_sent" "0-1,1-2" "skip: a line that failed only in an outage is sent, not skipped"
if grep -q 'skipping it' "$MNEMON_DIR/capture-errors.log"; then
    FAIL=$((FAIL+1)); printf '  FAIL: skip: a line was skipped after transient failures\n'
else
    PASS=$((PASS+1)); printf '  ok: skip: nothing is skipped after transient failures\n'
fi
stop_server $BO_PID

# A line the server keeps failing on for what it holds -- here the model's
# answer to it is not a proposals list -- is skipped, logged, once it has been
# tried a third time and failed again. The rest is sent.
: > "$BO_LOG"
: > "$MNEMON_DIR/capture-errors.log"
FAKE_PORT="$BO_PORT" FAKE_REQUEST_LOG="$BO_LOG" \
  FAKE_TOOL_ERROR="Retryable: the digest did not run (the model answered with content that is not a proposals list). Nothing was stored; send the same start again, with the same or a later end." \
  "$THIS_DIR/fixtures/server.sh" &
BO_PID=$!
wait_server "$BO_PORT"
rm -f "$MNEMON_DIR/sessions/s45".*
for _ in 1 2; do
    bo_stop s45 "$tp_one"
    jq '.digest_retry_after = 0' "$MNEMON_DIR/sessions/s45.json" > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/sessions/s45.json"
done
pl_sent=$(jq -rs '[.[] | select(.params.name? == "session_digest") | "\(.params.arguments.turn_range.start)-\(.params.arguments.turn_range.end)"] | join(",")' "$BO_LOG")
assert_eq "$pl_sent" "0-1,0-1" "skip: a payload failure is retried before anything is skipped"
assert_eq "$(jq -r '.digest_failed_kind' "$MNEMON_DIR/sessions/s45.json")" "payload" \
    "skip: unparseable model output is recorded as a payload failure"
bo_stop s45 "$tp_one"
pl_sent=$(jq -rs '[.[] | select(.params.name? == "session_digest") | "\(.params.arguments.turn_range.start)-\(.params.arguments.turn_range.end)"] | join(",")' "$BO_LOG")
assert_eq "$pl_sent" "0-1,0-1,0-1,1-2" "skip: the third payload failure skips the line, and the next is sent"
assert_eq "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/s45.json")" "1" "skip: the pointer moves past the skipped line only"
if grep -q 'line 1 failed 3 times in a row on what it holds; skipping it' "$MNEMON_DIR/capture-errors.log"; then
    PASS=$((PASS+1)); printf '  ok: skip: the skipped line is logged\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: skip: the skipped line was not logged\n'
fi
stop_server $BO_PID

# Which failures the payload can cause. A 413 and a validation error can; a
# 5xx, a 401 and a "Not retryable:" cannot.
for kc in "FAKE_STATUS=413|payload" "FAKE_STATUS=503|transient" "FAKE_STATUS=401|transient" \
    "FAKE_TOOL_ERROR=The transcript field must not be greater than 200000 characters.|payload" \
    "FAKE_TOOL_ERROR=Not retryable: the digest did not run (OPENAI_API_KEY is not set on the server).|not_retryable"; do
    : > "$BO_LOG"
    env "${kc%|*}" FAKE_PORT="$BO_PORT" FAKE_REQUEST_LOG="$BO_LOG" "$THIS_DIR/fixtures/server.sh" &
    BO_PID=$!
    wait_server "$BO_PORT"
    rm -f "$MNEMON_DIR/sessions/s46".*
    bo_stop s46 "$tp_one"
    assert_eq "$(jq -r '.digest_failed_kind' "$MNEMON_DIR/sessions/s46.json")" "${kc##*|}" \
        "skip: $(printf '%s' "${kc%|*}" | cut -c1-48) is a ${kc##*|} failure"
    stop_server $BO_PID
done

# A digest that finds no credentials once the worker runs leaves its range in
# the pending marker, so an ended session still shows as undigested and is
# sent when it is resumed.
rm -f "$MNEMON_DIR/sessions/s47".*
mv "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.nc.json"
mkdir -p "$MNEMON_DIR/nc-home"
env -u MNEMON_ENDPOINT -u MNEMON_TOKEN -u CLAUDE_PLUGIN_OPTION_MNEMON_ENDPOINT -u CLAUDE_PLUGIN_OPTION_MNEMON_TOKEN \
    HOME="$MNEMON_DIR/nc-home" "$HOOKS_DIR/lib/digest-worker.sh" s47 0 2 "" "" "" "$tp_one" >/dev/null 2>&1
mv "$MNEMON_DIR/config.nc.json" "$MNEMON_DIR/config.json"
assert_eq "$(jq -r '.end' "$MNEMON_DIR/sessions/s47.digest.pending" 2>/dev/null)" "2" \
    "worker: a range with no credentials is kept as pending"
assert_eq "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/s47.json")" "0" \
    "worker: a range with no credentials does not advance"

# A replay says where the stored slice ended. The client advances there and
# carries the rest: the server committed 0-2 while this client timed out, and
# the retry asks for 0-4.
: > "$BO_LOG"
FAKE_PORT="$BO_PORT" FAKE_REQUEST_LOG="$BO_LOG" FAKE_REPLAY_END=2 "$THIS_DIR/fixtures/server.sh" &
BO_PID=$!
wait_server "$BO_PORT"
jq 'del(.digest_chunk_chars)' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
tp_rp="$MNEMON_DIR/transcript-replay.jsonl"
for i in 1 2 3 4; do printf '{"type":"user","message":{"role":"user","content":"replay turn %s"}}\n' "$i"; done > "$tp_rp"
rm -f "$MNEMON_DIR/sessions/s36".*
printf '{"session_id":"s36","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$tp_rp" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s36
rp_ranges=$(jq -rs '[.[] | select(.params.name? == "session_digest") | "\(.params.arguments.turn_range.start)-\(.params.arguments.turn_range.end)"] | join(",")' "$BO_LOG")
assert_eq "${rp_ranges%%,*}" "0-4" "replay: the first send asks for the whole range"
case "$rp_ranges" in
    0-4,2-4*) PASS=$((PASS+1)); printf '  ok: replay: the client advances to the replayed end and sends the rest\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: replay: expected 0-4 then 2-4, got %s\n' "$rp_ranges";;
esac
# Replay by start is opt-in on the server: a 0.3.10 client, which ignores
# turn_end, gets only exact-range replays. This client advances to turn_end, so
# it must say so on every digest.
rp_modes=$(jq -rs '[.[] | select(.params.name? == "session_digest") | .params.arguments.replay // "none"] | unique | join(",")' "$BO_LOG")
assert_eq "$rp_modes" "by_start" "replay: every digest opts in to replay by start"
stop_server $BO_PID
# A replay that claims an end past the one sent (a slice recorded under this
# session id by someone else) cannot move the pointer past turns this client
# never sent.
: > "$BO_LOG"
FAKE_PORT="$BO_PORT" FAKE_REQUEST_LOG="$BO_LOG" FAKE_REPLAY_END=99 "$THIS_DIR/fixtures/server.sh" &
BO_PID=$!
wait_server "$BO_PORT"
rm -f "$MNEMON_DIR/sessions/s36b".*
printf '{"session_id":"s36b","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$tp_rp" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s36b
assert_eq "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/s36b.json")" "4" \
    "replay: an end past the one sent advances only to the end sent"
stop_server $BO_PID
mv "$MNEMON_DIR/config.bo.json" "$MNEMON_DIR/config.json"

# Worker report: the backfill runs the worker in the foreground, one chunk
# per call (MNEMON_DIGEST_MAX_PASSES=1), reads one JSON line per call from
# MNEMON_DIGEST_REPORT, and enforces its spending cap between calls. With
# neither set, live capture behaves as before.
WR_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
WR_LOG="$MNEMON_DIR/requests-wr.log"
: > "$WR_LOG"
FAKE_PORT="$WR_PORT" FAKE_REQUEST_LOG="$WR_LOG" FAKE_USAGE=1 "$THIS_DIR/fixtures/server.sh" &
WR_PID=$!
wait_server "$WR_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.wr.json"
jq --arg e "http://127.0.0.1:$WR_PORT/mcp" '.endpoint=$e | .digest_chunk_chars=1000' \
  "$MNEMON_DIR/config.wr.json" > "$MNEMON_DIR/config.json"
# Six turns of ~450 characters, roles alternating so none merge: three chunks
# of two turns each at digest_chunk_chars=1000.
tp_wr="$MNEMON_DIR/transcript-wr.jsonl"
: > "$tp_wr"
for i in 1 2 3 4 5 6; do
    if [ $((i % 2)) = 1 ]; then
        printf '{"type":"user","timestamp":"2026-09-0%sT10:00:00.123Z","message":{"role":"user","content":"REPORT-TURN-%s %s"}}\n' \
            "$i" "$i" "$(head -c 400 /dev/zero | tr '\0' 'r')" >> "$tp_wr"
    else
        printf '{"type":"assistant","timestamp":"2026-09-0%sT10:00:00.456Z","message":{"role":"assistant","content":[{"type":"text","text":"REPORT-TURN-%s %s"}]}}\n' \
            "$i" "$i" "$(head -c 400 /dev/zero | tr '\0' 'r')" >> "$tp_wr"
    fi
done
wr_sent() { jq -rs '[.[] | select(.params.name? == "session_digest") | "\(.params.arguments.turn_range.start)-\(.params.arguments.turn_range.end)"] | join(",")' "$WR_LOG"; }

rm -f "$MNEMON_DIR/sessions/wr1".* "$MNEMON_DIR/wr1.report"
MNEMON_DIGEST_MAX_PASSES=1 MNEMON_DIGEST_REPORT="$MNEMON_DIR/wr1.report" \
    "$HOOKS_DIR/lib/digest-worker.sh" wr1 0 6 "" "" "" "$tp_wr" >/dev/null 2>&1
assert_eq "$(wr_sent)" "0-2" "worker: MAX_PASSES=1 sends exactly one chunk of a 3-chunk session"
assert_eq "$(jq -r '.last_digest_turn' "$MNEMON_DIR/sessions/wr1.json")" "2" \
    "worker: MAX_PASSES=1 advances the pointer past that chunk only"
assert_eq "$(jq -r '.end' "$MNEMON_DIR/sessions/wr1.digest.pending" 2>/dev/null)" "6" \
    "worker: MAX_PASSES=1 carries the rest in the pending marker"
assert_eq "$(wc -l < "$MNEMON_DIR/wr1.report" | tr -d ' ')" "1" "worker: one report line per call"
assert_eq "$(jq -c 'keys_unsorted' "$MNEMON_DIR/wr1.report")" \
    '["start","end","outcome","replayed","turn_end","drawers","dropped","usage"]' \
    "worker: a report line has exactly the documented fields"
assert_eq "$(jq -c '[.start, .end, .outcome, .replayed, .turn_end, .drawers, .dropped, .usage.prompt_tokens, .usage.completion_tokens]' "$MNEMON_DIR/wr1.report")" \
    '[0,2,"ok",false,2,[{"id":41,"wing":"research"},{"id":42,"wing":"project-atlas"}],1,1200,300]' \
    "worker: report line on success carries drawers, usage, turn_end"
assert_eq "$(jq -rs '[.[] | select(.params.name? == "session_digest") | .params.arguments.occurred_at] | join(",")' "$WR_LOG")" \
    "2026-09-02T10:00:00Z" "worker: occurred_at is the last turn's ts"
# The next call picks up where the last stopped; a value below 1 is 1.
: > "$WR_LOG"
MNEMON_DIGEST_MAX_PASSES=0 MNEMON_DIGEST_REPORT="$MNEMON_DIR/wr1.report" \
    "$HOOKS_DIR/lib/digest-worker.sh" wr1 0 6 "" "" "" "$tp_wr" >/dev/null 2>&1
assert_eq "$(wr_sent)" "2-4" "worker: MAX_PASSES=0 is read as 1, and the next call resumes at the pointer"
assert_eq "$(sed -n 2p "$MNEMON_DIR/wr1.report" | jq -c '[.start, .end, .outcome]')" '[2,4,"ok"]' \
    "worker: report lines are appended, one per call"
assert_eq "$(jq -rs '[.[] | select(.params.name? == "session_digest") | .params.arguments.occurred_at] | join(",")' "$WR_LOG")" \
    "2026-09-04T10:00:00Z" "worker: occurred_at follows the chunk sent"

# A replay reports replayed and the server's turn_end.
: > "$WR_LOG"
stop_server $WR_PID
FAKE_PORT="$WR_PORT" FAKE_REQUEST_LOG="$WR_LOG" FAKE_REPLAY_END=1 "$THIS_DIR/fixtures/server.sh" &
WR_PID=$!
wait_server "$WR_PORT"
rm -f "$MNEMON_DIR/sessions/wr2".* "$MNEMON_DIR/wr2.report"
MNEMON_DIGEST_MAX_PASSES=1 MNEMON_DIGEST_REPORT="$MNEMON_DIR/wr2.report" \
    "$HOOKS_DIR/lib/digest-worker.sh" wr2 0 6 "" "" "" "$tp_wr" >/dev/null 2>&1
assert_eq "$(jq -c '[.start, .end, .outcome, .replayed, .turn_end, .drawers, .dropped, .usage]' "$MNEMON_DIR/wr2.report")" \
    '[0,2,"ok",true,1,[],0,null]' "worker: report line on a replay says replayed, with the server's turn_end"
stop_server $WR_PID

# Failures write a line too, each with its kind. No call, no line.
for kc in "FAKE_TOOL_ERROR=Retryable: the digest did not run (the model timed out). Nothing was stored.|transient" \
    "FAKE_TOOL_ERROR=Not retryable: the digest did not run (OPENAI_API_KEY is not set on the server).|not_retryable" \
    "FAKE_STATUS=413|payload"; do
    : > "$WR_LOG"
    env "${kc%|*}" FAKE_PORT="$WR_PORT" FAKE_REQUEST_LOG="$WR_LOG" "$THIS_DIR/fixtures/server.sh" &
    WR_PID=$!
    wait_server "$WR_PORT"
    rm -f "$MNEMON_DIR/sessions/wr3".* "$MNEMON_DIR/wr3.report"
    MNEMON_DIGEST_MAX_PASSES=1 MNEMON_DIGEST_REPORT="$MNEMON_DIR/wr3.report" \
        "$HOOKS_DIR/lib/digest-worker.sh" wr3 0 6 "" "" "" "$tp_wr" >/dev/null 2>&1
    case "${kc##*|}" in
        transient) wr_what="report line on a Retryable error has outcome transient and usage null" ;;
        *) wr_what="report line on $(printf '%s' "${kc%|*}" | cut -c1-30) has outcome ${kc##*|}" ;;
    esac
    assert_eq "$(jq -c '[.start, .end, .outcome, .replayed, .turn_end, .drawers, .dropped, .usage]' "$MNEMON_DIR/wr3.report" 2>/dev/null)" \
        "[0,2,\"${kc##*|}\",false,null,[],0,null]" "worker: $wr_what"
    stop_server $WR_PID
done
rm -f "$MNEMON_DIR/sessions/wr4".* "$MNEMON_DIR/wr4.report"
mv "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.nc.json"
mkdir -p "$MNEMON_DIR/nc-home"
env -u MNEMON_ENDPOINT -u MNEMON_TOKEN -u CLAUDE_PLUGIN_OPTION_MNEMON_ENDPOINT -u CLAUDE_PLUGIN_OPTION_MNEMON_TOKEN \
    HOME="$MNEMON_DIR/nc-home" MNEMON_DIGEST_MAX_PASSES=1 MNEMON_DIGEST_REPORT="$MNEMON_DIR/wr4.report" \
    "$HOOKS_DIR/lib/digest-worker.sh" wr4 0 6 "" "" "" "$tp_wr" >/dev/null 2>&1
mv "$MNEMON_DIR/config.nc.json" "$MNEMON_DIR/config.json"
assert_eq "$([ -e "$MNEMON_DIR/wr4.report" ] && echo written || echo none)" "none" \
    "worker: no call (no credentials) writes no report line"

# occurred_at is left out when the last turn has no ts, and for an inline
# transcript, which the worker did not slice.
: > "$WR_LOG"
FAKE_PORT="$WR_PORT" FAKE_REQUEST_LOG="$WR_LOG" "$THIS_DIR/fixtures/server.sh" &
WR_PID=$!
wait_server "$WR_PORT"
tp_nots="$MNEMON_DIR/transcript-nots.jsonl"
{
  printf '%s\n' '{"type":"user","timestamp":"2026-09-01T10:00:00Z","message":{"role":"user","content":"stamped"}}'
  printf '%s\n' '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"not stamped"}]}}'
  printf '%s\n' '{"type":"user","timestamp":"yesterday","message":{"role":"user","content":"stamped badly"}}'
} > "$tp_nots"
rm -f "$MNEMON_DIR/sessions/wr5".* "$MNEMON_DIR/sessions/wr6".*
head -n 2 "$tp_nots" > "$MNEMON_DIR/transcript-nots2.jsonl"
"$HOOKS_DIR/lib/digest-worker.sh" wr5 0 2 "" "" "" "$MNEMON_DIR/transcript-nots2.jsonl" >/dev/null 2>&1
"$HOOKS_DIR/lib/digest-worker.sh" wr6 0 3 "" "" "" "$tp_nots" >/dev/null 2>&1
bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript "$2"' _ "$HOOKS_DIR" "$tp_wr" > "$MNEMON_DIR/sessions/wr7.in"
rm -f "$MNEMON_DIR/sessions/wr7.json"
"$HOOKS_DIR/lib/digest-worker.sh" wr7 0 6 "$MNEMON_DIR/sessions/wr7.in" "" "" >/dev/null 2>&1
assert_eq "$(jq -rs '[.[] | select(.params.name? == "session_digest") | "\(.params.arguments.session_id):\(.params.arguments | has("occurred_at"))"] | join(",")' "$WR_LOG")" \
    "wr5:false,wr6:false,wr7:false" "worker: occurred_at is omitted with no ts, a ts that is not ISO-8601, and an inline transcript"
stop_server $WR_PID

# The worker's own temp files go on every exit, a signal included -- the
# backfill runs it in the foreground, where ^C is likely.
: > "$WR_LOG"
FAKE_PORT="$WR_PORT" FAKE_REQUEST_LOG="$WR_LOG" FAKE_DELAY=2 "$THIS_DIR/fixtures/server.sh" &
WR_PID=$!
wait_server "$WR_PORT"
wr_tmp="$MNEMON_DIR/wr-tmp"
mkdir -p "$wr_tmp"
rm -f "$MNEMON_DIR/sessions/wr8".*
wr_sid=""
command -v setsid >/dev/null 2>&1 && wr_sid=setsid
TMPDIR="$wr_tmp" $wr_sid "$HOOKS_DIR/lib/digest-worker.sh" wr8 0 6 "" "" "" "$tp_wr" >/dev/null 2>&1 &
wr_w=$!
wait_until 10 grep -q '"session_digest"' "$WR_LOG"
if [ -n "$wr_sid" ]; then kill -TERM -- "-$wr_w" 2>/dev/null; else kill -TERM "$wr_w" 2>/dev/null; fi
wait "$wr_w" 2>/dev/null
wr_left=$(cd "$MNEMON_DIR/sessions" && ls wr8.slice.* wr8.input.* wr8.digest-error.* 2>/dev/null; ls "$wr_tmp")
assert_eq "$wr_left" "" "worker: a TERM leaves no slice, input, error or request-body file behind"
assert_eq "$([ -d "$MNEMON_DIR/sessions/wr8.digest.lock.d" ] && echo held || echo released)" "released" \
    "worker: a TERM releases the digest lock"
stop_server $WR_PID

# Unset, the env vars change nothing: capture sends every chunk, as before,
# and nothing writes a report.
rm -f "$MNEMON_DIR"/wr*.report
: > "$WR_LOG"
FAKE_PORT="$WR_PORT" FAKE_REQUEST_LOG="$WR_LOG" "$THIS_DIR/fixtures/server.sh" &
WR_PID=$!
wait_server "$WR_PORT"
rm -f "$MNEMON_DIR/sessions/wr9".*
printf '{"session_id":"wr9","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$tp_wr" \
  | env -u MNEMON_DIGEST_MAX_PASSES -u MNEMON_DIGEST_REPORT "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker wr9
assert_eq "$(wr_sent)" "0-2,2-4,4-6" "worker: unset env vars change nothing (every chunk sent in one run)"
assert_eq "$(find "$MNEMON_DIR" -name '*report*' | wc -l | tr -d ' ')" "0" \
    "worker: unset env vars change nothing (no report file is created)"
stop_server $WR_PID
mv "$MNEMON_DIR/config.wr.json" "$MNEMON_DIR/config.json"

# mnemon_redact_text applies the cleaner's secret rules to plain text. Each
# fixture line that carries a secret-shaped string is cleaned alone, and its
# raw text redacted: the two must agree, marker for marker.
rd_n=0
rd_bad=""
while IFS= read -r rd_line; do
    printf '%s' "$rd_line" | grep -qE 'AKIA|eyJ|Bearer|sk-|github_pat|gh[pousr]_|xox|AVNS|PRIVATE KEY|PASSWORD|password|secret|token|://[^ ]*:[^ ]*@' || continue
    rd_clean=$(printf '%s\n' "$rd_line" | bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript' _ "$HOOKS_DIR" 2>/dev/null | jq -r '.[0].text // empty')
    [ -n "$rd_clean" ] || continue
    rd_n=$((rd_n + 1))
    rd_text=$(printf '%s' "$rd_line" \
        | jq -j '.message.content | if type == "string" then . else [.[] | objects | select(.type == "text") | .text] | join("\n\n") end' \
        | bash -c '. "$1/lib/common.sh"; mnemon_redact_text' _ "$HOOKS_DIR" 2>/dev/null \
        | jq -Rrs 'gsub("\\n{3,}"; "\n\n") | sub("^\\s+"; "") | sub("\\s+$"; "")')
    [ "$rd_text" = "$rd_clean" ] || rd_bad="$rd_bad $rd_n"
done < "$THIS_DIR/fixtures/transcript-shapes.jsonl"
assert_eq "$([ "$rd_n" -gt 0 ] && echo "${rd_bad:-same}")" "same" \
    "redact: mnemon_redact_text redacts what the cleaner redacts ($rd_n secret-bearing lines)"
assert_eq "$(printf 'key AKIA%s and sk-%s\n' ABCDEFGHIJKLMNOP abcdefghijklmnopqrstuvwx | bash -c '. "$1/lib/common.sh"; mnemon_redact_text' _ "$HOOKS_DIR")" \
    "key [REDACTED-AWS-KEY] and [REDACTED-OPENAI-KEY]" "redact: reads stdin, writes stdout, keeps the text around a secret"

# The cleaner shares its redaction with mnemon_redact_text. Its output on the
# fixture is the snapshot taken before the two were split apart, byte for byte.
for cl_mode in "" --lines; do
    cl_snap="$THIS_DIR/fixtures/transcript-shapes.cleaned${cl_mode:+-lines}.json"
    if bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript $3 "$2"' _ "$HOOKS_DIR" "$THIS_DIR/fixtures/transcript-shapes.jsonl" "$cl_mode" 2>/dev/null \
        | cmp -s - "$cl_snap"; then
        PASS=$((PASS+1)); printf '  ok: cleaner: output unchanged after the refactor%s\n' "${cl_mode:+ ($cl_mode)}"
    else
        FAIL=$((FAIL+1)); printf '  FAIL: cleaner: output differs from %s\n' "$cl_snap"
    fi
done

# Test 10p: a worker that takes the lock after another finished sends from the
# pointer, not from where its own Stop read it. W1 digests 0-2; a Stop at 4
# read the pointer as 0 while W1 ran; W2, starting after W1, used to send 0-4,
# digesting 0-2 twice on a server that replays only the exact range (and
# costing a round trip on one that replays by start).
ST_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
ST_LOG="$MNEMON_DIR/requests-st.log"
: > "$ST_LOG"
FAKE_PORT="$ST_PORT" FAKE_REQUEST_LOG="$ST_LOG" "$THIS_DIR/fixtures/server.sh" &
ST_PID=$!
wait_server "$ST_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.st.json"
jq --arg e "http://127.0.0.1:$ST_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
tp_st="$MNEMON_DIR/transcript-stale.jsonl"
{
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"ALREADY-DIGESTED-BY-W1"}}'
  printf '%s\n' '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"ALREADY-DIGESTED-BY-W1 too"}]}}'
  printf '%s\n' '{"type":"user","message":{"role":"user","content":"NEW-FOR-W2"}}'
  printf '%s\n' '{"type":"assistant","message":{"role":"assistant","content":[{"type":"text","text":"NEW-FOR-W2 too"}]}}'
} > "$tp_st"
rm -rf "$MNEMON_DIR/sessions/s41".*
printf '{"last_digest_turn":2,"last_recall_at":0,"recent_drawer_ids":[],"nomemo":false,"disabled":false}' > "$MNEMON_DIR/sessions/s41.json"
bash -c '. "$1/lib/common.sh"; mnemon_clean_transcript "$2"' _ "$HOOKS_DIR" "$tp_st" > "$MNEMON_DIR/sessions/s41.in"
"$HOOKS_DIR/lib/digest-worker.sh" s41 0 4 "$MNEMON_DIR/sessions/s41.in" "" "" "$tp_st" >/dev/null 2>&1
st_sent=$(jq -rs '[.[] | select(.params.name? == "session_digest")] | map("\(.params.arguments.turn_range.start)-\(.params.arguments.turn_range.end) \(.params.arguments.transcript)") | join("|")' "$ST_LOG" 2>/dev/null)
case "$st_sent" in
    "2-4 "*NEW-FOR-W2*) case "$st_sent" in
        *ALREADY-DIGESTED*) FAIL=$((FAIL+1)); printf '  FAIL: worker: re-sent turns another worker digested\n';;
        *) PASS=$((PASS+1)); printf '  ok: worker: a stale start is re-sliced from the pointer\n';;
        esac;;
    *) FAIL=$((FAIL+1)); printf '  FAIL: worker: a stale start was sent as-is: %s\n' "$(printf '%s' "$st_sent" | head -c 80)";;
esac
stop_server $ST_PID
mv "$MNEMON_DIR/config.st.json" "$MNEMON_DIR/config.json"

# Test 10q: the digest lock is released only by its owner, and the pending
# marker and the session state survive concurrent writers.
mkdir -p "$MNEMON_DIR/sessions/s42.digest.lock.d"; echo "$$" > "$MNEMON_DIR/sessions/s42.digest.lock.d/pid"
bash -c '. "$1/lib/common.sh"; mnemon_digest_unlock s42' _ "$HOOKS_DIR"
assert_eq "$([ -d "$MNEMON_DIR/sessions/s42.digest.lock.d" ] && echo held || echo gone)" "held" \
    "lock: another process's lock is not released"
rm -rf "$MNEMON_DIR/sessions/s42".*
pids=""
for e in 3 17 9 30 12 5 28 1 22 14 8 26; do
    bash -c '. "$1/lib/common.sh"; mnemon_digest_pending_add s42 /x "$2"' _ "$HOOKS_DIR" "$e" & pids="$pids $!"
done
for p in $pids; do wait "$p"; done
assert_eq "$(jq -r '.end' "$MNEMON_DIR/sessions/s42.digest.pending")" "30" "pending: concurrent Stops keep the furthest end"
printf '{"last_digest_turn":0,"last_recall_at":0,"recent_drawer_ids":[],"nomemo":true,"disabled":false}' > "$MNEMON_DIR/sessions/s43.json"
pids=""
for i in $(seq 1 12); do
    bash -c '. "$1/lib/common.sh"; mnemon_session_state_update s43 ".recent_drawer_ids += [\$i]" --argjson i "$2"' _ "$HOOKS_DIR" "$i" & pids="$pids $!"
done
for p in $pids; do wait "$p"; done
assert_eq "$(jq -c '[(.recent_drawer_ids | length), .nomemo]' "$MNEMON_DIR/sessions/s43.json")" "[12,true]" \
    "state: concurrent updates all land, and @nomemo survives them"

# Test 10q2: the locks hold even where mkdir reports success to more than one
# racing caller, as uutils coreutils' mkdir (newer Ubuntu's default) sometimes
# does. This fake mkdir always succeeds, existing directory or not.
LAXBIN=$(mktemp -d)
printf '#!/bin/sh\nexec /bin/mkdir -p "$@"\n' > "$LAXBIN/mkdir"; chmod +x "$LAXBIN/mkdir"
printf '{"last_digest_turn":0,"last_recall_at":0,"recent_drawer_ids":[],"nomemo":true,"disabled":false}' > "$MNEMON_DIR/sessions/s44.json"
pids=""
for i in $(seq 1 12); do
    PATH="$LAXBIN:$PATH" bash -c '. "$1/lib/common.sh"; mnemon_session_state_update s44 ".recent_drawer_ids += [\$i]" --argjson i "$2"' _ "$HOOKS_DIR" "$i" & pids="$pids $!"
done
for p in $pids; do wait "$p"; done
assert_eq "$(jq -c '[(.recent_drawer_ids | length), .nomemo]' "$MNEMON_DIR/sessions/s44.json")" "[12,true]" \
    "lock: a lax mkdir still lets only one writer in at a time"
assert_eq "$(PATH="$LAXBIN:$PATH" bash -c '. "$1/lib/common.sh"
    mnemon_digest_lock s45 && echo first; (mnemon_digest_lock s45 && echo second || echo refused) &
    wait' _ "$HOOKS_DIR" | tr '\n' ' ')" "first refused " \
    "lock: a lax mkdir does not hand a held digest lock to a second worker"
rm -rf "$MNEMON_DIR/sessions/s45".*
assert_eq "$(PATH="$LAXBIN:$PATH" bash -c '. "$1/lib/common.sh"
    mnemon_claim_once s46 wake && echo first; mnemon_claim_once s46 wake && echo second || echo refused' _ "$HOOKS_DIR" | tr '\n' ' ')" "first refused " \
    "claim: a lax mkdir does not deliver an event twice"
rm -rf "$MNEMON_DIR/sessions/s46".* "$LAXBIN"

# Test 10r: a pending range whose transcript is gone is logged, not dropped
# silently.
PG_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
FAKE_PORT="$PG_PORT" "$THIS_DIR/fixtures/server.sh" &
PG_PID=$!
wait_server "$PG_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.pg.json"
jq --arg e "http://127.0.0.1:$PG_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
: > "$MNEMON_DIR/capture-errors.log"
rm -rf "$MNEMON_DIR/sessions/s44".*
printf '{"path":"%s/gone.jsonl","end":9}' "$MNEMON_DIR" > "$MNEMON_DIR/sessions/s44.digest.pending"
printf '[{"role":"user","text":"turn"}]' > "$MNEMON_DIR/sessions/s44.in"
"$HOOKS_DIR/lib/digest-worker.sh" s44 0 1 "$MNEMON_DIR/sessions/s44.in" "" "" >/dev/null 2>&1
if grep -q 'pending range' "$MNEMON_DIR/capture-errors.log" 2>/dev/null; then
    PASS=$((PASS+1)); printf '  ok: worker: an unusable pending range is logged\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: worker: an unusable pending range was dropped silently\n'
fi
rm -rf "$MNEMON_DIR/sessions/s44".*
stop_server $PG_PID
mv "$MNEMON_DIR/config.pg.json" "$MNEMON_DIR/config.json"

# Test 10o: the inline `.transcript` a Stop payload can carry is cleaned and
# sent the same way as a transcript file.
IL_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
IL_LOG="$MNEMON_DIR/requests-il.log"
: > "$IL_LOG"
FAKE_PORT="$IL_PORT" FAKE_REQUEST_LOG="$IL_LOG" "$THIS_DIR/fixtures/server.sh" &
IL_PID=$!
wait_server "$IL_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.il.json"
jq --arg e "http://127.0.0.1:$IL_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"
rm -rf "$MNEMON_DIR/sessions/s33".*
jq -cn --slurpfile t "$SHAPES" '{session_id:"s33", transcript:$t, turn_index:2, cwd:"/tmp", hook_event_name:"Stop"}' \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s33
for _ in $(seq 1 40); do grep -q 'session_digest' "$IL_LOG" 2>/dev/null && break; sleep 0.25; done
il_sent=$(jq -rs '[.[] | select(.params.name? == "session_digest")][0].params.arguments.transcript // ""' "$IL_LOG" 2>/dev/null)
assert_eq "$il_sent" "$cleaned" "capture: an inline transcript is cleaned and sent like a file"
stop_server $IL_PID
mv "$MNEMON_DIR/config.il.json" "$MNEMON_DIR/config.json"

# Capture removes a pending range the pointer has passed, under the marker's
# lock: the check and the removal used to run bare, so a worker adding a
# later range in between lost it.
tp_sp="$MNEMON_DIR/transcript-spent.jsonl"
printf '%s\n' '{"type":"user","message":{"role":"user","content":"one"}}' '{"type":"user","message":{"role":"user","content":"two"}}' > "$tp_sp"
rm -rf "$MNEMON_DIR/sessions/s48".*
printf '{"last_digest_turn":2,"recent_drawer_ids":[],"nomemo":false,"disabled":false}' > "$MNEMON_DIR/sessions/s48.json"
printf '{"path":"%s","end":1}' "$tp_sp" > "$MNEMON_DIR/sessions/s48.digest.pending"
mkdir "$MNEMON_DIR/sessions/s48.pending.lock.d"; echo "$$" > "$MNEMON_DIR/sessions/s48.pending.lock.d/pid"
printf '{"session_id":"s48","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$tp_sp" \
  | "$HOOKS_DIR/mnemon-capture.sh" >/dev/null 2>&1 &
sp_pid=$!
sleep 0.5
assert_eq "$([ -e "$MNEMON_DIR/sessions/s48.digest.pending" ] && echo kept || echo removed)" "kept" \
    "capture: a spent pending range is not removed while its lock is held"
rm -rf "$MNEMON_DIR/sessions/s48.pending.lock.d"
wait "$sp_pid" 2>/dev/null
assert_eq "$([ -e "$MNEMON_DIR/sessions/s48.digest.pending" ] && echo kept || echo removed)" "removed" \
    "capture: ...and is removed once the lock is free"
rm -rf "$MNEMON_DIR/sessions/s48".*

# Test 10s: SessionStart says when sessions have turns undigested after
# failures -- only ones with real work left -- and clears what can never be
# retried, so the banner does not repeat for ever. Each session is retried by
# its own next Stop (for one that has ended, when it is resumed).
SW_DIR=$(mktemp -d)
mkdir -p "$SW_DIR/sessions"
jq -n --arg e "http://127.0.0.1:1/mcp" '{endpoint:$e, bearer_token:"t", recall_timeout_ms:300}' > "$SW_DIR/config.json"
sw_tp="$SW_DIR/live.jsonl"
printf '%s\n' '{"type":"user","message":{"role":"user","content":"one"}}' '{"type":"user","message":{"role":"user","content":"two"}}' > "$sw_tp"
sw_state() {  # <sid> <pointer> <failures> <transcript> [nomemo]
    jq -n --argjson p "$2" --argjson f "$3" --arg t "$4" --argjson n "${5:-false}" \
        '{last_digest_turn:$p,recent_drawer_ids:[],nomemo:$n,disabled:false,digest_failures:$f,digest_retry_after:0,transcript_path:$t}' \
        > "$SW_DIR/sessions/$1.json"
}
sw_state failed1 0 2 "$sw_tp"                       # counts: failures, lines past the pointer
sw_state caughtup 2 1 "$sw_tp"                      # failures but nothing past the pointer
sw_state private1 0 2 "$sw_tp" true                 # @nomemo
sw_state gone1 0 2 "$SW_DIR/deleted.jsonl"          # transcript gone: cleared
sw_state pend1 0 0 "$sw_tp"; printf '{"path":"%s","end":2}' "$sw_tp" > "$SW_DIR/sessions/pend1.digest.pending"   # counts
sw_state spent1 2 0 "$sw_tp"; printf '{"path":"%s","end":1}' "$sw_tp" > "$SW_DIR/sessions/spent1.digest.pending" # removed
sw_out=$(printf '{"session_id":"new1","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | env -u MNEMON_ENDPOINT -u MNEMON_TOKEN MNEMON_DIR="$SW_DIR" "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$sw_out" in
    *"Mnemon: 2 session(s) have undigested turns;"*) PASS=$((PASS+1)); printf '  ok: wake: counts only sessions with work left\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: wake: wrong undigested banner, got: %s\n' "$(printf '%s' "$sw_out" | head -c 200)";;
esac
assert_eq "$([ -e "$SW_DIR/sessions/spent1.digest.pending" ] && echo kept || echo removed)" "removed" \
    "wake: a pending range the pointer has passed is removed"
assert_eq "$(jq -r '.digest_failures' "$SW_DIR/sessions/gone1.json")" "0" \
    "wake: a session whose transcript is gone is given up on"
grep -q 'session=gone1 has undigested turns but its transcript' "$SW_DIR/capture-errors.log" 2>/dev/null \
    && { PASS=$((PASS+1)); printf '  ok: wake: giving up is logged\n'; } \
    || { FAIL=$((FAIL+1)); printf '  FAIL: wake: giving up was not logged\n'; }
sw_out2=$(printf '{"session_id":"new2","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | env -u MNEMON_ENDPOINT -u MNEMON_TOKEN MNEMON_DIR="$SW_DIR" "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$sw_out2" in
    *"Mnemon: 2 session(s)"*) PASS=$((PASS+1)); printf '  ok: wake: the count is stable once tidied\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: wake: second banner differs: %s\n' "$(printf '%s' "$sw_out2" | head -c 120)";;
esac

# Pending work is not always a failure: a run stops after ten chunks and leaves
# the rest pending. The banner says "undigested", not "after failures".
case "$sw_out" in
    *"after failures"*) FAIL=$((FAIL+1)); printf '  FAIL: wake: the banner blames failures for pending work\n';;
    *) PASS=$((PASS+1)); printf '  ok: wake: the banner does not blame failures for pending work\n';;
esac
# When the scan's time budget runs out the count is a lower bound, and says so.
# A budget of 0 stops after the first candidate; both here count.
SW3_DIR=$(mktemp -d)
mkdir -p "$SW3_DIR/sessions"
cp "$SW_DIR/config.json" "$SW_DIR/live.jsonl" "$SW3_DIR/"
for sid in late1 late2; do
    jq -n --arg t "$SW3_DIR/live.jsonl" '{last_digest_turn:0,recent_drawer_ids:[],nomemo:false,disabled:false,digest_failures:2,digest_retry_after:0,transcript_path:$t}' \
        > "$SW3_DIR/sessions/$sid.json"
done
sw_out3=$(printf '{"session_id":"new3","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | env -u MNEMON_ENDPOINT -u MNEMON_TOKEN MNEMON_DIR="$SW3_DIR" MNEMON_UNDIGESTED_SCAN_SECONDS=0 "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$SW3_DIR" in /tmp/*|"${TMPDIR:-/tmp}"/*) rm -rf "${SW3_DIR:?}" ;; esac
case "$sw_out3" in
    *"Mnemon: at least 1 session(s) have undigested turns"*) PASS=$((PASS+1)); printf '  ok: wake: a scan cut short says "at least"\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: wake: a scan cut short should say "at least 1", got: %s\n' "$(printf '%s' "$sw_out3" | head -c 160)";;
esac

# Cheap however many sessions a device has seen: one grep, not a fork each.
for i in $(seq 1 1500); do
    printf '{"last_digest_turn":0,"recent_drawer_ids":[],"nomemo":false,"disabled":false}' > "$SW_DIR/sessions/bulk$i.json"
done
sw_start=$(date +%s%N)
sw_n=$(MNEMON_DIR="$SW_DIR" bash -c '. "$1/lib/common.sh"; mnemon_undigested_sessions' _ "$HOOKS_DIR")
sw_ms=$(( ($(date +%s%N) - sw_start) / 1000000 ))
assert_eq "$sw_n" "2" "wake: counts correctly among 1,500 sessions"
# A fork per state file costs 3-60ms each, 5-90s for 1,500; one grep takes
# tens of milliseconds. The bound is loose so a loaded machine does not fail it.
if [ "$sw_ms" -lt 1500 ]; then
    PASS=$((PASS+1)); printf '  ok: wake: 1,500 sessions are scanned in %sms\n' "$sw_ms"
else
    FAIL=$((FAIL+1)); printf '  FAIL: wake: scanning 1,500 sessions took %sms\n' "$sw_ms"
fi
case "$SW_DIR" in /tmp/*|"${TMPDIR:-/tmp}"/*) rm -rf "${SW_DIR:?}" ;; esac

# Recall waits at most a second for the state lock, and then skips its
# timestamp rather than hold up the prompt.
RL_LOCK="$MNEMON_DIR/sessions/s37.state.lock.d"
mkdir -p "$RL_LOCK"; echo "$$" > "$RL_LOCK/pid"
: > "$MNEMON_DIR/capture-errors.log"
rl_start=$(date +%s)
printf '{"session_id":"s37","cwd":"/tmp","prompt":"a substantive prompt about the retry design"}' \
  | "$HOOKS_DIR/mnemon-recall.sh" >/dev/null 2>&1 || true
rl_secs=$(( $(date +%s) - rl_start ))
if [ "$rl_secs" -le 4 ] && grep -q "s37.state.lock.d busy for 1s; skipped" "$MNEMON_DIR/capture-errors.log"; then
    PASS=$((PASS+1)); printf '  ok: recall: a busy state lock skips the timestamp after a second\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: recall: busy lock took %ss / not logged\n' "$rl_secs"
fi
case "$RL_LOCK" in "$MNEMON_DIR"/*) rm -rf "${RL_LOCK:?}" ;; esac

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
    wait_server "$port"
}

rf_stop_all() {
    # shellcheck disable=SC2086
    [ -n "$RF_SERVERS" ] && stop_server $RF_SERVERS
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

# R1b: a refresh lock left with an empty pid (its holder killed between taking
# it and writing the pid, or a full disk) is taken over once it is old, rather
# than failing every refresh from then on.
rf_config "$R1_PORT"
mkdir -p "$MNEMON_DIR/refresh.lock.d"; : > "$MNEMON_DIR/refresh.lock.d/pid"
touch -d '@'"$(( $(date +%s) - 60 ))" "$MNEMON_DIR/refresh.lock.d"
r1b=$(bash -c '. "$1/lib/common.sh"; mnemon_refresh token-1' _ "$HOOKS_DIR")
case "$r1b" in
    token-1|"") FAIL=$((FAIL+1)); printf '  FAIL: refresh: an old lock with no pid wedged the refresh (got "%s")\n' "$r1b";;
    *) PASS=$((PASS+1)); printf '  ok: refresh: an old lock with no pid is taken over\n';;
esac
[ -d "$MNEMON_DIR/refresh.lock.d" ] && rm -rf "$MNEMON_DIR/refresh.lock.d"

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

# --- Automation opt-out -------------------------------------------------------
# Hooks are registered globally, so every Claude Code session on the box feeds
# the palace -- including cron-launched ones, which produce byte-identical
# transcripts every day. Measured on the live palace before this landed: 17% of
# all drawers were redundant copies, the worst offender stored seven times.
# @nomemo suppresses a session from inside the prompt; automation needs a switch
# it can set from outside, without authoring the prompt.
D_PORT=$(rf_free_port); D_LOG="$MNEMON_DIR/d.log"; D_STATE=$(mktemp -d)
rf_start "$D_PORT" "$D_LOG" "$D_STATE"
jq -n --arg e "http://127.0.0.1:$D_PORT/mcp" \
    '{endpoint:$e, bearer_token:"t", recall_timeout_ms:5000}' > "$MNEMON_DIR/config.json"

: > "$D_LOG"
d_wake=$(printf '{"session_id":"d1","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | env MNEMON_DISABLE=1 "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
assert_eq "$d_wake" "" "opt-out: wake says nothing when disabled"

d_recall=$(printf '{"session_id":"d2","prompt":"a substantive prompt from an automated run"}' \
    | env MNEMON_DISABLE=1 "$HOOKS_DIR/mnemon-recall.sh" 2>/dev/null)
assert_eq "$d_recall" "" "opt-out: recall says nothing when disabled"

d_tp="$MNEMON_DIR/d-transcript.jsonl"
printf '%s\n' '{"type":"user","message":{"role":"user","content":"the daily cron prompt"}}' > "$d_tp"
printf '{"session_id":"d3","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$d_tp" \
    | env MNEMON_DISABLE=1 "$HOOKS_DIR/mnemon-capture.sh" >/dev/null 2>&1 || true
sleep 1
assert_eq "$(wc -c < "$D_LOG" | tr -d ' ')" "0" "opt-out: a disabled session makes no server call at all"

# And the guard against over-suppression: without the switch, nothing changes.
: > "$D_LOG"
d_on=$(printf '{"session_id":"d4","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$d_on" in
    *"Mnemon palace state"*) PASS=$((PASS+1)); printf '  ok: opt-out: without the switch the hooks behave as before\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: opt-out: suppressed a session that was not opted out, got: %s\n' "$d_on";;
esac
rf_stop_all

# Test: the client's slice cap must keep the *sanitized* payload under the
# server's limit on the transcript field. The cap was applied to the raw slice
# before sanitization while the tool validates what actually arrives
# (`transcript` => `required|string|max:200000`), and sanitization only strips
# about a fifth: a 262144-byte raw slice measured 207,613 characters on the
# wire. Every digest was rejected in full, so last_digest_turn never advanced
# and the next Stop re-sent the same oversized tail forever.
CAP_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
CAP_LOG="$MNEMON_DIR/requests-cap.log"
: > "$CAP_LOG"
FAKE_PORT="$CAP_PORT" FAKE_REQUEST_LOG="$CAP_LOG" FAKE_MAX_TRANSCRIPT=200000 \
  "$THIS_DIR/fixtures/server.sh" &
CAP_PID=$!
wait_server "$CAP_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.cap-orig.json"
jq --arg e "http://127.0.0.1:$CAP_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"

tp4="$MNEMON_DIR/transcript-cap.jsonl"
: > "$tp4"
cap_chunk=$(head -c 60000 /dev/zero | tr '\0' 'y')
for _ in $(seq 1 12); do
    printf '{"type":"user","message":{"role":"user","content":[{"type":"text","text":"%s"}]}}\n' \
      "$cap_chunk" >> "$tp4"
done
printf '{"session_id":"s15","transcript_path":"%s","hook_event_name":"Stop","cwd":"/tmp"}' "$tp4" \
  | "$HOOKS_DIR/mnemon-capture.sh" || true
wait_worker s15
cap_ldt=0
for _ in $(seq 1 60); do
    cap_ldt=$(jq -r '.last_digest_turn // 0' "$MNEMON_DIR/sessions/s15.json" 2>/dev/null || echo 0)
    [ "$cap_ldt" != "0" ] && break
    sleep 0.25
done
assert_ne "$cap_ldt" "0" "capture: an oversized transcript is trimmed under the server's limit"

stop_server $CAP_PID
mv "$MNEMON_DIR/config.cap-orig.json" "$MNEMON_DIR/config.json"

# Test: a long prompt must still recall. `RecallTool` validates
# `prompt` => `required|string|max:4000` and the hook sent it uncapped, so a
# prompt over the limit was rejected whole and the session silently got no
# memory at all. Exactly the shape of the transcript cap above -- client sends
# unbounded, server validates a ceiling, nothing surfaces.
LP_PORT=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
LP_LOG="$MNEMON_DIR/requests-longprompt.log"
: > "$LP_LOG"
FAKE_PORT="$LP_PORT" FAKE_REQUEST_LOG="$LP_LOG" FAKE_MAX_PROMPT=4000 \
  "$THIS_DIR/fixtures/server.sh" &
LP_PID=$!
wait_server "$LP_PORT"
cp "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.lp-orig.json"
jq --arg e "http://127.0.0.1:$LP_PORT/mcp" '.endpoint=$e' "$MNEMON_DIR/config.json" > "$MNEMON_DIR/c.tmp" \
  && mv "$MNEMON_DIR/c.tmp" "$MNEMON_DIR/config.json"

long_prompt="what do we know about the atlas rollout $(head -c 6000 /dev/zero | tr '\0' 'z')"
lp_out=$(printf '{"session_id":"s16","prompt":"%s"}' "$long_prompt" | "$HOOKS_DIR/mnemon-recall.sh" 2>/dev/null || true)
case "$lp_out" in
    *"Mnemon recall"*|*"wiki"*|*"Anthony"*|*"Dorothy"*)
        PASS=$((PASS+1)); printf '  ok: recall: a prompt over the server limit is trimmed, not dropped\n';;
    *)
        FAIL=$((FAIL+1)); printf '  FAIL: recall: a long prompt produced no context\n    got: %s\n' "$(printf '%s' "$lp_out" | head -c 120)";;
esac

# And the payload that actually left the machine must be under the limit.
lp_sent=$(python3 - "$LP_LOG" <<'PYEOF'
import json, sys
# Bodies are pretty-printed across many lines, so decode objects from the
# stream rather than assuming one JSON document per line.
raw = open(sys.argv[1], "rb").read().decode("utf-8", "replace")
dec, i, best = json.JSONDecoder(), 0, 0
while i < len(raw):
    j = raw.find("{", i)
    if j < 0:
        break
    try:
        obj, end = dec.raw_decode(raw, j)
        i = end
    except ValueError:
        i = j + 1
        continue
    args = (obj.get("params") or {}).get("arguments") or {}
    if isinstance(args.get("prompt"), str):
        best = max(best, len(args["prompt"]))
print(best)
PYEOF
)
if [ "${lp_sent:-0}" -gt 0 ] && [ "${lp_sent:-0}" -le 4000 ]; then
    PASS=$((PASS+1)); printf '  ok: recall: the prompt on the wire is within the server limit (%s chars)\n' "$lp_sent"
else
    FAIL=$((FAIL+1)); printf '  FAIL: recall: prompt on the wire measured %s chars\n' "${lp_sent:-0}"
fi

stop_server $LP_PID
mv "$MNEMON_DIR/config.lp-orig.json" "$MNEMON_DIR/config.json"

# --- Doctor -------------------------------------------------------------------
# mnemon-doctor.sh answers one question for a person at a terminal: does this
# device's connection work, and if not, why not. Every case runs in its own
# MNEMON_DIR and HOME, so neither the state the tests above left behind nor the
# real ~/.claude.json fallback can make a broken device look connected.
DOCTOR_SH="$HOOKS_DIR/../scripts/mnemon-doctor.sh"
LOCAL_VERSION=$(jq -r .version "$HOOKS_DIR/../.claude-plugin/plugin.json")
DOC_ALL=""
DOC_DIRS=""
# Distinctive, so the leak check below cannot pass by accident.
DOC_BEARER="doctor-bearer-SECRET-1f2e"
DOC_REFRESH="doctor-refresh-SECRET-3c4d"
DOC_CSEC="doctor-client-SECRET-5a6b"

doc_dir() {
    local d
    d=$(mktemp -d)
    mkdir -p "$d/home"
    printf '%s' "$d"
}

# Args: <dir> <port> <bearer> [renewable]. Writes a 0600 config.
doc_config() {
    local dir="$1" port="$2" bearer="$3" renew="${4:-}"
    if [ -n "$renew" ]; then
        jq -n --arg e "http://127.0.0.1:$port/mcp" --arg te "http://127.0.0.1:$port/oauth/token" \
            --arg b "$bearer" --arg r "$DOC_REFRESH" --arg cs "$DOC_CSEC" \
            '{endpoint:$e, token_endpoint:$te, bearer_token:$b, refresh_token:$r,
              client_id:"cid", client_secret:$cs, auth_state:"ok"}' > "$dir/config.json"
    else
        jq -n --arg e "http://127.0.0.1:$port/mcp" --arg b "$bearer" \
            '{endpoint:$e, bearer_token:$b}' > "$dir/config.json"
    fi
    chmod 600 "$dir/config.json"
}

# Args: <dir> [VAR=value ...]. Sets DOC_OUT and DOC_RC.
doc_run() {
    local dir="$1"; shift
    DOC_OUT=$(env -u MNEMON_ENDPOINT -u MNEMON_TOKEN \
        -u CLAUDE_PLUGIN_OPTION_MNEMON_ENDPOINT -u CLAUDE_PLUGIN_OPTION_MNEMON_TOKEN \
        -u MNEMON_DISABLE -u CLAUDE_PLUGIN_OPTION_MNEMON_DISABLE -u CLAUDE_PLUGIN_ROOT \
        -u DISABLE_UPDATES -u DISABLE_AUTOUPDATER -u CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC \
        -u FORCE_AUTOUPDATE_PLUGINS -u CLAUDE_CONFIG_DIR \
        HOME="$dir/home" MNEMON_DIR="$dir" MNEMON_CLAUDE_SETTINGS="$dir/settings.json" \
        MNEMON_KNOWN_MARKETPLACES="$dir/known_marketplaces.json" \
        "$@" bash "${DOC_SCRIPT:-$DOCTOR_SH}" ${DOC_ARGS:-} 2>&1)
    DOC_RC=$?
    DOC_ALL="$DOC_ALL
$DOC_OUT"
}

doc_show() { printf '    output was:\n%s\n' "$(printf '%s\n' "$DOC_OUT" | sed 's/^/      /')"; }

# Args: <line-prefix-regex> <extended-regex> <label>. Some line starting with
# the prefix (ok / warn / FAIL) must match the regex, case-insensitively.
doc_line() {
    if printf '%s\n' "$DOC_OUT" | grep -E "^($1) " | grep -qiE "$2"; then
        PASS=$((PASS+1)); printf '  ok: %s\n' "$3"
    else
        FAIL=$((FAIL+1)); printf '  FAIL: %s\n' "$3"; doc_show
    fi
}
doc_no_line() {
    if printf '%s\n' "$DOC_OUT" | grep -E "^($1) " | grep -qiE "$2"; then
        FAIL=$((FAIL+1)); printf '  FAIL: %s\n' "$3"; doc_show
    else
        PASS=$((PASS+1)); printf '  ok: %s\n' "$3"
    fi
}
doc_has() {
    case "$DOC_OUT" in
        *"$1"*) PASS=$((PASS+1)); printf '  ok: %s\n' "$2";;
        *) FAIL=$((FAIL+1)); printf '  FAIL: %s\n' "$2"; doc_show;;
    esac
}

# DR1: healthy. Every check passes, the wings are named, exit 0.
DR1_PORT=$(rf_free_port); DR1_STATE=$(mktemp -d); DR1=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR1"
rf_start "$DR1_PORT" "$DR1/req.log" "$DR1_STATE" FAKE_PLUGIN_VERSION="$LOCAL_VERSION"
doc_config "$DR1" "$DR1_PORT" "$(make_jwt 3000)" renew
printf '{"extraKnownMarketplaces":{"mnemon":{"source":{"source":"github","repo":"coopers98/mnemon"},"autoUpdate":true}}}' > "$DR1/settings.json"
DOC_JWT1=$(jq -r .bearer_token "$DR1/config.json")
doc_run "$DR1"
assert_eq "$DOC_RC" "0" "doctor: a healthy device exits 0"
doc_has "All checks passed." "doctor: a healthy device says all checks passed"
doc_has "research" "doctor: names the wings the device can reach"
doc_has "project-atlas" "doctor: names every reachable wing"
doc_no_line "FAIL" "." "doctor: a healthy device has no FAIL line"
doc_no_line "warn" "." "doctor: a healthy device has no warning"
doc_line "ok" "renew" "doctor: says the device can self-renew"
doc_line "ok" "config\.json" "doctor: says the credentials come from config.json"
doc_line "ok" "minute" "doctor: reports the time left on the access token"
doc_line "ok" "none" "doctor: an empty error log reports no recent errors"
case "$DOC_OUT" in
    *$'\e['*) FAIL=$((FAIL+1)); printf '  FAIL: doctor: output carries colour codes\n';;
    *) PASS=$((PASS+1)); printf '  ok: doctor: output is plain text\n';;
esac
assert_eq "$(jq -r .bearer_token "$DR1/config.json")" "$DOC_JWT1" "doctor: a healthy run does not touch the config"
# Sessions left with undigested turns after failures are part of "recent errors".
mkdir -p "$DR1/sessions"
printf '%s\n' '{"type":"user","message":{"role":"user","content":"x"}}' > "$DR1/failed-transcript.jsonl"
jq -n --arg t "$DR1/failed-transcript.jsonl" '{last_digest_turn:0,digest_failures:2,digest_retry_after:0,transcript_path:$t}' > "$DR1/sessions/failed.json"
doc_run "$DR1"
doc_line "warn" "1 session.*undigested" "doctor: reports sessions with undigested turns after failures"
case "$DOC_OUT" in
    *"after failures"*) FAIL=$((FAIL+1)); printf '  FAIL: doctor: blames failures for pending work\n';;
    *) PASS=$((PASS+1)); printf '  ok: doctor: the undigested line does not blame failures\n';;
esac
rm -f "$DR1/sessions/failed.json"
rf_stop_all

# DR2: no configuration at all. FAIL with the enrolment command, exit 1.
DR2=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR2"
doc_run "$DR2"
assert_eq "$DOC_RC" "1" "doctor: an unconfigured device exits 1"
doc_line "FAIL" "not connected|no credentials|not configured" "doctor: an unconfigured device FAILs"
doc_has "mnemon-authorize.sh" "doctor: the unconfigured FAIL names the enrolment script"
doc_has "mnemon:device-client" "doctor: the unconfigured FAIL says how to get a client"
doc_has "1 problem(s) found." "doctor: counts the problem"

# DR3: the server rejects the token and nothing can renew it. The FAIL names
# authentication, not reachability -- the fixes are different.
DR3_PORT=$(rf_free_port); DR3_STATE=$(mktemp -d); DR3=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR3"
rf_start "$DR3_PORT" "$DR3/req.log" "$DR3_STATE" FAKE_STATUS=401
doc_config "$DR3" "$DR3_PORT" "$DOC_BEARER"
doc_run "$DR3"
assert_eq "$DOC_RC" "1" "doctor: a rejected token exits 1"
doc_line "FAIL" "auth|401|rejected" "doctor: a rejected token FAILs naming authentication"
doc_no_line "FAIL" "reach|resolve|connect" "doctor: a rejected token is not reported as unreachable"
doc_line "ok" "reach|answered" "doctor: a server that answers 401 is still reachable"
rf_stop_all

# DR4: nothing listening. The FAIL names reachability, not authentication.
DR4_PORT=$(rf_free_port); DR4=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR4"
doc_config "$DR4" "$DR4_PORT" "$DOC_BEARER"
doc_run "$DR4"
assert_eq "$DOC_RC" "1" "doctor: an unreachable server exits 1"
doc_line "FAIL" "reach|connect" "doctor: an unreachable server FAILs naming reachability"
doc_no_line "FAIL" "auth|HTTP 401|token" "doctor: an unreachable server is not blamed on the token"
doc_has "1 problem(s) found." "doctor: an unreachable server counts once, not again for the tool call"
doc_has "tool call also failed, as expected with the instance unreachable" "doctor: the tool call is still attempted and labelled as following from reachability"

# DR5: plugin version mismatch is a warning, not a failure.
DR5_PORT=$(rf_free_port); DR5_STATE=$(mktemp -d); DR5=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR5"
rf_start "$DR5_PORT" "$DR5/req.log" "$DR5_STATE" FAKE_PLUGIN_VERSION=9.9.9
doc_config "$DR5" "$DR5_PORT" "$DOC_BEARER"
doc_run "$DR5"
assert_eq "$DOC_RC" "0" "doctor: a version mismatch alone still exits 0"
doc_line "warn" "9\.9\.9" "doctor: a version mismatch is a warning naming the server's version"
doc_has "claude plugin update mnemon" "doctor: the mismatch names the fix"
rf_stop_all

# DR6: an expired token that can renew is fine -- the call renews it.
DR6_PORT=$(rf_free_port); DR6_STATE=$(mktemp -d); DR6=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR6"
rf_start "$DR6_PORT" "$DR6/req.log" "$DR6_STATE" FAKE_EXPIRE_FIRST=1
doc_config "$DR6" "$DR6_PORT" "$(make_jwt -600)" renew
DOC_JWT6=$(jq -r .bearer_token "$DR6/config.json")
doc_run "$DR6"
assert_eq "$DOC_RC" "0" "doctor: an expired but renewable token exits 0"
doc_line "ok" "renew" "doctor: an expired renewable token says it will renew"
assert_eq "$(jq -r .bearer_token "$DR6/config.json")" "token-2" "doctor: the tool call's own refresh renewed the token"
rf_stop_all

# DR7: an expired token that cannot renew is a FAIL.
DR7_PORT=$(rf_free_port); DR7_STATE=$(mktemp -d); DR7=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR7"
rf_start "$DR7_PORT" "$DR7/req.log" "$DR7_STATE"
doc_config "$DR7" "$DR7_PORT" "$(make_jwt -600)"
DOC_JWT7=$(jq -r .bearer_token "$DR7/config.json")
doc_run "$DR7"
assert_eq "$DOC_RC" "1" "doctor: an expired token that cannot renew exits 1"
doc_line "FAIL" "expired" "doctor: an expired token that cannot renew FAILs"
rf_stop_all

# DR8: a dead authorization FAILs with the reason and the re-enrol command.
DR8_PORT=$(rf_free_port); DR8_STATE=$(mktemp -d); DR8=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR8"
rf_start "$DR8_PORT" "$DR8/req.log" "$DR8_STATE"
doc_config "$DR8" "$DR8_PORT" "$DOC_BEARER" renew
jq '. + {auth_state:"dead", auth_dead_reason:"invalid_grant"}' "$DR8/config.json" > "$DR8/c.tmp" \
    && mv "$DR8/c.tmp" "$DR8/config.json" && chmod 600 "$DR8/config.json"
doc_run "$DR8"
assert_eq "$DOC_RC" "1" "doctor: a dead authorization exits 1"
doc_line "FAIL" "dead.*invalid_grant|invalid_grant.*dead" "doctor: a dead authorization FAILs with its reason"
doc_has "mnemon-authorize.sh\" http://127.0.0.1:$DR8_PORT cid" "doctor: the dead FAIL names the re-enrol command for this instance and client"
assert_eq "$(grep -c jsonrpc "$DR8/req.log")" "0" "doctor: a dead authorization makes no MCP call"
rf_stop_all

# DR9: loose permissions and recent errors are warnings; env credentials are named.
DR9_PORT=$(rf_free_port); DR9_STATE=$(mktemp -d); DR9=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR9"
rf_start "$DR9_PORT" "$DR9/req.log" "$DR9_STATE"
doc_config "$DR9" "$DR9_PORT" "$DOC_BEARER"
chmod 644 "$DR9/config.json"
printf '[2000-01-01T00:00:00Z] an error from long ago\n' > "$DR9/capture-errors.log"
# Untimestamped lines (stray stderr) have no date, so they are never "recent".
printf 'jq: an untimestamped stray line\n' >> "$DR9/capture-errors.log"
printf '[%s] HTTP 502 from somewhere: bad gateway\n' "$(date -u +%FT%TZ)" >> "$DR9/capture-errors.log"
doc_run "$DR9"
assert_eq "$DOC_RC" "0" "doctor: warnings alone exit 0"
doc_line "warn" "600|permission" "doctor: a config readable by others is a warning"
doc_has "chmod 600" "doctor: the permission warning names the fix"
doc_has "bad gateway" "doctor: shows a recent error from the log"
case "$DOC_OUT" in
    *"long ago"*) FAIL=$((FAIL+1)); printf '  FAIL: doctor: showed an error older than 24h\n';;
    *) PASS=$((PASS+1)); printf '  ok: doctor: errors older than 24h are left out\n';;
esac
case "$DOC_OUT" in
    *"untimestamped stray"*) FAIL=$((FAIL+1)); printf '  FAIL: doctor: showed an untimestamped line as recent\n';;
    *) PASS=$((PASS+1)); printf '  ok: doctor: untimestamped lines are not counted as recent\n';;
esac
rm -f "$DR9/config.json"
doc_run "$DR9" MNEMON_ENDPOINT="http://127.0.0.1:$DR9_PORT/mcp" MNEMON_TOKEN="$DOC_BEARER"
assert_eq "$DOC_RC" "0" "doctor: environment credentials count as configured"
doc_line "ok" "environment" "doctor: says the credentials come from the environment"
rf_stop_all

# DR10: MNEMON_DISABLE and a double registration are warnings.
DR10_PORT=$(rf_free_port); DR10_STATE=$(mktemp -d); DR10=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR10"
rf_start "$DR10_PORT" "$DR10/req.log" "$DR10_STATE"
doc_config "$DR10" "$DR10_PORT" "$DOC_BEARER"
printf '{"hooks":{"SessionStart":[{"hooks":[{"type":"command","command":"/x/mnemon-wake.sh"}]}]}}' > "$DR10/settings.json"
doc_run "$DR10" MNEMON_DISABLE=1 CLAUDE_PLUGIN_ROOT="$HOOKS_DIR/.."
assert_eq "$DOC_RC" "0" "doctor: opt-out and double registration are not failures"
doc_line "warn" "MNEMON_DISABLE|off on purpose" "doctor: MNEMON_DISABLE is a warning"
doc_line "warn" "twice|double" "doctor: a double registration is a warning"
rf_stop_all

# DR11: no jq. FAIL naming jq, stop there, exit 1. PATH is a directory of links
# to every tool on the box except jq.
NOJQ_BIN=$(mktemp -d)
for f in /usr/local/bin/* /usr/bin/* /bin/*; do
    n="${f##*/}"
    [ "$n" = jq ] && continue
    [ -e "$NOJQ_BIN/$n" ] || ln -s "$f" "$NOJQ_BIN/$n" 2>/dev/null
done
DR11=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR11 $NOJQ_BIN"
doc_config "$DR11" "$(rf_free_port)" "$DOC_BEARER"
doc_run "$DR11" PATH="$NOJQ_BIN"
assert_eq "$DOC_RC" "1" "doctor: missing jq exits 1"
doc_line "FAIL" "jq" "doctor: missing jq FAILs naming jq"
doc_no_line "ok|warn|FAIL" "reach|tool call" "doctor: stops after the missing jq"

# DR12: redaction must not shred the report. A one-letter secret redacted as a
# substring turned "passed" into "pa[redacted][redacted]ed" on every line.
DR12_PORT=$(rf_free_port); DR12_STATE=$(mktemp -d); DR12=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR12"
rf_start "$DR12_PORT" "$DR12/req.log" "$DR12_STATE"
jq -n --arg e "http://127.0.0.1:$DR12_PORT/mcp" --arg te "http://127.0.0.1:$DR12_PORT/oauth/token" \
    '{endpoint:$e, token_endpoint:$te, bearer_token:"t", refresh_token:"r", client_id:"c", client_secret:"s"}' \
    > "$DR12/config.json"
chmod 600 "$DR12/config.json"
doc_run "$DR12"
doc_line "ok" "palace_wake_up succeeded" "doctor: a short secret does not shred the report"
case "$DOC_OUT" in
    *"[redacted]"*) FAIL=$((FAIL+1)); printf '  FAIL: doctor: redacted text that was not a secret\n'; doc_show;;
    *) PASS=$((PASS+1)); printf '  ok: doctor: redacts nothing when no secret is in the text\n';;
esac
rf_stop_all

# DR13: a transient refresh failure is not a dead credential. mnemon_call logs
# "refresh failed ... keeping credentials" and then the 401; reading only the
# last line said "re-enrol", which would replace credentials that still work.
DR13_PORT=$(rf_free_port); DR13_STATE=$(mktemp -d); DR13=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR13"
rf_start "$DR13_PORT" "$DR13/req.log" "$DR13_STATE" FAKE_EXPIRE_FIRST=1 FAKE_REFRESH_FAIL=500
doc_config "$DR13" "$DR13_PORT" "$(make_jwt -600)" renew
DOC_JWT13=$(jq -r .bearer_token "$DR13/config.json")
doc_run "$DR13"
assert_eq "$DOC_RC" "1" "doctor: a transient refresh failure exits 1"
doc_line "FAIL" "renewal failed transiently" "doctor: a transient refresh failure is named as such"
case "$DOC_OUT" in
    *mnemon-authorize*) FAIL=$((FAIL+1)); printf '  FAIL: doctor: told a device with working credentials to re-enrol\n'; doc_show;;
    *) PASS=$((PASS+1)); printf '  ok: doctor: a transient refresh failure does not say re-enrol\n';;
esac
doc_has "http://127.0.0.1:$DR13_PORT/oauth/token" "doctor: the transient fix names the token endpoint"
assert_eq "$(jq -r .bearer_token "$DR13/config.json")" "$DOC_JWT13" "doctor: credentials are kept after a transient refresh failure"
rf_stop_all

# DR14: a refresh rejected with invalid_grant kills the authorization during
# the run; say so, with the re-enrol command for this client.
DR14_PORT=$(rf_free_port); DR14_STATE=$(mktemp -d); DR14=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR14"
rf_start "$DR14_PORT" "$DR14/req.log" "$DR14_STATE" FAKE_EXPIRE_FIRST=1 FAKE_REFRESH_FAIL=invalid_grant
doc_config "$DR14" "$DR14_PORT" "$(make_jwt -600)" renew
DOC_JWT14=$(jq -r .bearer_token "$DR14/config.json")
doc_run "$DR14"
assert_eq "$DOC_RC" "1" "doctor: a rejected refresh exits 1"
doc_line "FAIL" "authorization is now dead \(invalid_grant\)" "doctor: a rejected refresh says the authorization is now dead"
doc_has "mnemon-authorize.sh\" http://127.0.0.1:$DR14_PORT cid" "doctor: a rejected refresh names the re-enrol command with the client id"
rf_stop_all

# DR15: printed commands survive a path with a space (C:\Users\First Last).
DR15_BASE=$(mktemp -d); DOC_DIRS="$DOC_DIRS $DR15_BASE"
DR15="$DR15_BASE/First Last"; mkdir -p "$DR15/home"
DR15_PORT=$(rf_free_port); DR15_STATE=$(mktemp -d)
rf_start "$DR15_PORT" "$DR15_BASE/req.log" "$DR15_STATE"
doc_config "$DR15" "$DR15_PORT" "$DOC_BEARER"
chmod 644 "$DR15/config.json"
doc_run "$DR15"
dr15_fix=$(printf '%s\n' "$DOC_OUT" | grep 'fix: chmod 600' | head -1 | sed 's/^ *fix: //')
( eval "$dr15_fix" ) >/dev/null 2>&1
assert_eq "$(stat -c '%a' "$DR15/config.json")" "600" "doctor: the printed chmod works on a path with a space"
rf_stop_all
DR15_PLUG="$DR15_BASE/plug in/mnemon"; mkdir -p "$DR15_BASE/plug in"
cp -r "$HOOKS_DIR/.." "$DR15_PLUG"
rm -f "$DR15/config.json"
DOC_SCRIPT="$DR15_PLUG/scripts/mnemon-doctor.sh" doc_run "$DR15"
doc_has "bash \"$DR15_PLUG/scripts/mnemon-authorize.sh\"" "doctor: the enrol command quotes a plugin path with a space"
out=$(printf '{"session_id":"dr15","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | env -u MNEMON_ENDPOINT -u MNEMON_TOKEN HOME="$DR15/home" MNEMON_DIR="$DR15" bash "$DR15_PLUG/hooks/mnemon-wake.sh" 2>/dev/null)
case "$out" in
    *"bash \"$DR15_PLUG/scripts/mnemon-authorize.sh\""*) PASS=$((PASS+1)); printf '  ok: wake: the not-connected banner quotes the script path\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: wake: banner path unquoted, got: %s\n' "$out";;
esac

# DR16: the slash command's mode. --exit-zero reports the same and exits 0, so
# the command needs no "|| true" -- a compound command allowed-tools may not
# cover. Everything goes to stdout.
DOC_ARGS=--exit-zero doc_run "$DR2"
assert_eq "$DOC_RC" "0" "doctor: --exit-zero exits 0 even with a FAIL"
doc_has "1 problem(s) found." "doctor: --exit-zero still reports the problem"
dr16_line=$(grep '^!`' "$HOOKS_DIR/../commands/doctor.md")
case "$dr16_line" in
    *"--exit-zero"*) PASS=$((PASS+1)); printf '  ok: command: runs the doctor in --exit-zero mode\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: command: does not use --exit-zero: %s\n' "$dr16_line";;
esac
case "$dr16_line" in
    *"||"*|*"&&"*|*";"*|*"|"*|*">"*) FAIL=$((FAIL+1)); printf '  FAIL: command: the injection is not a single command: %s\n' "$dr16_line";;
    *) PASS=$((PASS+1)); printf '  ok: command: the injection is a single command\n';;
esac
if grep -q 'CLAUDE_PLUGIN_ROOT' <(grep -v '^!`' "$HOOKS_DIR/../commands/doctor.md"); then
    FAIL=$((FAIL+1)); printf '  FAIL: command: the Bash-tool fallback relies on CLAUDE_PLUGIN_ROOT\n'
else
    PASS=$((PASS+1)); printf '  ok: command: the Bash-tool fallback does not rely on CLAUDE_PLUGIN_ROOT\n'
fi

# DR17: a secret that does reach the text is redacted -- so the sweep below is
# not vacuous -- and URLs lose their userinfo and query string.
DR17_PORT=$(rf_free_port); DR17_STATE=$(mktemp -d); DR17=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR17"
rf_start "$DR17_PORT" "$DR17/req.log" "$DR17_STATE"
jq -n --arg e "http://user:pw-URLSECRET-9@127.0.0.1:$DR17_PORT/mcp?key=QUERYSECRET-9" --arg b "$DOC_BEARER" \
    '{endpoint:$e, bearer_token:$b}' > "$DR17/config.json"
chmod 600 "$DR17/config.json"
printf '[%s] upstream echoed: Authorization: Bearer %s\n' "$(date -u +%FT%TZ)" "$DOC_BEARER" > "$DR17/capture-errors.log"
doc_run "$DR17"
doc_has "[redacted]" "doctor: a token in an error line is shown as [redacted]"
case "$DOC_OUT" in
    *URLSECRET*|*QUERYSECRET*) FAIL=$((FAIL+1)); printf '  FAIL: doctor: printed URL userinfo or query string\n'; doc_show;;
    *) PASS=$((PASS+1)); printf '  ok: doctor: URLs are printed without userinfo or query string\n';;
esac
doc_line "ok" "tool call: palace_wake_up succeeded" "doctor: an endpoint with userinfo and query still works"
rf_stop_all

# DR18: a config with a token but no endpoint is not the credential source.
DR18_PORT=$(rf_free_port); DR18_STATE=$(mktemp -d); DR18=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR18"
rf_start "$DR18_PORT" "$DR18/req.log" "$DR18_STATE"
jq -n --arg b "$DOC_BEARER" '{bearer_token:$b}' > "$DR18/config.json"; chmod 600 "$DR18/config.json"
jq -n --arg u "http://127.0.0.1:$DR18_PORT/mcp" --arg a "Bearer $DOC_BEARER" \
    '{mcpServers:{mnemon:{url:$u, headers:{Authorization:$a}}}}' > "$DR18/home/.claude.json"
doc_run "$DR18"
doc_line "ok" "credentials from .*\.claude\.json" "doctor: a config without an endpoint is not named as the source"
rf_stop_all

# DR19: a 401 that survives a successful refresh names the client in the fix.
DR19_PORT=$(rf_free_port); DR19_STATE=$(mktemp -d); DR19=$(doc_dir); DOC_DIRS="$DOC_DIRS $DR19"
rf_start "$DR19_PORT" "$DR19/req.log" "$DR19_STATE" FAKE_STATUS=401
doc_config "$DR19" "$DR19_PORT" "$DOC_BEARER" renew
doc_run "$DR19"
doc_line "FAIL" "HTTP 401" "doctor: a 401 after a good refresh is an authentication FAIL"
doc_has "mnemon-authorize.sh\" http://127.0.0.1:$DR19_PORT cid" "doctor: the 401 fix names the client id"
rf_stop_all

# --- Plugin auto-update ---------------------------------------------------------
# Claude Code leaves auto-update off for third-party marketplaces, so a device
# stays on whatever it installed until someone runs "claude plugin update". The
# doctor reports the effective setting; the setup script turns it on.

# AU1-AU6: the doctor's line. No config, so the run stops at the config check --
# the plugin section, where this line lives, comes first.
AU=$(doc_dir); DOC_DIRS="$DOC_DIRS $AU"
au_line() { printf '%s\n' "$DOC_OUT" | grep -E '^(ok|warn|FAIL) +plugin auto-update' | head -1; }

# AU1: on via settings.json's extraKnownMarketplaces entry.
printf '{"extraKnownMarketplaces":{"mnemon":{"source":{"source":"github","repo":"coopers98/mnemon"},"autoUpdate":true}}}' > "$AU/settings.json"
printf '{"mnemon":{"source":{"source":"github","repo":"coopers98/mnemon"}}}' > "$AU/known_marketplaces.json"
au_sum=$(cat "$AU/settings.json" "$AU/known_marketplaces.json" | cksum)
doc_run "$AU"
case "$(au_line)" in
    "ok "*"auto-update: on"*settings.json*) PASS=$((PASS+1)); printf '  ok: doctor: auto-update on via settings.json\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: doctor: auto-update via settings.json, got: %s\n' "$(au_line)"; doc_show;;
esac
assert_eq "$(cat "$AU/settings.json" "$AU/known_marketplaces.json" | cksum)" "$au_sum" "doctor: never writes the Claude Code settings"

# AU2: on via known_marketplaces.json (what the /plugin UI writes).
printf '{"extraKnownMarketplaces":{"mnemon":{"source":{"source":"github","repo":"coopers98/mnemon"}}}}' > "$AU/settings.json"
printf '{"mnemon":{"source":{"source":"github","repo":"coopers98/mnemon"},"autoUpdate":true}}' > "$AU/known_marketplaces.json"
doc_run "$AU"
case "$(au_line)" in
    "ok "*"auto-update: on"*known_marketplaces.json*) PASS=$((PASS+1)); printf '  ok: doctor: auto-update on via known_marketplaces.json\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: doctor: auto-update via known_marketplaces.json, got: %s\n' "$(au_line)";;
esac

# AU3: settings.json wins. false there beats true in known_marketplaces.json.
printf '{"extraKnownMarketplaces":{"mnemon":{"source":{"source":"github","repo":"coopers98/mnemon"},"autoUpdate":false}}}' > "$AU/settings.json"
doc_run "$AU"
case "$(au_line)" in
    "warn "*"auto-update: off"*) PASS=$((PASS+1)); printf '  ok: doctor: settings.json takes precedence over known_marketplaces.json\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: doctor: precedence wrong, got: %s\n' "$(au_line)";;
esac

# AU4: off -- a warning with the manual command and the fix, never a FAIL.
rm -f "$AU/settings.json" "$AU/known_marketplaces.json"
doc_run "$AU"
case "$(au_line)" in
    "warn "*"auto-update: off"*'claude plugin update mnemon@mnemon'*) PASS=$((PASS+1)); printf '  ok: doctor: auto-update off is a warning naming the manual update\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: doctor: auto-update off, got: %s\n' "$(au_line)"; doc_show;;
esac
doc_has "/plugin" "doctor: the auto-update fix names the /plugin UI path"
doc_has "/install" "doctor: the auto-update fix names the setup script"
doc_no_line "FAIL" "auto-update" "doctor: auto-update off is never a FAIL"

# AU4b: the line says what it can see -- user settings only. Project and local
# settings can override them, and the doctor does not know which project.
printf '{"extraKnownMarketplaces":{"mnemon":{"source":{"source":"github","repo":"coopers98/mnemon"},"autoUpdate":true}}}' > "$AU/settings.json"
doc_run "$AU"
case "$(au_line)" in
    *"user settings"*) PASS=$((PASS+1)); printf '  ok: doctor: the auto-update line says it reads user settings only\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: doctor: auto-update scope unstated, got: %s\n' "$(au_line)";;
esac

# AU4c: a Windows jq ends its lines with CRLF; "true\r" must still read as on.
AU_CRLF=$(mktemp -d); DOC_DIRS="$DOC_DIRS $AU_CRLF"
printf '#!/usr/bin/env bash\n%s "$@" | sed "s/$/\\r/"\nexit "${PIPESTATUS[0]}"\n' "$(command -v jq)" > "$AU_CRLF/jq"
chmod +x "$AU_CRLF/jq"
doc_run "$AU" PATH="$AU_CRLF:$PATH"
case "$(au_line)" in
    "ok "*"auto-update"*": on"*) PASS=$((PASS+1)); printf '  ok: doctor: a CRLF jq still reads auto-update as on\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: doctor: CRLF jq broke the auto-update read, got: %s\n' "$(au_line)";;
esac

# AU5: on, but switched off by the environment.
printf '{"extraKnownMarketplaces":{"mnemon":{"source":{"source":"github","repo":"coopers98/mnemon"},"autoUpdate":true}}}' > "$AU/settings.json"
doc_run "$AU" DISABLE_AUTOUPDATER=1
case "$(au_line)" in
    "warn "*DISABLE_AUTOUPDATER*) PASS=$((PASS+1)); printf '  ok: doctor: names the env var that disables auto-update\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: doctor: env-disabled auto-update, got: %s\n' "$(au_line)";;
esac
doc_run "$AU" CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1
case "$(au_line)" in
    "warn "*CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC*) PASS=$((PASS+1)); printf '  ok: doctor: nonessential-traffic switch counts as disabling\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: doctor: nonessential traffic not reported, got: %s\n' "$(au_line)";;
esac

# AU6: FORCE_AUTOUPDATE_PLUGINS overrides the disabling switch.
doc_run "$AU" DISABLE_UPDATES=1 FORCE_AUTOUPDATE_PLUGINS=1
case "$(au_line)" in
    "ok "*"auto-update: on"*FORCE_AUTOUPDATE_PLUGINS*) PASS=$((PASS+1)); printf '  ok: doctor: FORCE_AUTOUPDATE_PLUGINS overrides the disabling switch\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: doctor: force override, got: %s\n' "$(au_line)";;
esac

# --- Setup script: turning auto-update on --------------------------------------
# The stub is what /install serves, with two placeholders filled in. Rendered
# here the way the controller renders it, then either sourced for its enable
# function or run whole against a fake `claude`. Every case has its own HOME and
# CLAUDE_CONFIG_DIR, so the real ~/.claude is never in reach.
STUB="$THIS_DIR/../../resources/install/install.sh.stub"
IN_DIR=$(mktemp -d); DOC_DIRS="$DOC_DIRS $IN_DIR"
IN_SH="$IN_DIR/install.sh"
sed -e 's#{{INSTANCE_URL}}#https://mnemon.example.test#g' -e 's#{{MARKETPLACE}}#coopers98/mnemon#g' "$STUB" > "$IN_SH"
# A `claude` that refuses everything, first on PATH for the enable-step cases:
# if the library guard ever regressed, sourcing the stub would run the whole
# install, and it must not reach the real binary.
mkdir -p "$IN_DIR/nobin"
printf '#!/usr/bin/env bash\nexit 1\n' > "$IN_DIR/nobin/claude"; chmod +x "$IN_DIR/nobin/claude"

# Args: <case-dir> [VAR=value ...]. Runs the enable step alone; sets IN_OUT.
in_enable() {
    local d="$1"; shift
    mkdir -p "$d/home" "$d/claude"
    IN_OUT=$(env -u MNEMON_NO_AUTOUPDATE PATH="$IN_DIR/nobin:$PATH" HOME="$d/home" CLAUDE_CONFIG_DIR="$d/claude" "$@" \
        bash -c 'MNEMON_INSTALL_LIB_ONLY=1; . "$1"; mnemon_enable_autoupdate' _ "$IN_SH" 2>&1)
    IN_RC=$?
}
in_has() {
    case "$IN_OUT" in
        *"$1"*) PASS=$((PASS+1)); printf '  ok: %s\n' "$2";;
        *) FAIL=$((FAIL+1)); printf '  FAIL: %s\n    output: %s\n' "$2" "$IN_OUT";;
    esac
}

# I1: no settings file yet -- created with the right source and autoUpdate.
I1="$IN_DIR/i1"
in_enable "$I1"
assert_eq "$IN_RC" "0" "install: enabling auto-update succeeds with no settings file"
assert_eq "$(jq -c '.extraKnownMarketplaces.mnemon' "$I1/claude/settings.json" 2>/dev/null)" \
    '{"source":{"source":"github","repo":"coopers98/mnemon"},"autoUpdate":true}' \
    "install: creates the marketplace entry with its github source and autoUpdate"
in_has "Turned on auto-update for the mnemon marketplace in $I1/claude/settings.json" "install: says what it did"

# I2: an existing entry without autoUpdate, among other settings. Merged in;
# nothing else moves; a backup of the original exists; the file mode is kept.
I2="$IN_DIR/i2"; mkdir -p "$I2/claude"
cat > "$I2/claude/settings.json" <<'JSON'
{
  "theme": "dark",
  "hooks": {"Stop": [{"hooks": [{"type": "command", "command": "/x/other.sh"}]}]},
  "extraKnownMarketplaces": {
    "mnemon": {"source": {"source": "github", "repo": "someone-else/mnemon"}},
    "other": {"source": {"source": "github", "repo": "a/b"}}
  }
}
JSON
chmod 640 "$I2/claude/settings.json"
i2_orig=$(cat "$I2/claude/settings.json")
in_enable "$I2"
assert_eq "$(jq -c '.extraKnownMarketplaces.mnemon' "$I2/claude/settings.json")" \
    '{"source":{"source":"github","repo":"someone-else/mnemon"},"autoUpdate":true}' \
    "install: merges autoUpdate into the existing entry and keeps its source"
assert_eq "$(jq -c 'del(.extraKnownMarketplaces.mnemon.autoUpdate)' "$I2/claude/settings.json")" \
    "$(printf '%s' "$i2_orig" | jq -c .)" "install: every other setting is preserved"
i2_backup=$(ls "$I2/claude"/settings.json.mnemon-backup-* 2>/dev/null | head -1)
assert_eq "$(cat "$i2_backup" 2>/dev/null)" "$i2_orig" "install: backs up settings.json before changing it"
assert_eq "$(stat -c '%a' "$I2/claude/settings.json")" "640" "install: keeps the settings file's mode"
assert_eq "$(ls -A "$I2/claude" | grep -c 'tmp')" "0" "install: leaves no temp file behind"

# I3: settings.json that is not plain JSON (comments) is left byte-identical.
I3="$IN_DIR/i3"; mkdir -p "$I3/claude"
printf '{\n  // my settings\n  "theme": "dark"\n}\n' > "$I3/claude/settings.json"
i3_orig=$(cksum < "$I3/claude/settings.json")
in_enable "$I3"
assert_eq "$IN_RC" "0" "install: unreadable settings do not abort the setup"
assert_eq "$(cksum < "$I3/claude/settings.json")" "$i3_orig" "install: settings that are not valid JSON are left untouched"
assert_eq "$(ls "$I3/claude"/settings.json.mnemon-backup-* 2>/dev/null | wc -l | tr -d ' ')" "0" "install: no backup when nothing was changed"
in_has "/plugin" "install: unreadable settings get the manual /plugin instruction"

# I4: the opt-out.
I4="$IN_DIR/i4"; mkdir -p "$I4/claude"
printf '{"theme":"dark"}' > "$I4/claude/settings.json"
in_enable "$I4" MNEMON_NO_AUTOUPDATE=1
assert_eq "$(cat "$I4/claude/settings.json")" '{"theme":"dark"}' "install: MNEMON_NO_AUTOUPDATE=1 changes nothing"
in_has "MNEMON_NO_AUTOUPDATE" "install: the opt-out says it was honoured"

# I5: already on -- nothing to do, nothing rewritten.
I5="$IN_DIR/i5"; mkdir -p "$I5/claude"
printf '{"extraKnownMarketplaces":{"mnemon":{"source":{"source":"github","repo":"coopers98/mnemon"},"autoUpdate":true}}}' > "$I5/claude/settings.json"
i5_orig=$(cksum < "$I5/claude/settings.json")
in_enable "$I5"
assert_eq "$(cksum < "$I5/claude/settings.json")" "$i5_orig" "install: an entry already on is not rewritten"
in_has "already on" "install: says auto-update was already on"

# I1b: a brand-new settings file is created owner-only.
assert_eq "$(stat -c '%a' "$I1/claude/settings.json")" "600" "install: a new settings file is created 0600"

# I7: a symlinked settings.json (stow-style dotfiles) is left alone: mv would
# replace the link with a regular file and leave the real one stale.
I7="$IN_DIR/i7"; mkdir -p "$I7/claude" "$I7/dotfiles"
printf '{"theme":"dark"}' > "$I7/dotfiles/settings.json"
ln -s "$I7/dotfiles/settings.json" "$I7/claude/settings.json"
in_enable "$I7"
assert_eq "$([ -L "$I7/claude/settings.json" ] && echo link || echo file)" "link" "install: a symlinked settings.json stays a symlink"
assert_eq "$(cat "$I7/dotfiles/settings.json")" '{"theme":"dark"}' "install: the symlink's target is unchanged"
in_has "symlink" "install: says the settings file is a symlink"
in_has "/plugin" "install: a symlinked settings.json gets the manual /plugin instruction"

# I8: an empty settings.json is treated as {} -- and still backed up.
I8="$IN_DIR/i8"; mkdir -p "$I8/claude"
printf '  \n' > "$I8/claude/settings.json"
in_enable "$I8"
assert_eq "$(jq -r '.extraKnownMarketplaces.mnemon.autoUpdate' "$I8/claude/settings.json" 2>/dev/null)" "true" "install: an empty settings.json is treated as {}"
assert_eq "$(ls "$I8/claude"/settings.json.mnemon-backup-* 2>/dev/null | wc -l | tr -d ' ')" "1" "install: an empty settings.json is still backed up"
case "$IN_OUT" in
    *"comments?"*) FAIL=$((FAIL+1)); printf '  FAIL: install: an empty file was blamed on comments\n';;
    *) PASS=$((PASS+1)); printf '  ok: install: an empty file is not blamed on comments\n';;
esac

# I9: the library guard must not error when the script is executed, not sourced.
I9="$IN_DIR/i9"; mkdir -p "$I9/home" "$I9/claude"
i9_out=$(PATH="$IN_DIR/nobin:$PATH" HOME="$I9/home" CLAUDE_CONFIG_DIR="$I9/claude" MNEMON_INSTALL_LIB_ONLY=1 bash "$IN_SH" 2>&1); i9_rc=$?
assert_eq "$i9_rc:$i9_out" "0:" "install: executed with the library guard, it exits 0 quietly"

# I6: the whole script, against a fake `claude` that records what it was asked.
I6="$IN_DIR/i6"; mkdir -p "$I6/bin" "$I6/home" "$I6/claude"
cat > "$I6/bin/claude" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$FAKE_CLAUDE_LOG"
exit 0
FAKE
chmod +x "$I6/bin/claude"
i6_out=$(env -u MNEMON_NO_AUTOUPDATE PATH="$I6/bin:$PATH" HOME="$I6/home" CLAUDE_CONFIG_DIR="$I6/claude" \
    FAKE_CLAUDE_LOG="$I6/claude.log" bash "$IN_SH" 2>&1); i6_rc=$?
assert_eq "$i6_rc" "0" "install: the whole script runs to the end"
case "$i6_out" in
    *"Turned on auto-update for the mnemon marketplace"*) PASS=$((PASS+1)); printf '  ok: install: the full run turns auto-update on\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: install: full run did not enable auto-update:\n%s\n' "$i6_out";;
esac
assert_eq "$(jq -r '.extraKnownMarketplaces.mnemon.autoUpdate' "$I6/claude/settings.json" 2>/dev/null)" "true" \
    "install: the full run leaves autoUpdate true in settings.json"
case "$i6_out" in
    *"Plugin installed."*"auto-update"*"One step left"*) PASS=$((PASS+1)); printf '  ok: install: auto-update comes after the plugin install, before the last step\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: install: auto-update step out of place\n';;
esac
i6b_out=$(PATH="$I6/bin:$PATH" HOME="$I6/home" CLAUDE_CONFIG_DIR="$IN_DIR/i6b" MNEMON_NO_AUTOUPDATE=1 \
    FAKE_CLAUDE_LOG="$I6/claude.log" bash "$IN_SH" 2>&1)
case "$i6b_out" in
    *"MNEMON_NO_AUTOUPDATE"*) PASS=$((PASS+1)); printf '  ok: install: a full run with the opt-out says so\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: install: full opt-out run silent about it\n';;
esac
assert_eq "$([ -e "$IN_DIR/i6b/settings.json" ] && echo exists || echo absent)" "absent" "install: a full run with the opt-out writes no settings"

# No run, healthy or not, prints a secret.
for s in "$DOC_BEARER" "$DOC_REFRESH" "$DOC_CSEC" "$DOC_JWT1" "$DOC_JWT6" "$DOC_JWT7" "$DOC_JWT13" "$DOC_JWT14" "token-2" "refresh-2"; do
    case "$DOC_ALL" in
        *"$s"*) FAIL=$((FAIL+1)); printf '  FAIL: doctor: printed a secret (%s...)\n' "${s:0:12}";;
        *) PASS=$((PASS+1)); printf '  ok: doctor: never prints %s...\n' "${s:0:12}";;
    esac
done

# The wake banners point at the doctor as the next step.
mv "$MNEMON_DIR/config.json" "$MNEMON_DIR/config.dr.json" 2>/dev/null
out=$(printf '{"session_id":"dr-w1","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | env -u MNEMON_ENDPOINT -u MNEMON_TOKEN HOME="$DR2/home" "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$out" in
    *"/mnemon:doctor"*) PASS=$((PASS+1)); printf '  ok: wake: the not-connected banner points at the doctor\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: wake: not-connected banner does not mention the doctor, got: %s\n' "$out";;
esac
jq -n '{endpoint:"http://127.0.0.1:1/mcp", bearer_token:"t", refresh_token:"r", client_id:"c",
        client_secret:"s", token_endpoint:"http://127.0.0.1:1/oauth/token",
        auth_state:"dead", auth_dead_reason:"invalid_grant"}' > "$MNEMON_DIR/config.json"
out=$(printf '{"session_id":"dr-w2","cwd":"/tmp","hook_event_name":"SessionStart"}' \
    | env -u MNEMON_ENDPOINT -u MNEMON_TOKEN "$HOOKS_DIR/mnemon-wake.sh" 2>/dev/null)
case "$out" in
    *"/mnemon:doctor"*) PASS=$((PASS+1)); printf '  ok: wake: the dead banner points at the doctor\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: wake: dead banner does not mention the doctor, got: %s\n' "$out";;
esac
mv "$MNEMON_DIR/config.dr.json" "$MNEMON_DIR/config.json" 2>/dev/null
for d in $DOC_DIRS; do
    case "$d" in /tmp/*|"${TMPDIR:-/tmp}"/*) rm -rf "${d:?}" ;; esac
done

# --- backfill scan -------------------------------------------------------------
# mnemon-backfill with no arguments is the free scan: it says what an import
# would send and what it would cost, and sends nothing. Everything here runs in
# a HOME of its own with a synthetic ~/.claude/projects tree built below; no
# real transcript is read.
BACKFILL_SH="$HOOKS_DIR/../scripts/mnemon-backfill.sh"
BACKFILL_LIB="$HOOKS_DIR/../scripts/lib/backfill-scan.sh"
BF=$(mktemp -d)
BF_HOME="$BF/home"
BF_MN="$BF_HOME/.mnemon"
BF_ROOT="$BF_HOME/.claude/projects"
mkdir -p "$BF_MN/sessions" "$BF_ROOT" "$BF/work"
BF_SECRET="sk-bfSECRETabcdefghijklmnop0123"

# Repositories the sessions ran in. atlas has a remote, so its name comes from
# the remote; the others are named for their directory.
for r in atlas cron heart beacon ignoredrepo; do git init -q "$BF/work/$r"; done
git -C "$BF/work/atlas" remote add origin "https://example.com/me/atlas.git"

# Args: <file> <session_id> <cwd> <entrypoint> <text>... One record per text,
# user and assistant alternating, starting with the user. A text that starts
# with "U:" is forced to a user record (two user records in a row).
bf_session() {
    local file="$1" sid="$2" cwd="$3" ep="$4" role=user n=0 t
    shift 4
    mkdir -p "$(dirname "$file")"
    : > "$file"
    for t in "$@"; do
        case "$t" in U:*) role=user; t="${t#U:}" ;; esac
        n=$((n + 1))
        jq -cn --arg r "$role" --arg s "$sid" --arg c "$cwd" --arg e "$ep" --arg t "$t" \
            --arg ts "2026-09-0$(( (n % 9) + 1 ))T10:00:00.123Z" \
            '{type:$r, sessionId:$s, cwd:$c, entrypoint:$e, timestamp:$ts, isSidechain:false,
              message:{role:$r, content:(if $r == "user" then $t else [{type:"text", text:$t}] end)}}' >> "$file"
        if [ "$role" = user ]; then role=assistant; else role=user; fi
    done
}
bf_id() { printf 'bf000000-0000-0000-0000-%012d' "$1"; }

A="$BF_ROOT/-work-atlas"
bf_session "$A/$(bf_id 1).jsonl" "$(bf_id 1)" "$BF/work/atlas" cli "Plan the atlas migration" "Here is the plan." "Ship it" "Done."
bf_session "$A/$(bf_id 2).jsonl" "$(bf_id 2)" "$BF/work/atlas" sdk-py "Review the atlas schema" "Looks fine." "Thanks" "Welcome."
# @nomemo in the third user record, straight after the second: the cleaner
# would merge the two, so the test must see each record on its own.
bf_session "$A/$(bf_id 3).jsonl" "$(bf_id 3)" "$BF/work/atlas" cli "Hello atlas" "Hi." "second thought" "U:  @NoMemo keep this between us" "Understood."
# A subagent's transcript carries its parent's sessionId; it is never read.
bf_session "$A/$(bf_id 1)/subagents/agent-1.jsonl" "$(bf_id 1)" "$BF/work/atlas" cli "SUBAGENT prompt" "SUBAGENT reply"
bf_session "$A/$(bf_id 5).jsonl" "$(bf_id 5)" "$BF/work/atlas" cli "Already digested session" "Yes." "Again" "Yes."
jq -n --argjson n "$(wc -l < "$A/$(bf_id 5).jsonl" | tr -d ' ')" '{last_digest_turn:$n}' > "$BF_MN/sessions/$(bf_id 5).json"
bf_session "$A/$(bf_id 6).jsonl" "other-session-id" "$BF/work/atlas" cli "Mismatched id" "Ok."
bf_session "$A/$(bf_id 7).jsonl" "$(bf_id 7)" "$BF/work/atlas" cli "Session held by live capture" "Ok."
mkdir "$BF_MN/sessions/$(bf_id 7).digest.lock.d" && echo "$$" > "$BF_MN/sessions/$(bf_id 7).digest.lock.d/pid"
bf_session "$A/$(bf_id 8).jsonl" "$(bf_id 8)" "$BF/work/atlas" cli "Disabled session" "Ok."
printf '{"last_digest_turn":0,"disabled":true}' > "$BF_MN/sessions/$(bf_id 8).json"
mkdir -p "$A/memory"
printf '# Note A\nThe atlas deploy runs on Fridays.\n' > "$A/memory/note-a.md"
printf '# Note B\nUse the staging bucket.\n' > "$A/memory/note-b.md"
printf -- '- [Note A](note-a.md)\n' > "$A/memory/MEMORY.md"
# A 5-session cluster of one first prompt (automation, skipped) and a 4-session
# one (under the threshold, eligible -- and still listed).
for i in 11 12 13 14 15; do
    bf_session "$BF_ROOT/-work-cron/$(bf_id $i).jsonl" "$(bf_id $i)" "$BF/work/cron" cli \
        "Heartbeat $BF_SECRET: check the queue and report" "Queue is empty ($i)."
done
for i in 21 22 23 24; do
    bf_session "$BF_ROOT/-work-heart/$(bf_id $i).jsonl" "$(bf_id $i)" "$BF/work/heart" sdk-py \
        "Summarise the overnight logs" "Nothing unusual ($i)."
done
# A cwd that no longer exists, under a directory name with a space.
bf_session "$BF_ROOT/-tmp-my dir/$(bf_id 31).jsonl" "$(bf_id 31)" "$BF/gone/my dir" cli "Work in a dir that is gone" "Ok."
bf_session "$BF_ROOT/-work-beacon/$(bf_id 41).jsonl" "$(bf_id 41)" "$BF/work/beacon" cli "A beacon session" "Ok."
bf_session "$BF_ROOT/-work-ignoredrepo/$(bf_id 51).jsonl" "$(bf_id 51)" "$BF/work/ignoredrepo" cli "An ignored repo" "Ok."
# Everything is old except the one file modified now.
find "$BF_ROOT" -type f -exec touch -t 202601010000 {} +
bf_session "$A/$(bf_id 4).jsonl" "$(bf_id 4)" "$BF/work/atlas" cli "A live session" "Still going."

BF_STATE=$(mktemp -d)
BF_PORT=$(rf_free_port); BF_LOG="$BF/req.log"
rf_start "$BF_PORT" "$BF_LOG" "$BF_STATE" FAKE_DIGEST=priced FAKE_DIGEST_WINGS="atlas,cron,heart"
BF_PORT_U=$(rf_free_port); BF_LOG_U="$BF/req-u.log"
rf_start "$BF_PORT_U" "$BF_LOG_U" "$BF_STATE" FAKE_DIGEST=unpriced FAKE_DIGEST_WINGS="atlas,cron,heart"
BF_PORT_OLD=$(rf_free_port); BF_LOG_OLD="$BF/req-old.log"
rf_start "$BF_PORT_OLD" "$BF_LOG_OLD" "$BF_STATE"
# Args: <port>. Points the sandbox's config at a server.
bf_config() {
    jq -n --arg e "http://127.0.0.1:$1/mcp" \
        '{endpoint:$e, bearer_token:"bf-token", capture_ignore:["ignoredrepo"]}' > "$BF_MN/config.json"
}
bf_config "$BF_PORT"

# A snapshot of everything under ~/.mnemon: names, sizes and contents.
bf_snapshot() {
    (cd "$BF_MN" && find . -print | LC_ALL=C sort && find . -type f -exec cksum {} + | LC_ALL=C sort)
}
BF_BEFORE=$(bf_snapshot)

# Args: [script args...]. Runs the scan in the sandbox; sets BF_OUT and BF_RC.
bf_run() {
    BF_OUT=$(env -u MNEMON_ENDPOINT -u MNEMON_TOKEN -u CLAUDE_CONFIG_DIR \
        -u CLAUDE_PLUGIN_OPTION_MNEMON_ENDPOINT -u CLAUDE_PLUGIN_OPTION_MNEMON_TOKEN \
        HOME="$BF_HOME" MNEMON_DIR="$BF_MN" bash "$BACKFILL_SH" "$@" 2>&1)
    BF_RC=$?
}
# Args: <function> [args...]. Runs one of the scan library's functions on its
# own, with the flags in BF_ARGS; its output is the function's.
bf_lib() {
    local fn="$1"; shift
    env -u MNEMON_ENDPOINT -u MNEMON_TOKEN -u CLAUDE_CONFIG_DIR \
        -u CLAUDE_PLUGIN_OPTION_MNEMON_ENDPOINT -u CLAUDE_PLUGIN_OPTION_MNEMON_TOKEN \
        HOME="$BF_HOME" MNEMON_DIR="$BF_MN" BF_ARGS="${BF_ARGS:-}" bash -c '
            . "$1"; shift
            fn="$1"; shift
            backfill_parse_args ${BF_ARGS:-} || exit 2
            "$fn" "$BACKFILL_PROJECTS_ROOT" "$@"' _ "$BACKFILL_LIB" "$fn" "$@" 2>/dev/null
}
bf_show() { printf '    output was:\n%s\n' "$(printf '%s\n' "$BF_OUT" | sed 's/^/      /')"; }
bf_has() {
    case "$BF_OUT" in
        *"$1"*) PASS=$((PASS+1)); printf '  ok: %s\n' "$2" ;;
        *) FAIL=$((FAIL+1)); printf '  FAIL: %s\n' "$2"; bf_show ;;
    esac
}
bf_lacks() {
    case "$BF_OUT" in
        *"$1"*) FAIL=$((FAIL+1)); printf '  FAIL: %s\n' "$2"; bf_show ;;
        *) PASS=$((PASS+1)); printf '  ok: %s\n' "$2" ;;
    esac
}
# Args: <tsv> <session_id>. Echoes "status reason" for that session.
bf_status() { awk -F'\t' -v s="$2" '$1 == s { print $5 " " $6 }' <<< "$1"; }

bf_run
assert_eq "$BF_RC" "0" "backfill scan: exits 0"
assert_eq "$(grep -c '"brain_status"' "$BF_LOG")" "1" "backfill scan: asks brain_status exactly once"
assert_eq "$(grep -c '"session_digest"' "$BF_LOG")" "0" "backfill scan: sends no session_digest"
assert_eq "$(grep -c '"drawer_add"' "$BF_LOG")" "0" "backfill scan: sends no drawer_add"
bf_has "Per project" "backfill scan: prints the per-project table"
bf_has "Skipped" "backfill scan: prints the skip summary"
bf_has "[REDACTED-OPENAI-KEY]" "backfill scan: a cluster's prompt is shown redacted"
bf_lacks "$BF_SECRET" "backfill scan: a secret in a prompt is never printed"
bf_lacks "SUBAGENT" "backfill scan: a subagent transcript is never read"
case "$BF_OUT" in
    *"heart"*"sdk-py 4"*) PASS=$((PASS+1)); printf '  ok: backfill scan: prints the entrypoint mix per project\n' ;;
    *) FAIL=$((FAIL+1)); printf '  FAIL: backfill scan: no entrypoint mix for heart\n'; bf_show ;;
esac
if printf '%s\n' "$BF_OUT" | grep -E '^ +cost: ' | grep -qE '\$[0-9]+\.[0-9]{2} to \$[0-9]+\.[0-9]{2}'; then
    PASS=$((PASS+1)); printf '  ok: backfill scan: a published price gives a dollar range\n'
else
    FAIL=$((FAIL+1)); printf '  FAIL: backfill scan: no dollar range with a published price\n'; bf_show
fi
bf_has "20-60 s per call" "backfill scan: estimates wall-clock time at 20-60 s per call"
# The cluster's prompt is cut to 60 characters after redaction.
bf_cluster=$(printf '%s\n' "$BF_OUT" | grep -F '[REDACTED-OPENAI-KEY]' | head -n 1 | sed 's/.*"\(.*\)".*/\1/')
assert_eq "$([ "${#bf_cluster}" -le 60 ] && echo short)" "short" "backfill scan: a cluster's prompt is cut to 60 characters"

BF_TSV=$(bf_lib backfill_scan)
assert_eq "$(bf_status "$BF_TSV" "$(bf_id 1)")" "eligible " "backfill scan: a real-work session is eligible"
assert_eq "$(bf_status "$BF_TSV" "$(bf_id 2)")" "eligible " "backfill scan: an sdk-py work session is eligible too"
assert_eq "$(bf_status "$BF_TSV" "$(bf_id 3)")" "skip nomemo" "backfill scan: @nomemo in a merged user record skips the session"
assert_eq "$(bf_status "$BF_TSV" "$(bf_id 4)")" "skip recent" "backfill scan: a file modified now is left to its hooks"
assert_eq "$(bf_status "$BF_TSV" "$(bf_id 5)")" "skip done" "backfill scan: a pointer at the line count is done"
assert_eq "$(bf_status "$BF_TSV" "$(bf_id 6)")" "skip id_mismatch" "backfill scan: a mismatched sessionId is skipped"
assert_eq "$(bf_status "$BF_TSV" "$(bf_id 7)")" "skip busy" "backfill scan: a held digest lock is busy"
assert_eq "$(bf_status "$BF_TSV" "$(bf_id 8)")" "skip disabled" "backfill scan: a disabled state file skips the session"
for i in 11 12 13 14 15; do
    assert_eq "$(bf_status "$BF_TSV" "$(bf_id $i)")" "skip automation" "backfill scan: 5-cluster session $i is automation"
done
for i in 21 22 23 24; do
    assert_eq "$(bf_status "$BF_TSV" "$(bf_id $i)")" "eligible " "backfill scan: 4-cluster session $i is eligible"
done
assert_eq "$(bf_status "$BF_TSV" "$(bf_id 31)")" "skip unresolved" "backfill scan: a cwd that is gone is unresolved"
assert_eq "$(bf_status "$BF_TSV" "$(bf_id 41)")" "skip new_project" "backfill scan: a project with no wing is new"
assert_eq "$(bf_status "$BF_TSV" "$(bf_id 51)")" "skip capture_ignore" "backfill scan: capture_ignore is honoured"
assert_eq "$(printf '%s\n' "$BF_TSV" | grep -c 'agent-1')" "0" "backfill scan: the subagent file is absent"
assert_eq "$(printf '%s\n' "$BF_TSV" | awk -F'\t' 'NF != 13' | wc -l | tr -d ' ')" "0" "backfill scan: every row has 13 columns"
assert_eq "$(awk -F'\t' -v s="$(bf_id 1)" '$1 == s { print $3 "|" $4 "|" $7 "|" $8 "|" $12 }' <<< "$BF_TSV")" \
    "atlas|atlas|4|0|cli" "backfill scan: a row names project, repo, lines, pointer and entrypoint"
assert_eq "$(awk -F'\t' -v s="$(bf_id 1)" '$1 == s { print ($9 >= 1 && $10 > 0) ? "y" : "n" }' <<< "$BF_TSV")" "y" \
    "backfill scan: an eligible session is simulated to at least one call"
assert_eq "$(awk -F'\t' '$1 ~ /bf000000-0000-0000-0000-00000000001[1-5]/ { print $11 }' <<< "$BF_TSV" | sort -u | wc -l | tr -d ' ')" "1" \
    "backfill scan: a cluster shares one first-prompt hash"

# The estimate follows from the rows and the server's published figures:
# overhead 2000 (to 1.5x), 2500 output tokens per call.
bf_calls=$(awk -F'\t' '$5 == "eligible" { c += $9 } END { print c + 0 }' <<< "$BF_TSV")
bf_chars=$(awk -F'\t' '$5 == "eligible" { c += $10 } END { print c + 0 }' <<< "$BF_TSV")
bf_in_low=$(( (bf_chars + 3) / 4 + bf_calls * 2000 ))
bf_in_high=$(( (bf_chars + 3) / 4 + bf_calls * 3000 ))
bf_has "input tokens:  $bf_in_low to $bf_in_high" "backfill scan: input tokens are chunk characters / 4 plus overhead to 1.5x"
bf_has "output tokens: $((bf_calls * 2500))" "backfill scan: output tokens use the server's typical figure"

BF_NOTES=$(bf_lib backfill_notes)
assert_eq "$(awk -F'\t' '$1 ~ /note-a\.md$/ { print $2 " " $3 }' <<< "$BF_NOTES")" "atlas eligible" "backfill notes: a memory note is eligible under its project"
assert_eq "$(awk -F'\t' '$1 ~ /note-b\.md$/ { print $3 }' <<< "$BF_NOTES")" "eligible" "backfill notes: every note is listed"
assert_eq "$(printf '%s\n' "$BF_NOTES" | grep -c 'MEMORY\.md')" "0" "backfill notes: MEMORY.md is absent"
assert_eq "$(printf '%s\n' "$BF_NOTES" | awk -F'\t' 'NF != 6' | wc -l | tr -d ' ')" "0" "backfill notes: every row has 6 columns"

# --include brings in an unresolved directory, by its name, and a new project.
BF_TSV2=$(env -u MNEMON_ENDPOINT -u MNEMON_TOKEN -u CLAUDE_CONFIG_DIR \
    HOME="$BF_HOME" MNEMON_DIR="$BF_MN" bash -c '. "$1"; backfill_parse_args --include "tmp-my dir" --include beacon \
        --include-automation "$2" && backfill_scan "$BACKFILL_PROJECTS_ROOT"' _ "$BACKFILL_LIB" \
        "$(awk -F'\t' -v s="$(bf_id 11)" '$1 == s { print $11 }' <<< "$BF_TSV")" 2>/dev/null)
assert_eq "$(awk -F'\t' -v s="$(bf_id 31)" '$1 == s { print $3 " " $5 }' <<< "$BF_TSV2")" "tmp-my dir eligible" \
    "backfill scan: --include files an unresolved directory under its name"
assert_eq "$(bf_status "$BF_TSV2" "$(bf_id 41)")" "eligible " "backfill scan: --include brings in a new project"
assert_eq "$(bf_status "$BF_TSV2" "$(bf_id 11)")" "eligible " "backfill scan: --include-automation brings a cluster back"
BF_TSV3=$(BF_ARGS="--skip-entrypoint sdk --project heart" bf_lib backfill_scan)
assert_eq "$(bf_status "$BF_TSV3" "$(bf_id 21)")" "skip automation" "backfill scan: --skip-entrypoint skips by entrypoint prefix"
assert_eq "$(bf_status "$BF_TSV3" "$(bf_id 1)")" "skip not_included" "backfill scan: --project leaves other projects out"

# No published price: tokens and calls, and the dollars say unknown.
bf_config "$BF_PORT_U"
bf_run
bf_has "cost:          unknown (no price published)" "backfill scan: no published price says unknown"
# A server too old to publish a digest block: unknown dollars, fallback figures.
bf_config "$BF_PORT_OLD"
bf_run --include beacon
assert_eq "$BF_RC" "0" "backfill scan: an older server still gets a scan"
bf_has "cost:          unknown (no price published)" "backfill scan: an older server's price is unknown"
bf_has "output tokens: 3000" "backfill scan: an older server uses the fallback output figure"
bf_config "$BF_PORT"

# Sampling: when simulating every file would take too long, a size-stratified
# sample is simulated and the totals say so.
bf_run_sampled() { MNEMON_BACKFILL_SIM_SECONDS=0 MNEMON_BACKFILL_SIM_PROBE=1 bf_run "$@"; }
bf_run_sampled
bf_has "sampled" "backfill scan: a slow scan samples and says so"

# A cleaning error in the simulation is printed, not logged under ~/.mnemon.
MNEMON_CLEAN_FAIL=aws bf_run
bf_has "redaction pattern aws failed" "backfill scan: a simulated cleaning error is printed"

bf_run --bogus
assert_eq "$BF_RC" "2" "backfill scan: an unknown option exits 2"

assert_eq "$(bf_snapshot)" "$BF_BEFORE" "backfill scan: ~/.mnemon is byte-identical after every scan"
rf_stop_all
rm -rf "$BF" "$BF_STATE"

# Test 10: capture hook with no token short-circuits.
rm -f "$MNEMON_DIR/config.json"
# A HOME of its own: mnemon_token falls back to ~/.claude.json.
mkdir -p "$MNEMON_DIR/nohome"
out=$(printf '{"session_id":"s4","transcript":[],"turn_index":1}' \
    | env -u MNEMON_ENDPOINT -u MNEMON_TOKEN HOME="$MNEMON_DIR/nohome" "$HOOKS_DIR/mnemon-capture.sh" || true)
assert_eq "$out" "" "capture: no token → silent no-op"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" = 0 ]
