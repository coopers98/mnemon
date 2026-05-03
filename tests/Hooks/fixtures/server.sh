#!/usr/bin/env bash
# Fake MCP server for hook tests. Listens on $FAKE_PORT and replies to
# any incoming request with a canned recall payload.

set -u
FAKE_PORT="${FAKE_PORT:-9876}"

reply_recall='{"jsonrpc":"2.0","id":1,"result":{"structuredContent":{"found":true,"summary":"Found 1 wiki page and 0 drawers.","wiki":[{"slug":"person:dorothy-vaughan","title":"Dorothy Vaughan","content":"Lead engineer.","confidence":0.9}],"drawers":[],"tokens_used":50}}}'

while true; do
    {
        body="$reply_recall"
        printf 'HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: %d\r\nConnection: close\r\n\r\n%s' "${#body}" "$body"
    } | nc -l "$FAKE_PORT" >/dev/null 2>&1 || true
done
