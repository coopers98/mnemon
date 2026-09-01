"""Score the QA layer: join answers and verdicts, report accuracy.

Consumes `results/answers-{tag}.jsonl` (qa_run.py) and
`results/verdicts-{tag}.jsonl` (qa_judge.py); produces
`results/qa-metrics-{tag}.json` and a printed summary.

Kept separate from qa_run.py/qa_judge.py for the same reason evaluate.py is
kept separate from retrieve.py: a run can be re-scored -- with a different
combination rule, say -- without touching an API or a server.

## Joining two resumable files

Neither input file is guaranteed to be "finished," and they are not
guaranteed to agree on which questions exist. A question_id can land in one
of five buckets:

  * SCORED       -- a clean answer and a clean verdict. The only bucket that
                    feeds the accuracy figures.
  * ANSWER_ERROR  -- the answer row itself carries an error (a retrieval
                    failure from retrieve.py, or a reader failure from
                    qa_run.py). qa_judge.py mirrors that error into a
                    verdict row with its own error string, but the bucket
                    is decided from the answer row, not re-derived from the
                    verdict row -- see JoinRowsTest for why the two are not
                    interchangeable.
  * NOT_JUDGED    -- answered cleanly, but qa_judge.py has not reached this
                    question_id yet (or has not been run at all). Not an
                    error; just not scoreable yet.
  * JUDGE_ERROR   -- the answer was fine but the judge stage itself failed
                    (a rate limit, an unparseable verdict).
  * ORPHAN_VERDICT -- a question_id present in verdicts-{tag}.jsonl with no
                    matching row in answers-{tag}.jsonl at all. Nothing in
                    this pipeline produces that today (qa_judge.py reads its
                    question set from the answers file), but a mismatched
                    pair of files -- wrong tag, hand-edited results -- would,
                    and dropping it silently would hide the mismatch instead
                    of surfacing it.

`join_rows` returns one record per question_id found in either file, with
its bucket made explicit, so a caller sees a discrepancy in the `counts`
block of `qa-metrics-{tag}.json` rather than in a denominator that quietly
moved.

## The headline accuracy figure

Each judged question carries two independent verdicts (`verdict_a`,
`verdict_b`) from two separate calls to the same judge prompt -- run twice
specifically to measure the judge's own nondeterminism rather than assume it
away. The headline `accuracy` pools both calls as equally-weighted votes,
rather than reporting `verdict_a` alone: doing the latter would make the
published number depend on which of two equally-valid calls happened to run
first, and would hide the exact noise this stage exists to measure. Pooling
is mathematically equivalent to averaging `accuracy_verdict_a` and
`accuracy_verdict_b` (every scored row contributes exactly one vote to each),
so the headline always sits at the midpoint of the two "alone" figures --
which is what makes publishing all three together meaningful: the two
"alone" numbers bound how far the judge's noise could have moved the number
actually reported.
"""

from __future__ import annotations

import argparse
import json
import sys
from collections import defaultdict
from pathlib import Path

import config

SCORED = "scored"
ANSWER_ERROR = "answer_error"
NOT_JUDGED = "not_judged"
JUDGE_ERROR = "judge_error"
ORPHAN_VERDICT = "orphan_verdict"


def join_rows(answers: dict[str, dict], verdicts: dict[str, dict]) -> list[dict]:
    """Union the two files by question_id and classify each into one bucket.

    `question_type` and `retrieval_hit` always come from the answer row --
    the verdict row does not carry them. Sorted by question_id so output
    (and test fixtures) are deterministic.
    """
    joined: list[dict] = []
    for qid in sorted(set(answers) | set(verdicts)):
        a = answers.get(qid)
        v = verdicts.get(qid)

        record = {
            "question_id": qid,
            "question_type": a.get("question_type") if a else None,
            "retrieval_hit": a.get("retrieval_hit") if a else None,
            "verdict_a": None,
            "verdict_b": None,
            "agreed": None,
            "status": None,
        }

        if a is None:
            record["status"] = ORPHAN_VERDICT
        elif a.get("error"):
            record["status"] = ANSWER_ERROR
        elif v is None:
            record["status"] = NOT_JUDGED
        elif v.get("error") or v.get("verdict_a") is None or v.get("verdict_b") is None:
            record["status"] = JUDGE_ERROR
        else:
            record["verdict_a"] = v["verdict_a"]
            record["verdict_b"] = v["verdict_b"]
            record["agreed"] = v.get("agreed")
            record["status"] = SCORED

        joined.append(record)

    return joined


