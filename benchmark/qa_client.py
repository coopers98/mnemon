"""Minimal chat-completions client.

Raw HTTP rather than the vendor SDK: the harness's standing constraint is no
new runtime dependency, and it already speaks raw JSON-RPC to Mnemon the same
way. Usage is returned with every call so a run can report what it cost
instead of estimating afterwards.
"""

from __future__ import annotations

import time
from typing import Any

import requests

ENDPOINT = "https://api.openai.com/v1/chat/completions"


class QaError(RuntimeError):
    """A failed completion. Callers record these as errors, never as answers."""


class QaClient:
    def __init__(self, model: str, api_key: str, timeout: int = 120):
        if not api_key:
            raise QaError("no API key — set OPENAI_API_KEY")
        self.model = model
        self.timeout = timeout
        self._session = requests.Session()
        self._session.headers.update(
            {"Authorization": f"Bearer {api_key}", "Content-Type": "application/json"}
        )

    def complete(self, messages: list[dict], retries: int = 3) -> tuple[str, dict]:
        body: dict[str, Any] = {
            "model": self.model,
            "messages": messages,
            # Pinned so a run is as reproducible as the provider allows. Even at
            # zero these models are not bit-deterministic, which is why the judge
            # stage measures its own disagreement rather than assuming none.
            "temperature": 0,
        }

        for attempt in range(retries + 1):
            try:
                resp = self._session.post(ENDPOINT, json=body, timeout=self.timeout)
            except requests.RequestException as exc:
                if attempt < retries:
                    time.sleep(1 + attempt * 2)
                    continue
                raise QaError(f"network error after {retries} retries: {exc}") from exc

            if resp.status_code in (401, 403):
                # Never interpolate the key into the message.
                raise QaError(f"HTTP {resp.status_code} — the API key is missing, invalid, or lacks access")

            if resp.status_code == 429:
                if attempt < retries:
                    time.sleep(float(resp.headers.get("Retry-After", 1 + attempt * 2)))
                    continue
                raise QaError(f"rate limited after {retries} retries")

            if resp.status_code >= 500:
                if attempt < retries:
                    time.sleep(1 + attempt * 2)
                    continue
                raise QaError(f"HTTP {resp.status_code} after {retries} retries")

            if not resp.ok:
                raise QaError(f"HTTP {resp.status_code}: {resp.text[:300]}")

            payload = resp.json()
            try:
                text = payload["choices"][0]["message"]["content"]
            except (KeyError, IndexError, TypeError) as exc:
                raise QaError(f"malformed response: {str(payload)[:300]}") from exc

            return (text or "").strip(), payload.get("usage", {})

        raise QaError("exhausted retries")
