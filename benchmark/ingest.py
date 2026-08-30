"""Ingest LongMemEval haystacks into the palace, one isolated wing per question.

Resumability is a correctness requirement, not a convenience: ~25,000 drawers
over an HTTP endpoint will be interrupted, and a re-run that duplicated drawers
would silently inflate the haystack and corrupt every downstream number.

Progress is tracked per drawer, not per question: each question's state file
records which session ids have already been written, and a drawer already
recorded there is skipped on the next run instead of being resent. That
narrows the exposure from "an interrupted question doubles its whole
haystack" to "an interrupted question may hold one duplicate drawer" — it
does NOT close the window entirely. The state file is updated only after
`drawer_add` returns, so a process killed between a successful write and that
update still leaves one drawer un-recorded and therefore re-sent on retry.
The server has no dedup key, so this residue cannot be closed from the client
side; an operator who needs exactness should re-ingest the affected wing from
clean rather than trust the resume.
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


def _load_state(qid: str) -> dict | None:
    path = _state_path(qid)
    if not path.exists():
        return None
    return json.loads(path.read_text())


def is_done(qid: str) -> bool:
    """True once every drawer for `qid` has landed.

    A legacy state file — written by the previous per-question scheme, with
    no "complete" key — is treated as complete: those questions were fully
    ingested under the old all-or-nothing run, and re-checking them here
    would just duplicate their drawers.
    """
    state = _load_state(qid)
    return state is not None and state.get("complete", True)


def done_sessions(qid: str) -> set[str]:
    """Session ids already written for `qid` (empty for missing/legacy state)."""
    state = _load_state(qid)
    if state is None:
        return set()
    return set(state.get("done_sessions", []))


def _save_progress(qid: str, done: set[str], truncated: int, complete: bool) -> None:
    stats = {
        "drawers": len(done),
        "truncated": truncated,
        "done_sessions": sorted(done),
        "complete": complete,
    }
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
    """Send every planned drawer for `record`, skipping ones already sent.

    Each session id is recorded to the state file right after its
    `drawer_add` returns — before the next drawer is attempted — so an
    `McpError` partway through leaves the completed prefix on disk and a
    retry resumes instead of resending the whole question. `complete` is
    only set once every planned drawer has landed.
    """
    qid = record["question_id"]
    plans = plan_drawers(record, max_chars)
    truncated = sum(1 for p in plans if p["metadata"]["truncated"])

    done = done_sessions(qid)
    for plan in plans:
        if plan["source"] in done:
            continue
        client.drawer_add(**plan)
        done.add(plan["source"])
        _save_progress(qid, done, truncated, complete=False)

    _save_progress(qid, done, truncated, complete=True)
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
        print(
            f"[ingest] {len(failures)} questions failed; re-run to resume them "
            "from the last drawer successfully written, not from scratch — "
            "at most one drawer per failed question may end up duplicated "
            "(the write-then-record window can't be closed from here); "
            "re-ingest a wing from clean if you need exactness"
        )
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
