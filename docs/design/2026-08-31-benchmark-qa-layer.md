# Benchmark QA Layer Implementation Plan

> **Deferred work, dated 2026-08-31.** This is a plan for work that has not
> been done, kept because the roadmap points at it. It describes an intended
> design, not the current system.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Measure LongMemEval-S QA accuracy — feed each question's retrieved sessions to a reader model, grade its answer against the gold answer with an LLM judge, and report accuracy split by whether retrieval actually found the evidence.

**Architecture:** Three stages over the existing `results/hits-{tag}.jsonl`: assemble a reader prompt from the retrieved sessions, get an answer, judge it. All stages are resumable and record token usage. The judge runs twice on every question so its own disagreement rate is measured rather than assumed.

**Tech Stack:** Python 3.12, stdlib + `requests` (already present). Raw HTTP to OpenAI's chat completions endpoint — the harness's standing constraint is no new runtime dependency, and it already speaks raw JSON-RPC the same way.

**Spec:** `docs/superpowers/specs/2026-08-29-benchmark-harness-design.md` (Layer 2)

## Global Constraints

- **No new runtime dependency.** `requests` only. Do not add the `openai` SDK.
- Reader and judge both run at `temperature=0`, and every result file records the model id, temperature, and K actually used. A number whose generating parameters are not recorded is not reproducible.
- **The judge is GPT-4o**, matching LongMemEval's published methodology. This is what makes the accuracy figure comparable to other systems' published numbers; changing it silently would break that comparison. `config.QA_MODEL` already defaults to `gpt-4o`.
- **Errors are never wrong answers.** An API failure, a rate limit, or a refusal is recorded as an error and excluded from accuracy denominators — the same rule the retrieval layer follows. A run where everything errored must exit non-zero, not report 0%.
- Resumable per question, like `ingest.py`. A re-run must not re-pay for work already done.
- stdlib `unittest` only; no pytest. Every test must name the production change that would make it fail.
- Do not modify PHP, or any of `config.py`, `mnemon_client.py`, `dataset.py`, `preflight.py`, `ingest.py`, `retrieve.py`, `evaluate.py`, `report.py`, `cleanup.py`. This layer is additive.
- `OPENAI_API_KEY` comes from the environment. Never log it, never write it to `results/`.

## Cost, measured not guessed

Reader context, measured from the real `results/hits-keyless.jsonl` against the dataset:

| K | mean chars/question | ≈ tokens/question | 25 questions | 500 questions |
|---|---|---|---|---|
| 5 | 68,094 | ~17,000 | ~0.43M | ~8.5M |
| 10 | 128,651 | ~32,000 | ~0.80M | ~16.1M |

At GPT-4o input pricing that is roughly **$21 (K=5)** or **$40 (K=10)** for a full 500-question run, plus a small judge cost. The 25-question subset is about **$1–2**.

**K defaults to 5** for that reason, and is a flag. This is a real trade-off, not a default to ignore: K=10 feeds the reader everything retrieval found, but retrieval's own recall@5 and recall@10 differ by only 0.02–0.06, so the second five sessions rarely add evidence and always add cost.

## File structure

| File | Responsibility |
|---|---|
| `benchmark/qa_prompt.py` | Assemble reader and judge prompts. Pure, no network. |
| `benchmark/qa_client.py` | Raw-HTTP chat completions with retry and usage capture |
| `benchmark/qa_run.py` | Reader stage over a hits file, resumable |
| `benchmark/qa_judge.py` | Judge stage, two independent verdicts per question |
| `benchmark/qa_evaluate.py` | Accuracy overall, by type, conditional on retrieval, judge agreement |
| `benchmark/tests/test_qa_*.py` | stdlib unittest coverage |

---

### Task 1: Prompt assembly

**Files:**
- Create: `benchmark/qa_prompt.py`
- Test: `benchmark/tests/test_qa_prompt.py`

