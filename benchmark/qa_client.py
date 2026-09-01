"""Minimal chat-completions client.

Raw HTTP rather than the vendor SDK: the harness's standing constraint is no
new runtime dependency, and it already speaks raw JSON-RPC to Mnemon the same
way. Usage is returned with every call so a run can report what it cost
instead of estimating afterwards.
"""

from __future__ import annotations

import re
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
                # This is the one branch whose message embeds provider output.
                # The key travels in a header and providers do not echo it, but
                # that is their behaviour to change, not ours — redact anything
                # key-shaped rather than trusting it.
                body = re.sub(r"sk-[A-Za-z0-9_\-]{8,}", "<redacted>", resp.text[:300])
                raise QaError(f"HTTP {resp.status_code}: {body}")

            payload = resp.json()
            try:
                text = payload["choices"][0]["message"]["content"]
            except (KeyError, IndexError, TypeError) as exc:
                raise QaError(f"malformed response: {str(payload)[:300]}") from exc

            # `content` can be null on a real, HTTP-200 response — a
            # content-filter refusal or a tool-call-only turn both produce it.
            # Coercing that to "" would hand the judge a blank answer to grade,
            # so an API refusal would be scored as a wrong answer and quietly
            # lower the published number. It is an error, and must raise like
            # one. The isinstance check also keeps a non-string `content` from
            # escaping as an AttributeError that callers catching QaError would
            # not see.
            if not isinstance(text, str):
                raise QaError(
                    f"response carried no usable content (type {type(text).__name__}) — "
                    "a refusal or a tool-call-only turn, not an answer"
                )

            return text.strip(), payload.get("usage", {})

        raise QaError("exhausted retries")