def _pooled_accuracy(rows: list[dict]) -> float | None:
    """The headline figure: both judge calls pooled as equal votes. See the
    module docstring for why this, and not `verdict_a` alone, is reported."""
    if not rows:
        return None
    votes = []
    for r in rows:
        votes.append(bool(r["verdict_a"]))
        votes.append(bool(r["verdict_b"]))
    return sum(votes) / len(votes)


def _accuracy_from(rows: list[dict], key: str) -> float | None:
    if not rows:
        return None
    return sum(1 for r in rows if r[key]) / len(rows)


def _disagreement_rate(rows: list[dict]) -> float | None:
    if not rows:
        return None
    return sum(1 for r in rows if r["agreed"] is False) / len(rows)


def score_rows(joined: list[dict]) -> dict:
    """Aggregate a joined row list into the reported metrics.

    Only SCORED rows feed any accuracy figure or breakdown -- every other
    bucket is counted (so the exclusion is visible) but never averaged in.
    """
    scored = [r for r in joined if r["status"] == SCORED]

    out: dict = {
        "total": len(joined),
        "scored": len(scored),
        "answer_errors": sum(1 for r in joined if r["status"] == ANSWER_ERROR),
        "judge_errors": sum(1 for r in joined if r["status"] == JUDGE_ERROR),
        "not_judged": sum(1 for r in joined if r["status"] == NOT_JUDGED),
        "orphan_verdicts": sum(1 for r in joined if r["status"] == ORPHAN_VERDICT),
    }

    out["accuracy"] = _pooled_accuracy(scored)
    out["accuracy_verdict_a"] = _accuracy_from(scored, "verdict_a")
    out["accuracy_verdict_b"] = _accuracy_from(scored, "verdict_b")
    out["disagreement_rate"] = _disagreement_rate(scored)

    by_type: dict[str, list[dict]] = defaultdict(list)
    for r in scored:
        by_type[r.get("question_type") or "unknown"].append(r)
    out["by_type"] = {
        qtype: {"n": len(group), "accuracy": _pooled_accuracy(group)}
        for qtype, group in sorted(by_type.items())
    }

    hits = [r for r in scored if r["retrieval_hit"] is True]
    misses = [r for r in scored if r["retrieval_hit"] is False]
    out["by_retrieval"] = {
        "hit": {"n": len(hits), "accuracy": _pooled_accuracy(hits)},
        "miss": {"n": len(misses), "accuracy": _pooled_accuracy(misses)},
    }

    return out


