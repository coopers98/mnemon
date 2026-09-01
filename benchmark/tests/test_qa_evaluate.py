"""qa_evaluate.py joins two independently-resumable files -- answers-{tag}.jsonl
and verdicts-{tag}.jsonl -- that are not guaranteed to agree on which
questions exist yet. Every case here is a case the join must not silently
drop; see qa_evaluate.py's module docstring for the bucket definitions this
suite pins down.
"""

import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import config
import qa_evaluate
from qa_evaluate import (
    ANSWER_ERROR,
    JUDGE_ERROR,
    NOT_JUDGED,
    ORPHAN_VERDICT,
    SCORED,
    join_rows,
    score_rows,
)


def answer_row(qid, question_type="t", retrieval_hit=True, error=None):
    return {
        "question_id": qid,
        "question_type": question_type,
        "answer": None if error else "some answer",
        "retrieval_hit": retrieval_hit,
        "error": error,
        "usage": None,
    }


def verdict_row(qid, verdict_a=True, verdict_b=True, error=None):
    agreed = None if error else (verdict_a == verdict_b)
    return {
        "question_id": qid,
        "verdict_a": None if error else verdict_a,
        "verdict_b": None if error else verdict_b,
        "agreed": agreed,
        "error": error,
        "usage": None,
    }


def scored_row(qid, question_type="t", retrieval_hit=True, verdict_a=True, verdict_b=True):
    """A pre-joined row already in the SCORED bucket, for exercising
    score_rows() directly without going through join_rows() first."""
    return {
        "question_id": qid,
        "question_type": question_type,
        "retrieval_hit": retrieval_hit,
        "verdict_a": verdict_a,
        "verdict_b": verdict_b,
        "agreed": verdict_a == verdict_b,
        "status": SCORED,
    }


def unscored_row(qid, status):
    return {
        "question_id": qid,
        "question_type": None,
        "retrieval_hit": None,
        "verdict_a": None,
        "verdict_b": None,
        "agreed": None,
        "status": status,
    }


class JoinRowsTest(unittest.TestCase):
    def test_clean_pair_is_scored(self):
        joined = join_rows({"1": answer_row("1")}, {"1": verdict_row("1")})
        self.assertEqual(SCORED, joined[0]["status"])
        self.assertIs(True, joined[0]["verdict_a"])
        self.assertIs(True, joined[0]["verdict_b"])

    def test_answered_but_not_yet_judged(self):
        joined = join_rows({"1": answer_row("1")}, {})
        self.assertEqual(NOT_JUDGED, joined[0]["status"])

    def test_answer_error_wins_even_when_a_mirrored_verdict_row_exists(self):
        # qa_judge.py carries a reader-stage error forward into a verdict row
        # that has its own error string set. The bucket must come from the
        # answer row's error, not be re-derived from the verdict row -- an
        # implementation that inspected the verdict row's error field first
        # would land on JUDGE_ERROR here instead of ANSWER_ERROR. Both label
        # the row "some kind of error," so only a test that pins the EXACT
        # bucket, with both rows present and both erroring, can tell the two
        # implementations apart.
        joined = join_rows(
            {"1": answer_row("1", error="retrieval error: boom")},
            {"1": verdict_row("1", error="answer error: retrieval error: boom")},
        )
        self.assertEqual(ANSWER_ERROR, joined[0]["status"])
        self.assertIsNone(joined[0]["verdict_a"])

    def test_answer_error_with_no_verdict_row_at_all(self):
        joined = join_rows({"1": answer_row("1", error="boom")}, {})
        self.assertEqual(ANSWER_ERROR, joined[0]["status"])

    def test_judge_stage_error_when_the_answer_was_fine(self):
        joined = join_rows(
            {"1": answer_row("1")},
            {"1": verdict_row("1", error="429 rate limited")},
        )
        self.assertEqual(JUDGE_ERROR, joined[0]["status"])

    def test_orphan_verdict_has_no_matching_answer(self):
        joined = join_rows({}, {"1": verdict_row("1")})
        self.assertEqual(ORPHAN_VERDICT, joined[0]["status"])
        self.assertIsNone(joined[0]["question_type"])

    def test_retrieval_hit_and_question_type_come_from_the_answer_row(self):
        joined = join_rows(
            {"1": answer_row("1", question_type="temporal-reasoning", retrieval_hit=False)},
            {"1": verdict_row("1")},
        )
        self.assertEqual("temporal-reasoning", joined[0]["question_type"])
        self.assertIs(False, joined[0]["retrieval_hit"])

    def test_every_question_id_from_either_file_is_represented_exactly_once(self):
        # A silent inner join would drop the not-yet-judged and orphan rows
        # entirely rather than surfacing them -- this pins the union, not an
        # intersection.
        answers = {"1": answer_row("1"), "2": answer_row("2")}
        verdicts = {"1": verdict_row("1"), "3": verdict_row("3")}
        joined = join_rows(answers, verdicts)
        self.assertEqual(["1", "2", "3"], sorted(r["question_id"] for r in joined))


