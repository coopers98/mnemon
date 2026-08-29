import unittest

import config
from cleanup import build_tinker_expression, clear_state_for_prefix


class BuildTinkerExpressionTest(unittest.TestCase):
    def test_targets_only_the_benchmark_prefix(self):
        expr = build_tinker_expression("benchmark-q")
        self.assertIn("benchmark-q", expr)
        self.assertIn("where", expr.lower())

    def test_refuses_an_empty_prefix(self):
        # An empty prefix would match every wing and delete a real palace.
        with self.assertRaises(ValueError):
            build_tinker_expression("")

    def test_refuses_a_wildcard_prefix(self):
        with self.assertRaises(ValueError):
            build_tinker_expression("%")

    def test_refuses_a_prefix_containing_percent_anywhere(self):
        # "%q" becomes `LIKE '%q%'`, matching any slug containing "q".
        with self.assertRaises(ValueError):
            build_tinker_expression("%q")

    def test_refuses_a_lone_underscore_prefix(self):
        # "_" is a LIKE single-char wildcard: "_%" matches every non-empty
        # wing slug — exactly the one-character typo the docstring warns
        # about turning into "deletes a real palace".
        with self.assertRaises(ValueError):
            build_tinker_expression("_")

    def test_refuses_a_prefix_containing_underscore_anywhere(self):
        with self.assertRaises(ValueError):
            build_tinker_expression("benchmark_q")

    def test_escapes_quotes_in_the_prefix(self):
        expr = build_tinker_expression("bench'mark")
        self.assertNotIn("'bench'mark", expr)


class ClearStateForPrefixTest(unittest.TestCase):
    """State clearing must follow --prefix.

    Wiping every state file regardless of prefix is a data hazard, not untidiness:
    ingest.py has no server-side dedup key, so a question whose drawers still
    exist but whose state was discarded is fully re-sent next run, doubling its
    haystack. These fail if clear_state_for_prefix stops filtering.
    """

    def setUp(self):
        import tempfile, pathlib
        self.tmp = tempfile.TemporaryDirectory()
        self.orig = config.STATE_DIR
        config.STATE_DIR = pathlib.Path(self.tmp.name)
        d = config.STATE_DIR / "ingested"
        d.mkdir(parents=True)
        for qid in ("aaa", "bbb"):
            (d / f"{qid}.json").write_text("{}")
        (d / "cleanuptestzz.json").write_text("{}")

    def tearDown(self):
        config.STATE_DIR = self.orig
        self.tmp.cleanup()

    def _remaining(self):
        return sorted(p.stem for p in (config.STATE_DIR / "ingested").glob("*.json"))

    def test_a_narrow_prefix_leaves_unrelated_state_alone(self):
        # wing_slug("cleanuptestzz") -> benchmark-qcleanuptestzz
        cleared = clear_state_for_prefix("benchmark-qcleanuptest")
        self.assertEqual(1, cleared)
        self.assertEqual(["aaa", "bbb"], self._remaining())

    def test_the_default_prefix_clears_everything_it_owns(self):
        cleared = clear_state_for_prefix("benchmark-q")
        self.assertEqual(3, cleared)
        self.assertEqual([], self._remaining())

    def test_a_non_matching_prefix_clears_nothing(self):
        self.assertEqual(0, clear_state_for_prefix("benchmark-qnope"))
        self.assertEqual(3, len(self._remaining()))
