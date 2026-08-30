# Benchmark Harness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run LongMemEval-S against Mnemon's palace layer and publish two numbers — one for the shipped keyless default, one with OpenAI embeddings.

**Architecture:** A small Python harness that speaks JSON-RPC over Mnemon's MCP endpoint. Each of the 500 questions becomes an isolated wing holding one drawer per haystack session. The corpus is ingested once with embeddings off, measured, re-embedded via `mnemon:reembed`, and measured again — so the only variable between the two runs is the embedding.

**Tech Stack:** Python 3.12 (stdlib + `requests`, already installed), the existing `benchmark/mnemon_client.py` and `benchmark/config.py`, and a local Docker Mnemon stack.

**Spec:** `docs/superpowers/specs/2026-08-29-benchmark-harness-design.md`

## Global Constraints

- **Never `json.load()` the dataset.** `benchmark/data/longmemeval_s_cleaned.json` is 265 MB; loading it whole costs multiple GB of RAM. All access goes through `dataset.iter_records()`.
- **Always pass `config.wing_slug(qid)` as the `wing` argument — never `wing_name()`.** `DrawerWriteService` does `firstOrCreate(['slug' => $wingSlug])`, using the argument **verbatim as the slug** with no slugification. Passing the colon form creates a wing whose slug contains a colon, which no later search or restriction pattern will match.
- `drawer_search`'s `query` is capped at **500 characters** server-side. The longest question in the dataset is 355. Assert this; never assume it.
- A failed search (denial, 429, validation error) is an **error**, never a retrieval miss. Errors are recorded separately and excluded from metric denominators.
- Ingestion must be resumable and idempotent. Duplicate drawers silently inflate the haystack and corrupt the measurement.
- Tests use stdlib `unittest`. Do not add a test dependency.
- Python files are formatted consistently with the existing two (`config.py`, `mnemon_client.py`): 4-space indent, double quotes, module docstrings that explain *why*.
- No new runtime dependency beyond `requests`.
- Do not modify any PHP. This piece is additive to `benchmark/` only.

## File structure

| File | Responsibility |
|---|---|
| `benchmark/dataset.py` | Stream records out of the 265 MB dataset without loading it |
| `benchmark/preflight.py` | Prove the harness can measure anything at all before a long run |
| `benchmark/ingest.py` | Create wings/rooms/drawers, resumably |
| `benchmark/retrieve.py` | Search each question, persist raw hits |
| `benchmark/evaluate.py` | Retrieval metrics, then optional LLM answer + judge |
| `benchmark/report.py` | Render the keyless/embedded pair |
| `benchmark/cleanup.py` | Remove benchmark wings |
| `benchmark/tests/test_*.py` | stdlib unittest coverage for the pure logic |
| `benchmark/README.md` | How to run the whole thing |

Existing and reused unchanged: `config.py`, `mnemon_client.py`, `setup.sh`.

---

### Task 1: Dataset streaming

**Files:**
- Create: `benchmark/dataset.py`
- Test: `benchmark/tests/test_dataset.py`

**Interfaces:**
- Produces: `iter_records(path: Path, limit: int | None = None) -> Iterator[dict]`, `subset_ids(path, n, seed) -> list[str]`, and `session_text(session: list[dict]) -> str`. Every later task consumes these.

- [ ] **Step 1: Write the failing test**

Create `benchmark/tests/test_dataset.py`:

```python
"""The dataset is 265MB, so every access must stream. These tests use a tiny
inline fixture rather than the real file."""

import json
import tempfile
import unittest
from pathlib import Path

from dataset import iter_records, session_text, subset_ids

FIXTURE = [
    {
        "question_id": "aaa",
        "question_type": "single-session-user",
        "question": "What degree did I graduate with?",
        "answer": "Business Administration",
        "answer_session_ids": ["answer_1"],
        "haystack_session_ids": ["answer_1", "noise_1"],
        "haystack_sessions": [
            [{"role": "user", "content": "I graduated in business administration"}],
            [{"role": "user", "content": "unrelated chatter"}],
        ],
        "haystack_dates": ["2023/05/20 (Sat) 02:21", "2023/05/21 (Sun) 03:00"],
    },
    {
        "question_id": "bbb",
        "question_type": "temporal-reasoning",
        "question": "When did I move?",
        "answer": "June",
        "answer_session_ids": ["answer_2"],
        "haystack_session_ids": ["answer_2"],
        "haystack_sessions": [[{"role": "assistant", "content": "you moved in June"}]],
        "haystack_dates": ["2023/06/01 (Thu) 10:00"],
    },
]


class DatasetTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False)
        json.dump(FIXTURE, self.tmp)
        self.tmp.close()
        self.path = Path(self.tmp.name)

    def tearDown(self):
        self.path.unlink()

    def test_iterates_every_record(self):
        got = list(iter_records(self.path))
        self.assertEqual(["aaa", "bbb"], [r["question_id"] for r in got])

    def test_records_keep_their_nested_structure(self):
        first = next(iter_records(self.path))
        self.assertEqual(2, len(first["haystack_sessions"]))
        self.assertEqual("user", first["haystack_sessions"][0][0]["role"])

    def test_limit_stops_early(self):
        self.assertEqual(1, len(list(iter_records(self.path, limit=1))))

    def test_session_text_includes_role_and_content(self):
        text = session_text(FIXTURE[0]["haystack_sessions"][0])
        self.assertIn("user", text)
        self.assertIn("business administration", text)

    def test_subset_is_deterministic_for_a_seed(self):
        a = subset_ids(self.path, 1, seed=7)
        b = subset_ids(self.path, 1, seed=7)
        self.assertEqual(a, b)
        self.assertEqual(1, len(a))

    def test_subset_larger_than_dataset_returns_everything(self):
        self.assertEqual(2, len(subset_ids(self.path, 99, seed=1)))
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd benchmark && python3 -m unittest discover -s tests -t . -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'dataset'`.

- [ ] **Step 3: Implement**

Create `benchmark/dataset.py`:

