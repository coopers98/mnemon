"""The client is tested against fake HTTP responses. Nothing here calls the
real API — a test suite that costs money per run will stop being run."""

import unittest
from unittest.mock import Mock, patch

from qa_client import QaClient, QaError


def _response(status=200, payload=None, headers=None):
    r = Mock()
    r.status_code = status
    r.ok = 200 <= status < 300
    r.json.return_value = payload or {}
    r.headers = headers or {}
    r.text = "body"
    return r


OK = {
    "choices": [{"message": {"content": "Business Administration"}}],
    "usage": {"prompt_tokens": 100, "completion_tokens": 3, "total_tokens": 103},
}


class QaClientTest(unittest.TestCase):
    def setUp(self):
        self.client = QaClient(model="gpt-4o", api_key="test-key")

    def test_returns_text_and_usage(self):
        with patch.object(self.client._session, "post", return_value=_response(payload=OK)):
            text, usage = self.client.complete([{"role": "user", "content": "hi"}])
        self.assertEqual("Business Administration", text)
        self.assertEqual(103, usage["total_tokens"])

    def test_sends_temperature_zero(self):
        with patch.object(self.client._session, "post", return_value=_response(payload=OK)) as post:
            self.client.complete([{"role": "user", "content": "hi"}])
        self.assertEqual(0, post.call_args.kwargs["json"]["temperature"])

    def test_sends_the_configured_model(self):
        with patch.object(self.client._session, "post", return_value=_response(payload=OK)) as post:
            self.client.complete([{"role": "user", "content": "hi"}])
        self.assertEqual("gpt-4o", post.call_args.kwargs["json"]["model"])

    def test_auth_failure_is_not_retried(self):
        with patch.object(self.client._session, "post", return_value=_response(status=401)) as post:
            with self.assertRaises(QaError):
                self.client.complete([{"role": "user", "content": "hi"}])
        self.assertEqual(1, post.call_count, "a bad key never fixes itself")

    def test_rate_limit_is_retried_then_raises(self):
        with patch.object(
            self.client._session, "post",
            return_value=_response(status=429, headers={"Retry-After": "0"}),
        ) as post:
            with self.assertRaises(QaError):
                self.client.complete([{"role": "user", "content": "hi"}], retries=2)
        self.assertEqual(3, post.call_count)

    def test_a_transient_500_is_retried_and_can_succeed(self):
        with patch.object(
            self.client._session, "post",
            side_effect=[_response(status=500), _response(payload=OK)],
        ):
            text, _ = self.client.complete([{"role": "user", "content": "hi"}], retries=2)
        self.assertEqual("Business Administration", text)

    def test_a_malformed_payload_raises_rather_than_returning_empty(self):
        with patch.object(self.client._session, "post", return_value=_response(payload={})):
            with self.assertRaises(QaError):
                self.client.complete([{"role": "user", "content": "hi"}])

    def test_the_api_key_is_never_included_in_an_error_message(self):
        with patch.object(self.client._session, "post", return_value=_response(status=401)):
            try:
                self.client.complete([{"role": "user", "content": "hi"}])
            except QaError as exc:
                self.assertNotIn("test-key", str(exc))


class NoUsableContentTest(unittest.TestCase):
    """A 200 that carries no usable content is an error, not an empty answer.

    `content: null` is a real OpenAI shape — content-filter refusals and
    tool-call-only turns both produce it. Coercing it to "" hands the judge a
    blank answer to grade, so a refusal is scored as a wrong answer and quietly
    lowers the published number. These fail if `complete` goes back to
    `(text or "").strip()`.
    """

    def setUp(self):
        self.client = QaClient(model="gpt-4o", api_key="test-key")

    def _payload(self, content):
        return {"choices": [{"message": {"content": content}}], "usage": {"total_tokens": 1}}

    def test_null_content_raises_rather_than_returning_empty(self):
        with patch.object(self.client._session, "post", return_value=_response(payload=self._payload(None))):
            with self.assertRaises(QaError) as ctx:
                self.client.complete([{"role": "user", "content": "hi"}])
        self.assertIn("no usable content", str(ctx.exception))

    def test_non_string_content_raises_qa_error_not_attribute_error(self):
        # Must be QaError: callers catch that and record a per-question error.
        # An AttributeError escaping here would kill the whole run.
        with patch.object(self.client._session, "post", return_value=_response(payload=self._payload(42))):
            with self.assertRaises(QaError):
                self.client.complete([{"role": "user", "content": "hi"}])

    def test_an_empty_string_answer_is_still_allowed_through(self):
        # Distinct from null: the model genuinely replied with nothing. That is
        # a real (bad) answer and the judge should grade it, not an API failure.
        with patch.object(self.client._session, "post", return_value=_response(payload=self._payload("  "))):
            text, _ = self.client.complete([{"role": "user", "content": "hi"}])
        self.assertEqual("", text)


class ErrorBodyRedactionTest(unittest.TestCase):
    """The generic error path is the only one that embeds provider output.

    Fails if the body excerpt is interpolated without redaction.
    """

    def test_a_key_shaped_token_in_the_body_is_redacted(self):
        client = QaClient(model="gpt-4o", api_key="test-key")
        resp = _response(status=400)
        resp.text = "bad request near sk-proj-AAAAAAAAAAAAAAAAAAAA end"
        with patch.object(client._session, "post", return_value=resp):
            with self.assertRaises(QaError) as ctx:
                client.complete([{"role": "user", "content": "hi"}])
        msg = str(ctx.exception)
        self.assertIn("<redacted>", msg)
        self.assertNotIn("sk-proj-AAAAAAAAAAAAAAAAAAAA", msg)
