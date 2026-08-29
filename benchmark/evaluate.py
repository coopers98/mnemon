"""Score a retrieval run.

Kept separate from retrieve.py so a run can be re-scored at different K
without touching the server.
"""

from __future__ import annotations

import argparse
import json
import sys
from collections import defaultdict

import config


def hit_rate_at_k(retrieved: list[str], gold: list[str], k: int) -> bool:
    """Did *any* gold evidence session appear in the top k?

    This is success@k / hit-rate@k, NOT standard recall, even though an
    earlier version of this function was named `recall_at_k` and published
    under that name. The two coincide only when every question has exactly
    one gold session. As soon as a question has two or more (13 of this
    benchmark's 25-question subset do; 324 of the full 500-question
    LongMemEval-S set do), a run that finds one gold session out of three and
    a run that finds all three both score this function as a plain hit —
    the metric cannot tell "found some evidence" from "found all of it"
    apart. That distinction is exactly what `recall_at_k` below answers.
    Keep this function anyway: "can the agent find any evidence at all" is a
    real, useful question on its own, just not the one "recall" names.
    """
    if not gold:
        return False
    return bool(set(retrieved[:k]) & set(gold))


def recall_at_k(retrieved: list[str], gold: list[str], k: int) -> float:
    """Standard recall@k: the fraction of gold evidence sessions retrieved in
    the top k, i.e. `|retrieved[:k] ∩ gold| / |gold|`.

    Returns 0.0 when `gold` is empty — there is nothing to recall, so no
    fraction of it was found. This is the convention other systems' published
    LongMemEval retrieval figures use, and it is the metric that answers "how
    much of the evidence did the agent find" rather than merely "did it find
    any."
    """
    if not gold:
        return 0.0
    return len(set(retrieved[:k]) & set(gold)) / len(gold)


def reciprocal_rank(retrieved: list[str], gold: list[str]) -> float:
    goldset = set(gold)
    for position, sid in enumerate(retrieved, 1):
        if sid in goldset:
            return 1.0 / position
    return 0.0


def score_rows(rows: list[dict], ks: list[int]) -> dict:
    """Aggregate metrics. Errored rows are excluded, never counted as misses.

    Both `hit_rate@{k}` and `recall@{k}` are emitted, top-level and per
    question type — see the two functions above for why they are not
    interchangeable and both are worth publishing.
    """
    scored = [r for r in rows if not r.get("error")]
    errors = len(rows) - len(scored)

    out: dict = {"total": len(rows), "scored": len(scored), "errors": errors}

    for k in ks:
        hr_key = f"hit_rate@{k}"
        rc_key = f"recall@{k}"
        if not scored:
            out[hr_key] = None
            out[rc_key] = None
            continue
        out[hr_key] = sum(
            1 for r in scored
            if hit_rate_at_k(r["retrieved"], r["answer_session_ids"], k)
        ) / len(scored)
        out[rc_key] = sum(
            recall_at_k(r["retrieved"], r["answer_session_ids"], k)
            for r in scored
        ) / len(scored)

    out["mrr"] = (
        sum(reciprocal_rank(r["retrieved"], r["answer_session_ids"]) for r in scored)
        / len(scored)
        if scored
        else None
    )

    by_type: dict = defaultdict(list)
    for r in scored:
        by_type[r.get("question_type") or "unknown"].append(r)

    out["by_type"] = {}
    for qtype, group in sorted(by_type.items()):
        entry = {"n": len(group)}
        for k in ks:
            entry[f"hit_rate@{k}"] = sum(
                1 for r in group
                if hit_rate_at_k(r["retrieved"], r["answer_session_ids"], k)
            ) / len(group)
            entry[f"recall@{k}"] = sum(
                recall_at_k(r["retrieved"], r["answer_session_ids"], k)
                for r in group
            ) / len(group)
        out["by_type"][qtype] = entry

    return out


def _load_run_meta(tag: str) -> dict | None:
    """Companion metadata retrieve.py wrote alongside `hits-{tag}.jsonl`.

    Absent for hits files produced before this was added, or if the
    brain_status() call in retrieve.py failed — callers must treat a missing
    file as "unknown configuration," not as an error.
    """
    path = config.RESULTS_DIR / f"meta-{tag}.json"
    if not path.exists():
        return None
    try:
        return json.loads(path.read_text())
    except json.JSONDecodeError:
        return None


def main() -> int:
    parser = argparse.ArgumentParser(description="Score a retrieval run.")
    parser.add_argument("--tag", required=True)
    parser.add_argument("--k", type=int, nargs="+", default=[1, 3, 5, 10])
    args = parser.parse_args()

    path = config.RESULTS_DIR / f"hits-{args.tag}.jsonl"
    if not path.exists():
        print(f"[evaluate] no such file: {path}", file=sys.stderr)
        return 1

    rows = [json.loads(line) for line in path.read_text().splitlines() if line.strip()]
    metrics = score_rows(rows, args.k)

    run_meta = _load_run_meta(args.tag)
    metrics["embedding"] = (run_meta or {}).get("embedding")

    out = config.RESULTS_DIR / f"metrics-{args.tag}.json"
    out.write_text(json.dumps(metrics, indent=2))

    print(f"[evaluate] {args.tag}: {metrics['scored']}/{metrics['total']} scored, "
          f"{metrics['errors']} errors")
    if metrics["embedding"]:
        print(f"[evaluate] {args.tag}: embedding driver = {metrics['embedding'].get('driver')}")
    def fmt(value):
        # Not an f-string with nested same-type quotes: that only parses on
        # Python 3.12+ (PEP 701), and this harness has no reason to require it.
        return "n/a" if value is None else f"{value:.3f}"

    for k in args.k:
        print(f"  hit_rate@{k}: {fmt(metrics[f'hit_rate@{k}'])}   "
              f"recall@{k}: {fmt(metrics[f'recall@{k}'])}")
    print(f"  MRR: {fmt(metrics['mrr'])}")
    print(f"[evaluate] wrote {out}")

    if metrics["scored"] == 0:
        print("[evaluate] FAILED: nothing was scored", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