```python
"""Streaming access to the LongMemEval dataset.

The file is a 265MB JSON array. `json.load()` on it costs several GB of RAM,
so records are parsed one at a time by matching braces at depth 1. Every other
module in this harness goes through here rather than reading the file directly.
"""

from __future__ import annotations

import json
import random
from pathlib import Path
from typing import Iterator


def iter_records(path: Path, limit: int | None = None) -> Iterator[dict]:
    """Yield one question record at a time without holding the file in memory."""
    yielded = 0
    with open(path, encoding="utf-8") as fh:
        if fh.read(1) != "[":
            raise ValueError(f"{path} does not start with a JSON array")

        buf: list[str] = []
        depth = 0
        in_string = False
        escaped = False

        while True:
            ch = fh.read(1)
            if not ch:
                return

            if depth > 0:
                buf.append(ch)

            if escaped:
                escaped = False
                continue
            if in_string:
                if ch == "\\":
                    escaped = True
                elif ch == '"':
                    in_string = False
                continue
            if ch == '"':
                in_string = True
                continue

            if ch == "{":
                if depth == 0:
                    buf = ["{"]
                depth += 1
            elif ch == "}":
                depth -= 1
                if depth == 0:
                    yield json.loads("".join(buf))
                    yielded += 1
                    buf = []
                    if limit is not None and yielded >= limit:
                        return


def session_text(session: list[dict]) -> str:
    """Render one session's turns as the verbatim text stored in a drawer."""
    return "\n\n".join(
        f"{turn.get('role', 'unknown')}: {turn.get('content', '')}" for turn in session
    )


def subset_ids(path: Path, n: int, seed: int) -> list[str]:
    """A deterministic sample of question ids.

    Seeded so a subset run is reproducible and can be quoted alongside its
    number — an unseeded subset is not a result anyone can check.
    """
    ids = [r["question_id"] for r in iter_records(path)]
    if n >= len(ids):
        return ids
    return random.Random(seed).sample(ids, n)
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `cd benchmark && python3 -m unittest discover -s tests -t . -v`
Expected: 6 tests, all PASS.

- [ ] **Step 5: Confirm it streams the real 265MB file without exploding**

```bash
cd benchmark && /usr/bin/time -v python3 -c "
from pathlib import Path
from dataset import iter_records
n = sum(1 for _ in iter_records(Path('data/longmemeval_s_cleaned.json')))
print('records:', n)
" 2>&1 | grep -E "records:|Maximum resident"
```

Expected: `records: 500`, and maximum resident set size well under 500 MB. If peak memory approaches the file size, the streaming is not working and the rest of the harness will fall over on ingest — stop and fix it here.

- [ ] **Step 6: Commit**

```bash
git add benchmark/dataset.py benchmark/tests/test_dataset.py
git commit -m "feat(benchmark): streaming dataset reader

json.load on the 265MB dataset costs several GB. Records are parsed one
at a time by brace-matching at depth 1; verified 500 records with peak
RSS far below the file size."
```

---

### Task 2: Preflight checks

**Files:**
- Create: `benchmark/preflight.py`
- Test: `benchmark/tests/test_preflight.py`

**Interfaces:**
- Consumes: `dataset.iter_records`, `config.wing_slug`, `mnemon_client.MnemonClient`.
- Produces: `check_query_lengths(path, cap) -> tuple[int, str]` and `check_slug_agreement(client, probe_id) -> str`. `main()` exits non-zero if any check fails.

This task exists because of the failure mode most likely to produce a confident wrong number: if `config.wing_slug()` and the server disagree, ingestion writes to one wing and retrieval searches another, every question scores zero, and the output looks like a product failure rather than a harness bug.

- [ ] **Step 1: Write the failing test**

Create `benchmark/tests/test_preflight.py`:

```python
"""Preflight must fail loudly. A benchmark that cannot measure anything must
not look like a benchmark that measured zero."""

import json
import tempfile
import unittest
from pathlib import Path

from preflight import check_query_lengths, check_slug_agreement


class FakeClient:
    """Stands in for MnemonClient. `stored_wing` is what the server would
    report back for the wing we wrote to."""

    def __init__(self, stored_wing):
        self.stored_wing = stored_wing
        self.added = []

    def drawer_add(self, wing, room, content, source=None, metadata=None):
        self.added.append(wing)
        return {"id": 1}

    def drawer_search(self, query, wing=None, limit=10):
        return [{"id": 1, "wing": self.stored_wing, "source": "probe", "metadata": {}}]


class QueryLengthTest(unittest.TestCase):
    def _dataset(self, questions):
        tmp = tempfile.NamedTemporaryFile("w", suffix=".json", delete=False)
        json.dump(
            [
                {
                    "question_id": f"q{i}",
                    "question": q,
                    "question_type": "t",
                    "answer": "a",
                    "answer_session_ids": [],
                    "haystack_session_ids": [],
                    "haystack_sessions": [],
                    "haystack_dates": [],
                }
                for i, q in enumerate(questions)
            ],
            tmp,
        )
        tmp.close()
        return Path(tmp.name)

    def test_passes_when_every_question_fits(self):
        path = self._dataset(["short", "also short"])
        longest, offender = check_query_lengths(path, cap=500)
        self.assertEqual(10, longest)
        self.assertIsNone(offender)

    def test_reports_the_offending_question_when_one_is_too_long(self):
        path = self._dataset(["short", "x" * 501])
        longest, offender = check_query_lengths(path, cap=500)
        self.assertEqual(501, longest)
        self.assertIsNotNone(offender)


class SlugAgreementTest(unittest.TestCase):
    def test_passes_when_the_server_stores_the_slug_we_sent(self):
        sent = check_slug_agreement(FakeClient(stored_wing="benchmark-qprobe"), "probe")
        self.assertEqual("benchmark-qprobe", sent)

    def test_raises_when_the_server_stored_something_else(self):
        # This is the real trap: passing the colon form makes the server store
        # a slug with a colon, which no later search will match.
        with self.assertRaises(AssertionError) as ctx:
            check_slug_agreement(FakeClient(stored_wing="benchmark:qprobe"), "probe")
        self.assertIn("benchmark:qprobe", str(ctx.exception))
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd benchmark && python3 -m unittest tests.test_preflight -v`
Expected: FAIL — `ModuleNotFoundError: No module named 'preflight'`.

- [ ] **Step 3: Implement**

Create `benchmark/preflight.py`:

```python
"""Prove the harness can measure anything at all, before a multi-hour run.

Every check here guards a failure mode that would otherwise produce a
confident wrong number rather than an error.
"""

from __future__ import annotations

import sys
from pathlib import Path

import config
from dataset import iter_records
from mnemon_client import MnemonClient

QUERY_CAP = 500
PROBE_CONTENT = "mnemon benchmark preflight probe drawer"


