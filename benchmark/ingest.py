"""Ingest LongMemEval haystacks into the palace, one isolated wing per question.

Resumability is a correctness requirement, not a convenience: ~25,000 drawers
over an HTTP endpoint will be interrupted, and a re-run that duplicated drawers
would silently inflate the haystack and corrupt every downstream number.
"""

from __future__ import annotations

import argparse
import json
import sys
from concurrent.futures import ThreadPoolExecutor, as_completed

import config
from dataset import iter_records, session_text, subset_ids
from mnemon_client import McpError, MnemonClient

STATE_SUBDIR = "ingested"


def _state_path(qid: str):
    d = config.STATE_DIR / STATE_SUBDIR
    d.mkdir(parents=True, exist_ok=True)
    return d / f"{qid}.json"


def is_done(qid: str) -> bool:
    return _state_path(qid).exists()


def mark_done(qid: str, stats: dict) -> None:
    _state_path(qid).write_text(json.dumps(stats))


def plan_drawers(record: dict, max_chars: int) -> list[dict]:
    """Turn one question record into the drawers it should produce.

    Pure by design so its edge cases are testable without a server.
    """
    qid = record["question_id"]
    wing = config.wing_slug(qid)
    sessions = record.get("haystack_sessions", [])
    ids = record.get("haystack_session_ids", [])
    dates = record.get("haystack_dates", [])

    plans = []
    for index, session in enumerate(sessions):
        text = session_text(session)
        truncated = len(text) > max_chars
        plans.append(
            {
                "wing": wing,
                "room": config.ROOM_NAME,
                "content": text[:max_chars],
                "source": ids[index] if index < len(ids) else f"session_{index}",
                "metadata": {
                    "session_id": ids[index] if index < len(ids) else None,
                    "date": dates[index] if index < len(dates) else None,
                    "index": index,
                    "truncated": truncated,
                },
            }
        )
    return plans


def ingest_question(client: MnemonClient, record: dict, max_chars: int) -> dict:
    plans = plan_drawers(record, max_chars)
    truncated = sum(1 for p in plans if p["metadata"]["truncated"])
    for plan in plans:
        client.drawer_add(**plan)
    return {"drawers": len(plans), "truncated": truncated}


def main() -> int:
    parser = argparse.ArgumentParser(description="Ingest LongMemEval into Mnemon.")
    parser.add_argument("--limit", type=int, default=None, help="first N questions")
    parser.add_argument("--subset", type=int, default=None, help="seeded sample of N")
    parser.add_argument("--seed", type=int, default=1234)
    args = parser.parse_args()

    client = MnemonClient(config.MNEMON_URL, config.mnemon_token())

    wanted = None
    if args.subset:
        wanted = set(subset_ids(config.DATASET_S, args.subset, args.seed))
        print(f"[ingest] seeded subset of {len(wanted)} questions (seed={args.seed})")

    records = []
    for record in iter_records(config.DATASET_S, limit=args.limit):
        if wanted is not None and record["question_id"] not in wanted:
            continue
        if is_done(record["question_id"]):
            continue
        records.append(record)

    print(f"[ingest] {len(records)} questions to ingest")
    if not records:
        print("[ingest] nothing to do — all requested questions already ingested")
        return 0

    total_drawers = 0
    total_truncated = 0
    failures = []

    with ThreadPoolExecutor(max_workers=config.INGEST_WORKERS) as pool:
        futures = {
            pool.submit(ingest_question, client, r, config.MAX_DRAWER_CHARS): r
            for r in records
        }
        for done, future in enumerate(as_completed(futures), 1):
            record = futures[future]
            qid = record["question_id"]
            try:
                stats = future.result()
            except McpError as exc:
                failures.append((qid, str(exc)))
                print(f"[ingest] {qid} FAILED: {exc}", file=sys.stderr)
                continue
            mark_done(qid, stats)
            total_drawers += stats["drawers"]
            total_truncated += stats["truncated"]
            if done % 10 == 0 or done == len(records):
                print(f"[ingest] {done}/{len(records)} questions, {total_drawers} drawers")

    print(f"[ingest] done: {total_drawers} drawers, {total_truncated} truncated")
    if total_truncated:
        pct = 100 * total_truncated / total_drawers
        print(
            f"[ingest] WARNING: {total_truncated} sessions ({pct:.1f}%) were truncated "
            f"at {config.MAX_DRAWER_CHARS} chars — evidence may have been cut"
        )
    if failures:
        print(f"[ingest] {len(failures)} questions failed; re-run to retry them")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