def _load_jsonl(path: Path) -> tuple[dict[str, dict], list[str]]:
    """Rows keyed by question_id, plus any ids that appeared more than once.

    Last-wins is the right merge -- a resumed run appends a retry after the
    attempt it replaces -- but doing it silently is not. A duplicate can flip a
    scored row to an error or a correct verdict to an incorrect one while every
    count in the output stays internally consistent, so nothing looks wrong.
    qa_run.py's own answered() docstring warns about this by name, for this
    stage. Collapsing is fine; collapsing without saying so is how a wrong
    headline gets published.
    """
    if not path.exists():
        raise FileNotFoundError(path)
    rows: dict[str, dict] = {}
    duplicates: list[str] = []
    for line in path.read_text().splitlines():
        if not line.strip():
            continue
        row = json.loads(line)
        qid = row["question_id"]
        if qid in rows:
            duplicates.append(qid)
        rows[qid] = row
    return rows, duplicates


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Score the QA layer: join answers and verdicts, report accuracy."
    )
    parser.add_argument(
        "--tag", required=True,
        help="matches answers-{tag}.jsonl and verdicts-{tag}.jsonl",
    )
    args = parser.parse_args()

    answers_path = config.RESULTS_DIR / f"answers-{args.tag}.jsonl"
    verdicts_path = config.RESULTS_DIR / f"verdicts-{args.tag}.jsonl"

    try:
        answers, answer_dupes = _load_jsonl(answers_path)
    except FileNotFoundError as exc:
        print(f"[qa_evaluate] no such file: {exc}", file=sys.stderr)
        return 1
    try:
        verdicts, verdict_dupes = _load_jsonl(verdicts_path)
    except FileNotFoundError as exc:
        print(f"[qa_evaluate] no such file: {exc}", file=sys.stderr)
        return 1

    joined = join_rows(answers, verdicts)
    metrics = score_rows(joined)
    metrics["tag"] = args.tag
    metrics["answers_total"] = len(answers)
    metrics["verdicts_total"] = len(verdicts)
    metrics["duplicate_answer_ids"] = sorted(set(answer_dupes))
    metrics["duplicate_verdict_ids"] = sorted(set(verdict_dupes))

    config.RESULTS_DIR.mkdir(parents=True, exist_ok=True)
    out_path = config.RESULTS_DIR / f"qa-metrics-{args.tag}.json"
    out_path.write_text(json.dumps(metrics, indent=2))

    def fmt(value):
        # Not an f-string with nested same-type quotes -- see evaluate.py's
        # fmt() for why (PEP 701 gate, no reason to require Python 3.12+ here).
        return "n/a" if value is None else f"{value:.3f}"

    print(
        f"[qa_evaluate] {args.tag}: {metrics['scored']}/{metrics['total']} scored "
        f"(answer_errors={metrics['answer_errors']}, judge_errors={metrics['judge_errors']}, "
        f"not_judged={metrics['not_judged']}, orphan_verdicts={metrics['orphan_verdicts']})"
    )
    print(f"  accuracy: {fmt(metrics['accuracy'])}")
    print(f"  accuracy (verdict_a alone): {fmt(metrics['accuracy_verdict_a'])}")
    print(f"  accuracy (verdict_b alone): {fmt(metrics['accuracy_verdict_b'])}")
    print(f"  judge disagreement rate: {fmt(metrics['disagreement_rate'])}")
    print("  by retrieval:")
    print(f"    hit  (n={metrics['by_retrieval']['hit']['n']}): "
          f"{fmt(metrics['by_retrieval']['hit']['accuracy'])}")
    print(f"    miss (n={metrics['by_retrieval']['miss']['n']}): "
          f"{fmt(metrics['by_retrieval']['miss']['accuracy'])}")
    if metrics["by_type"]:
        print("  by type:")
        for qtype, entry in metrics["by_type"].items():
            print(f"    {qtype} (n={entry['n']}): {fmt(entry['accuracy'])}")
    print(f"[qa_evaluate] wrote {out_path}")

    for label, dupes in (("answers", answer_dupes), ("verdicts", verdict_dupes)):
        if dupes:
            unique = sorted(set(dupes))
            print(
                f"[qa_evaluate] WARNING: {len(dupes)} duplicate row(s) in {label} for "
                f"{len(unique)} question(s): {', '.join(unique[:5])}"
                f"{' ...' if len(unique) > 5 else ''}. Last row wins, so an earlier "
                "result was discarded -- re-check the run that produced this file.",
                file=sys.stderr,
            )

    if metrics["scored"] == 0:
        print("[qa_evaluate] FAILED: nothing was scored", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