def check_query_lengths(path: Path, cap: int = QUERY_CAP) -> tuple[int, str | None]:
    """Return the longest question length and the first question over `cap`.

    drawer_search rejects a query over 500 characters. That rejection would be
    recorded as "no results" — i.e. scored as a retrieval miss — so an
    over-length question would quietly depress the number instead of failing.
    """
    longest = 0
    offender = None
    for record in iter_records(path):
        q = record.get("question", "")
        if len(q) > longest:
            longest = len(q)
        if offender is None and len(q) > cap:
            offender = record.get("question_id")
    return longest, offender


def check_slug_agreement(client, probe_id: str = "preflight") -> str:
    """Write a probe drawer, read it back, and assert the wing slug round-trips.

    config.wing_slug() reimplements Laravel's Wing::slugify() in Python, and
    DrawerWriteService stores the `wing` argument verbatim as the slug. If the
    two ever diverge, ingestion and retrieval address different wings, every
    search returns nothing, and the run scores 0% while looking like a product
    failure.
    """
    slug = config.wing_slug(probe_id)
    client.drawer_add(
        wing=slug,
        room=config.ROOM_NAME,
        content=PROBE_CONTENT,
        source="preflight",
        metadata={"preflight": True},
    )
    results = client.drawer_search(query="preflight probe", wing=slug, limit=5)
    if not results:
        raise AssertionError(
            f"wrote a probe drawer to wing {slug!r} and searching that wing "
            "returned nothing — ingestion and retrieval are not addressing the "
            "same wing, so no measurement from this harness would be meaningful"
        )
    stored = results[0].get("wing")
    if stored != slug:
        raise AssertionError(
            f"sent wing {slug!r} but the server stored {stored!r}. "
            "DrawerWriteService uses the argument verbatim as the slug — pass "
            "config.wing_slug(...), never config.wing_name()"
        )
    return slug


