import unittest

from cleanup import build_tinker_expression


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

    def test_escapes_quotes_in_the_prefix(self):
        expr = build_tinker_expression("bench'mark")
        self.assertNotIn("'bench'mark", expr)