**Interfaces:**
- Produces: `build_reader_prompt(question, sessions, question_date) -> list[dict]`, `build_judge_prompt(question, gold, answer) -> list[dict]`, `sessions_for_row(row, record, k) -> list[tuple[str, str]]`.

- [ ] **Step 1: Write the failing test**

Create `benchmark/tests/test_qa_prompt.py`:

```python
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
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd benchmark && python3 -m unittest tests.test_qa_prompt -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'qa_prompt'`.

- [ ] **Step 3: Implement**

Create `benchmark/qa_prompt.py`:

```python
"""Reader and judge prompts.

Kept pure and separate from the HTTP layer so the wording — which materially
moves the score — can be tested and reviewed without spending anything, and so
the exact prompts can be recorded alongside the results.
"""

from __future__ import annotations

from dataset import session_text

READER_SYSTEM = (
    "You answer questions using only the conversation excerpts provided. "
    "The excerpts are prior conversations between the user and an assistant, "
    "given in relevance order and labelled with the date each took place. "
    "Answer only from those excerpts. Do not use outside knowledge and do not "
    "guess. If the excerpts do not contain the answer, reply with exactly "
    "NOT FOUND."
)

JUDGE_SYSTEM = (
    "You grade a candidate answer against a reference answer. Reply with "
    "exactly one word: CORRECT if the candidate conveys the same fact as the "
    "reference, or INCORRECT if it does not. Differences in wording, "
    "formatting, extra detail, or verbosity do not matter — only whether the "
    "substantive fact matches. A candidate of NOT FOUND is INCORRECT unless "
    "the reference itself says the information is unavailable."
)


def sessions_for_row(row: dict, record: dict, k: int) -> list[tuple[str, str]]:
    """The top-k retrieved sessions as (session_id, rendered text), in rank order.

    Rank order is preserved deliberately: the reader is told the excerpts are
    ordered by relevance, and reordering them would change what is being
    measured.
    """
    by_id = dict(zip(record.get("haystack_session_ids", []), record.get("haystack_sessions", [])))
    dates = dict(zip(record.get("haystack_session_ids", []), record.get("haystack_dates", [])))

    out: list[tuple[str, str]] = []
    for sid in row.get("retrieved", [])[:k]:
        session = by_id.get(sid)
        if session is None:
            continue
        date = dates.get(sid)
        header = f"[{date}]" if date else "[date unknown]"
        out.append((sid, f"{header}\n{session_text(session)}"))
    return out


def build_reader_prompt(
    question: str, sessions: list[tuple[str, str]], question_date: str | None
) -> list[dict]:
    if sessions:
        body = "\n\n---\n\n".join(text for _, text in sessions)
        excerpts = f"Conversation excerpts, most relevant first:\n\n{body}"
    else:
        excerpts = "No conversation excerpts were retrieved."

    asked = f"\n\nThe question is being asked on {question_date}." if question_date else ""

    return [
        {"role": "system", "content": READER_SYSTEM},
        {"role": "user", "content": f"{excerpts}{asked}\n\nQuestion: {question}"},
    ]


def build_judge_prompt(question: str, gold: str, answer: str) -> list[dict]:
    return [
        {"role": "system", "content": JUDGE_SYSTEM},
        {
            "role": "user",
            "content": (
                f"Question: {question}\n\n"
                f"Reference answer: {gold}\n\n"
                f"Candidate answer: {answer}\n\n"
                "Reply with exactly CORRECT or INCORRECT."
            ),
        },
    ]
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `cd benchmark && python3 -m unittest tests.test_qa_prompt -v`
Expected: 11 tests, all PASS.

- [ ] **Step 5: Commit**

```bash
git add benchmark/qa_prompt.py benchmark/tests/test_qa_prompt.py
git commit -m "feat(benchmark): reader and judge prompt assembly

