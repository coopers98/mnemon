import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import config
import evaluate
from evaluate import hit_rate_at_k, recall_at_k, reciprocal_rank, score_rows


class HitRateTest(unittest.TestCase):
    """Pins hit-rate@k semantics deliberately: "did any gold session appear
    in the top k" is a real, useful question, but it is not recall — see
    RecallTest below for the case where the two diverge."""

    def test_hit_within_k(self):
        self.assertTrue(hit_rate_at_k(["a", "b", "c"], ["c"], k=3))

    def test_miss_outside_k(self):
        self.assertFalse(hit_rate_at_k(["a", "b", "c"], ["c"], k=2))

    def test_any_gold_session_counts_toward_hit_rate(self):
        # This is the behaviour that makes hit-rate NOT recall: finding one
        # of two gold sessions is a full hit here...
        self.assertTrue(hit_rate_at_k(["x", "b"], ["a", "b"], k=5))

    def test_no_gold_sessions_is_not_a_hit(self):
        self.assertFalse(hit_rate_at_k(["a"], [], k=5))


class RecallTest(unittest.TestCase):
    """`recall_at_k` is `|retrieved[:k] ∩ gold| / |gold|` — the standard
    definition, and the one that diverges from hit-rate whenever a question
    has more than one gold session."""

    def test_hit_within_k_is_full_recall_for_a_single_gold_session(self):
        self.assertEqual(1.0, recall_at_k(["a", "b", "c"], ["c"], k=3))

    def test_miss_outside_k_is_zero_recall(self):
        self.assertEqual(0.0, recall_at_k(["a", "b", "c"], ["c"], k=2))

    def test_finding_half_of_two_gold_sessions_scores_one_half(self):
        # ...but the same retrieval scores 0.5 recall, not 1.0. This case is
        # the whole bug this function exists to fix: a metric that cannot
        # tell "found half the evidence" from "found all of it" is not
        # recall, no matter what it's called.
        self.assertEqual(0.5, recall_at_k(["x", "b"], ["a", "b"], k=5))

    def test_finding_neither_gold_session_scores_zero(self):
        self.assertEqual(0.0, recall_at_k(["x", "y"], ["a", "b"], k=5))

    def test_finding_both_gold_sessions_scores_one(self):
        self.assertEqual(1.0, recall_at_k(["a", "b"], ["a", "b"], k=5))

    def test_no_gold_sessions_scores_zero_not_none(self):
        self.assertEqual(0.0, recall_at_k(["a"], [], k=5))


class ReciprocalRankTest(unittest.TestCase):
    def test_first_position_scores_one(self):
        self.assertEqual(1.0, reciprocal_rank(["a"], ["a"]))

    def test_third_position_scores_a_third(self):
        self.assertAlmostEqual(1 / 3, reciprocal_rank(["x", "y", "a"], ["a"]))

    def test_absent_scores_zero(self):
        self.assertEqual(0.0, reciprocal_rank(["x"], ["a"]))


class ScoreRowsTest(unittest.TestCase):
    def test_errored_rows_are_excluded_from_the_denominator(self):
        rows = [
            {"question_id": "1", "question_type": "t", "retrieved": ["a"],
             "answer_session_ids": ["a"], "error": None},
            {"question_id": "2", "question_type": "t", "retrieved": [],
             "answer_session_ids": ["b"], "error": "429 rate limited"},
        ]
        out = score_rows(rows, ks=[10])
        self.assertEqual(1, out["scored"])
        self.assertEqual(1, out["errors"])
        self.assertEqual(1.0, out["hit_rate@10"])
        self.assertEqual(1.0, out["recall@10"])

    def test_two_gold_question_diverges_between_hit_rate_and_recall(self):
        # The exact bug C1 exists to fix, exercised through score_rows: one
        # question, two gold sessions, only one found. hit_rate says "hit";
        # recall says "half."
        rows = [
            {"question_id": "1", "question_type": "t", "retrieved": ["a", "z"],
             "answer_session_ids": ["a", "b"], "error": None},
        ]
        out = score_rows(rows, ks=[10])
        self.assertEqual(1.0, out["hit_rate@10"])
        self.assertEqual(0.5, out["recall@10"])

    def test_per_type_breakdown(self):
        rows = [
            {"question_id": "1", "question_type": "alpha", "retrieved": ["a"],
             "answer_session_ids": ["a"], "error": None},
            {"question_id": "2", "question_type": "beta", "retrieved": [],
             "answer_session_ids": ["b"], "error": None},
        ]
        out = score_rows(rows, ks=[10])
        self.assertEqual(1.0, out["by_type"]["alpha"]["hit_rate@10"])
        self.assertEqual(1.0, out["by_type"]["alpha"]["recall@10"])
        self.assertEqual(0.0, out["by_type"]["beta"]["hit_rate@10"])
        self.assertEqual(0.0, out["by_type"]["beta"]["recall@10"])

    def test_all_rows_errored_scores_nothing_rather_than_zero(self):
        rows = [{"question_id": "1", "question_type": "t", "retrieved": [],
                 "answer_session_ids": ["a"], "error": "boom"}]
        out = score_rows(rows, ks=[10])
        self.assertEqual(0, out["scored"])
        self.assertIsNone(out["hit_rate@10"])
        self.assertIsNone(out["recall@10"])


