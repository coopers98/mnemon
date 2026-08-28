"""Tiny MCP client for the benchmark.

Speaks JSON-RPC 2.0 over Mnemon's Streamable HTTP endpoint (`POST /mcp`) with
an OAuth 2.1 bearer token. Includes retry-on-transient-error so a flaky
network, a single 5xx, or a rate-limit burst doesn't kill a multi-hour run.

Minting a token for a script (see docs/USERGUIDE.md):

    php artisan tinker
    >>> $user = App\\Models\\User::first();
    >>> echo $user->createToken('Benchmark', ['mcp:use'])->accessToken;

Personal access tokens carry no wing restrictions, which is what the benchmark
wants — it namespaces its own wings and needs to read all of them back.
"""

from __future__ import annotations

import itertools
import time
from typing import Any

import requests


class McpError(RuntimeError):
    """A tool or protocol level failure. Not retryable."""


class MnemonAuthError(McpError):
    """Token missing, expired, or lacking the mcp:use scope."""


class MnemonClient:
    def __init__(self, url: str, token: str, timeout: int = 60):
        self.url = url
        self.timeout = timeout
        self._ids = itertools.count(1)
        self._session = requests.Session()
        self._session.headers.update(
            {
                "Authorization": f"Bearer {token}",
                "Content-Type": "application/json",
                "Accept": "application/json",
            }
        )

    # ---- transport ---------------------------------------------------------

    def _rpc(self, method: str, params: dict[str, Any], retries: int = 3) -> dict:
        body = {
            "jsonrpc": "2.0",
            "id": next(self._ids),
            "method": method,
            "params": params,
        }

        last_err: Exception | None = None
        for attempt in range(retries + 1):
            try:
                resp = self._session.post(self.url, json=body, timeout=self.timeout)
            except requests.RequestException as e:
                last_err = e
                if attempt < retries:
                    time.sleep(1 + attempt * 2)
                    continue
                raise McpError(f"network error calling {method}: {e}") from e

            # Auth failures never fix themselves — fail fast with a useful message.
            if resp.status_code in (401, 403):
                raise MnemonAuthError(
                    f"HTTP {resp.status_code} from {self.url}. The token is missing, "
                    "expired, or lacks the 'mcp:use' scope. Mint a new one with "
                    "`php artisan tinker` — see this module's docstring."
                )

            # The server rate-limits authenticated callers (throttle:mcp).
            # Honour Retry-After; the benchmark runs many workers concurrently.
            if resp.status_code == 429:
                if attempt < retries:
                    wait = float(resp.headers.get("Retry-After", 1 + attempt * 2))
                    time.sleep(wait)
                    continue
                raise McpError(f"{method}: rate limited after {retries} retries")

            if resp.status_code >= 500 and attempt < retries:
                time.sleep(1 + attempt * 2)
                continue

            if not resp.ok:
                raise McpError(
                    f"{method} failed: HTTP {resp.status_code} — {resp.text[:500]}"
                )

            payload = resp.json()

            # JSON-RPC protocol error (unknown method, malformed request, ...).
            if "error" in payload:
                err = payload["error"]
                raise McpError(f"{method} protocol error: {err}")

            return payload.get("result", {})

        raise McpError(f"{method} exhausted retries: {last_err}")

    def call_tool(self, tool: str, arguments: dict[str, Any]) -> dict:
        """Invoke a tool and return its structured content.

        Tool-level failures come back as a successful JSON-RPC response with
        `result.isError` set, so they must be checked separately from protocol
        errors — a wing-restriction denial looks like HTTP 200.
        """
        result = self._rpc("tools/call", {"name": tool, "arguments": arguments})

        if result.get("isError"):
            raise McpError(f"{tool} error: {_error_text(result)}")

        return result.get("structuredContent", {})

    def list_tools(self) -> list[dict]:
        """Handy for verifying auth and discovering the server's tool surface."""
        return self._rpc("tools/list", {}).get("tools", [])

    # ---- tools -------------------------------------------------------------

    def drawer_add(
        self,
        wing: str,
        room: str,
        content: str,
        source: str | None = None,
        metadata: dict | None = None,
    ) -> dict:
        """Store a drawer.

        `wing` and `room` are SLUGS, not display names — the server creates the
        wing verbatim from what it is given. Pass `config.wing_slug(...)`, never
        the colon form, or you get a wing whose slug contains a colon that no
        restriction pattern can match.
        """
        args: dict[str, Any] = {"wing": wing, "room": room, "content": content}
        if source:
            args["source"] = source
        if metadata:
            args["metadata"] = metadata
        return self.call_tool("drawer_add", args)

    def drawer_search(
        self,
        query: str,
        wing: str | None = None,
        limit: int = 10,
    ) -> list[dict]:
        """Hybrid search over the palace.

        The server dropped `room` and `mode` when the tools were rewritten
        against laravel/mcp; retrieval mode is no longer caller-selectable.
        """
        args: dict[str, Any] = {"query": query, "limit": limit}
        if wing:
            args["wing"] = wing
        return self.call_tool("drawer_search", args).get("results", [])

    def drawer_get(self, drawer_id: int) -> dict:
        return self.call_tool("drawer_get", {"id": drawer_id})

    def brain_status(self) -> dict:
        """Server counts. Note the key is `drawers`, not `drawer_count` —
        verified against a live server, along with `wings`, `rooms`,
        `wiki_pages`, `drawers_by_tier`, and `last_write`.
        """
        return self.call_tool("brain_status", {})


def _error_text(result: dict) -> str:
    """Pull readable text out of an MCP error result's content blocks."""
    blocks = result.get("content") or []
    texts = [b.get("text", "") for b in blocks if isinstance(b, dict)]
    return " ".join(t for t in texts if t) or str(result)[:300]
