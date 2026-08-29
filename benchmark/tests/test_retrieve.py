import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import config
import retrieve
from mnemon_client import McpError
from retrieve import hit_session_ids


class HitSessionIdsTest(unittest.TestCase):
    def test_prefers_metadata_session_id(self):
        results = [{"metadata": {"session_id": "s1"}, "source": "ignored"}]
        self.assertEqual(["s1"], hit_session_ids(results))

    def test_falls_back_to_source_when_metadata_is_missing(self):
        self.assertEqual(["s2"], hit_session_ids([{"metadata": {}, "source": "s2"}]))

    def test_preserves_rank_order(self):
        results = [
            {"metadata": {"session_id": "a"}},
            {"metadata": {"session_id": "b"}},
        ]
        self.assertEqual(["a", "b"], hit_session_ids(results))

    def test_skips_results_with_no_identifiable_session(self):
        self.assertEqual(["a"], hit_session_ids([{"metadata": {"session_id": "a"}}, {}]))

    def test_handles_metadata_returned_as_none(self):
        self.assertEqual(["s3"], hit_session_ids([{"metadata": None, "source": "s3"}]))

    def test_parses_metadata_returned_as_a_json_string(self):
        # The live drawer_search endpoint serializes `metadata` as a JSON
        # string rather than a nested object. Dropping the json.loads() call
        # in hit_session_ids makes this raise AttributeError instead of
        # returning ["s4"].
        results = [{"metadata": '{"session_id": "s4"}', "source": "ignored"}]
        self.assertEqual(["s4"], hit_session_ids(results))

    def test_falls_back_to_source_when_metadata_is_unparseable_junk(self):
        # Malformed JSON must not crash the run — it should just fall through
        # to `source` like any other missing-metadata case.
        results = [{"metadata": "not json", "source": "s5"}]
        self.assertEqual(["s5"], hit_session_ids(results))


def _write_dataset(records: list[dict]) -> Path:
    tmp = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False)
    json.dump(records, tmp)
    tmp.close()
    return Path(tmp.name)


def _question(qid: str, qtype: str = "single-session-user") -> dict:
    return {
        "question_id": qid,
        "question": f"question about {qid}",
        "question_type": qtype,
        "answer": "irrelevant",
        "answer_session_ids": ["gold-1"],
        "haystack_session_ids": ["s1"],
        "haystack_sessions": [[{"role": "user", "content": "hello"}]],
        "haystack_dates": ["d1"],
    }


class AlwaysErrorsClient:
    """Every drawer_search call fails — simulates a total outage."""

    def __init__(self, *_args, **_kwargs):
        pass

    def drawer_search(self, query, wing=None, limit=10):
        raise McpError("synthetic failure")

    def brain_status(self):
        return {"embedding": {"driver": "none", "dimensions": None,
                               "embedded_drawers": 0, "unembedded_drawers": 0}}


class AlwaysEmptyClient:
    """Every drawer_search call succeeds but finds nothing."""

    def __init__(self, *_args, **_kwargs):
        pass

    def drawer_search(self, query, wing=None, limit=10):
        return []

    def brain_status(self):
        return {"embedding": {"driver": "none", "dimensions": None,
                               "embedded_drawers": 0, "unembedded_drawers": 0}}


class WingKeyedClient:
    """Fails only for questions whose wing slug contains `fail_marker` —
    lets a test control exactly which of several questions errors, without
    depending on thread-scheduling order the way a shared failure counter
    would."""

    def __init__(self, fail_marker: str, *_args, **_kwargs):
        self.fail_marker = fail_marker

    def drawer_search(self, query, wing=None, limit=10):
        if self.fail_marker in (wing or ""):
            raise McpError("synthetic failure")
        return [{"metadata": {"session_id": "gold-1"}}]

    def brain_status(self):
        return {"embedding": {"driver": "none", "dimensions": None,
                               "embedded_drawers": 0, "unembedded_drawers": 0}}


class SucceedingClient:
    """Every drawer_search call finds the gold session."""

    def __init__(self, *_args, **_kwargs):
        pass

    def drawer_search(self, query, wing=None, limit=10):
        return [{"metadata": {"session_id": "gold-1"}}]

    def brain_status(self):
        return {"embedding": {"driver": "openai", "dimensions": 1536,
                               "embedded_drawers": 2, "unembedded_drawers": 0}}


