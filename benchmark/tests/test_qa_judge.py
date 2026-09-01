"""Judge stage tests.

parse_verdict is pure and cheap to exercise directly. judge_answer is tested
against a fake client -- same pattern test_qa_run.py uses for
answer_question -- so nothing here calls the real API.
"""

import json
import tempfile
import unittest
from pathlib import Path

import config
from qa_client import QaError
from qa_judge import ANSWER_ERROR_PREFIX, answered, judge_answer, parse_verdict


class ParseVerdictTest(unittest.TestCase):
    def test_correct_parses_true(self):
        self.assertIs(True, parse_verdict("CORRECT"))

    def test_incorrect_parses_false(self):
        self.assertIs(False, parse_verdict("INCORRECT"))

    def test_lowercase_and_trailing_punctuation_are_tolerated(self):
        self.assertIs(True, parse_verdict("correct."))

    def test_surrounding_whitespace_is_tolerated(self):
        self.assertIs(False, parse_verdict("  Incorrect  \n"))

    def test_an_unparseable_verdict_raises_rather_than_defaulting(self):
        # Fails if parse_verdict falls back to True or False instead of
        # raising -- either default would silently bias the score in
        # whichever direction it points.
        with self.assertRaises(ValueError):
            parse_verdict("maybe")

    def test_incorrect_is_not_matched_by_a_naive_correct_substring_check(self):
        # INCORRECT contains CORRECT as a substring. This fails if
        # parse_verdict is implemented as (or reordered into) a bare
        # `"CORRECT" in text` check that runs before -- or instead of -- the
        # INCORRECT check: every INCORRECT verdict would then read as True,
        # and a run's accuracy number would be silently, badly inflated.
        self.assertIs(
            False, parse_verdict("INCORRECT"),
            "a naive 'CORRECT' in text substring check makes this True",
        )


RECORD = {
    "question_id": "q1",
    "question": "What degree?",
    "answer": "Business Administration",
}

ROW_OK = {"question_id": "q1", "answer": "business admin", "error": None}


class FakeClient:
    """Returns one fixed (text, usage) response per call, in order.

    `raise_on_call` names 1-based call numbers that raise QaError instead --
    used to simulate the second of the two judge calls failing.
    """

    def __init__(self, responses=None, raise_on_call=None):
        self.responses = list(responses or [])
        self.raise_on_call = raise_on_call or set()
        self.calls = 0

    def complete(self, messages, retries=3):
        self.calls += 1
        if self.calls in self.raise_on_call:
            raise QaError("boom")
        return self.responses[self.calls - 1]


class JudgeAnswerTest(unittest.TestCase):
    def test_agreement_when_both_verdicts_match(self):
        client = FakeClient([("CORRECT", {"total_tokens": 5}), ("CORRECT", {"total_tokens": 5})])
        out = judge_answer(client, RECORD, ROW_OK)
        self.assertIs(True, out["verdict_a"])
        self.assertIs(True, out["verdict_b"])
        self.assertIs(True, out["agreed"])
        self.assertIsNone(out["error"])

    def test_a_disagreement_is_recorded_with_both_verdicts_preserved(self):
        # Fails if `agreed` is hardcoded True, or if only one verdict is kept.
        client = FakeClient([("CORRECT", {"total_tokens": 5}), ("INCORRECT", {"total_tokens": 5})])
        out = judge_answer(client, RECORD, ROW_OK)
        self.assertIs(True, out["verdict_a"])
        self.assertIs(False, out["verdict_b"])
        self.assertIs(False, out["agreed"])

    def test_two_independent_calls_are_made(self):
        # Fails if the second verdict is derived from the first (e.g. copied)
        # instead of a genuine second HTTP round trip.
        client = FakeClient([("CORRECT", {}), ("CORRECT", {})])
        judge_answer(client, RECORD, ROW_OK)
        self.assertEqual(2, client.calls)

    def test_usage_from_both_calls_is_recorded_separately(self):
        client = FakeClient([("CORRECT", {"total_tokens": 5}), ("CORRECT", {"total_tokens": 7})])
        out = judge_answer(client, RECORD, ROW_OK)
        self.assertEqual(5, out["usage"]["a"]["total_tokens"])
        self.assertEqual(7, out["usage"]["b"]["total_tokens"])

    def test_an_errored_answer_row_is_not_judged_at_all(self):
        # An answer row already carrying a reader/retrieval error is carried
        # through as an error, not sent to the judge. Fails if judge_answer
        # tries to grade row["answer"] == None anyway.
        client = FakeClient([("CORRECT", {}), ("CORRECT", {})])
        row = {"question_id": "q1", "answer": None, "error": "reader boom"}
        out = judge_answer(client, RECORD, row)
        self.assertIsNone(out["verdict_a"])
        self.assertIsNone(out["verdict_b"])
        self.assertIsNone(out["agreed"])
        self.assertEqual(f"{ANSWER_ERROR_PREFIX}reader boom", out["error"])
        self.assertEqual(0, client.calls, "an already-errored answer must never reach the judge")

    def test_a_missing_dataset_record_is_an_error_and_is_never_judged(self):
        client = FakeClient([("CORRECT", {}), ("CORRECT", {})])
        out = judge_answer(client, None, ROW_OK)
        self.assertIsNotNone(out["error"])
        self.assertEqual(0, client.calls)

    def test_a_qa_error_from_the_second_call_is_recorded_not_raised(self):
        # The first call succeeds; the second (a rate limit, a 5xx) fails.
        # The row is recorded as an error, and judge_answer itself must not
        # raise -- a run judging 500 questions cannot die on question 3.
        client = FakeClient([("CORRECT", {"total_tokens": 5})], raise_on_call={2})
        out = judge_answer(client, RECORD, ROW_OK)
        self.assertIsNone(out["verdict_a"])
        self.assertIsNone(out["verdict_b"])
        self.assertIsNotNone(out["error"])
        self.assertEqual(2, client.calls)

    def test_an_unparseable_verdict_from_the_model_is_recorded_not_raised(self):
        # parse_verdict's contract is to raise on "maybe" -- but judge_answer
        # must catch that and record a per-row error, not let it kill the run.
        # The second call is never made once the first verdict fails to parse.
        client = FakeClient([("maybe", {"total_tokens": 5}), ("CORRECT", {"total_tokens": 5})])
        out = judge_answer(client, RECORD, ROW_OK)
        self.assertIsNone(out["verdict_a"])
        self.assertIsNotNone(out["error"])
        self.assertEqual(1, client.calls, "no reason to spend on the second call once already erroring")