class LoadRunMetaTest(unittest.TestCase):
    """The companion file retrieve.py writes with the server's embedding
    configuration (I1) — evaluate.py must tolerate it being absent."""

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self._orig_results = config.RESULTS_DIR
        config.RESULTS_DIR = Path(self.tmp.name)

    def tearDown(self):
        config.RESULTS_DIR = self._orig_results
        self.tmp.cleanup()

    def test_missing_meta_file_is_none_not_an_error(self):
        self.assertIsNone(evaluate._load_run_meta("nope"))

    def test_reads_the_embedding_block_written_by_retrieve(self):
        embedding = {"driver": "openai", "dimensions": 1536,
                     "embedded_drawers": 5, "unembedded_drawers": 0}
        (config.RESULTS_DIR / "meta-keyless.json").write_text(
            json.dumps({"embedding": embedding})
        )
        self.assertEqual({"embedding": embedding}, evaluate._load_run_meta("keyless"))


class MainScoredZeroGuardTest(unittest.TestCase):
    """A run whose hits file has rows but nothing scoreable must not exit 0.

    Calls the real evaluate.main() — with sys.argv patched and a temp
    results dir — against a hits file where every row errored, so
    metrics['scored'] == 0 and the guard at the bottom of main() must trip.
    A test that instead re-derives "scored == 0" from its own rows list (as
    the previous version of this suite did for retrieve.py's guards) would
    keep passing even if main()'s guard were deleted outright.
    """

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self._orig_results = config.RESULTS_DIR
        config.RESULTS_DIR = Path(self.tmp.name)
        rows = [{"question_id": "1", "question_type": "t", "retrieved": [],
                 "answer_session_ids": ["a"], "error": "boom"}]
        (config.RESULTS_DIR / "hits-guardtest.jsonl").write_text(
            "\n".join(json.dumps(r) for r in rows) + "\n"
        )

    def tearDown(self):
        config.RESULTS_DIR = self._orig_results
        self.tmp.cleanup()

    def test_nothing_scored_is_a_failure_not_a_silent_pass(self):
        with patch.object(sys, "argv", ["evaluate.py", "--tag", "guardtest"]):
            code = evaluate.main()
        self.assertEqual(1, code)
        metrics = json.loads(
            (config.RESULTS_DIR / "metrics-guardtest.json").read_text()
        )
        self.assertEqual(0, metrics["scored"])

    def test_partial_scoring_does_not_trip_the_guard(self):
        rows = [
            {"question_id": "1", "question_type": "t", "retrieved": [],
             "answer_session_ids": ["a"], "error": "boom"},
            {"question_id": "2", "question_type": "t", "retrieved": ["a"],
             "answer_session_ids": ["a"], "error": None},
        ]
        (config.RESULTS_DIR / "hits-partial.jsonl").write_text(
            "\n".join(json.dumps(r) for r in rows) + "\n"
        )
        with patch.object(sys, "argv", ["evaluate.py", "--tag", "partial"]):
            code = evaluate.main()
        self.assertEqual(0, code)


if __name__ == "__main__":
    unittest.main()
