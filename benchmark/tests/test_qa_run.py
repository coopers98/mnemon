import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import config
import qa_run


class FakeClient:
    def __init__(self, answers=None, raise_on=None):
        self.answers = answers or {}
        self.raise_on = raise_on or set()
        self.calls = 0

    def complete(self, messages, retries=3):
        self.calls += 1
        blob = " ".join(m["content"] for m in messages)
        for key in self.raise_on:
            if key in blob:
                from qa_client import QaError
                raise QaError("boom")
        for key, val in self.answers.items():
            if key in blob:
                return val, {"total_tokens": 10}
        return "unknown", {"total_tokens": 10}


RECORD = {
    "question_id": "q1",
    "question": "What degree?",
    "question_type": "single-session-user",
    "question_date": "2023/05/30",
    "answer": "Business Administration",
    "answer_session_ids": ["s1"],
    "haystack_session_ids": ["s1", "s2"],
    "haystack_sessions": [
        [{"role": "user", "content": "I studied business administration"}],
        [{"role": "user", "content": "noise"}],
    ],
    "haystack_dates": ["2023/05/20", "2023/05/21"],
}


class QaRunTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.orig = config.RESULTS_DIR
        config.RESULTS_DIR = Path(self.tmp.name)

    def tearDown(self):
        config.RESULTS_DIR = self.orig
        self.tmp.cleanup()

    def _row(self, retrieved):
        return {"question_id": "q1", "question_type": "single-session-user",
                "answer_session_ids": ["s1"], "retrieved": retrieved, "error": None}

    def test_records_the_answer(self):
        out = qa_run.answer_question(FakeClient({"business": "Business Administration"}),
                                     self._row(["s1"]), RECORD, k=5)
        self.assertEqual("Business Administration", out["answer"])
        self.assertIsNone(out["error"])

    def test_retrieval_hit_is_true_when_gold_was_shown(self):
        out = qa_run.answer_question(FakeClient(), self._row(["s1", "s2"]), RECORD, k=5)
        self.assertTrue(out["retrieval_hit"])

    def test_retrieval_hit_is_false_when_gold_was_not_shown(self):
        out = qa_run.answer_question(FakeClient(), self._row(["s2"]), RECORD, k=5)
        self.assertFalse(out["retrieval_hit"])

    def test_retrieval_hit_respects_k_not_the_full_retrieved_list(self):
        # Gold is retrieved at rank 2 but the reader only sees rank 1.
        out = qa_run.answer_question(FakeClient(), self._row(["s2", "s1"]), RECORD, k=1)
        self.assertFalse(out["retrieval_hit"], "hit must reflect what the reader was shown")

    def test_an_api_failure_is_an_error_not_an_answer(self):
        out = qa_run.answer_question(FakeClient(raise_on={"degree"}), self._row(["s1"]), RECORD, k=5)
        self.assertIsNone(out["answer"])
        self.assertIsNotNone(out["error"])

    def test_usage_is_recorded(self):
        out = qa_run.answer_question(FakeClient(), self._row(["s1"]), RECORD, k=5)
        self.assertEqual(10, out["usage"]["total_tokens"])

    def test_answered_reads_back_completed_question_ids(self):
        path = config.RESULTS_DIR / "answers-t.jsonl"
        path.write_text(json.dumps({"question_id": "q1", "error": None}) + "\n")
        self.assertEqual({"q1"}, qa_run.answered("t"))

    def test_answered_excludes_rows_that_errored_so_they_are_retried(self):
        path = config.RESULTS_DIR / "answers-t.jsonl"
        path.write_text(json.dumps({"question_id": "q1", "error": "boom"}) + "\n")
        self.assertEqual(set(), qa_run.answered("t"))

    # Regression: none of the 8 tests above ever set a retrieval `error` on the
    # row, so nothing above exercises the brief's Step 3 requirement to skip
    # such rows rather than sending the reader an empty context. Falling
    # through would have `sessions_for_row` return [] (no ids resolve),
    # `build_reader_prompt` render "No conversation excerpts were retrieved.",
    # and the reader confidently answer NOT FOUND -- recorded as a real,
    # scoreable answer instead of the retrieval failure it actually is.
    def test_retrieval_error_is_recorded_here_and_reader_is_never_called(self):
        client = FakeClient()
        row = self._row(["s1"])
        row["error"] = "search timed out"
        out = qa_run.answer_question(client, row, RECORD, k=5)
        self.assertIsNone(out["answer"])
        self.assertIsNotNone(out["error"])
        self.assertEqual(0, client.calls, "an empty context must never be sent to the reader")
