"""Search each question against its own wing and persist the raw hits.

Raw hits are written verbatim rather than scored here, so the same retrieval
run can be re-scored later without touching the server — and so the keyless
and embedded runs can be compared question by question.
"""

from __future__ import annotations

import argparse
import json
import sys
from concurrent.futures import ThreadPoolExecutor, as_completed

import config
from dataset import iter_records, subset_ids
from mnemon_client import McpError, MnemonClient


def hit_session_ids(results: list[dict]) -> list[str]:
    """Session ids of the retrieved drawers, in rank order.

    metadata.session_id is authoritative; `source` is the fallback because the
    server may substitute the OAuth client name when source is absent.

    Live `drawer_search` responses carry `metadata` as a JSON-encoded string,
    not a parsed object, so it must be decoded before `.get()` is safe to
    call. A dict or None (as the brief's own unit tests assume) still works.
    """
    ids = []
    for result in results:
        metadata = result.get("metadata") or {}
        if isinstance(metadata, str):
            try:
                metadata = json.loads(metadata)
            except json.JSONDecodeError:
                metadata = {}
        if not isinstance(metadata, dict):
            metadata = {}
        sid = metadata.get("session_id") or result.get("source")
        if sid:
            ids.append(sid)
    return ids


def search_question(client: MnemonClient, record: dict, limit: int) -> dict:
    qid = record["question_id"]
    row = {
        "question_id": qid,
        "question_type": record.get("question_type"),
        "answer_session_ids": record.get("answer_session_ids", []),
        "retrieved": [],
        "error": None,
    }
    try:
        results = client.drawer_search(
            query=record["question"], wing=config.wing_slug(qid), limit=limit
        )
        row["retrieved"] = hit_session_ids(results)
    except McpError as exc:
        # An error is not a miss. Scoring it as one would silently depress the
        # number and hide a broken run behind a plausible result.
        row["error"] = str(exc)
    return row


def main() -> int:
    parser = argparse.ArgumentParser(description="Retrieve for each question.")
    parser.add_argument(
        "--tag",
        required=True,
        help="label for this run, e.g. keyless or embedded (required so two "
        "runs cannot overwrite each other)",
    )
    parser.add_argument("--limit", type=int, default=config.SEARCH_LIMIT)
    parser.add_argument("--subset", type=int, default=None)
    parser.add_argument("--seed", type=int, default=1234)
    args = parser.parse_args()

    client = MnemonClient(config.MNEMON_URL, config.mnemon_token())

    wanted = None
    if args.subset:
        wanted = set(subset_ids(config.DATASET_S, args.subset, args.seed))

    records = [
        r
        for r in iter_records(config.DATASET_S)
        if wanted is None or r["question_id"] in wanted
    ]
    print(f"[retrieve] {len(records)} questions, limit={args.limit}, tag={args.tag}")

    rows = []
    with ThreadPoolExecutor(max_workers=config.SEARCH_WORKERS) as pool:
        futures = [
            pool.submit(search_question, client, r, args.limit) for r in records
        ]
        for done, future in enumerate(as_completed(futures), 1):
            rows.append(future.result())
            if done % 25 == 0 or done == len(records):
                print(f"[retrieve] {done}/{len(records)}")

    config.RESULTS_DIR.mkdir(parents=True, exist_ok=True)
    out = config.RESULTS_DIR / f"hits-{args.tag}.jsonl"
    with open(out, "w", encoding="utf-8") as fh:
        for row in sorted(rows, key=lambda r: r["question_id"]):
            fh.write(json.dumps(row) + "\n")

    errors = sum(1 for r in rows if r["error"])
    empty = sum(1 for r in rows if not r["error"] and not r["retrieved"])
    print(f"[retrieve] wrote {out} ({len(rows)} rows, {errors} errors, {empty} empty)")

    if errors:
        print(f"[retrieve] WARNING: {errors} searches errored and are excluded from scoring")
    if empty == len(rows) and rows:
        print(
            "[retrieve] FAILED: every search returned nothing. This is a broken "
            "run, not a 0% result — check preflight and that ingestion targeted "
            "the same wings.",
            file=sys.stderr,
        )
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