def main() -> int:
    failures = []

    client = MnemonClient(config.MNEMON_URL, config.mnemon_token())

    tools = [t.get("name") for t in client.list_tools()]
    print(f"[preflight] server exposes {len(tools)} tools")
    for needed in ("drawer_add", "drawer_search", "brain_status"):
        if needed not in tools:
            failures.append(f"missing required tool: {needed}")

    if not config.DATASET_S.exists():
        failures.append(f"dataset missing at {config.DATASET_S} — run ./setup.sh")
    else:
        longest, offender = check_query_lengths(config.DATASET_S)
        print(f"[preflight] longest question: {longest} chars (cap {QUERY_CAP})")
        if offender:
            failures.append(
                f"question {offender} exceeds the {QUERY_CAP}-char query cap"
            )

    try:
        slug = check_slug_agreement(client)
        print(f"[preflight] slug agreement OK ({slug})")
    except AssertionError as exc:
        failures.append(str(exc))

    if failures:
        print("\n[preflight] FAILED:")
        for f in failures:
            print(f"  - {f}")
        return 1

    print("\n[preflight] all checks passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `cd benchmark && python3 -m unittest tests.test_preflight -v`
Expected: 4 tests, all PASS.

- [ ] **Step 5: Run preflight against a live stack**

Bring the stack up, mint a token, and run it:

```bash
cd /root/projects/mnemon
cp .env.docker.example .env.bench && export MNEMON_ENV_FILE=.env.bench
docker compose -p mnemon-bench --env-file .env.bench up -d --build
# wait for health, then mint a token:
docker compose -p mnemon-bench --env-file .env.bench exec -T app \
  php artisan tinker --execute='echo App\Models\User::first()->createToken("Benchmark", ["mcp:use"])->accessToken;' \
  | tail -1 > /tmp/.mnemon-token
cd benchmark && python3 preflight.py
```

Expected: all checks pass, exit 0. If slug agreement fails, stop — nothing downstream is worth building until it passes.

- [ ] **Step 6: Prove preflight can fail**

Temporarily change `check_slug_agreement`'s call to pass `config.wing_name(probe_id)` instead of `config.wing_slug(probe_id)`, re-run `python3 preflight.py`, and confirm it exits non-zero naming the colon-form slug. Revert the change and confirm `git diff --stat benchmark/preflight.py` is empty.

This is the point of the task. A preflight that cannot fail is worse than none, because it certifies the run.

- [ ] **Step 7: Commit**

```bash
git add benchmark/preflight.py benchmark/tests/test_preflight.py
git commit -m "feat(benchmark): preflight checks that can actually fail

Guards the failure mode most likely to produce a confident wrong number:
config.wing_slug() reimplements Wing::slugify() in Python, and
DrawerWriteService stores the wing argument verbatim as the slug. If they
diverge, ingest and retrieval address different wings and every question
scores zero while looking like a product failure.

Verified by inducing the divergence and watching preflight exit non-zero."
```

---

### Task 3: Resumable ingestion

**Files:**
- Create: `benchmark/ingest.py`
- Test: `benchmark/tests/test_ingest.py`

**Interfaces:**
- Consumes: `dataset.iter_records`, `dataset.session_text`, `config.wing_slug`, `MnemonClient.drawer_add`.
- Produces: `plan_drawers(record, max_chars) -> list[dict]` (pure, testable), `is_done(qid) -> bool`, `mark_done(qid, stats)`, and `main()` with `--limit`, `--seed`, `--subset`.

- [ ] **Step 1: Write the failing test**

Create `benchmark/tests/test_ingest.py`:

```python
"""Ingestion correctness is measured here rather than against a server: the
drawer plan is pure, so its edge cases (truncation, metadata, ordering) are
cheap to pin down."""

import unittest

from ingest import plan_drawers

RECORD = {
    "question_id": "aaa",
    "haystack_session_ids": ["s1", "s2"],
    "haystack_sessions": [
        [{"role": "user", "content": "hello"}],
        [{"role": "assistant", "content": "x" * 100}],
    ],
    "haystack_dates": ["2023/05/20 (Sat) 02:21", "2023/05/21 (Sun) 03:00"],
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
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd benchmark && python3 -m unittest tests.test_ingest -v`
Expected: FAIL — no module named `ingest`.

- [ ] **Step 3: Implement**

Create `benchmark/ingest.py`:

```python
"""Ingest LongMemEval haystacks into the palace, one isolated wing per question.

Resumability is a correctness requirement, not a convenience: ~25,000 drawers
over an HTTP endpoint will be interrupted, and a re-run that duplicated drawers
would silently inflate the haystack and corrupt every downstream number.
"""

from __future__ import annotations

import argparse
import json
import sys
from concurrent.futures import ThreadPoolExecutor, as_completed

import config
from dataset import iter_records, session_text, subset_ids
from mnemon_client import McpError, MnemonClient

STATE_SUBDIR = "ingested"


def _state_path(qid: str):
    d = config.STATE_DIR / STATE_SUBDIR
    d.mkdir(parents=True, exist_ok=True)
    return d / f"{qid}.json"


def is_done(qid: str) -> bool:
    return _state_path(qid).exists()


def mark_done(qid: str, stats: dict) -> None:
    _state_path(qid).write_text(json.dumps(stats))


def plan_drawers(record: dict, max_chars: int) -> list[dict]:
    """Turn one question record into the drawers it should produce.

    Pure by design so its edge cases are testable without a server.
    """
    qid = record["question_id"]
    wing = config.wing_slug(qid)
    sessions = record.get("haystack_sessions", [])
    ids = record.get("haystack_session_ids", [])
    dates = record.get("haystack_dates", [])

    plans = []
    for index, session in enumerate(sessions):
        text = session_text(session)
        truncated = len(text) > max_chars
        plans.append(
            {
                "wing": wing,
                "room": config.ROOM_NAME,
                "content": text[:max_chars],
                "source": ids[index] if index < len(ids) else f"session_{index}",
                "metadata": {
                    "session_id": ids[index] if index < len(ids) else None,
                    "date": dates[index] if index < len(dates) else None,
                    "index": index,
                    "truncated": truncated,
                },
            }
        )
    return plans


def ingest_question(client: MnemonClient, record: dict, max_chars: int) -> dict:
    plans = plan_drawers(record, max_chars)
    truncated = sum(1 for p in plans if p["metadata"]["truncated"])
    for plan in plans:
        client.drawer_add(**plan)
    return {"drawers": len(plans), "truncated": truncated}


def main() -> int:
    parser = argparse.ArgumentParser(description="Ingest LongMemEval into Mnemon.")
    parser.add_argument("--limit", type=int, default=None, help="first N questions")
    parser.add_argument("--subset", type=int, default=None, help="seeded sample of N")
    parser.add_argument("--seed", type=int, default=1234)
    args = parser.parse_args()

    client = MnemonClient(config.MNEMON_URL, config.mnemon_token())

    wanted = None
    if args.subset:
        wanted = set(subset_ids(config.DATASET_S, args.subset, args.seed))
        print(f"[ingest] seeded subset of {len(wanted)} questions (seed={args.seed})")

    records = []
    for record in iter_records(config.DATASET_S, limit=args.limit):
        if wanted is not None and record["question_id"] not in wanted:
            continue
        if is_done(record["question_id"]):
            continue
        records.append(record)

    print(f"[ingest] {len(records)} questions to ingest")
    if not records:
        print("[ingest] nothing to do — all requested questions already ingested")
        return 0

    total_drawers = 0
    total_truncated = 0
    failures = []

    with ThreadPoolExecutor(max_workers=config.INGEST_WORKERS) as pool:
        futures = {
            pool.submit(ingest_question, client, r, config.MAX_DRAWER_CHARS): r
            for r in records
        }
        for done, future in enumerate(as_completed(futures), 1):
            record = futures[future]
            qid = record["question_id"]
            try:
                stats = future.result()
            except McpError as exc:
                failures.append((qid, str(exc)))
                print(f"[ingest] {qid} FAILED: {exc}", file=sys.stderr)
                continue
            mark_done(qid, stats)
            total_drawers += stats["drawers"]
            total_truncated += stats["truncated"]
            if done % 10 == 0 or done == len(records):
                print(f"[ingest] {done}/{len(records)} questions, {total_drawers} drawers")

    print(f"[ingest] done: {total_drawers} drawers, {total_truncated} truncated")
    if total_truncated:
        pct = 100 * total_truncated / total_drawers
        print(
            f"[ingest] WARNING: {total_truncated} sessions ({pct:.1f}%) were truncated "
            f"at {config.MAX_DRAWER_CHARS} chars — evidence may have been cut"
        )
    if failures:
        print(f"[ingest] {len(failures)} questions failed; re-run to retry them")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `cd benchmark && python3 -m unittest tests.test_ingest -v`
Expected: 7 tests, all PASS.

- [ ] **Step 5: Ingest the validation subset**

```bash
cd benchmark && python3 ingest.py --subset 25 --seed 1234
```

Expected: 25 questions ingested, a drawer count printed, and the truncation warning either absent or explaining a small percentage.

- [ ] **Step 6: Prove resumability actually resumes**

Re-run the identical command:

```bash
cd benchmark && python3 ingest.py --subset 25 --seed 1234
```

Expected: `nothing to do — all requested questions already ingested`, exit 0, and **no new drawers**. Confirm the drawer count is unchanged:

```bash
cd /root/projects/mnemon && docker compose -p mnemon-bench --env-file .env.bench exec -T app \
  php artisan tinker --execute='echo App\Models\Drawer::count();'
```

Run this before and after the second ingest; the two numbers must be identical. A resumability check that does not compare drawer counts proves nothing.

- [ ] **Step 7: Commit**

```bash
git add benchmark/ingest.py benchmark/tests/test_ingest.py
git commit -m "feat(benchmark): resumable ingestion, one wing per question

Per-question wings keep retrieval competing against ~50 sessions rather
than 25,000, which is what LongMemEval measures. Resumability is a
correctness requirement: duplicate drawers would silently inflate the
haystack. Verified by re-running and comparing drawer counts."
```

---

### Task 4: Retrieval

**Files:**
- Create: `benchmark/retrieve.py`
- Test: `benchmark/tests/test_retrieve.py`

**Interfaces:**
- Consumes: `dataset.iter_records`, `config.wing_slug`, `MnemonClient.drawer_search`.
- Produces: `hit_session_ids(results) -> list[str]` (pure) and a JSONL file at `results/hits-{tag}.jsonl`, one object per question: `{question_id, question_type, answer_session_ids, retrieved, error}`.

The `--tag` argument is what separates the keyless run from the embedded one. It is required, with no default, so a second run cannot silently overwrite the first.

- [ ] **Step 1: Write the failing test**

Create `benchmark/tests/test_retrieve.py`:

```python
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
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd benchmark && python3 -m unittest tests.test_retrieve -v`
Expected: FAIL — no module named `retrieve`.

- [ ] **Step 3: Implement**

Create `benchmark/retrieve.py`:

```python
"""Search each question against its own wing and persist the raw hits.

Raw hits are written verbatim rather than scored here, so the same retrieval
run can be re-scored later without touching the server — and so the keyless
and embedded runs can be compared question by question.
"""

from __future__ import annotations

import argparse
import json
import sys
from concurrent.futures import ThreadPoolExecutor, as_completed

import config
from dataset import iter_records, subset_ids
from mnemon_client import McpError, MnemonClient


def hit_session_ids(results: list[dict]) -> list[str]:
    """Session ids of the retrieved drawers, in rank order.

    metadata.session_id is authoritative; `source` is the fallback because the
    server may substitute the OAuth client name when source is absent.
    """
    ids = []
    for result in results:
        metadata = result.get("metadata") or {}
        sid = metadata.get("session_id") or result.get("source")
        if sid:
            ids.append(sid)
    return ids


def search_question(client: MnemonClient, record: dict, limit: int) -> dict:
    qid = record["question_id"]
    row = {
        "question_id": qid,
        "question_type": record.get("question_type"),
        "answer_session_ids": record.get("answer_session_ids", []),
        "retrieved": [],
        "error": None,
    }
    try:
        results = client.drawer_search(
            query=record["question"], wing=config.wing_slug(qid), limit=limit
        )
        row["retrieved"] = hit_session_ids(results)
    except McpError as exc:
        # An error is not a miss. Scoring it as one would silently depress the
        # number and hide a broken run behind a plausible result.
        row["error"] = str(exc)
    return row


def main() -> int:
    parser = argparse.ArgumentParser(description="Retrieve for each question.")
    parser.add_argument(
        "--tag",
        required=True,
        help="label for this run, e.g. keyless or embedded (required so two "
        "runs cannot overwrite each other)",
    )
    parser.add_argument("--limit", type=int, default=config.SEARCH_LIMIT)
    parser.add_argument("--subset", type=int, default=None)
    parser.add_argument("--seed", type=int, default=1234)
    args = parser.parse_args()

    client = MnemonClient(config.MNEMON_URL, config.mnemon_token())

    wanted = None
    if args.subset:
        wanted = set(subset_ids(config.DATASET_S, args.subset, args.seed))

    records = [
        r
        for r in iter_records(config.DATASET_S)
        if wanted is None or r["question_id"] in wanted
    ]
    print(f"[retrieve] {len(records)} questions, limit={args.limit}, tag={args.tag}")

    rows = []
    with ThreadPoolExecutor(max_workers=config.SEARCH_WORKERS) as pool:
        futures = [
            pool.submit(search_question, client, r, args.limit) for r in records
        ]
        for done, future in enumerate(as_completed(futures), 1):
            rows.append(future.result())
            if done % 25 == 0 or done == len(records):
                print(f"[retrieve] {done}/{len(records)}")

    config.RESULTS_DIR.mkdir(parents=True, exist_ok=True)
    out = config.RESULTS_DIR / f"hits-{args.tag}.jsonl"
    with open(out, "w", encoding="utf-8") as fh:
        for row in sorted(rows, key=lambda r: r["question_id"]):
            fh.write(json.dumps(row) + "\n")

    errors = sum(1 for r in rows if r["error"])
    empty = sum(1 for r in rows if not r["error"] and not r["retrieved"])
    print(f"[retrieve] wrote {out} ({len(rows)} rows, {errors} errors, {empty} empty)")

    if errors:
        print(f"[retrieve] WARNING: {errors} searches errored and are excluded from scoring")
    if empty == len(rows) and rows:
        print(
            "[retrieve] FAILED: every search returned nothing. This is a broken "
            "run, not a 0% result — check preflight and that ingestion targeted "
            "the same wings.",
            file=sys.stderr,
        )
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `cd benchmark && python3 -m unittest tests.test_retrieve -v`
Expected: 5 tests, all PASS.

- [ ] **Step 5: Retrieve against the ingested subset**

```bash
cd benchmark && python3 retrieve.py --tag keyless --subset 25 --seed 1234
```

Expected: 25 rows written to `results/hits-keyless.jsonl`, 0 errors, and **not** 25 empty. Spot-check one row contains plausible session ids:

```bash
cd benchmark && head -1 results/hits-keyless.jsonl | python3 -m json.tool | head -20
```

- [ ] **Step 6: Commit**

```bash
git add benchmark/retrieve.py benchmark/tests/test_retrieve.py
git commit -m "feat(benchmark): retrieval run, raw hits persisted per tag

--tag is required so the keyless and embedded runs cannot overwrite each
other. Search errors are recorded as errors rather than scored as misses,
and a run where every search returns nothing exits non-zero instead of
reporting 0%."
```

---

### Task 5: Retrieval metrics

**Files:**
- Create: `benchmark/evaluate.py`
- Test: `benchmark/tests/test_evaluate.py`

**Interfaces:**
- Consumes: `results/hits-{tag}.jsonl`.
- Produces: `recall_at_k(retrieved, gold, k) -> bool`, `reciprocal_rank(retrieved, gold) -> float`, `score_rows(rows, ks) -> dict`, and `results/metrics-{tag}.json`.

- [ ] **Step 1: Write the failing test**

Create `benchmark/tests/test_evaluate.py`:

```python
import unittest

from evaluate import recall_at_k, reciprocal_rank, score_rows


class RecallTest(unittest.TestCase):
    def test_hit_within_k(self):
        self.assertTrue(recall_at_k(["a", "b", "c"], ["c"], k=3))

    def test_miss_outside_k(self):
        self.assertFalse(recall_at_k(["a", "b", "c"], ["c"], k=2))

    def test_any_gold_session_counts(self):
        self.assertTrue(recall_at_k(["x", "b"], ["a", "b"], k=5))

    def test_no_gold_sessions_is_not_a_hit(self):
        self.assertFalse(recall_at_k(["a"], [], k=5))


class ReciprocalRankTest(unittest.TestCase):
    def test_first_position_scores_one(self):
        self.assertEqual(1.0, reciprocal_rank(["a"], ["a"]))

    def test_third_position_scores_a_third(self):
        self.assertAlmostEqual(1 / 3, reciprocal_rank(["x", "y", "a"], ["a"]))

    def test_absent_scores_zero(self):
        self.assertEqual(0.0, reciprocal_rank(["x"], ["a"]))


class ScoreRowsTest(unittest.TestCase):
    def test_errored_rows_are_excluded_from_the_denominator(self):
        rows = [
            {"question_id": "1", "question_type": "t", "retrieved": ["a"],
             "answer_session_ids": ["a"], "error": None},
            {"question_id": "2", "question_type": "t", "retrieved": [],
             "answer_session_ids": ["b"], "error": "429 rate limited"},
        ]
        out = score_rows(rows, ks=[10])
        self.assertEqual(1, out["scored"])
        self.assertEqual(1, out["errors"])
        self.assertEqual(1.0, out["recall@10"])

    def test_per_type_breakdown(self):
        rows = [
            {"question_id": "1", "question_type": "alpha", "retrieved": ["a"],
             "answer_session_ids": ["a"], "error": None},
            {"question_id": "2", "question_type": "beta", "retrieved": [],
             "answer_session_ids": ["b"], "error": None},
        ]
        out = score_rows(rows, ks=[10])
        self.assertEqual(1.0, out["by_type"]["alpha"]["recall@10"])
        self.assertEqual(0.0, out["by_type"]["beta"]["recall@10"])

    def test_all_rows_errored_scores_nothing_rather_than_zero(self):
        rows = [{"question_id": "1", "question_type": "t", "retrieved": [],
                 "answer_session_ids": ["a"], "error": "boom"}]
        out = score_rows(rows, ks=[10])
        self.assertEqual(0, out["scored"])
        self.assertIsNone(out["recall@10"])
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd benchmark && python3 -m unittest tests.test_evaluate -v`
Expected: FAIL — no module named `evaluate`.

- [ ] **Step 3: Implement**

Create `benchmark/evaluate.py`:

```python
"""Score a retrieval run.

Kept separate from retrieve.py so a run can be re-scored at different K
without touching the server.
"""

from __future__ import annotations

import argparse
import json
import sys
from collections import defaultdict

import config


def recall_at_k(retrieved: list[str], gold: list[str], k: int) -> bool:
    """Did any gold evidence session appear in the top k?"""
    if not gold:
        return False
    return bool(set(retrieved[:k]) & set(gold))


def reciprocal_rank(retrieved: list[str], gold: list[str]) -> float:
    goldset = set(gold)
    for position, sid in enumerate(retrieved, 1):
        if sid in goldset:
            return 1.0 / position
    return 0.0


def score_rows(rows: list[dict], ks: list[int]) -> dict:
    """Aggregate metrics. Errored rows are excluded, never counted as misses."""
    scored = [r for r in rows if not r.get("error")]
    errors = len(rows) - len(scored)

    out: dict = {"total": len(rows), "scored": len(scored), "errors": errors}

    for k in ks:
        key = f"recall@{k}"
        if not scored:
            out[key] = None
            continue
        hits = sum(
            1 for r in scored
            if recall_at_k(r["retrieved"], r["answer_session_ids"], k)
        )
        out[key] = hits / len(scored)

    out["mrr"] = (
        sum(reciprocal_rank(r["retrieved"], r["answer_session_ids"]) for r in scored)
        / len(scored)
        if scored
        else None
    )

    by_type: dict = defaultdict(list)
    for r in scored:
        by_type[r.get("question_type") or "unknown"].append(r)

    out["by_type"] = {}
    for qtype, group in sorted(by_type.items()):
        entry = {"n": len(group)}
        for k in ks:
            hits = sum(
                1 for r in group
                if recall_at_k(r["retrieved"], r["answer_session_ids"], k)
            )
            entry[f"recall@{k}"] = hits / len(group)
        out["by_type"][qtype] = entry

    return out


def main() -> int:
    parser = argparse.ArgumentParser(description="Score a retrieval run.")
    parser.add_argument("--tag", required=True)
    parser.add_argument("--k", type=int, nargs="+", default=[1, 3, 5, 10])
    args = parser.parse_args()

    path = config.RESULTS_DIR / f"hits-{args.tag}.jsonl"
    if not path.exists():
        print(f"[evaluate] no such file: {path}", file=sys.stderr)
        return 1

    rows = [json.loads(line) for line in path.read_text().splitlines() if line.strip()]
    metrics = score_rows(rows, args.k)

    out = config.RESULTS_DIR / f"metrics-{args.tag}.json"
    out.write_text(json.dumps(metrics, indent=2))

    print(f"[evaluate] {args.tag}: {metrics['scored']}/{metrics['total']} scored, "
          f"{metrics['errors']} errors")
    def fmt(value):
        # Not an f-string with nested same-type quotes: that only parses on
        # Python 3.12+ (PEP 701), and this harness has no reason to require it.
        return "n/a" if value is None else f"{value:.3f}"

    for k in args.k:
        print(f"  recall@{k}: {fmt(metrics[f'recall@{k}'])}")
    print(f"  MRR: {fmt(metrics['mrr'])}")
    print(f"[evaluate] wrote {out}")

    if metrics["scored"] == 0:
        print("[evaluate] FAILED: nothing was scored", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `cd benchmark && python3 -m unittest tests.test_evaluate -v`
Expected: 10 tests, all PASS.

- [ ] **Step 5: Score the keyless subset run**

```bash
cd benchmark && python3 evaluate.py --tag keyless
```

Expected: recall figures printed and `results/metrics-keyless.json` written. Record the numbers in your report — this is the keyless half of the pair.

- [ ] **Step 6: Commit**

```bash
git add benchmark/evaluate.py benchmark/tests/test_evaluate.py
git commit -m "feat(benchmark): retrieval metrics with errors excluded

recall@k, MRR, and a per-question-type breakdown. Errored rows are
excluded from the denominator rather than scored as misses, and a run
where nothing could be scored exits non-zero instead of reporting 0."
```

---

### Task 6: The A/B run and the report

**Files:**
- Create: `benchmark/report.py`
- Test: `benchmark/tests/test_report.py`

**Interfaces:**
- Consumes: `results/metrics-{tag}.json` for two tags.
- Produces: `render(metrics_by_tag, ks) -> str` and a printed markdown table.

- [ ] **Step 1: Write the failing test**

Create `benchmark/tests/test_report.py`:

```python
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
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd benchmark && python3 -m unittest tests.test_report -v`
Expected: FAIL — no module named `report`.

- [ ] **Step 3: Implement**

Create `benchmark/report.py`:

```python
"""Render the keyless/embedded pair as a markdown table.

The palace-only caveat is emitted unconditionally: LongMemEval exercises
conversational recall, which is the palace. The wiki's synthesis is not
measured, and a number published without that sentence overclaims.
"""

from __future__ import annotations

import argparse
import json
import sys

import config

CAVEAT = (
    "Measured against Mnemon's **palace** layer only — LongMemEval tests recall "
    "over conversational history. The wiki's compiled synthesis is not exercised "
    "by this benchmark."
)


def _fmt(value) -> str:
    return "n/a" if value is None else f"{value:.3f}"


def render(metrics_by_tag: dict[str, dict], ks: list[int]) -> str:
    tags = list(metrics_by_tag)
    lines = ["## LongMemEval-S — retrieval", "", CAVEAT, ""]

    header = "| Metric | " + " | ".join(tags) + " |"
    lines.append(header)
    lines.append("|---" * (len(tags) + 1) + "|")

    for k in ks:
        key = f"recall@{k}"
        row = [_fmt(metrics_by_tag[t].get(key)) for t in tags]
        lines.append(f"| {key} | " + " | ".join(row) + " |")

    lines.append("| MRR | " + " | ".join(_fmt(metrics_by_tag[t].get("mrr")) for t in tags) + " |")
    lines.append("| questions scored | " + " | ".join(str(metrics_by_tag[t].get("scored")) for t in tags) + " |")
    lines.append("| errors | " + " | ".join(str(metrics_by_tag[t].get("errors")) for t in tags) + " |")

    types = sorted({t for m in metrics_by_tag.values() for t in m.get("by_type", {})})
    if types:
        lines += ["", "### By question type", "", "| Type | n | " + " | ".join(tags) + " |",
                  "|---" * (len(tags) + 2) + "|"]
        primary = ks[-1]
        for qtype in types:
            n = next(
                (m["by_type"][qtype]["n"] for m in metrics_by_tag.values()
                 if qtype in m.get("by_type", {})),
                0,
            )
            cells = [
                _fmt(metrics_by_tag[t].get("by_type", {}).get(qtype, {}).get(f"recall@{primary}"))
                for t in tags
            ]
            lines.append(f"| {qtype} | {n} | " + " | ".join(cells) + " |")

    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description="Render the benchmark report.")
    parser.add_argument("--tags", nargs="+", default=["keyless", "embedded"])
    parser.add_argument("--k", type=int, nargs="+", default=[1, 3, 5, 10])
    args = parser.parse_args()

    metrics = {}
    for tag in args.tags:
        path = config.RESULTS_DIR / f"metrics-{tag}.json"
        if not path.exists():
            print(f"[report] missing {path} — run evaluate.py --tag {tag}", file=sys.stderr)
            continue
        metrics[tag] = json.loads(path.read_text())

    if not metrics:
        print("[report] no metrics to render", file=sys.stderr)
        return 1

    text = render(metrics, args.k)
    print(text)
    out = config.RESULTS_DIR / "report.md"
    out.write_text(text + "\n")
    print(f"\n[report] wrote {out}", file=sys.stderr)
    return 0


