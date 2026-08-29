"""Preflight must fail loudly. A benchmark that cannot measure anything must
not look like a benchmark that measured zero."""

import json
import tempfile
import unittest
from pathlib import Path

from preflight import check_query_lengths, check_slug_agreement


class FakeClient:
    """Stands in for MnemonClient. `stored_wing` is what the server would
    report back for the wing we wrote to."""

    def __init__(self, stored_wing):
        self.stored_wing = stored_wing
        self.added = []

    def drawer_add(self, wing, room, content, source=None, metadata=None):
        self.added.append(wing)
        return {"id": 1}

    def drawer_search(self, query, wing=None, limit=10):
        return [{"id": 1, "wing": self.stored_wing, "source": "probe", "metadata": {}}]


class QueryLengthTest(unittest.TestCase):
    def _dataset(self, questions):
        tmp = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False)
        json.dump(
            [
                {
                    "question_id": f"q{i}",
                    "question": q,
                    "question_type": "t",
                    "answer": "a",
                    "answer_session_ids": [],
                    "haystack_session_ids": [],
                    "haystack_sessions": [],
                    "haystack_dates": [],
                }
                for i, q in enumerate(questions)
            ],
            tmp,
        )
        tmp.close()
        return Path(tmp.name)

    def test_passes_when_every_question_fits(self):
        path = self._dataset(["short", "also short"])
        longest, offender = check_query_lengths(path, cap=500)
        self.assertEqual(10, longest)
        self.assertIsNone(offender)

    def test_reports_the_offending_question_when_one_is_too_long(self):
        path = self._dataset(["short", "x" * 501])
        longest, offender = check_query_lengths(path, cap=500)
        self.assertEqual(501, longest)
        self.assertIsNotNone(offender)


class SlugAgreementTest(unittest.TestCase):
    def test_passes_when_the_server_stores_the_slug_we_sent(self):
        sent = check_slug_agreement(FakeClient(stored_wing="benchmark-qprobe"), "probe")
        self.assertEqual("benchmark-qprobe", sent)

    def test_raises_when_the_server_stored_something_else(self):
        # This is the real trap: passing the colon form makes the server store
        # a slug with a colon, which no later search will match.
        with self.assertRaises(AssertionError) as ctx:
            check_slug_agreement(FakeClient(stored_wing="benchmark:qprobe"), "probe")
        self.assertIn("benchmark:qprobe", str(ctx.exception))
