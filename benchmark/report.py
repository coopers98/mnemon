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

# Kept short deliberately -- the full explanation (why pooling, why the reader
# abstains, why judge and reader share a model) lives in benchmark/README.md,
# which this points to. But the three facts below are load-bearing enough
# that a reader who only ever sees report.md must not be able to miss them:
# without the pooling rule the accuracy figure cannot be reconstructed from
# its own by-verdict rows, and the other two are the difference between a
# memory-quality number and a prompt-policy number.
QA_CAVEAT = (
    "LLM-judged answer accuracy (LongMemEval's own headline metric). Each answer is "
    "judged twice by the same judge model; a split verdict (`verdict_a` != `verdict_b`) "
    "contributes 0.5 to `accuracy` below — see `disagreement_rate` and the two "
    "verdict-alone rows for how much that pooling could have moved the number. The "
    "reader is instructed to abstain (reply `NOT FOUND`) rather than guess, which is "
    "conservative relative to a system whose reader guesses. Reader and judge are the "
    "same model (self-judged), matching LongMemEval's published methodology for "
    "comparability, at the cost of self-preference bias pointing the other way. See "
    "benchmark/README.md for the full explanation."
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


def render_qa(qa_by_tag: dict[str, dict]) -> str:
    """Render the QA-layer metrics (qa-metrics-{tag}.json) side by side.

    Deliberately a separate function from `render`, not a branch inside it:
    `render`'s signature and output are pinned by RenderTest et al., and this
    section is optional (a tag may have retrieval metrics but no QA run yet).
    Keeping them apart is what makes "does not disturb the existing retrieval
    tables" true by construction rather than by care.
    """
    tags = list(qa_by_tag)
    lines = ["## LongMemEval-S — QA accuracy (LLM-judged)", "", QA_CAVEAT, ""]

    header = "| Metric | " + " | ".join(tags) + " |"
    lines.append(header)
    lines.append("|---" * (len(tags) + 1) + "|")

    def row(label: str, getter) -> str:
        return f"| {label} | " + " | ".join(_fmt(getter(qa_by_tag[t])) for t in tags) + " |"

    def row_n(label: str, getter) -> str:
        return f"| {label} | " + " | ".join(str(getter(qa_by_tag[t]) if getter(qa_by_tag[t]) is not None else "n/a") for t in tags) + " |"

    lines.append(row("accuracy", lambda m: m.get("accuracy")))
    lines.append(row("accuracy (verdict_a alone)", lambda m: m.get("accuracy_verdict_a")))
    lines.append(row("accuracy (verdict_b alone)", lambda m: m.get("accuracy_verdict_b")))
    lines.append(row("judge disagreement rate", lambda m: m.get("disagreement_rate")))

    # The conditional split (D5/Task 5's whole reason for existing): a poor
    # QA score has two very different causes, and this is what tells them
    # apart -- retrieval never surfaced the evidence (miss) versus the reader
    # had the evidence and still answered wrong (hit).
    lines.append(row(
        "accuracy | retrieval_hit=True",
        lambda m: m.get("by_retrieval", {}).get("hit", {}).get("accuracy"),
    ))
    lines.append(row_n(
        "n (retrieval_hit=True)",
        lambda m: m.get("by_retrieval", {}).get("hit", {}).get("n"),
    ))
    lines.append(row(
        "accuracy | retrieval_hit=False",
        lambda m: m.get("by_retrieval", {}).get("miss", {}).get("accuracy"),
    ))
    lines.append(row_n(
        "n (retrieval_hit=False)",
        lambda m: m.get("by_retrieval", {}).get("miss", {}).get("n"),
    ))

    lines.append(row_n("questions scored", lambda m: m.get("scored")))
    lines.append(row_n("answer errors", lambda m: m.get("answer_errors")))
    lines.append(row_n("judge errors", lambda m: m.get("judge_errors")))
    # Model and K come from qa_run.py's qa-meta-{tag}.json companion file
    # (mirroring retrieve.py's embedding-driver row) -- not from a value
    # hardcoded here, which could silently drift from what actually ran.
    lines.append(row_n("model", lambda m: m.get("model")))
    lines.append(row_n("k", lambda m: m.get("k")))

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

    # QA metrics are optional and looked up under the same --tags: a tag with
    # only a retrieval run (no qa_run.py/qa_judge.py/qa_evaluate.py pass yet)
    # is silently absent from this section rather than treated as an error --
    # this section did not exist for most of this harness's history and a lot
    # of retrieval-only tags still don't have it.
    qa_metrics = {}
    for tag in args.tags:
        path = config.RESULTS_DIR / f"qa-metrics-{tag}.json"
        if path.exists():
            qa_metrics[tag] = json.loads(path.read_text())

    text = render(metrics, args.k)
    if qa_metrics:
        text += "\n\n" + render_qa(qa_metrics)
    print(text)
    out = config.RESULTS_DIR / "report.md"
    out.write_text(text + "\n")
    print(f"\n[report] wrote {out}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
