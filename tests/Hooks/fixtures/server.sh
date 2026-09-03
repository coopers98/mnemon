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

        # FAKE_STATUS lets a test exercise a proxy error, whose body is HTML
        # rather than JSON -- the shape that used to surface as a jq parse error.
        status = int(os.environ.get("FAKE_STATUS", "0"))
        if status:
            # Laravel answers 401/403 with JSON carrying "message" (not "error"),
            # which is exactly why an expired token used to pass unnoticed.
            if status in (401, 403):
                page = b'{"message":"Unauthenticated."}'
            else:
                page = b"<html>\r\n<head><title>%d</title></head>\r\n</html>\r\n" % status
            self.send_response(status)
            self.send_header("Content-Type", "application/json" if status in (401, 403) else "text/html")
            self.send_header("Content-Length", str(len(page)))
            self.end_headers()
            self.wfile.write(page)
            return

        if LOG:
            with open(LOG, "ab") as fh:
                fh.write(body + b"\n")

        hdr_log = os.environ.get("FAKE_HEADER_LOG", "")
        if hdr_log:
            with open(hdr_log, "a") as fh:
                for k, v in self.headers.items():
                    fh.write(f"{k}: {v}\n")

        # FAKE_TOOL_ERROR serves what a wing denial actually looks like: HTTP 200
        # with isError on the *result*, not a JSON-RPC .error. That shape used to
        # read to the hooks as "found nothing".
        if os.environ.get("FAKE_TOOL_ERROR"):
            err = (b'{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text",'
                   b'"text":"Token does not have access to wing: personal"}],"isError":true}}')
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(err)))
            self.end_headers()
            self.wfile.write(err)
            return

        if DELAY:
            time.sleep(DELAY)

        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(REPLY)))
        self.end_headers()
        self.wfile.write(REPLY)

    def handle_one_request(self):
        # The budget tests make curl disconnect mid-reply on purpose, which
        # raises BrokenPipeError from sendall. That is the expected outcome, not
        # a fault, and its traceback is noise that would hide a real failure.
        try:
            super().handle_one_request()
        except (BrokenPipeError, ConnectionResetError):
            self.close_connection = True

    def log_message(self, *args):
        pass


try:
    http.server.ThreadingHTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
except KeyboardInterrupt:
    sys.exit(0)
PY
