import unittest

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
