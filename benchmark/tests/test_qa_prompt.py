"""Prompt assembly is pure, so its edge cases are cheap to pin down here rather
than discovering them against a paid API."""

import unittest

from qa_prompt import build_judge_prompt, build_reader_prompt, sessions_for_row

RECORD = {
    "question_id": "q1",
    "haystack_session_ids": ["s1", "s2", "s3"],
    "haystack_sessions": [
        [{"role": "user", "content": "I graduated in business administration"}],
        [{"role": "user", "content": "unrelated chatter"}],
        [{"role": "assistant", "content": "noted"}],
    ],
    "haystack_dates": ["2023/05/20 (Sat) 02:21", "2023/05/21 (Sun) 03:00", "2023/05/22"],
}
ROW = {"question_id": "q1", "retrieved": ["s3", "s1", "s2"]}

# The real LongMemEval dataset (data/longmemeval_s_cleaned.json) has 13/500
# records where the same session id appears twice in haystack_session_ids
# with identical content but different haystack_dates — the haystack padded
# with a repeated distractor session at a second point in time.
# ingest.py's plan_drawers/ingest_question walk the lists by index and skip
# `drawer_add` for a source id already sent (`if plan["source"] in done:
# continue`), so only the FIRST occurrence's date is ever actually ingested
# and retrievable. A lookup dict built as `dict(zip(ids, dates))` keeps the
# LAST occurrence for a repeated key instead, so it would hand the reader a
# date that was never on the ingested drawer.
RECORD_DUP_ID = {
    "question_id": "q2",
    "haystack_session_ids": ["dup", "dup", "other"],
    "haystack_sessions": [
        [{"role": "user", "content": "same distractor text"}],
        [{"role": "user", "content": "same distractor text"}],
        [{"role": "user", "content": "other text"}],
    ],
    "haystack_dates": ["2023/01/01", "2023/06/15", "2023/02/02"],
}


class SessionsForRowTest(unittest.TestCase):
    def test_returns_sessions_in_retrieved_rank_order(self):
        got = sessions_for_row(ROW, RECORD, k=3)
        self.assertEqual(["s3", "s1", "s2"], [sid for sid, _ in got])

    def test_k_truncates_from_the_end_of_the_ranking(self):
        self.assertEqual(["s3", "s1"], [sid for sid, _ in sessions_for_row(ROW, RECORD, k=2)])

    def test_text_comes_from_the_matching_session(self):
        got = dict(sessions_for_row(ROW, RECORD, k=3))
        self.assertIn("business administration", got["s1"])

    def test_a_retrieved_id_absent_from_the_record_is_skipped(self):
        row = {"question_id": "q1", "retrieved": ["ghost", "s1"]}
        self.assertEqual(["s1"], [sid for sid, _ in sessions_for_row(row, RECORD, k=5)])

    def test_no_retrieved_sessions_yields_nothing(self):
        self.assertEqual([], sessions_for_row({"question_id": "q1", "retrieved": []}, RECORD, k=5))

    def test_duplicate_session_id_in_the_record_uses_the_first_occurrences_date(self):
        # Would fail if the id->date lookup were a plain `dict(zip(ids,
        # dates))`, since that keeps the *last* duplicate's date
        # ("2023/06/15") instead of the first ("2023/01/01") — the one
        # ingest.py actually sent to the server.
        row = {"question_id": "q2", "retrieved": ["dup"]}
        got = dict(sessions_for_row(row, RECORD_DUP_ID, k=5))
        self.assertIn("2023/01/01", got["dup"])
        self.assertNotIn("2023/06/15", got["dup"])


class ReaderPromptTest(unittest.TestCase):
    def test_question_and_session_text_both_appear(self):
        msgs = build_reader_prompt("What degree?", sessions_for_row(ROW, RECORD, k=3), "2023/05/30")
        blob = " ".join(m["content"] for m in msgs)
        self.assertIn("What degree?", blob)
        self.assertIn("business administration", blob)

    def test_the_question_date_is_supplied(self):
        msgs = build_reader_prompt("When?", sessions_for_row(ROW, RECORD, k=1), "2023/05/30")
        self.assertIn("2023/05/30", " ".join(m["content"] for m in msgs))

    def test_session_dates_are_supplied(self):
        # Temporal-reasoning questions are unanswerable without them.
        msgs = build_reader_prompt("When?", sessions_for_row(ROW, RECORD, k=3), "2023/05/30")
        self.assertIn("2023/05/20", " ".join(m["content"] for m in msgs))

    def test_reader_is_told_to_say_when_the_answer_is_absent(self):
        msgs = build_reader_prompt("q", [], "2023/05/30")
        self.assertIn("NOT FOUND", " ".join(m["content"] for m in msgs))


class JudgePromptTest(unittest.TestCase):
    def test_carries_question_gold_and_answer(self):
        msgs = build_judge_prompt("What degree?", "Business Administration", "business admin")
        blob = " ".join(m["content"] for m in msgs)
        self.assertIn("What degree?", blob)
        self.assertIn("Business Administration", blob)
        self.assertIn("business admin", blob)

    def test_asks_for_a_single_token_verdict(self):
        msgs = build_judge_prompt("q", "g", "a")
        self.assertIn("CORRECT", " ".join(m["content"] for m in msgs))
