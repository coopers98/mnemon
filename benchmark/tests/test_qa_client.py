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
