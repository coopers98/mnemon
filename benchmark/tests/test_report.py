import unittest

from report import render

KEYLESS = {
    "total": 25, "scored": 25, "errors": 0,
    "hit_rate@10": 0.6, "recall@10": 0.4, "mrr": 0.25,
    "embedding": {"driver": "none", "dimensions": None},
    "by_type": {"alpha": {"n": 25, "hit_rate@10": 0.6, "recall@10": 0.4}},
}
EMBEDDED = {
    "total": 25, "scored": 25, "errors": 0,
    "hit_rate@10": 0.9, "recall@10": 0.8, "mrr": 0.55,
    "embedding": {"driver": "openai", "dimensions": 1536},
    "by_type": {"alpha": {"n": 25, "hit_rate@10": 0.9, "recall@10": 0.8}},
}


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


class HitRateAndRecallAreBothPublishedTest(unittest.TestCase):
    """C1: the report must not publish hit-rate under the name "recall", or
    drop either family — they answer different questions."""

    def test_hit_rate_and_recall_rows_both_appear_with_distinct_values(self):
        out = render({"keyless": KEYLESS, "embedded": EMBEDDED}, ks=[10])
        self.assertIn("| hit_rate@10 | 0.600 | 0.900 |", out)
        self.assertIn("| recall@10 | 0.400 | 0.800 |", out)

    def test_multiple_ks_render_both_families_for_each_k(self):
        keyless = dict(KEYLESS, **{"hit_rate@1": 0.5, "recall@1": 0.3})
        embedded = dict(EMBEDDED, **{"hit_rate@1": 0.7, "recall@1": 0.6})
        out = render({"keyless": keyless, "embedded": embedded}, ks=[1, 10])
        self.assertIn("| hit_rate@1 | 0.500 | 0.700 |", out)
        self.assertIn("| recall@1 | 0.300 | 0.600 |", out)
        self.assertIn("| hit_rate@10 | 0.600 | 0.900 |", out)
        self.assertIn("| recall@10 | 0.400 | 0.800 |", out)


class EmbeddingDriverRowTest(unittest.TestCase):
    """I1: the table must say which configuration each column actually
    measured, from brain_status() — not just repeat the operator's --tag."""

    def test_driver_is_printed_per_column(self):
        out = render({"keyless": KEYLESS, "embedded": EMBEDDED}, ks=[10])
        self.assertIn("| embedding driver | none | openai (1536d) |", out)

    def test_missing_embedding_info_renders_as_na(self):
        keyless_no_meta = dict(KEYLESS)
        keyless_no_meta.pop("embedding")
        out = render({"keyless": keyless_no_meta}, ks=[10])
        self.assertIn("| embedding driver | n/a |", out)

    def test_none_embedding_value_renders_as_na(self):
        # This is the shape evaluate.py actually writes when retrieve.py's
        # meta-{tag}.json companion file doesn't exist: {"embedding": None},
        # not a missing key.
        out = render({"keyless": dict(KEYLESS, embedding=None)}, ks=[10])
        self.assertIn("| embedding driver | n/a |", out)


class ByTypeTableTest(unittest.TestCase):
    """I4/I5: the by-type table must render at ks[0] (not ks[-1]) with the K
    named in the header, and give each tag its own `n`."""

    def test_renders_at_first_k_not_last_k(self):
        keyless = dict(KEYLESS, by_type={
            "alpha": {"n": 25, "hit_rate@1": 0.2, "recall@1": 0.1,
                      "hit_rate@10": 1.0, "recall@10": 1.0},
        })
        out = render({"keyless": keyless}, ks=[1, 10])
        self.assertIn("By question type — hit_rate@1", out)
        self.assertIn("By question type — recall@1", out)
        # The interesting, non-saturated k=1 figures must be the ones shown...
        self.assertIn("| alpha | 25 | 0.200 |", out)
        # ...not the saturated k=10 figures the old ks[-1] rendering used.
        self.assertNotIn("By question type — hit_rate@10", out)
        self.assertNotIn("| alpha | 25 | 1.000 |", out)

    def test_k_is_named_in_the_header_unambiguously(self):
        out = render({"keyless": KEYLESS}, ks=[10])
        self.assertIn("hit_rate@10", out)
        self.assertIn("recall@10", out)

    def test_each_tag_gets_its_own_n(self):
        # Excluding errors from the denominator (by design) means two tags
        # can legitimately score a different number of questions of the
        # same type — a single shared `n` column would misattribute one of
        # them.
        keyless = dict(KEYLESS, by_type={"alpha": {"n": 25, "hit_rate@10": 0.6, "recall@10": 0.4}})
        embedded = dict(EMBEDDED, by_type={"alpha": {"n": 24, "hit_rate@10": 0.9, "recall@10": 0.8}})
        out = render({"keyless": keyless, "embedded": embedded}, ks=[10])
        self.assertIn("| alpha | 25 | 24 | 0.600 | 0.900 |", out)


class ColumnAttributionTest(unittest.TestCase):
    """Ties each value to its column, not merely to the page.

    test_values_are_rendered only asserts 0.400 and 0.800 appear somewhere, so
    a pure column swap — headers in order, values reversed — passes it. That is
    the highest-stakes bug this file can have: it would publish the keyless
    figure as the embedded one, and nothing downstream would notice.

    Fails if `render` emits values in an order that does not follow `tags`.
    """

    def test_each_value_sits_under_its_own_tag(self):
        out = render({"keyless": KEYLESS, "embedded": EMBEDDED}, ks=[10])
        self.assertIn("| recall@10 | 0.400 | 0.800 |", out)

    def test_reversing_tags_reverses_values_with_the_headers(self):
        out = render({"embedded": EMBEDDED, "keyless": KEYLESS}, ks=[10])
        self.assertIn("| recall@10 | 0.800 | 0.400 |", out)

    def test_mrr_is_attributed_per_column_too(self):
        out = render({"keyless": KEYLESS, "embedded": EMBEDDED}, ks=[10])
        self.assertIn("| MRR | 0.250 | 0.550 |", out)


if __name__ == "__main__":
    unittest.main()
