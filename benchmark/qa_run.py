"""Reader stage: feed each question's retrieved sessions to a model, record its answer.

Resumable per question, like ingest.py and retrieve.py: `results/answers-{tag}.jsonl` is
appended to, not rewritten, and a question already answered without error is skipped on
the next run rather than re-paid for.

A question whose retrieval itself failed is never sent to the reader. Falling through
would hand it an empty context (no sessions resolve), and "No conversation excerpts were
retrieved" reliably produces a confident NOT FOUND -- a plausible-looking wrong answer
with nothing in the output to say the run was broken for that question rather than merely
unlucky. It is recorded as an error here too, same as retrieve.py's own failures.
"""

from __future__ import annotations

import argparse
import json
import sys

import config
from dataset import iter_records, subset_ids
from qa_client import QaClient, QaError
from qa_prompt import build_reader_prompt, sessions_for_row

# Per-1M-token pricing (input, output), used only to print an estimate alongside
# the measured token total -- never to gate anything. Current as of the GPT-4o
# price cut; check OpenAI's pricing page for what's actually billed.
PRICING = {
    "gpt-4o": (2.50, 10.00),
}


RETRIEVAL_ERROR_PREFIX = "retrieval error: "


def answered(tag: str) -> set[str]:
    """Question ids that a resumed run should not process again.

    A reader-side error is excluded, so a rerun retries it -- the same rule
    retrieve.py follows: an error is never a stand-in for a finished result,
    and a transient rate limit or 5xx deserves another attempt.

    A *retrieval* error is different, and treating the two alike was a real
    bug. It is a permanent fact about a static hits file: this stage never
    re-runs retrieval, so rerunning can never turn it into an answer. Excluding
    it meant every resumed run appended another duplicate row for the same
    question id -- and Task 5 scores that file assuming one row per question,
    so the duplicates would quietly skew the denominator. Terminal errors count
    as done.
    """
    path = config.RESULTS_DIR / f"answers-{tag}.jsonl"
    if not path.exists():
        return set()
    done: set[str] = set()
    for line in path.read_text().splitlines():
        if not line.strip():
            continue
        row = json.loads(line)
        error = row.get("error") or ""
        if not error or error.startswith(RETRIEVAL_ERROR_PREFIX):
            done.add(row["question_id"])
    return done


def answer_question(client, row: dict, record: dict, k: int) -> dict:
    """Answer one question, or record why it could not be answered.

    `retrieval_hit` is computed from the sessions the reader was actually shown
    -- `sessions_for_row` already filters unresolvable ids and truncates to k --
    never from the full `retrieved` list. It is the field the conditional
    accuracy split downstream is built on; computing it against a K the reader
    never saw would score the pipeline instead of the reader.
    """
    out = {
        "question_id": row["question_id"],
        "question_type": row.get("question_type"),
        "answer": None,
        "retrieval_hit": False,
        "error": None,
        "usage": None,
    }

    if row.get("error"):
        out["error"] = f"{RETRIEVAL_ERROR_PREFIX}{row['error']}"
        return out

    sessions = sessions_for_row(row, record, k)
    shown_ids = {sid for sid, _ in sessions}
    gold = set(record.get("answer_session_ids") or [])
    out["retrieval_hit"] = bool(shown_ids & gold)

    messages = build_reader_prompt(
        record["question"], sessions, record.get("question_date")
    )
    try:
        text, usage = client.complete(messages)
    except QaError as exc:
        out["error"] = str(exc)
        return out

    out["answer"] = text
    out["usage"] = usage
    return out


def _load_hits(tag: str) -> dict[str, dict]:
    path = config.RESULTS_DIR / f"hits-{tag}.jsonl"
    if not path.exists():
        raise FileNotFoundError(path)
    rows: dict[str, dict] = {}
    for line in path.read_text().splitlines():
        if not line.strip():
            continue
        row = json.loads(line)
        rows[row["question_id"]] = row
    return rows


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Reader stage: answer each question from its retrieved sessions."
    )
    parser.add_argument(
        "--tag", required=True,
        help="matches the hits-{tag}.jsonl produced by retrieve.py",
    )
    parser.add_argument("--k", type=int, default=5)
    parser.add_argument("--subset", type=int, default=None)
    parser.add_argument("--seed", type=int, default=1234)
    parser.add_argument(
        "--limit", type=int, default=None,
        help="answer only the first N of the selected questions, for smoke testing",
    )
    args = parser.parse_args()

    try:
        hits = _load_hits(args.tag)
    except FileNotFoundError as exc:
        print(f"[qa_run] no such file: {exc}", file=sys.stderr)
        return 1

    wanted = None
    if args.subset:
        wanted = set(subset_ids(config.DATASET_S, args.subset, args.seed))

    records = {
        r["question_id"]: r
        for r in iter_records(config.DATASET_S)
        if r["question_id"] in hits and (wanted is None or r["question_id"] in wanted)
    }

    done = answered(args.tag)
    todo = sorted(qid for qid in records if qid not in done)
    if args.limit is not None:
        todo = todo[: args.limit]

    print(
        f"[qa_run] {len(todo)} question(s) to answer ({len(done)} already done), "
        f"tag={args.tag}, k={args.k}, model={config.QA_MODEL}"
    )
    if not todo:
        print("[qa_run] nothing to do -- all requested questions already answered")
        return 0

    client = QaClient(config.QA_MODEL, config.OPENAI_API_KEY)

    config.RESULTS_DIR.mkdir(parents=True, exist_ok=True)
    out_path = config.RESULTS_DIR / f"answers-{args.tag}.jsonl"

    token_total = 0
    prompt_total = 0
    completion_total = 0
    errors = 0

    with open(out_path, "a", encoding="utf-8") as fh:
        for n, qid in enumerate(todo, 1):
            result = answer_question(client, hits[qid], records[qid], args.k)
            fh.write(json.dumps(result) + "\n")
            fh.flush()

            if result["error"]:
                errors += 1
                print(f"[qa_run] {n}/{len(todo)} {qid} FAILED: {result['error']}", file=sys.stderr)
                continue

            usage = result["usage"] or {}
            prompt_total += usage.get("prompt_tokens", 0)
            completion_total += usage.get("completion_tokens", 0)
            token_total += usage.get("total_tokens", 0)
            print(
                f"[qa_run] {n}/{len(todo)} {qid} answered "
                f"({usage.get('total_tokens', 0)} tokens, running total {token_total})"
            )

    rate = PRICING.get(config.QA_MODEL)
    if rate is None:
        cost_note = f"no pricing on file for {config.QA_MODEL}"
    else:
        input_rate, output_rate = rate
        cost = prompt_total / 1_000_000 * input_rate + completion_total / 1_000_000 * output_rate
        cost_note = f"~${cost:.4f}"
    print(f"[qa_run] done: {token_total} tokens, estimated cost {cost_note}")

    if errors == len(todo):
        print(
            f"[qa_run] FAILED: all {errors} question(s) errored -- nothing was answered",
            file=sys.stderr,
        )
        return 1
    if errors:
        print(f"[qa_run] WARNING: {errors} question(s) errored and are excluded from accuracy")
    return 0


if __name__ == "__main__":
    sys.exit(main())
