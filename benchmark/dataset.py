"""Streaming access to the LongMemEval dataset.

The file is a 265MB JSON array. `json.load()` on it costs several GB of RAM,
so records are parsed one at a time by matching braces at depth 1. Every other
module in this harness goes through here rather than reading the file directly.
"""

from __future__ import annotations

import json
import random
from pathlib import Path
from typing import Iterator


def iter_records(path: Path, limit: int | None = None) -> Iterator[dict]:
    """Yield one question record at a time without holding the file in memory."""
    yielded = 0
    with open(path, encoding="utf-8") as fh:
        if fh.read(1) != "[":
            raise ValueError(f"{path} does not start with a JSON array")

        buf: list[str] = []
        depth = 0
        in_string = False
        escaped = False

        while True:
            ch = fh.read(1)
            if not ch:
                return

            if depth > 0:
                buf.append(ch)

            if escaped:
                escaped = False
                continue
            if in_string:
                if ch == "\\":
                    escaped = True
                elif ch == '"':
                    in_string = False
                continue
            if ch == '"':
                in_string = True
                continue

            if ch == "{":
                if depth == 0:
                    buf = ["{"]
                depth += 1
            elif ch == "}":
                depth -= 1
                if depth == 0:
                    yield json.loads("".join(buf))
                    yielded += 1
                    buf = []
                    if limit is not None and yielded >= limit:
                        return


def session_text(session: list[dict]) -> str:
    """Render one session's turns as the verbatim text stored in a drawer."""
    return "\n\n".join(
        f"{turn.get('role', 'unknown')}: {turn.get('content', '')}" for turn in session
    )


def subset_ids(path: Path, n: int, seed: int) -> list[str]:
    """A deterministic sample of question ids.

    Seeded so a subset run is reproducible and can be quoted alongside its
    number — an unseeded subset is not a result anyone can check.
    """
    ids = [r["question_id"] for r in iter_records(path)]
    if n >= len(ids):
        return ids
    return random.Random(seed).sample(ids, n)