if __name__ == "__main__":
    sys.exit(main())
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `cd benchmark && python3 -m unittest tests.test_report -v`
Expected: 5 tests, all PASS.

- [ ] **Step 5: Run the embedded half of the A/B**

Switch the driver and re-embed the **already-ingested** corpus, then retrieve and score again. Do not re-ingest — the whole point is that only the embedding changes.

```bash
cd /root/projects/mnemon
# OPENAI_API_KEY must be set in the environment for this half.
sed -i 's/^MNEMON_EMBEDDING_DRIVER=none/MNEMON_EMBEDDING_DRIVER=openai/' .env.bench
grep -q '^OPENAI_API_KEY=' .env.bench || echo "OPENAI_API_KEY=${OPENAI_API_KEY}" >> .env.bench
docker compose -p mnemon-bench --env-file .env.bench up -d
docker compose -p mnemon-bench --env-file .env.bench exec -T app \
  php artisan mnemon:reembed --model=drawer --batch=100
cd benchmark
python3 retrieve.py --tag embedded --subset 25 --seed 1234
python3 evaluate.py --tag embedded
python3 report.py --tags keyless embedded
```

Expected: a two-column table. Record both columns in your report.

If `recall@10` is identical between the two tags to three decimal places, treat that as a red flag and investigate before reporting it — it most likely means the re-embed did not take effect (check `MNEMON_EMBEDDING_DRIVER` inside the container with `docker compose -p mnemon-bench --env-file .env.bench exec -T app php artisan tinker --execute='echo config("mnemon.embedding.driver");'`), not that embeddings do not help.