class MainGuardTestCase(unittest.TestCase):
    """Runs retrieve.main() end to end against a temp dataset and a temp
    results directory, with a fake MCP client standing in for the network.

    This exists because the previous version of this suite's guard tests
    never called main() at all — they re-derived the guard's boolean
    condition inline and asserted on their own arithmetic, which cannot
    fail no matter what main() actually does. Exercising the real entry
    point is the only way a deleted guard shows up as a red test.
    """

    TAG = "guardtest"

    def setUp(self):
        self.dataset_path = _write_dataset([_question("qa1"), _question("qa2")])
        self._results_tmp = tempfile.TemporaryDirectory()
        self._orig_dataset = config.DATASET_S
        self._orig_results = config.RESULTS_DIR
        config.DATASET_S = self.dataset_path
        config.RESULTS_DIR = Path(self._results_tmp.name)

    def tearDown(self):
        config.DATASET_S = self._orig_dataset
        config.RESULTS_DIR = self._orig_results
        self._results_tmp.cleanup()
        self.dataset_path.unlink(missing_ok=True)

    def _hits_path(self) -> Path:
        return config.RESULTS_DIR / f"hits-{self.TAG}.jsonl"

    def _run(self, client_cls, argv_extra=(), is_done=lambda qid: True):
        argv = ["retrieve.py", "--tag", self.TAG, *argv_extra]
        with patch.object(sys, "argv", argv), \
             patch("retrieve.MnemonClient", client_cls), \
             patch.object(config, "mnemon_token", return_value="test-token"), \
             patch("ingest.is_done", side_effect=is_done):
            return retrieve.main()


class AllErroredRunTest(MainGuardTestCase):
    """A run where every search failed must not exit 0.

    The empty-run guard cannot catch this case on its own, because it only
    counts rows that searched successfully and found nothing, and an
    all-errored run has none of those.
    """

    def test_all_errored_is_a_failure_not_a_zero_score(self):
        code = self._run(AlwaysErrorsClient)
        self.assertEqual(1, code)
        rows = [json.loads(l) for l in self._hits_path().read_text().splitlines()]
        self.assertEqual(2, len(rows))
        self.assertTrue(all(r["error"] for r in rows))


class EmptyRunGuardTest(MainGuardTestCase):
    """A run where every search succeeded but found nothing must not exit 0
    — that is a broken run (wrong wings, failed ingest), not a real 0%.
    """

    def test_all_empty_is_a_failure_not_a_perfect_pass(self):
        code = self._run(AlwaysEmptyClient)
        self.assertEqual(1, code)
        rows = [json.loads(l) for l in self._hits_path().read_text().splitlines()]
        self.assertEqual(2, len(rows))
        self.assertTrue(all(not r["error"] and not r["retrieved"] for r in rows))


class PartialFailureIsNotFatalTest(MainGuardTestCase):
    """Partial errors or partial emptiness must not trip either all-or-
    nothing guard — only a total failure should."""

    def test_one_errored_question_out_of_two_does_not_fail_the_run(self):
        # qa1's wing slug contains "qa1"; only that question's search fails.
        code = self._run(lambda *a, **kw: WingKeyedClient("qa1", *a, **kw))
        self.assertEqual(0, code)
        rows = [json.loads(l) for l in self._hits_path().read_text().splitlines()]
        errored = [r for r in rows if r["error"]]
        ok = [r for r in rows if not r["error"]]
        self.assertEqual(1, len(errored))
        self.assertEqual(1, len(ok))
        self.assertEqual(["gold-1"], ok[0]["retrieved"])


class IncompleteIngestGuardTest(MainGuardTestCase):
    """I2: searching a question whose ingest never finished must fail loudly
    and not write a results file, unless --allow-incomplete overrides it."""

    def test_refuses_to_search_when_a_question_is_incomplete(self):
        code = self._run(SucceedingClient, is_done=lambda qid: qid != "qa1")
        self.assertEqual(1, code)
        self.assertFalse(self._hits_path().exists())

    def test_allow_incomplete_flag_proceeds_anyway(self):
        code = self._run(
            SucceedingClient,
            argv_extra=["--allow-incomplete"],
            is_done=lambda qid: qid != "qa1",
        )
        self.assertEqual(0, code)
        self.assertTrue(self._hits_path().exists())


class RunMetaTest(MainGuardTestCase):
    """I1: retrieve.py must record the server's actual embedding
    configuration alongside the raw hits, regardless of what --tag says."""

    def test_writes_the_embedding_block_from_brain_status(self):
        code = self._run(SucceedingClient)
        self.assertEqual(0, code)
        meta = json.loads((config.RESULTS_DIR / f"meta-{self.TAG}.json").read_text())
        self.assertEqual("openai", meta["embedding"]["driver"])
        self.assertEqual(1536, meta["embedding"]["dimensions"])

    def test_a_failed_brain_status_call_does_not_fail_the_run(self):
        class BrainStatusFailsClient(SucceedingClient):
            def brain_status(self):
                raise McpError("server unreachable")

        code = self._run(BrainStatusFailsClient)
        self.assertEqual(0, code)
        meta = json.loads((config.RESULTS_DIR / f"meta-{self.TAG}.json").read_text())
        self.assertIsNone(meta["embedding"])


if __name__ == "__main__":
    unittest.main()