Kept pure and separate from the HTTP layer so the wording — which moves
the score — is testable and reviewable without spending anything."
```

---

### Task 2: The chat client

**Files:**
- Create: `benchmark/qa_client.py`
- Test: `benchmark/tests/test_qa_client.py`

**Interfaces:**
- Produces: `QaError`, `QaClient(model, api_key, timeout)` with `.complete(messages) -> tuple[str, dict]` returning the text and a usage dict.

- [ ] **Step 1: Write the failing test**

Create `benchmark/tests/test_qa_client.py`:

```python
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
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd benchmark && python3 -m unittest tests.test_qa_client -v`
Expected: FAIL — no module named `qa_client`.

- [ ] **Step 3: Implement**

Create `benchmark/qa_client.py`:

```python
"""Minimal chat-completions client.

Raw HTTP rather than the vendor SDK: the harness's standing constraint is no
new runtime dependency, and it already speaks raw JSON-RPC to Mnemon the same
way. Usage is returned with every call so a run can report what it cost
instead of estimating afterwards.
"""

from __future__ import annotations

import time
from typing import Any

import requests

ENDPOINT = "https://api.openai.com/v1/chat/completions"


class QaError(RuntimeError):
    """A failed completion. Callers record these as errors, never as answers."""


class QaClient:
    def __init__(self, model: str, api_key: str, timeout: int = 120):
        if not api_key:
            raise QaError("no API key — set OPENAI_API_KEY")
        self.model = model
        self.timeout = timeout
        self._session = requests.Session()
        self._session.headers.update(
            {"Authorization": f"Bearer {api_key}", "Content-Type": "application/json"}
        )

    def complete(self, messages: list[dict], retries: int = 3) -> tuple[str, dict]:
        body: dict[str, Any] = {
            "model": self.model,
            "messages": messages,
            # Pinned so a run is as reproducible as the provider allows. Even at
            # zero these models are not bit-deterministic, which is why the judge
            # stage measures its own disagreement rather than assuming none.
            "temperature": 0,
        }

        for attempt in range(retries + 1):
            try:
                resp = self._session.post(ENDPOINT, json=body, timeout=self.timeout)
            except requests.RequestException as exc:
                if attempt < retries:
                    time.sleep(1 + attempt * 2)
                    continue
                raise QaError(f"network error after {retries} retries: {exc}") from exc

            if resp.status_code in (401, 403):
                # Never interpolate the key into the message.
                raise QaError(f"HTTP {resp.status_code} — the API key is missing, invalid, or lacks access")

            if resp.status_code == 429:
                if attempt < retries:
                    time.sleep(float(resp.headers.get("Retry-After", 1 + attempt * 2)))
                    continue
                raise QaError(f"rate limited after {retries} retries")

            if resp.status_code >= 500:
                if attempt < retries:
                    time.sleep(1 + attempt * 2)
                    continue
                raise QaError(f"HTTP {resp.status_code} after {retries} retries")

            if not resp.ok:
                raise QaError(f"HTTP {resp.status_code}: {resp.text[:300]}")

            payload = resp.json()
            try:
                text = payload["choices"][0]["message"]["content"]
            except (KeyError, IndexError, TypeError) as exc:
                raise QaError(f"malformed response: {str(payload)[:300]}") from exc

            return (text or "").strip(), payload.get("usage", {})

        raise QaError("exhausted retries")
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `cd benchmark && python3 -m unittest tests.test_qa_client -v`
Expected: 8 tests, all PASS.

- [ ] **Step 5: Commit**

```bash
git add benchmark/qa_client.py benchmark/tests/test_qa_client.py
git commit -m "feat(benchmark): raw-HTTP chat client with usage capture

No vendor SDK — the harness adds no runtime dependency beyond requests.
Auth failures are not retried, rate limits honour Retry-After, and the
key never appears in an error message."
```

---

### Task 3: The reader stage

**Files:**
- Create: `benchmark/qa_run.py`
- Test: `benchmark/tests/test_qa_run.py`