class AnsweredResumeTest(unittest.TestCase):
    """Mirrors qa_run.answered()'s terminal-vs-transient discipline, applied
    to the verdicts file."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.orig = config.RESULTS_DIR
        config.RESULTS_DIR = Path(self.tmp.name)

    def tearDown(self):
        config.RESULTS_DIR = self.orig
        self.tmp.cleanup()

    def _write(self, rows):
        path = config.RESULTS_DIR / "verdicts-t.jsonl"
        path.write_text("\n".join(json.dumps(r) for r in rows) + "\n")

    def test_no_file_yet_means_nothing_is_done(self):
        self.assertEqual(set(), answered("nope"))

    def test_a_clean_verdict_counts_as_done(self):
        self._write([{"question_id": "q1", "error": None}])
        self.assertEqual({"q1"}, answered("t"))

    def test_a_carried_answer_error_counts_as_done(self):
        # Fails if answered() does not special-case the ANSWER_ERROR_PREFIX:
        # rejudging cannot fix an error inherited from the reader stage, so
        # treating it as pending would append a duplicate row every resume.
        self._write([{"question_id": "q1", "error": f"{ANSWER_ERROR_PREFIX}boom"}])
        self.assertEqual({"q1"}, answered("t"))

    def test_a_judge_side_error_is_retried(self):
        # A transient failure of this stage's own calls (rate limit,
        # unparseable verdict) deserves another attempt on resume.
        self._write([{"question_id": "q1", "error": "rate limited"}])
        self.assertEqual(set(), answered("t"))

    def test_a_mixed_file_separates_the_two(self):
        self._write([
            {"question_id": "a", "error": None},
            {"question_id": "b", "error": f"{ANSWER_ERROR_PREFIX}boom"},
            {"question_id": "c", "error": "rate limited"},
        ])
        self.assertEqual({"a", "b"}, answered("t"))


class VerdictWordBoundaryTest(unittest.TestCase):
    """A word merely containing CORRECT is not a verdict.

    `"CORRECT" in text` also matches CORRECTION and "needs correction", so a
    judge that editorialised rather than answering would be read as grading the
    answer correct — the same failure as the INCORRECT/CORRECT overlap, one
    step subtler, and biased the same way. These fail if the implementation
    goes back to bare substring matching.
    """

    def test_correction_is_not_a_correct_verdict(self):
        with self.assertRaises(ValueError):
            parse_verdict("CORRECTION needed")

    def test_lowercase_correction_prose_is_not_a_verdict(self):
        with self.assertRaises(ValueError):
            parse_verdict("That needs correction")

    def test_incorrectly_is_not_an_incorrect_verdict(self):
        with self.assertRaises(ValueError):
            parse_verdict("answered incorrectly-ish")

    def test_the_six_tolerated_shapes_still_parse(self):
        self.assertTrue(parse_verdict("CORRECT"))
        self.assertTrue(parse_verdict("  correct.  "))
        self.assertTrue(parse_verdict('"CORRECT"'))
        self.assertTrue(parse_verdict("The answer is CORRECT."))
        self.assertFalse(parse_verdict("INCORRECT"))
        self.assertFalse(parse_verdict("INCORRECT — the candidate says nine months"))
