"""Ingestion correctness is measured here rather than against a server: the
drawer plan is pure, so its edge cases (truncation, metadata, ordering) are
cheap to pin down."""

import unittest

from ingest import plan_drawers

RECORD = {
    "question_id": "aaa",
    "haystack_session_ids": ["s1", "s2"],
    "haystack_sessions": [
        [{"role": "user", "content": "hello"}],
        [{"role": "assistant", "content": "x" * 100}],
    ],
    "haystack_dates": ["2023/05/20 (Sat) 02:21", "2023/05/21 (Sun) 03:00"],
}


class PlanDrawersTest(unittest.TestCase):
    def test_one_drawer_per_haystack_session(self):
        self.assertEqual(2, len(plan_drawers(RECORD, max_chars=10_000)))

    def test_wing_is_the_slug_never_the_colon_form(self):
        wing = plan_drawers(RECORD, max_chars=10_000)[0]["wing"]
        self.assertNotIn(":", wing)
        self.assertTrue(wing.startswith("benchmark-q"))

    def test_session_id_is_carried_in_source_and_metadata(self):
        first = plan_drawers(RECORD, max_chars=10_000)[0]
        self.assertEqual("s1", first["source"])
        self.assertEqual("s1", first["metadata"]["session_id"])

    def test_date_and_index_are_carried(self):
        second = plan_drawers(RECORD, max_chars=10_000)[1]
        self.assertEqual("2023/05/21 (Sun) 03:00", second["metadata"]["date"])
        self.assertEqual(1, second["metadata"]["index"])

    def test_oversized_session_is_truncated_and_flagged(self):
        plans = plan_drawers(RECORD, max_chars=50)
        big = plans[1]
        self.assertLessEqual(len(big["content"]), 50)
        self.assertTrue(big["metadata"]["truncated"])

    def test_small_session_is_not_flagged_as_truncated(self):
        self.assertFalse(plan_drawers(RECORD, max_chars=10_000)[0]["metadata"]["truncated"])

    def test_missing_dates_do_not_crash(self):
        record = dict(RECORD, haystack_dates=[])
        plans = plan_drawers(record, max_chars=10_000)
        self.assertIsNone(plans[0]["metadata"]["date"])