**Interfaces:**
- Consumes: `results/hits-{tag}.jsonl`, `dataset.iter_records`, `qa_prompt`, `qa_client`.
- Produces: `results/answers-{tag}.jsonl` with `{question_id, question_type, answer, retrieval_hit, error, usage}`, and `answered(tag) -> set[str]` for resume.

`retrieval_hit` records whether any gold session was among the K sessions the
reader was actually shown. It is the field that makes the conditional
breakdown in Task 5 possible, and it must be computed from the sessions
supplied to the reader, not from the full retrieved list.

- [ ] **Step 1: Write the failing test**

Create `benchmark/tests/test_qa_run.py`:

```python
import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

import config
import qa_run


class FakeClient:
    def __init__(self, answers=None, raise_on=None):
        self.answers = answers or {}
        self.raise_on = raise_on or set()
        self.calls = 0

    def complete(self, messages, retries=3):
        self.calls += 1
        blob = " ".join(m["content"] for m in messages)
        for key in self.raise_on:
            if key in blob:
                from qa_client import QaError
                raise QaError("boom")
        for key, val in self.answers.items():
            if key in blob:
                return val, {"total_tokens": 10}
        return "unknown", {"total_tokens": 10}


RECORD = {
    "question_id": "q1",
    "question": "What degree?",
    "question_type": "single-session-user",
    "question_date": "2023/05/30",
    "answer": "Business Administration",
    "answer_session_ids": ["s1"],
    "haystack_session_ids": ["s1", "s2"],
    "haystack_sessions": [
        [{"role": "user", "content": "I studied business administration"}],
        [{"role": "user", "content": "noise"}],
    ],
    "haystack_dates": ["2023/05/20", "2023/05/21"],
}


class QaRunTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.orig = config.RESULTS_DIR
        config.RESULTS_DIR = Path(self.tmp.name)

    def tearDown(self):
        config.RESULTS_DIR = self.orig
        self.tmp.cleanup()

    def _row(self, retrieved):
        return {"question_id": "q1", "question_type": "single-session-user",
                "answer_session_ids": ["s1"], "retrieved": retrieved, "error": None}

    def test_records_the_answer(self):
        out = qa_run.answer_question(FakeClient({"business": "Business Administration"}),
                                     self._row(["s1"]), RECORD, k=5)
        self.assertEqual("Business Administration", out["answer"])
        self.assertIsNone(out["error"])

    def test_retrieval_hit_is_true_when_gold_was_shown(self):
        out = qa_run.answer_question(FakeClient(), self._row(["s1", "s2"]), RECORD, k=5)
        self.assertTrue(out["retrieval_hit"])

    def test_retrieval_hit_is_false_when_gold_was_not_shown(self):
        out = qa_run.answer_question(FakeClient(), self._row(["s2"]), RECORD, k=5)
        self.assertFalse(out["retrieval_hit"])

    def test_retrieval_hit_respects_k_not_the_full_retrieved_list(self):
        # Gold is retrieved at rank 2 but the reader only sees rank 1.
        out = qa_run.answer_question(FakeClient(), self._row(["s2", "s1"]), RECORD, k=1)
        self.assertFalse(out["retrieval_hit"], "hit must reflect what the reader was shown")

    def test_an_api_failure_is_an_error_not_an_answer(self):
        out = qa_run.answer_question(FakeClient(raise_on={"degree"}), self._row(["s1"]), RECORD, k=5)
        self.assertIsNone(out["answer"])
        self.assertIsNotNone(out["error"])

    def test_usage_is_recorded(self):
        out = qa_run.answer_question(FakeClient(), self._row(["s1"]), RECORD, k=5)
        self.assertEqual(10, out["usage"]["total_tokens"])

    def test_answered_reads_back_completed_question_ids(self):
        path = config.RESULTS_DIR / "answers-t.jsonl"
        path.write_text(json.dumps({"question_id": "q1", "error": None}) + "\n")
        self.assertEqual({"q1"}, qa_run.answered("t"))

    def test_answered_excludes_rows_that_errored_so_they_are_retried(self):
        path = config.RESULTS_DIR / "answers-t.jsonl"
        path.write_text(json.dumps({"question_id": "q1", "error": "boom"}) + "\n")
        self.assertEqual(set(), qa_run.answered("t"))
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd benchmark && python3 -m unittest tests.test_qa_run -v`
Expected: FAIL — no module named `qa_run`.

