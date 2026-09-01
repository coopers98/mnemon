"""Judge stage: grade each recorded answer against the gold answer, twice.

Consumes `results/answers-{tag}.jsonl` (qa_run.py's output) and produces
`results/verdicts-{tag}.jsonl`.

The judge is run twice per question, with two independent HTTP calls against
the identical prompt -- not for redundancy's own sake, but because this
harness has already mistaken nondeterminism for signal once (the retrieval
layer's D19) and a temperature=0 model is still not bit-deterministic. Running
it twice turns "we assume the judge is stable" into a measured disagreement
rate that ships alongside the accuracy number instead of being assumed away.
"""

from __future__ import annotations

import argparse
import json
import re
import sys

import config
from dataset import iter_records
from qa_client import QaClient, QaError
from qa_prompt import build_judge_prompt

# Per-1M-token pricing (input, output), used only to print an estimate
# alongside the measured token total -- never to gate anything. Duplicated
# from qa_run.py rather than imported: each stage script owns its own copy in
# this harness (see qa_run.PRICING), and importing across sibling scripts
# would couple the judge stage to the reader stage for no benefit.
PRICING = {
    "gpt-4o": (2.50, 10.00),
}

# Prefix marking an error that is a permanent fact about the input row -- one
# this stage did not cause and rejudging cannot fix. Applied both to an error
# inherited from the answers file and to a question_id with no matching
# dataset record. Mirrors qa_run.py's RETRIEVAL_ERROR_PREFIX/`answered()`
# discipline: a resumed run must retry what might succeed next time and skip
# what never will.
ANSWER_ERROR_PREFIX = "answer error: "


def parse_verdict(text: str) -> bool:
    """Parse the judge's one-word verdict into True (CORRECT) / False (INCORRECT).

    Two things protect the INCORRECT/CORRECT overlap, and it is worth being
    clear about which does the work. The word-boundary match is the real
    guard: `\bCORRECT\b` does not match inside INCORRECT at all, so the
    ordering below is no longer load-bearing -- both orderings pass the suite.
    Checking INCORRECT first is kept as belt-and-braces, because anyone who
    "simplifies" these back to bare `in` checks would immediately need it: a
    substring check classifies every INCORRECT verdict as correct and silently
    inflates the accuracy number.

    Word boundaries also close the subtler version of the same bug, where a
    judge that editorialises instead of answering ("CORRECTION needed", "that
    needs correction") reads as a CORRECT verdict. Both failures point the
    same way -- toward a score that is too high.

    Tolerant of case and surrounding whitespace/punctuation (the model was
    asked for exactly one word, but "Correct." or a stray newline are cheap
    to accept). Anything that does not contain one of the two words raises
    rather than defaulting to either value -- a silent default would bias the
    score in whichever direction it points, invisibly.
    """
    normalized = text.strip().upper()
    # Word boundaries, not bare substrings. A plain `"CORRECT" in text` also
    # matches CORRECTION and "needs correction", so a judge that editorialised
    # instead of answering would be read as grading the answer correct. That is
    # the same failure as the INCORRECT/CORRECT overlap, one step subtler, and
    # it points the same way: toward a score that is too high.
    if re.search(r"\bINCORRECT\b", normalized):
        return False
    if re.search(r"\bCORRECT\b", normalized):
        return True
    raise ValueError(f"unparseable verdict: {text!r}")


def judge_answer(client, record: dict | None, row: dict) -> dict:
    """Judge one answer row, or record why it could not be judged.

    An answer row that already carries an error (a retrieval or reader
    failure from an earlier stage) is never sent to the judge -- there is
    nothing to grade, and doing so would either crash on a None answer or
    silently grade a placeholder. It is carried through as an error here too,
    same as qa_run.py does for a retrieval error it did not cause.

    The two judge calls share the exact same prompt, built once. If the
    first call's verdict does not parse, the second is never made -- there
    is no point spending on it once the row is already going to be recorded
    as an error.
    """
    out = {
        "question_id": row["question_id"],
        "verdict_a": None,
        "verdict_b": None,
        "agreed": None,
        "error": None,
        "usage": None,
    }

    if row.get("error"):
        out["error"] = f"{ANSWER_ERROR_PREFIX}{row['error']}"
        return out

    if record is None:
        out["error"] = f"{ANSWER_ERROR_PREFIX}no matching dataset record for question_id"
        return out

    messages = build_judge_prompt(record["question"], record["answer"], row.get("answer"))

    try:
        text_a, usage_a = client.complete(messages)
        verdict_a = parse_verdict(text_a)
        text_b, usage_b = client.complete(messages)
        verdict_b = parse_verdict(text_b)
    except (QaError, ValueError) as exc:
        out["error"] = str(exc)
        return out

    out["verdict_a"] = verdict_a
    out["verdict_b"] = verdict_b
    out["agreed"] = verdict_a == verdict_b
    out["usage"] = {"a": usage_a, "b": usage_b}
    return out


