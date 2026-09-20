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
#      FAKE_REQUEST_LOG (file to append request bodies to),
#      FAKE_HEADER_LOG (file to append request headers to),
#      FAKE_STATUS (answer every /mcp call with this status),
#      FAKE_TOOL_ERROR (answer /mcp with a tool-level error),
#      FAKE_MAX_TRANSCRIPT (reject a session_digest whose transcript exceeds
#                           this many characters, the way the real tool's
#                           `max:200000` validation does),
#      FAKE_EXPIRE_FIRST (401 every /mcp call not bearing the refreshed token),
#      FAKE_REFRESH_FAIL (how POST /oauth/token should fail:
#                         invalid_grant | invalid_client | garbage | 500 | empty),
#      FAKE_PLUGIN_VERSION (plugin version to advertise in the wake reply),
#      FAKE_DEVICE_MODE (how the device grant resolves:
#                        success | denied | expired; default success),
#      FAKE_STATE_DIR (directory where the server records that a refresh
#                      happened; ThreadingHTTPServer serves each request on its
#                      own thread, so the filesystem is the shared state).

set -u
exec python3 - <<'PY'
import http.server
import json
import os
import sys
import time

PORT = int(os.environ["FAKE_PORT"])
DELAY = float(os.environ.get("FAKE_DELAY", "0"))
LOG = os.environ.get("FAKE_REQUEST_LOG", "")
STATE = os.environ.get("FAKE_STATE_DIR", "")

REPLY = (
    b'{"jsonrpc":"2.0","id":1,"result":{"structuredContent":{"found":true,'
    b'"summary":"Found 1 wiki page and 0 drawers.",'
    b'"wiki":[{"slug":"person:dorothy-vaughan","title":"Dorothy Vaughan",'
    b'"content":"Lead engineer.","confidence":0.9}],'
    b'"drawers":[],"tokens_used":50}}}'
)

# The access token a successful refresh hands back. FAKE_EXPIRE_FIRST rejects
# every other bearer, so a test only gets a recall payload by refreshing first.
REFRESHED_TOKEN = "token-2"