- [ ] **Step 6: Commit**

```bash
git add benchmark/report.py benchmark/tests/test_report.py
git commit -m "feat(benchmark): render the keyless/embedded pair

Emits the palace-only caveat unconditionally — LongMemEval measures
conversational recall, and a number published without that sentence
overclaims what was tested."
```

---

### Task 7: Cleanup

**Files:**
- Create: `benchmark/cleanup.py`
- Test: `benchmark/tests/test_cleanup.py`

**Interfaces:**
- Produces: `build_tinker_expression(prefix) -> str` (pure) and a `main()` that shells out to `docker compose exec`.

There is no delete tool in the MCP surface — the 14 tools are read/write only — so cleanup must go through artisan. `config.py`'s `SSH_HOST`/`SSH_APP_PATH` were for the old deployed server; the harness now targets a local Docker stack, so cleanup uses `docker compose exec`.

- [ ] **Step 1: Write the failing test**

Create `benchmark/tests/test_cleanup.py`:

```python
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
```

- [ ] **Step 2: Run it and watch it fail**

Run: `cd benchmark && python3 -m unittest tests.test_cleanup -v`
Expected: FAIL — no module named `cleanup`.

- [ ] **Step 3: Implement**

Create `benchmark/cleanup.py`:

```python
"""Remove the benchmark wings.

No MCP tool deletes anything, so this goes through artisan in the container.
The prefix guard is deliberate: an empty or wildcard prefix would match every
wing, and this script is one typo away from deleting a real palace.
"""

from __future__ import annotations

import argparse
import shutil
import subprocess
import sys

import config

DEFAULT_PREFIX = "benchmark-q"


def build_tinker_expression(prefix: str) -> str:
    if not prefix or prefix.strip() in ("", "%", "*"):
        raise ValueError(
            f"refusing to delete with prefix {prefix!r} — that would match every wing"
        )
    safe = prefix.replace("\\", "\\\\").replace("'", "\\'")
    return (
        f"$w = App\\Models\\Wing::where('slug', 'like', '{safe}%')->get(); "
        "echo $w->count().' wings'; "
        "$w->each(fn($x) => $x->delete());"
    )


def main() -> int:
    parser = argparse.ArgumentParser(description="Delete benchmark wings.")
    parser.add_argument("--prefix", default=DEFAULT_PREFIX)
    parser.add_argument("--project", default="mnemon-bench")
    parser.add_argument("--env-file", default=".env.bench")
    parser.add_argument("--yes", action="store_true", help="skip the confirmation")
    args = parser.parse_args()

    expression = build_tinker_expression(args.prefix)

    if not args.yes:
        print(f"About to delete every wing whose slug starts with {args.prefix!r} "
              f"in compose project {args.project!r}.")
        if input("Type 'delete' to confirm: ").strip() != "delete":
            print("aborted")
            return 1

    if not shutil.which("docker"):
        print("[cleanup] docker not found", file=sys.stderr)
        return 1

    result = subprocess.run(
        ["docker", "compose", "-p", args.project, "--env-file", args.env_file,
         "exec", "-T", "app", "php", "artisan", "tinker", "--execute", expression],
        capture_output=True,
        text=True,
    )
    print(result.stdout.strip())
    if result.returncode != 0:
        print(result.stderr.strip(), file=sys.stderr)
        return result.returncode

    state_dir = config.STATE_DIR / "ingested"
    if state_dir.exists():
        for f in state_dir.glob("*.json"):
            f.unlink()
        print("[cleanup] cleared ingestion state")
    return 0


if __name__ == "__main__":
    sys.exit(main())
```

