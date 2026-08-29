import unittest

from report import render

KEYLESS = {"total": 25, "scored": 25, "errors": 0, "recall@10": 0.4, "mrr": 0.25,
           "by_type": {"alpha": {"n": 25, "recall@10": 0.4}}}
EMBEDDED = {"total": 25, "scored": 25, "errors": 0, "recall@10": 0.8, "mrr": 0.55,
            "by_type": {"alpha": {"n": 25, "recall@10": 0.8}}}


class RenderTest(unittest.TestCase):
    def test_both_tags_appear(self):
        out = render({"keyless": KEYLESS, "embedded": EMBEDDED}, ks=[10])
        self.assertIn("keyless", out)
        self.assertIn("embedded", out)

    def test_values_are_rendered(self):
        out = render({"keyless": KEYLESS, "embedded": EMBEDDED}, ks=[10])
        self.assertIn("0.400", out)
        self.assertIn("0.800", out)

    def test_renders_with_a_single_tag(self):
        out = render({"keyless": KEYLESS}, ks=[10])
        self.assertIn("keyless", out)

    def test_missing_metric_renders_as_na_not_zero(self):
        out = render({"keyless": dict(KEYLESS, **{"recall@10": None})}, ks=[10])
        self.assertIn("n/a", out)
        self.assertNotIn("0.000", out)

    def test_palace_only_caveat_is_always_present(self):
        out = render({"keyless": KEYLESS}, ks=[10])
        self.assertIn("palace", out.lower())