class ScoreRowsTest(unittest.TestCase):
    def test_errors_excluded_from_every_denominator(self):
        rows = [
            scored_row("1", verdict_a=True, verdict_b=True),
            unscored_row("2", ANSWER_ERROR),
            unscored_row("3", JUDGE_ERROR),
            unscored_row("4", NOT_JUDGED),
            unscored_row("5", ORPHAN_VERDICT),
        ]
        out = score_rows(rows)
        self.assertEqual(1, out["scored"])
        self.assertEqual(1.0, out["accuracy"])
        self.assertEqual(1, out["answer_errors"])
        self.assertEqual(1, out["judge_errors"])
        self.assertEqual(1, out["not_judged"])
        self.assertEqual(1, out["orphan_verdicts"])

    def test_conditional_split_on_retrieval_hit(self):
        rows = [
            scored_row("1", retrieval_hit=True, verdict_a=True, verdict_b=True),
            scored_row("2", retrieval_hit=True, verdict_a=False, verdict_b=False),
            scored_row("3", retrieval_hit=False, verdict_a=True, verdict_b=True),
        ]
        out = score_rows(rows)
        self.assertEqual(2, out["by_retrieval"]["hit"]["n"])
        self.assertEqual(0.5, out["by_retrieval"]["hit"]["accuracy"])
        self.assertEqual(1, out["by_retrieval"]["miss"]["n"])
        self.assertEqual(1.0, out["by_retrieval"]["miss"]["accuracy"])

    def test_retrieval_hit_and_miss_failures_are_not_interchangeable(self):
        # This is the split the whole layer exists for. Two rows that are
        # both wrong answers score identically on the headline number, but
        # must land on opposite sides of the conditional split -- a version
        # that pooled them into one group would still pass a test that only
        # checked the headline "accuracy" field.
        rows = [
            scored_row("1", retrieval_hit=True, verdict_a=False, verdict_b=False),
            scored_row("2", retrieval_hit=False, verdict_a=False, verdict_b=False),
        ]
        out = score_rows(rows)
        self.assertEqual(0.0, out["accuracy"])
        self.assertEqual(1, out["by_retrieval"]["hit"]["n"])
        self.assertEqual(1, out["by_retrieval"]["miss"]["n"])
        self.assertEqual(0.0, out["by_retrieval"]["hit"]["accuracy"])
        self.assertEqual(0.0, out["by_retrieval"]["miss"]["accuracy"])

    def test_by_question_type_breakdown(self):
        rows = [
            scored_row("1", question_type="alpha", verdict_a=True, verdict_b=True),
            scored_row("2", question_type="beta", verdict_a=False, verdict_b=False),
        ]
        out = score_rows(rows)
        self.assertEqual(1.0, out["by_type"]["alpha"]["accuracy"])
        self.assertEqual(0.0, out["by_type"]["beta"]["accuracy"])

    def test_disagreement_is_counted(self):
        rows = [
            scored_row("1", verdict_a=True, verdict_b=True),
            scored_row("2", verdict_a=True, verdict_b=False),
            scored_row("3", verdict_a=False, verdict_b=False),
        ]
        out = score_rows(rows)
        self.assertAlmostEqual(1 / 3, out["disagreement_rate"])

    def test_headline_pools_both_verdicts_while_alone_figures_isolate_one(self):
        # Pins the actual combination rule: the headline is not just
        # verdict_a scored alone (that would silently make the published
        # number depend on which of two equally-valid judge calls happened
        # to run first), and not just verdict_b alone either. It sits at the
        # pooled value, which for equal-sized groups is also the mean of the
        # two "alone" figures -- 0.75 here, distinct from both 1.0 and 0.5.
        rows = [
            scored_row("1", verdict_a=True, verdict_b=False),
            scored_row("2", verdict_a=True, verdict_b=True),
        ]
        out = score_rows(rows)
        self.assertEqual(1.0, out["accuracy_verdict_a"])
        self.assertEqual(0.5, out["accuracy_verdict_b"])
        self.assertEqual(0.75, out["accuracy"])

    def test_no_scored_rows_is_none_not_zero(self):
        rows = [unscored_row("1", ANSWER_ERROR), unscored_row("2", NOT_JUDGED)]
        out = score_rows(rows)
        self.assertEqual(0, out["scored"])
        self.assertIsNone(out["accuracy"])
        self.assertIsNone(out["accuracy_verdict_a"])
        self.assertIsNone(out["accuracy_verdict_b"])
        self.assertIsNone(out["disagreement_rate"])
        self.assertEqual({}, out["by_type"])
        self.assertIsNone(out["by_retrieval"]["hit"]["accuracy"])
        self.assertIsNone(out["by_retrieval"]["miss"]["accuracy"])

    def test_empty_side_of_the_retrieval_split_is_none_not_zero(self):
        rows = [scored_row("1", retrieval_hit=True, verdict_a=True, verdict_b=True)]
        out = score_rows(rows)
        self.assertEqual(0, out["by_retrieval"]["miss"]["n"])
        self.assertIsNone(out["by_retrieval"]["miss"]["accuracy"])
        self.assertEqual(1.0, out["by_retrieval"]["hit"]["accuracy"])


