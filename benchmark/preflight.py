"""Prove the harness can measure anything at all, before a multi-hour run.

Every check here guards a failure mode that would otherwise produce a
confident wrong number rather than an error.
"""

from __future__ import annotations

import sys
from pathlib import Path

import config
from dataset import iter_records
from mnemon_client import MnemonClient

QUERY_CAP = 500
PROBE_CONTENT = "mnemon benchmark preflight probe drawer"


def check_query_lengths(path: Path, cap: int = QUERY_CAP) -> tuple[int, str | None]:
    """Return the longest question length and the first question over `cap`.

    drawer_search rejects a query over 500 characters. That rejection would be
    recorded as "no results" — i.e. scored as a retrieval miss — so an
    over-length question would quietly depress the number instead of failing.
    """
    longest = 0
    offender = None
    for record in iter_records(path):
        q = record.get("question", "")
        if len(q) > longest:
            longest = len(q)
        if offender is None and len(q) > cap:
            offender = record.get("question_id")
    return longest, offender


def check_slug_agreement(client, probe_id: str = "preflight") -> str:
    """Write a probe drawer, read it back, and assert the wing slug round-trips.

    config.wing_slug() reimplements Laravel's Wing::slugify() in Python, and
    DrawerWriteService stores the `wing` argument verbatim as the slug. If the
    two ever diverge, ingestion and retrieval address different wings, every
    search returns nothing, and the run scores 0% while looking like a product
    failure.

    Read `wing_slug` from the search result when the server provides it —
    `wing` is the auto-created Wing's display *name* (Str::title of the slug
    with dashes turned into spaces, e.g. "Benchmark Qprobe" for slug
    "benchmark-qprobe"), not the slug itself, so comparing against `wing`
    would fail even when ingestion and retrieval agree. Fall back to `wing`
    for callers (and the unit tests' FakeClient) that don't provide
    `wing_slug`.
    """
    slug = config.wing_slug(probe_id)
    client.drawer_add(
        wing=slug,
        room=config.ROOM_NAME,
        content=PROBE_CONTENT,
        source="preflight",
        metadata={"preflight": True},
    )
    results = client.drawer_search(query="preflight probe", wing=slug, limit=5)
    if not results:
        raise AssertionError(
            f"wrote a probe drawer to wing {slug!r} and searching that wing "
            "returned nothing — ingestion and retrieval are not addressing the "
            "same wing, so no measurement from this harness would be meaningful"
        )
    stored = results[0].get("wing_slug")
    if stored is None:
        stored = results[0].get("wing")
    if stored != slug:
        raise AssertionError(
            f"sent wing {slug!r} but the server stored {stored!r}. "
            "DrawerWriteService uses the argument verbatim as the slug — pass "
            "config.wing_slug(...), never config.wing_name()"
        )
    return slug


def main() -> int:
    failures = []

    client = MnemonClient(config.MNEMON_URL, config.mnemon_token())

    tools = [t.get("name") for t in client.list_tools()]
    print(f"[preflight] server exposes {len(tools)} tools")
    for needed in ("drawer_add", "drawer_search", "brain_status"):
        if needed not in tools:
            failures.append(f"missing required tool: {needed}")

    if not config.DATASET_S.exists():
        failures.append(f"dataset missing at {config.DATASET_S} — run ./setup.sh")
    else:
        longest, offender = check_query_lengths(config.DATASET_S)
        print(f"[preflight] longest question: {longest} chars (cap {QUERY_CAP})")
        if offender:
            failures.append(
                f"question {offender} exceeds the {QUERY_CAP}-char query cap"
            )

    try:
        slug = check_slug_agreement(client)
        print(f"[preflight] slug agreement OK ({slug})")
    except AssertionError as exc:
        failures.append(str(exc))

    if failures:
        print("\n[preflight] FAILED:")
        for f in failures:
            print(f"  - {f}")
        return 1

    print("\n[preflight] all checks passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
