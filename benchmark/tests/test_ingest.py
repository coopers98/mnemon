"""Ingestion correctness is measured here rather than against a server: the
drawer plan is pure, so its edge cases (truncation, metadata, ordering) are
cheap to pin down.

The state-tracking tests below exist because of a real incident: the
previous per-question scheme recorded completion only after every drawer in
a question landed, so any interruption left drawers with no state file and
a re-run silently resent the whole question (a reviewer measured a wing
going from 43 to 86 drawers this way). The fix tracks completion per
drawer — these tests pin down `done_sessions`, `is_done`'s legacy-file
handling, and `ingest_question`'s skip/resume behaviour so that regression
can't come back quietly.
"""

import json
import tempfile
import unittest
from pathlib import Path

import config
import ingest
from ingest import done_sessions, ingest_question, is_done, plan_drawers
from mnemon_client import McpError

RECORD = {
    "question_id": "aaa",
    "haystack_session_ids": ["s1", "s2"],
    "haystack_sessions": [
        [{"role": "user", "content": "hello"}],
        [{"role": "assistant", "content": "x" * 100}],
    ],
    "haystack_dates": ["2023/05/20 (Sat) 02:21", "2023/05/21 (Sun) 03:00"],
}

THREE_SESSION_RECORD = {
    "question_id": "bbb",
    "haystack_session_ids": ["s1", "s2", "s3"],
    "haystack_sessions": [
        [{"role": "user", "content": "one"}],
        [{"role": "user", "content": "two"}],
        [{"role": "user", "content": "three"}],
    ],
    "haystack_dates": ["d1", "d2", "d3"],
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


class FakeClient:
    """Stands in for MnemonClient. Records the `source` of every drawer_add
    call, in order, and can be told to raise on a given 1-indexed call."""

    def __init__(self, fail_on: int | None = None):
        self.calls: list[str] = []
        self.fail_on = fail_on

    def drawer_add(self, wing, room, content, source=None, metadata=None):
        self.calls.append(source)
        if self.fail_on is not None and len(self.calls) == self.fail_on:
            raise McpError("synthetic failure")
        return {"id": len(self.calls)}


class StateDirTestCase(unittest.TestCase):
    """Points config.STATE_DIR at a scratch directory for the duration of
    the test so these never touch the real benchmark/state/ingested/ used
    by live runs."""

    def setUp(self):
        self._tmpdir = tempfile.TemporaryDirectory()
        self._orig_state_dir = config.STATE_DIR
        config.STATE_DIR = Path(self._tmpdir.name)

    def tearDown(self):
        config.STATE_DIR = self._orig_state_dir
        self._tmpdir.cleanup()


class DoneSessionsTest(StateDirTestCase):
    def test_missing_state_file_has_no_done_sessions(self):
        # Breaks if done_sessions raised instead of treating a missing file
        # as "nothing written yet".
        self.assertEqual(set(), done_sessions("nope"))

    def test_legacy_state_file_has_no_done_sessions(self):
        # Breaks if done_sessions assumed the "done_sessions" key is always
        # present and raised KeyError on a pre-fix state file.
        ingest._state_path("legacy").write_text(
            json.dumps({"drawers": 43, "truncated": 0})
        )
        self.assertEqual(set(), done_sessions("legacy"))

    def test_legacy_state_file_counts_as_done(self):
        # Breaks if is_done required an explicit `"complete": True` instead
        # of defaulting a missing key to True — the 25 already-ingested
        # questions would silently be re-sent on the next run.
        ingest._state_path("legacy").write_text(
            json.dumps({"drawers": 43, "truncated": 0})
        )
        self.assertTrue(is_done("legacy"))

    def test_incomplete_state_file_is_not_done_and_reports_its_sessions(self):
        # Breaks if is_done reverted to a bare file-existence check (the
        # original bug) instead of consulting "complete".
        ingest._state_path("partial").write_text(
            json.dumps(
                {
                    "drawers": 2,
                    "truncated": 0,
                    "done_sessions": ["s1", "s2"],
                    "complete": False,
                }
            )
        )
        self.assertFalse(is_done("partial"))
        self.assertEqual({"s1", "s2"}, done_sessions("partial"))


class IngestQuestionSkipTest(StateDirTestCase):
    def test_skips_sessions_already_recorded(self):
        # Breaks if ingest_question dropped the done_sessions(qid) lookup
        # and resent every planned drawer regardless of prior state —
        # exactly the duplication bug this fix closes.
        ingest._state_path("aaa").write_text(
            json.dumps(
                {
                    "drawers": 1,
                    "truncated": 0,
                    "done_sessions": ["s1"],
                    "complete": False,
                }
            )
        )
        client = FakeClient()
        stats = ingest_question(client, RECORD, max_chars=10_000)
        self.assertEqual(["s2"], client.calls)
        self.assertEqual({"drawers": 2, "truncated": 0}, stats)
        self.assertTrue(is_done("aaa"))

    def test_mid_flight_failure_persists_progress_before_raising(self):
        # Breaks if the state write moved to after the whole loop (or back
        # to main()'s old post-question mark_done) instead of happening
        # right after each drawer_add returns.
        client = FakeClient(fail_on=2)
        with self.assertRaises(McpError):
            ingest_question(client, THREE_SESSION_RECORD, max_chars=10_000)
        self.assertEqual({"s1"}, done_sessions("bbb"))
        self.assertFalse(is_done("bbb"))

    def test_retry_after_failure_sends_only_the_remaining_sessions(self):
        # Breaks if the skip check used the wrong key (e.g. matched on
        # metadata["index"] instead of plan["source"]) so a retry either
        # resent everything or skipped a session it hadn't actually sent.
        failing_client = FakeClient(fail_on=2)
        with self.assertRaises(McpError):
            ingest_question(failing_client, THREE_SESSION_RECORD, max_chars=10_000)

        retry_client = FakeClient()
        stats = ingest_question(retry_client, THREE_SESSION_RECORD, max_chars=10_000)
        self.assertEqual(["s2", "s3"], retry_client.calls)
        self.assertEqual({"drawers": 3, "truncated": 0}, stats)
        self.assertTrue(is_done("bbb"))
        self.assertEqual({"s1", "s2", "s3"}, done_sessions("bbb"))