class MainTest(unittest.TestCase):
    """Exercises the real qa_evaluate.main() against files on disk, in a temp
    results dir -- not a reimplementation of score_rows()'s arithmetic, so a
    deleted guard or a broken file lookup shows up here even if the pure
    functions above are untouched.
    """

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self._orig_results = config.RESULTS_DIR
        config.RESULTS_DIR = Path(self.tmp.name)

    def tearDown(self):
        config.RESULTS_DIR = self._orig_results
        self.tmp.cleanup()

    def _write(self, name, rows):
        text = "\n".join(json.dumps(r) for r in rows)
        if text:
            text += "\n"
        (config.RESULTS_DIR / name).write_text(text)

    def test_missing_answers_file_exits_1(self):
        self._write("verdicts-x.jsonl", [verdict_row("1")])
        with patch.object(sys, "argv", ["qa_evaluate.py", "--tag", "x"]):
            code = qa_evaluate.main()
        self.assertEqual(1, code)

    def test_missing_verdicts_file_exits_1(self):
        self._write("answers-x.jsonl", [answer_row("1")])
        with patch.object(sys, "argv", ["qa_evaluate.py", "--tag", "x"]):
            code = qa_evaluate.main()
        self.assertEqual(1, code)

    def test_end_to_end_writes_metrics_json(self):
        self._write(
            "answers-x.jsonl",
            [answer_row("1", retrieval_hit=True), answer_row("2", retrieval_hit=False)],
        )
        self._write(
            "verdicts-x.jsonl",
            [verdict_row("1", True, True), verdict_row("2", False, False)],
        )
        with patch.object(sys, "argv", ["qa_evaluate.py", "--tag", "x"]):
            code = qa_evaluate.main()
        self.assertEqual(0, code)
        metrics = json.loads((config.RESULTS_DIR / "qa-metrics-x.json").read_text())
        self.assertEqual("x", metrics["tag"])
        self.assertEqual(2, metrics["scored"])
        self.assertEqual(0.5, metrics["accuracy"])
        self.assertEqual(1.0, metrics["by_retrieval"]["hit"]["accuracy"])
        self.assertEqual(0.0, metrics["by_retrieval"]["miss"]["accuracy"])

    def test_nothing_scoreable_exits_nonzero(self):
        # A run whose only answer errored, judged or not, has nothing this
        # stage can score -- the exit code must say so, not report 0/0 as if
        # it succeeded (evaluate.py's guard, applied here).
        self._write("answers-x.jsonl", [answer_row("1", error="boom")])
        self._write("verdicts-x.jsonl", [])
        with patch.object(sys, "argv", ["qa_evaluate.py", "--tag", "x"]):
            code = qa_evaluate.main()
        self.assertEqual(1, code)
        metrics = json.loads((config.RESULTS_DIR / "qa-metrics-x.json").read_text())
        self.assertEqual(0, metrics["scored"])

    def test_partial_scoring_does_not_trip_the_guard(self):
        self._write(
            "answers-x.jsonl", [answer_row("1"), answer_row("2", error="boom")]
        )
        self._write("verdicts-x.jsonl", [verdict_row("1", True, True)])
        with patch.object(sys, "argv", ["qa_evaluate.py", "--tag", "x"]):
            code = qa_evaluate.main()
        self.assertEqual(0, code)


if __name__ == "__main__":
    unittest.main()