class Handler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def _reply(self, status, page, ctype="application/json"):
        self.send_response(status)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(page)))
        self.end_headers()
        self.wfile.write(page)

    def _log_body(self, prefix, body):
        if LOG:
            with open(LOG, "ab") as fh:
                fh.write(prefix + body + b"\n")

    def _state(self, name):
        return os.path.join(STATE, name) if STATE else ""

    def _device_code(self):
        self._reply(200, (
            '{"device_code":"dev-1","user_code":"BCDFGHJK",'
            '"verification_uri":"http://127.0.0.1:%d/oauth/device",'
            '"verification_uri_complete":"http://127.0.0.1:%d/oauth/device?user_code=BCDFGHJK",'
            '"expires_in":600,"interval":1}' % (PORT, PORT)
        ).encode())

    def _device_token(self):
        # The poll count lives on disk: each request is served on its own
        # thread, so an in-process counter would not be shared.
        mode = os.environ.get("FAKE_DEVICE_MODE", "success")
        polls = self._state("polls")
        n = 0
        if polls:
            if os.path.exists(polls):
                n = int(open(polls).read() or 0)
            open(polls, "w").write(str(n + 1))

        if mode == "denied":
            self._reply(400, b'{"error":"access_denied"}')
            return
        if mode == "expired":
            self._reply(400, b'{"error":"expired_token"}')
            return
        # Pending once before succeeding, so the polling loop is exercised
        # rather than short-circuited on the first request.
        if n == 0:
            self._reply(400, b'{"error":"authorization_pending"}')
            return
        self._reply(200, b'{"token_type":"Bearer","expires_in":3600,'
                         b'"access_token":"token-2","refresh_token":"refresh-2"}')

    def _oauth_token(self, body):
        # Logged with a prefix so a test can count refreshes without matching
        # them against MCP traffic in the same file.
        self._log_body(b"OAUTH_TOKEN ", body)

        # The device grant and the refresh grant share this endpoint, as they do
        # on a real server; the grant type in the body tells them apart.
        if b"device_code" in body:
            self._device_token()
            return

        fail = os.environ.get("FAKE_REFRESH_FAIL", "")
        if fail in ("invalid_grant", "invalid_client"):
            page = ('{"error":"%s","error_description":"nope"}' % fail).encode()
            # invalid_grant is a 400 and invalid_client a 401, which is what a
            # real authorization server returns for each.
            self._reply(400 if fail == "invalid_grant" else 401, page)
            return
        if fail == "garbage":
            # A proxy in front of the token endpoint answers with HTML.
            self._reply(200, b"<html>proxy says hi</html>", "text/html")
            return
        if fail == "500":
            self._reply(500, b"<html>boom</html>", "text/html")
            return
        if fail == "empty":
            # 200 with nothing usable in it — the shape that would overwrite a
            # working credential with an empty one if it were trusted.
            self._reply(200, b'{"token_type":"Bearer","access_token":"","refresh_token":""}')
            return

        if STATE:
            open(os.path.join(STATE, "refreshed"), "w").close()
        self._reply(200, (
            '{"token_type":"Bearer","expires_in":3600,'
            '"access_token":"%s","refresh_token":"refresh-2"}' % REFRESHED_TOKEN
        ).encode())

    def do_POST(self):
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length) if length else b""

        if self.path.startswith("/oauth/device/code"):
            self._device_code()
            return

        if self.path.startswith("/oauth/token"):
            self._oauth_token(body)
            return

        # FAKE_STATUS lets a test exercise a proxy error, whose body is HTML
        # rather than JSON -- the shape that used to surface as a jq parse error.
        status = int(os.environ.get("FAKE_STATUS", "0"))
        if status:
            # Laravel answers 401/403 with JSON carrying "message" (not "error"),
            # which is exactly why an expired token used to pass unnoticed.
            if status in (401, 403):
                page = b'{"message":"Unauthenticated."}'
                ctype = "application/json"
            else:
                page = b"<html>\r\n<head><title>%d</title></head>\r\n</html>\r\n" % status
                ctype = "text/html"
            self._reply(status, page, ctype)
            return

        # FAKE_EXPIRE_FIRST models an expired access token: everything but the
        # token a refresh would hand back is rejected, so the only way to a
        # successful call is through POST /oauth/token.
        if os.environ.get("FAKE_EXPIRE_FIRST"):
            if self.headers.get("Authorization", "") != "Bearer " + REFRESHED_TOKEN:
                self._reply(401, b'{"message":"Unauthenticated."}')
                return

        self._log_body(b"", body)

        hdr_log = os.environ.get("FAKE_HEADER_LOG", "")
        if hdr_log:
            with open(hdr_log, "a") as fh:
                for k, v in self.headers.items():
                    fh.write(f"{k}: {v}\n")

        # The real session_digest tool validates `transcript` as
        # `required|string|max:200000`. A fixture that accepts any size lets a
        # client-side cap larger than the server's limit pass the suite while
        # every real digest is rejected -- which is exactly what happened.
        maxt = int(os.environ.get("FAKE_MAX_TRANSCRIPT", "0"))
        if maxt:
            try:
                args = json.loads(body).get("params", {}).get("arguments", {})
            except (ValueError, AttributeError):
                args = {}
            t = args.get("transcript")
            if isinstance(t, str) and len(t) > maxt:
                self._reply(200, (
                    '{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text",'
                    '"text":"The transcript field must not be greater than '
                    '%d characters."}],"isError":true}}' % maxt
                ).encode())
                return

        # FAKE_TOOL_ERROR serves what a wing denial actually looks like: HTTP 200
        # with isError on the *result*, not a JSON-RPC .error. That shape used to
        # read to the hooks as "found nothing".
        if os.environ.get("FAKE_TOOL_ERROR"):
            self._reply(200, b'{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text",'
                             b'"text":"Token does not have access to wing: personal"}],"isError":true}}')
            return

        if DELAY:
            time.sleep(DELAY)

        # The version handshake rides on the wake reply, so the fixture has to be
        # able to claim any version -- including none, which is what an older
        # instance returns.
        reply = REPLY
        pv = os.environ.get("FAKE_PLUGIN_VERSION", "")
        if pv:
            reply = reply[:-3] + (',"plugin_version":"%s"}}}' % pv).encode()

        self._reply(200, reply)

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
