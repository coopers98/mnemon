"""Render the keyless/embedded pair as a markdown table.

The palace-only caveat is emitted unconditionally: LongMemEval exercises
conversational recall, which is the palace. The wiki's synthesis is not
measured, and a number published without that sentence overclaims.
"""

from __future__ import annotations

import argparse
import json
import sys

import config

CAVEAT = (
    "Measured against Mnemon's **palace** layer only — LongMemEval tests recall "
    "over conversational history. The wiki's compiled synthesis is not exercised "
    "by this benchmark."
)


def _fmt(value) -> str:
    return "n/a" if value is None else f"{value:.3f}"


def _driver(metrics: dict) -> str:
    """One tag column's actual server configuration, from brain_status()
    (I1) — not the free-text --tag an operator happened to type."""
    embedding = metrics.get("embedding")
    if not embedding:
        return "n/a"
    driver = embedding.get("driver", "n/a")
    dims = embedding.get("dimensions")
    return f"{driver} ({dims}d)" if dims else str(driver)


def render(metrics_by_tag: dict[str, dict], ks: list[int]) -> str:
    tags = list(metrics_by_tag)
    lines = ["## LongMemEval-S — retrieval", "", CAVEAT, ""]

    header = "| Metric | " + " | ".join(tags) + " |"
    lines.append(header)
    lines.append("|---" * (len(tags) + 1) + "|")

    # Both metric families are published side by side, never one renamed as
    # the other (C1): hit_rate@k is "did we find any gold session at all",
    # recall@k is "what fraction of the gold sessions did we find". They
    # only coincide when a question has exactly one gold session.
    for k in ks:
        row = [_fmt(metrics_by_tag[t].get(f"hit_rate@{k}")) for t in tags]
        lines.append(f"| hit_rate@{k} | " + " | ".join(row) + " |")
    for k in ks:
        row = [_fmt(metrics_by_tag[t].get(f"recall@{k}")) for t in tags]
        lines.append(f"| recall@{k} | " + " | ".join(row) + " |")

    lines.append("| MRR | " + " | ".join(_fmt(metrics_by_tag[t].get("mrr")) for t in tags) + " |")
    lines.append("| questions scored | " + " | ".join(str(metrics_by_tag[t].get("scored")) for t in tags) + " |")
    lines.append("| errors | " + " | ".join(str(metrics_by_tag[t].get("errors")) for t in tags) + " |")
    # I1: the driver actually measured, from brain_status(), not the
    # free-text --tag an operator chose — a "--tag embedded" run against a
    # keyless server used to print a column labelled "embedded" with no way
    # to tell from the report alone that it measured no such thing.
    lines.append("| embedding driver | " + " | ".join(_driver(metrics_by_tag[t]) for t in tags) + " |")

    types = sorted({t for m in metrics_by_tag.values() for t in m.get("by_type", {})})
    if types:
        # I4/I5: rendered at ks[0] (not ks[-1]) with K in the header — at
        # recall@10 every subset cell here reads 1.000 and the section
        # carries no information while looking like a clean sweep. Each tag
        # gets its own `n` column rather than one shared number, because
        # excluding errors from the denominator (by design, see score_rows)
        # means two tags can legitimately score a different number of
        # questions of the same type.
        k0 = ks[0]
        for metric in ("hit_rate", "recall"):
            lines += [
                "",
                f"### By question type — {metric}@{k0}",
                "",
                "| Type | " + " | ".join(f"n ({t})" for t in tags) + " | " + " | ".join(tags) + " |",
                "|---" * (len(tags) * 2 + 1) + "|",
            ]
            for qtype in types:
                n_cells = [
                    str(metrics_by_tag[t].get("by_type", {}).get(qtype, {}).get("n", "-"))
                    for t in tags
                ]
                cells = [
                    _fmt(metrics_by_tag[t].get("by_type", {}).get(qtype, {}).get(f"{metric}@{k0}"))
                    for t in tags
                ]
                lines.append(f"| {qtype} | " + " | ".join(n_cells) + " | " + " | ".join(cells) + " |")

    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description="Render the benchmark report.")
    parser.add_argument("--tags", nargs="+", default=["keyless", "embedded"])
    parser.add_argument("--k", type=int, nargs="+", default=[1, 3, 5, 10])
    args = parser.parse_args()

    metrics = {}
    for tag in args.tags:
        path = config.RESULTS_DIR / f"metrics-{tag}.json"
        if not path.exists():
            print(f"[report] missing {path} — run evaluate.py --tag {tag}", file=sys.stderr)
            continue
        metrics[tag] = json.loads(path.read_text())

    if not metrics:
        print("[report] no metrics to render", file=sys.stderr)
        return 1

    text = render(metrics, args.k)
    print(text)
    out = config.RESULTS_DIR / "report.md"
    out.write_text(text + "\n")
    print(f"\n[report] wrote {out}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