- [ ] **Step 4: Run the tests and watch them pass**

Run: `cd benchmark && python3 -m unittest tests.test_cleanup -v`
Expected: 4 tests, all PASS.

- [ ] **Step 5: Verify cleanup removes what it should and nothing else**

Count wings before, run cleanup, count after. A non-benchmark wing must survive:

```bash
cd /root/projects/mnemon
docker compose -p mnemon-bench --env-file .env.bench exec -T app php artisan tinker \
  --execute='App\Models\Wing::create(["name"=>"Keep Me","slug"=>"keep-me"]); echo App\Models\Wing::count();'
cd benchmark && python3 cleanup.py --yes
cd /root/projects/mnemon && docker compose -p mnemon-bench --env-file .env.bench exec -T app php artisan tinker \
  --execute='echo App\Models\Wing::count()." wings, keep-me exists: ".(App\Models\Wing::where("slug","keep-me")->exists() ? "yes" : "no");'
```

Expected: the benchmark wings are gone, `keep-me` still exists.

- [ ] **Step 6: Commit**

```bash
git add benchmark/cleanup.py benchmark/tests/test_cleanup.py
git commit -m "feat(benchmark): cleanup with a prefix guard

No MCP tool deletes, so this goes through artisan. An empty or wildcard
prefix is refused — the script is otherwise one typo away from deleting a
real palace. Verified a non-benchmark wing survives."
```

