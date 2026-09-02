#!/usr/bin/env bash
# Fake MCP server for hook tests. Replies to any request with a canned recall
# payload, and appends each request body to $FAKE_REQUEST_LOG when set.
#
# Implemented on Python's http.server rather than `nc -l` in a loop: nc handles
# one connection per iteration and can close before it has read a large request
# body, which made the oversized-transcript test fail roughly one run in three
# and produced "write error: Broken pipe" in CI. A real server reads the whole
# body every time.
#
# Env: FAKE_PORT (required), FAKE_DELAY (seconds before replying, default 0),
#      FAKE_REQUEST_LOG (file to append request bodies to).

set -u
exec python3 - <<'PY'
import http.server
import os
import sys
import time

PORT = int(os.environ["FAKE_PORT"])
DELAY = float(os.environ.get("FAKE_DELAY", "0"))
LOG = os.environ.get("FAKE_REQUEST_LOG", "")

REPLY = (
    b'{"jsonrpc":"2.0","id":1,"result":{"structuredContent":{"found":true,'
    b'"summary":"Found 1 wiki page and 0 drawers.",'
    b'"wiki":[{"slug":"person:dorothy-vaughan","title":"Dorothy Vaughan",'
    b'"content":"Lead engineer.","confidence":0.9}],'
    b'"drawers":[],"tokens_used":50}}}'
)


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def do_POST(self):
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length) if length else b""

        if LOG:
            with open(LOG, "ab") as fh:
                fh.write(body + b"\n")

        if DELAY:
            time.sleep(DELAY)

        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(REPLY)))
        self.end_headers()
        self.wfile.write(REPLY)

    def log_message(self, *args):
        pass


try:
    http.server.ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
except KeyboardInterrupt:
    sys.exit(0)
PY