- [ ] **Step 3: Implement**

Create `benchmark/qa_run.py`. It must:

- Load `results/hits-{tag}.jsonl` and the matching dataset records via `dataset.iter_records`.
- Skip questions already answered (`answered(tag)`), appending to the file so a resumed run does not re-pay.
- For each question, call `qa_prompt.sessions_for_row(row, record, k)`, build the reader prompt, call the client, and write a row with `question_id`, `question_type`, `answer`, `retrieval_hit`, `error`, `usage`.
- Compute `retrieval_hit` as whether any `answer_session_ids` entry appears among the session ids actually supplied to the reader — not the full `retrieved` list.
- Skip rows whose retrieval `error` is set, recording them as errors here too rather than sending an empty context to the reader.
- Take `--tag` (required, no default), `--k` (default `5`), `--subset`/`--seed`, and `--limit`.
- Print a running token total, and at the end print total tokens and an estimated cost.
- Exit non-zero if every question errored, matching `retrieve.py`'s guard.

- [ ] **Step 4: Run the tests and watch them pass**

Run: `cd benchmark && python3 -m unittest tests.test_qa_run -v`
Expected: 8 tests, all PASS.

- [ ] **Step 5: Run it for real on two questions**

```bash
cd benchmark && OPENAI_API_KEY="$OPENAI_API_KEY" python3 qa_run.py --tag keyless --subset 2 --seed 1234 --k 5
```

Expected: two rows in `results/answers-keyless.jsonl`, a token total, and a cost estimate. Paste both answers into your report — a reader answer that is obviously wrong at this stage is a prompt bug, and it is far cheaper to find now than after 25.

- [ ] **Step 6: Prove the resume works**

Re-run the identical command and confirm it reports nothing to do and makes **zero** API calls (the token total must be 0). A resume that silently re-pays is the failure mode here.

- [ ] **Step 7: Commit**

---

### Task 4: The judge stage

**Files:**
- Create: `benchmark/qa_judge.py`
- Test: `benchmark/tests/test_qa_judge.py`

**Interfaces:**
- Consumes: `results/answers-{tag}.jsonl`.
- Produces: `results/verdicts-{tag}.jsonl` with `{question_id, verdict_a, verdict_b, agreed, error, usage}`.

**The judge runs twice per question, independently.** This is not redundancy for its own sake: the retrieval layer already found one place where nondeterminism was being read as signal (D19), and an LLM judge at `temperature=0` is still not deterministic. Running it twice turns "we assume the judge is stable" into a measured disagreement rate that ships with the number.

- [ ] **Step 1: Write the failing test**

Create `benchmark/tests/test_qa_judge.py` covering:

- `parse_verdict("CORRECT") is True`, `parse_verdict("INCORRECT") is False`.
- Case and surrounding whitespace or punctuation are tolerated (`"correct."` → True).
- An unparseable verdict (`"maybe"`) raises rather than defaulting to either value — silently defaulting would bias the score in whichever direction the default points.
- `INCORRECT` is not matched by a naive substring check for `CORRECT` — assert `parse_verdict("INCORRECT") is False`, which fails if the implementation uses `"CORRECT" in text`.
- A row where the two verdicts differ is recorded with `agreed = False` and both verdicts preserved.
- An errored answer row is not judged at all and is carried through as an error.

- [ ] **Step 2: Run it and watch it fail**

- [ ] **Step 3: Implement**

`parse_verdict` must check `INCORRECT` **before** `CORRECT`, since the latter is a substring of the former. Judge each answer twice with two independent calls, record both, and set `agreed`.

- [ ] **Step 4: Run the tests and watch them pass**