---

### Task 8: Documentation and the validated subset run

**Files:**
- Create: `benchmark/README.md`
- Modify: `docs/superpowers/specs/2026-08-28-release-roadmap.md`

- [ ] **Step 1: Write `benchmark/README.md`**

It must cover, in order: prerequisites (`./setup.sh`, a running stack, a minted token), the preflight step and why it is not optional, ingestion with `--subset`, the keyless run, the re-embed switch, the embedded run, the report, and cleanup. Include the exact commands from Tasks 2–7 as verified above.

State three things explicitly:

1. **This measures the palace layer only.** The wiki is not exercised.
2. **`mnemon:reembed` re-embeds every drawer, not just benchmark wings.** Run the benchmark against a dedicated stack, never one holding real palace content.
3. **The keyless number is the shipped default.** `MNEMON_EMBEDDING_DRIVER=none` means no semantic leg, and D10 means Ollama cannot substitute; OpenAI is the only working embedding path today.

- [ ] **Step 2: Run the complete pipeline end to end on the subset**

From a clean stack, run every step in README order and confirm each exits 0. Paste the final report table into your task report. This is the deliverable — a plan that produces a number nobody ran is not finished.

- [ ] **Step 3: Record measured throughput**

From the subset timings, extrapolate the wall-clock for all 500 questions and write it into the report. That figure is what the scale decision will be made on, so state the measurement it came from rather than a guess.

- [ ] **Step 4: Update the roadmap**

Mark piece 3 in progress, note that the harness is complete and validated on a seeded 25-question subset, and record the extrapolated full-run cost.

- [ ] **Step 5: Run every test once more**

Run: `cd benchmark && python3 -m unittest discover -s tests -t . -v`
Expected: all tests from Tasks 1–7 pass together.

- [ ] **Step 6: Commit**

```bash
git add benchmark/README.md docs/superpowers/specs/2026-08-28-release-roadmap.md
git commit -m "docs(benchmark): how to run the harness, and the subset result

Validated end to end on a seeded 25-question subset. Records the palace-only
scope, the reembed blast radius, and measured throughput for the full-run
decision."
```

---

## Self-review

**Spec coverage.** Every section of the design doc maps to a task: ingest-once-measure-twice (Task 6 Step 5), data mapping (Task 3), both metric layers (Task 5 for retrieval; QA deferred, see below), resumability (Task 3 Steps 5–6), the five failure modes (slug in Task 2, truncation in Task 3, duplicates in Task 3, denials-as-misses in Tasks 4–5, empty-result-success in Task 4 Step 3), and the validation checklist (Tasks 2, 3, 6).

**One deliberate deferral.** The spec's layer 2 (LLM answer + judge) is not a task here. Retrieval is the layer that validates the whole pipeline, costs nothing, and is prerequisite to interpreting any QA number — and the scale decision comes before it is worth spending on. QA gets its own plan once the subset run has produced real retrieval figures. This is called out rather than silently dropped.

**Type consistency.** `plan_drawers` returns dicts keyed exactly as `MnemonClient.drawer_add`'s parameters (`wing`, `room`, `content`, `source`, `metadata`), so `client.drawer_add(**plan)` is valid. `hit_session_ids` consumes what `drawer_search` returns (`metadata`, `source`). `score_rows` consumes the rows `retrieve.py` writes. `render` consumes what `score_rows` produces via `evaluate.py`.

**No placeholders.** Every step contains runnable code or an exact command with an expected result.
