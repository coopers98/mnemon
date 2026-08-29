import unittest

from evaluate import recall_at_k, reciprocal_rank, score_rows


class RecallTest(unittest.TestCase):
    def test_hit_within_k(self):
        self.assertTrue(recall_at_k(["a", "b", "c"], ["c"], k=3))

    def test_miss_outside_k(self):
        self.assertFalse(recall_at_k(["a", "b", "c"], ["c"], k=2))

    def test_any_gold_session_counts(self):
        self.assertTrue(recall_at_k(["x", "b"], ["a", "b"], k=5))

    def test_no_gold_sessions_is_not_a_hit(self):
        self.assertFalse(recall_at_k(["a"], [], k=5))


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
        self.assertEqual(1.0, out["recall@10"])

    def test_per_type_breakdown(self):
        rows = [
            {"question_id": "1", "question_type": "alpha", "retrieved": ["a"],
             "answer_session_ids": ["a"], "error": None},
            {"question_id": "2", "question_type": "beta", "retrieved": [],
             "answer_session_ids": ["b"], "error": None},
        ]
        out = score_rows(rows, ks=[10])
        self.assertEqual(1.0, out["by_type"]["alpha"]["recall@10"])
        self.assertEqual(0.0, out["by_type"]["beta"]["recall@10"])

    def test_all_rows_errored_scores_nothing_rather_than_zero(self):
        rows = [{"question_id": "1", "question_type": "t", "retrieved": [],
                 "answer_session_ids": ["a"], "error": "boom"}]
        out = score_rows(rows, ks=[10])
        self.assertEqual(0, out["scored"])
        self.assertIsNone(out["recall@10"])