def answered(tag: str) -> set[str]:
    """Question ids that a resumed run should not judge again.

    A row whose error was inherited from the answers file (or was a missing
    dataset record) is a permanent fact -- this stage never re-runs the
    reader, so rejudging cannot turn it into a verdict. Excluding it would
    append a duplicate row on every resume. A judge-side error (a rate limit,
    a malformed response, an unparseable verdict) is different: it is this
    stage's own failure and may well succeed on a retry, so it is not counted
    as done. Same discipline as qa_run.answered().
    """
    path = config.RESULTS_DIR / f"verdicts-{tag}.jsonl"
    if not path.exists():
        return set()
    done: set[str] = set()
    for line in path.read_text().splitlines():
        if not line.strip():
            continue
        row = json.loads(line)
        error = row.get("error") or ""
        if not error or error.startswith(ANSWER_ERROR_PREFIX):
            done.add(row["question_id"])
    return done


def _load_answers(tag: str) -> dict[str, dict]:
    path = config.RESULTS_DIR / f"answers-{tag}.jsonl"
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
        description="Judge stage: grade each recorded answer against the gold answer, twice."
    )
    parser.add_argument(
        "--tag", required=True,
        help="matches the answers-{tag}.jsonl produced by qa_run.py",
    )
    parser.add_argument(
        "--limit", type=int, default=None,
        help="judge only the first N of the pending questions, for smoke testing",
    )
    args = parser.parse_args()

    try:
        answers = _load_answers(args.tag)
    except FileNotFoundError as exc:
        print(f"[qa_judge] no such file: {exc}", file=sys.stderr)
        return 1

    records = {
        r["question_id"]: r
        for r in iter_records(config.DATASET_S)
        if r["question_id"] in answers
    }

    done = answered(args.tag)
    todo = sorted(qid for qid in answers if qid not in done)
    if args.limit is not None:
        todo = todo[: args.limit]

    print(
        f"[qa_judge] {len(todo)} question(s) to judge ({len(done)} already done), "
        f"tag={args.tag}, model={config.QA_MODEL}"
    )
    if not todo:
        print("[qa_judge] nothing to do -- all requested questions already judged")
        return 0

    client = QaClient(config.QA_MODEL, config.OPENAI_API_KEY)

    config.RESULTS_DIR.mkdir(parents=True, exist_ok=True)
    out_path = config.RESULTS_DIR / f"verdicts-{args.tag}.jsonl"

    token_total = 0
    prompt_total = 0
    completion_total = 0
    errors = 0
    disagreements = 0

    with open(out_path, "a", encoding="utf-8") as fh:
        for n, qid in enumerate(todo, 1):
            result = judge_answer(client, records.get(qid), answers[qid])
            fh.write(json.dumps(result) + "\n")
            fh.flush()

            if result["error"]:
                errors += 1
                print(f"[qa_judge] {n}/{len(todo)} {qid} FAILED: {result['error']}", file=sys.stderr)
                continue

            if not result["agreed"]:
                disagreements += 1

            for usage in (result["usage"] or {}).values():
                prompt_total += usage.get("prompt_tokens", 0)
                completion_total += usage.get("completion_tokens", 0)
                token_total += usage.get("total_tokens", 0)

            print(
                f"[qa_judge] {n}/{len(todo)} {qid} verdict_a={result['verdict_a']} "
                f"verdict_b={result['verdict_b']} agreed={result['agreed']}"
            )

    judged = len(todo) - errors
    rate = PRICING.get(config.QA_MODEL)
    if rate is None:
        cost_note = f"no pricing on file for {config.QA_MODEL}"
    else:
        input_rate, output_rate = rate
        cost = prompt_total / 1_000_000 * input_rate + completion_total / 1_000_000 * output_rate
        cost_note = f"~${cost:.4f}"
    print(f"[qa_judge] done: {token_total} tokens, estimated cost {cost_note}")
    if judged:
        print(
            f"[qa_judge] disagreement rate: {disagreements}/{judged} "
            f"({disagreements / judged:.1%})"
        )

    if errors == len(todo):
        print(
            f"[qa_judge] FAILED: all {errors} question(s) errored -- nothing was judged",
            file=sys.stderr,
        )
        return 1
    if errors:
        print(f"[qa_judge] WARNING: {errors} question(s) errored and are excluded from scoring")
    return 0


if __name__ == "__main__":
    sys.exit(main())
