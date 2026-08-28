"""Tiny MCP client for the benchmark.

Wraps Mnemon's `POST /api/mcp/call` with X-API-Key auth. Includes simple
retry-on-transient-error so a flaky network or a single 5xx doesn't kill a
multi-hour run.
"""

from __future__ import annotations

import time
from typing import Any

import requests


class McpError(RuntimeError):
    pass


class MnemonClient:
    def __init__(self, url: str, api_key: str, timeout: int = 60):
        self.url = url
        self.api_key = api_key
        self.timeout = timeout
        self._session = requests.Session()
        self._session.headers.update(
            {"X-API-Key": api_key, "Content-Type": "application/json"}
        )

    def call(self, tool: str, params: dict[str, Any], retries: int = 3) -> dict:
        body = {"tool": tool, "params": params}
        last_err: Exception | None = None
        for attempt in range(retries + 1):
            try:
                resp = self._session.post(self.url, json=body, timeout=self.timeout)
            except requests.RequestException as e:
                last_err = e
                if attempt < retries:
                    time.sleep(1 + attempt * 2)
                    continue
                raise McpError(f"network error calling {tool}: {e}") from e

            if resp.status_code >= 500 and attempt < retries:
                time.sleep(1 + attempt * 2)
                continue

            if not resp.ok:
                raise McpError(
                    f"{tool} failed: HTTP {resp.status_code} — {resp.text[:500]}"
                )

            payload = resp.json()
            if "error" in payload:
                raise McpError(f"{tool} error: {payload['error']}")
            return payload.get("result", {})

        raise McpError(f"{tool} exhausted retries: {last_err}")

    def drawer_add(
        self,
        wing: str,
        room: str,
        content: str,
        source: str | None = None,
        metadata: dict | None = None,
    ) -> dict:
        params: dict[str, Any] = {"wing": wing, "room": room, "content": content}
        if source:
            params["source"] = source
        if metadata:
            params["metadata"] = metadata
        return self.call("drawer_add", params)

    def drawer_search(
        self,
        query: str,
        wing: str | None = None,
        room: str | None = None,
        limit: int = 5,
        mode: str = "hybrid",
    ) -> list[dict]:
        params: dict[str, Any] = {"query": query, "limit": limit, "mode": mode}
        if wing:
            params["wing"] = wing
        if room:
            params["room"] = room
        result = self.call("drawer_search", params)
        return result.get("results", [])

    def drawer_get(self, drawer_id: int) -> dict:
        return self.call("drawer_get", {"id": drawer_id})

    def brain_status(self) -> dict:
        return self.call("brain_status", {})
