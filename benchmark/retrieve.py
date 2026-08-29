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
import ingest
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


def _write_run_meta(tag: str, embedding: dict | None) -> None:
    """Record which server configuration produced this run.

    `--tag` is free text the operator chooses (e.g. "embedded"), and nothing
    previously checked it against the server's actual configuration — a
    `--tag embedded` run against a keyless server silently produced a file
    and a report column labelled "embedded" that measured no such thing.
    `brain_status()`'s `embedding` block is the ground truth; this writes it
    to a companion file next to `hits-{tag}.jsonl` so evaluate.py can carry
    it into `metrics-{tag}.json` and report.py can print it.
    """
    path = config.RESULTS_DIR / f"meta-{tag}.json"
    path.write_text(json.dumps({"embedding": embedding}, indent=2))


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
    parser.add_argument(
        "--allow-incomplete",
        action="store_true",
        help="search even if some questions are not fully ingested. Without "
        "this flag, an incomplete question fails the run loudly instead of "
        "producing a results file that looks scoreable but silently mixes "
        "real misses with un-ingested questions.",
    )
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

    # I2: drawer_search against a wing that was never (fully) ingested
    # returns zero results and raises nothing, so a question whose ingest
    # failed is indistinguishable from a genuine miss. Catch it here, before
    # any searching or file-writing happens, so an unscoreable run cannot
    # produce something that looks scoreable.
    incomplete = [r["question_id"] for r in records if not ingest.is_done(r["question_id"])]
    if incomplete and not args.allow_incomplete:
        preview = ", ".join(incomplete[:5])
        more = f" (+{len(incomplete) - 5} more)" if len(incomplete) > 5 else ""
        print(
            f"[retrieve] FAILED: {len(incomplete)} question(s) are not fully "
            f"ingested: {preview}{more}. Searching them now would look like a "
            "genuine miss instead of a broken run — run ingest.py to finish "
            "them, or pass --allow-incomplete if you genuinely want a "
            "partial run.",
            file=sys.stderr,
        )
        return 1
    if incomplete:
        print(
            f"[retrieve] WARNING: {len(incomplete)} question(s) are not fully "
            "ingested but --allow-incomplete was passed; proceeding anyway"
        )

    # I1: brain_status() is the ground truth for which configuration this
    # server is actually running, independent of what the operator typed
    # for --tag. Best-effort — a failure here must not block a run that
    # would otherwise succeed, but it does mean the report can't say which
    # configuration produced the column.
    embedding = None
    try:
        embedding = client.brain_status().get("embedding")
    except McpError as exc:
        print(f"[retrieve] WARNING: brain_status() failed, embedding config "
              f"will not be recorded: {exc}", file=sys.stderr)

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
    _write_run_meta(args.tag, embedding)

    errors = sum(1 for r in rows if r["error"])
    empty = sum(1 for r in rows if not r["error"] and not r["retrieved"])
    print(f"[retrieve] wrote {out} ({len(rows)} rows, {errors} errors, {empty} empty)")

    if errors == len(rows) and rows:
        # A total outage — server down, token invalid, every wing denied — must
        # not exit 0. The empty-run guard below cannot catch it, because it
        # counts only rows that searched successfully and found nothing, and an
        # all-errored run has none of those. Without this an automated pipeline
        # reads "0 scored, N errors" as a pass.
        print(
            f"[retrieve] FAILED: all {errors} searches errored — nothing was "
            "retrieved at all. Check the server is up and the token is valid.",
            file=sys.stderr,
        )
        return 1
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
