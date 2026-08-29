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


def render(metrics_by_tag: dict[str, dict], ks: list[int]) -> str:
    tags = list(metrics_by_tag)
    lines = ["## LongMemEval-S — retrieval", "", CAVEAT, ""]

    header = "| Metric | " + " | ".join(tags) + " |"
    lines.append(header)
    lines.append("|---" * (len(tags) + 1) + "|")

    for k in ks:
        key = f"recall@{k}"
        row = [_fmt(metrics_by_tag[t].get(key)) for t in tags]
        lines.append(f"| {key} | " + " | ".join(row) + " |")

    lines.append("| MRR | " + " | ".join(_fmt(metrics_by_tag[t].get("mrr")) for t in tags) + " |")
    lines.append("| questions scored | " + " | ".join(str(metrics_by_tag[t].get("scored")) for t in tags) + " |")
    lines.append("| errors | " + " | ".join(str(metrics_by_tag[t].get("errors")) for t in tags) + " |")

    types = sorted({t for m in metrics_by_tag.values() for t in m.get("by_type", {})})
    if types:
        lines += ["", "### By question type", "", "| Type | n | " + " | ".join(tags) + " |",
                  "|---" * (len(tags) + 2) + "|"]
        primary = ks[-1]
        for qtype in types:
            n = next(
                (m["by_type"][qtype]["n"] for m in metrics_by_tag.values()
                 if qtype in m.get("by_type", {})),
                0,
            )
            cells = [
                _fmt(metrics_by_tag[t].get("by_type", {}).get(qtype, {}).get(f"recall@{primary}"))
                for t in tags
            ]
            lines.append(f"| {qtype} | {n} | " + " | ".join(cells) + " |")

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
