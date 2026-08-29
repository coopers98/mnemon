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


def recall_at_k(retrieved: list[str], gold: list[str], k: int) -> bool:
    """Did any gold evidence session appear in the top k?"""
    if not gold:
        return False
    return bool(set(retrieved[:k]) & set(gold))


def reciprocal_rank(retrieved: list[str], gold: list[str]) -> float:
    goldset = set(gold)
    for position, sid in enumerate(retrieved, 1):
        if sid in goldset:
            return 1.0 / position
    return 0.0


def score_rows(rows: list[dict], ks: list[int]) -> dict:
    """Aggregate metrics. Errored rows are excluded, never counted as misses."""
    scored = [r for r in rows if not r.get("error")]
    errors = len(rows) - len(scored)

    out: dict = {"total": len(rows), "scored": len(scored), "errors": errors}

    for k in ks:
        key = f"recall@{k}"
        if not scored:
            out[key] = None
            continue
        hits = sum(
            1 for r in scored
            if recall_at_k(r["retrieved"], r["answer_session_ids"], k)
        )
        out[key] = hits / len(scored)

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
            hits = sum(
                1 for r in group
                if recall_at_k(r["retrieved"], r["answer_session_ids"], k)
            )
            entry[f"recall@{k}"] = hits / len(group)
        out["by_type"][qtype] = entry

    return out


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

    out = config.RESULTS_DIR / f"metrics-{args.tag}.json"
    out.write_text(json.dumps(metrics, indent=2))

    print(f"[evaluate] {args.tag}: {metrics['scored']}/{metrics['total']} scored, "
          f"{metrics['errors']} errors")
    def fmt(value):
        # Not an f-string with nested same-type quotes: that only parses on
        # Python 3.12+ (PEP 701), and this harness has no reason to require it.
        return "n/a" if value is None else f"{value:.3f}"

    for k in args.k:
        print(f"  recall@{k}: {fmt(metrics[f'recall@{k}'])}")
    print(f"  MRR: {fmt(metrics['mrr'])}")
    print(f"[evaluate] wrote {out}")

    if metrics["scored"] == 0:
        print("[evaluate] FAILED: nothing was scored", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