- [ ] **Step 5: Run it for real on the two answered questions**

Report both verdicts per question and whether they agreed.

- [ ] **Step 6: Commit**

---

### Task 5: Scoring

**Files:**
- Create: `benchmark/qa_evaluate.py`
- Test: `benchmark/tests/test_qa_evaluate.py`

**Interfaces:**
- Consumes: `results/answers-{tag}.jsonl`, `results/verdicts-{tag}.jsonl`.
- Produces: `results/qa-metrics-{tag}.json` and a printed summary.

Report:

- **QA accuracy** overall, errors excluded from the denominator.
- **By question type.**
- **Conditional on retrieval:** accuracy when `retrieval_hit` is true, and when false. This is the split the spec asks for and the reason the layer is worth building — a low score means something different depending on which side it falls on.
- **Judge disagreement rate**, and accuracy computed from `verdict_a` alone versus `verdict_b` alone, so a reader can see how much the headline moves with the judge's own noise.
- `None` rather than `0.0` when a denominator is empty, and a non-zero exit when nothing could be scored.

- [ ] **Step 1: Write the failing test**

Cover: errors excluded from every denominator; the conditional split computed correctly; disagreement counted; `None` not `0.0` for empty groups; a run with nothing scoreable exits non-zero.

- [ ] **Step 2–4: Red, implement, green**

- [ ] **Step 5: Commit**

---

### Task 6: Run the subset, integrate, document

- [ ] **Step 1: Run the full 25-question QA layer for both tags**

```bash
cd benchmark
python3 qa_run.py  --tag keyless  --subset 25 --seed 1234 --k 5
python3 qa_judge.py --tag keyless
python3 qa_evaluate.py --tag keyless
python3 qa_run.py  --tag embedded --subset 25 --seed 1234 --k 5
python3 qa_judge.py --tag embedded
python3 qa_evaluate.py --tag embedded
```

Report the actual token spend against this plan's estimate.

- [ ] **Step 2: Extend `report.py`'s output**

Add a QA section rendering both tags side by side: accuracy, the conditional split, judge disagreement, and the model and K used. Do not disturb the existing retrieval tables.

- [ ] **Step 3: Update `benchmark/README.md`**

Document the three new commands in sequence, the measured cost, the K trade-off, and — plainly — that the QA figure depends on a specific reader model, judge model, and prompt, all of which are recorded in the results files. State the judge disagreement rate as a property of the measurement.

- [ ] **Step 4: Update the roadmap**

Record the QA result, the spend, and whether the keyless/embedded pair separates on QA accuracy where it barely did on retrieval.

- [ ] **Step 5: Full suite**

Run: `cd benchmark && python3 -m unittest discover -s tests -t .`
Expected: all existing tests plus the new ones, green.

- [ ] **Step 6: Commit**

---

## Self-review

**Spec coverage.** Layer 2's two requirements — feed retrieved sessions to a reader, judge against gold — are Tasks 3 and 4. The spec's stated reason for the layer ("a poor QA score has two very different causes") is Task 5's conditional split, which is the deliverable that distinguishes them.

**Cost is measured, not guessed.** The K table comes from the real hits files against the real dataset, which is why K defaults to 5.

**Nondeterminism is measured, not assumed.** The judge runs twice and its disagreement rate ships with the number. This plan's predecessor produced eleven assertions that could not fail; the corresponding trap here is a judge whose noise is invisible because nobody looked.

**Type consistency.** `sessions_for_row` returns what `build_reader_prompt` consumes. `qa_run` writes the rows `qa_judge` reads; `qa_judge` writes the rows `qa_evaluate` reads. `retrieval_hit` is computed in Task 3 and consumed in Task 5.

**Known gap, stated rather than hidden.** `parse_verdict` raising on an unparseable judge reply means a persistently odd judge output becomes an error rather than a score. That is the intended direction — an error is visible, a silent default is not — but it does mean judge-prompt drift shows up as errors rather than as a wrong number.
