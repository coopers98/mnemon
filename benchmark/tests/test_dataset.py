"""The dataset is 265MB, so every access must stream. These tests use a tiny
inline fixture rather than the real file."""

import json
import tempfile
import unittest
from pathlib import Path

from dataset import iter_records, session_text, subset_ids

FIXTURE = [
    {
        "question_id": "aaa",
        "question_type": "single-session-user",
        "question": "What degree did I graduate with?",
        "answer": "Business Administration",
        "answer_session_ids": ["answer_1"],
        "haystack_session_ids": ["answer_1", "noise_1"],
        "haystack_sessions": [
            [{"role": "user", "content": "I graduated in business administration"}],
            [{"role": "user", "content": "unrelated chatter"}],
        ],
        "haystack_dates": ["2023/05/20 (Sat) 02:21", "2023/05/21 (Sun) 03:00"],
    },
    {
        "question_id": "bbb",
        "question_type": "temporal-reasoning",
        "question": "When did I move?",
        "answer": "June",
        "answer_session_ids": ["answer_2"],
        "haystack_session_ids": ["answer_2"],
        "haystack_sessions": [[{"role": "assistant", "content": "you moved in June"}]],
        "haystack_dates": ["2023/06/01 (Thu) 10:00"],
    },
]


class DatasetTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False)
        json.dump(FIXTURE, self.tmp)
        self.tmp.close()
        self.path = Path(self.tmp.name)

    def tearDown(self):
        self.path.unlink()
        for extra in getattr(self, "_extra_paths", []):
            extra.unlink(missing_ok=True)

    def _write_dataset(self, records):
        """Write a throwaway dataset file and clean it up in tearDown."""
        tmp = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False)
        json.dump(records, tmp)
        tmp.close()
        path = Path(tmp.name)
        self._extra_paths = getattr(self, "_extra_paths", []) + [path]
        return path

    def test_iterates_every_record(self):
        got = list(iter_records(self.path))
        self.assertEqual(["aaa", "bbb"], [r["question_id"] for r in got])

    def test_records_keep_their_nested_structure(self):
        first = next(iter_records(self.path))
        self.assertEqual(2, len(first["haystack_sessions"]))
        self.assertEqual("user", first["haystack_sessions"][0][0]["role"])

    def test_limit_stops_early(self):
        self.assertEqual(1, len(list(iter_records(self.path, limit=1))))

    def test_session_text_includes_role_and_content(self):
        text = session_text(FIXTURE[0]["haystack_sessions"][0])
        self.assertIn("user", text)
        self.assertIn("business administration", text)

    def test_subset_is_deterministic_for_a_seed(self):
        # A 2-record fixture sampling 1 would agree ~50% of the time even with a
        # broken RNG, so this builds a wider one. Determinism is what makes a
        # subset result quotable; a test that only catches half of its own
        # failures is not covering it.
        wide = self._write_dataset(
            [dict(FIXTURE[0], question_id=f"q{i:03d}") for i in range(40)]
        )
        a = subset_ids(wide, 8, seed=7)
        b = subset_ids(wide, 8, seed=7)
        self.assertEqual(a, b)
        self.assertEqual(8, len(a))
        self.assertEqual(8, len(set(a)), "sample must not repeat ids")

    def test_subset_varies_across_seeds(self):
        wide = self._write_dataset(
            [dict(FIXTURE[0], question_id=f"q{i:03d}") for i in range(40)]
        )
        seeds = [subset_ids(wide, 8, seed=s) for s in range(6)]
        self.assertGreater(
            len({tuple(s) for s in seeds}), 1, "every seed produced the same sample"
        )

    def test_subset_larger_than_dataset_returns_everything(self):
        self.assertEqual(2, len(subset_ids(self.path, 99, seed=1)))
