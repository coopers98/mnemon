# Benchmark harness — design

**Status:** approved 2026-08-29, not yet implemented.
**Roadmap piece:** 3 (Benchmark). Gates piece 4 (truth-up pass).

## Goal

Run LongMemEval-S against Mnemon's palace layer and publish a defensible
number — actually two numbers, one for the shipped keyless default and one
with OpenAI embeddings enabled.

## Why two numbers

`.env.docker.example` ships `MNEMON_EMBEDDING_DRIVER=none`, and
`PalaceSearchService` logs *"semantic search requires an embedding driver;
NullDriver is active — returning empty results."* So on the configuration a
stranger gets from `docker compose up`, hybrid retrieval has no semantic leg
at all — it is full-text plus temporal. D10 means Ollama cannot fill that gap
(768-dimension output into a `vector(1536)` column), so OpenAI is the only
working embedding path.

Publishing only the embedded number would describe a configuration the default
install does not provide. Publishing only the keyless number would understate
the design. The pair answers the question every reader actually has: what do I
get for free, and what does a key buy me?

## Scope

**In:** ingestion of LongMemEval-S into the palace, retrieval measurement,
LLM-judged QA measurement, reporting, and cleanup.

**Out:** the wiki layer. LongMemEval tests recall over conversational history,
which is what the palace stores. Any published number must say so explicitly —
it measures the palace only, and the wiki's synthesis is not exercised.

## Shape: ingest once, measure twice

Embeddings are computed at write time, so measuring both configurations
naively would ingest ~66M tokens twice. Instead:

1. Ingest the corpus once with `MNEMON_EMBEDDING_DRIVER=none`.
2. Measure. This is the keyless number.
3. Set the driver to `openai` and run `php artisan mnemon:reembed --model=drawer`.
4. Measure again. This is the embedded number.

Besides halving the ingest, this makes it a genuine A/B: the corpus, the wing
layout, the drawer boundaries and the question set are byte-identical between
runs, so the only variable that changed is the embedding.

`mnemon:reembed` re-embeds *all* drawers, not a selected wing. That is
acceptable because the benchmark runs against a dedicated stack whose only
content is benchmark data. It must not be run against an instance holding real
palace content.

## Data mapping

The dataset is a JSON array of 500 records. Verified structure:

| Field | Meaning |
|---|---|
| `question_id` | stable id, used for wing naming |
| `question_type` | category, carried into results for per-type breakdown |
| `question` | the query (longest of the 500 is 355 chars) |
| `question_date` | when the question is asked |
| `answer` | gold answer, for the QA judge |
| `answer_session_ids` | the sessions containing the evidence — ground truth for retrieval |
| `haystack_session_ids` | all session ids for this question |
| `haystack_sessions` | list of sessions; each is a list of `{role, content}` turns |
| `haystack_dates` | one date per haystack session |

Each question becomes an **isolated wing**: `benchmark:q{question_id}`, one
room `sessions`, and one drawer per haystack session. Drawer `source` is the
session id; `metadata` carries `session_id`, `date`, and `index`.

Per-question isolation is load-bearing. Searching within a single wing makes
retrieval mean "find the evidence among *this* question's ~50 sessions", which
is what LongMemEval measures. A shared wing would put the task in competition
with ~25,000 unrelated sessions and produce a number comparable to nothing.

## Metrics, in two layers

**Layer 1 — retrieval.** Search each question against its own wing; check
whether returned drawers' session ids intersect `answer_session_ids`. Report
recall@K and MRR, overall and per `question_type`. Free, deterministic, needs
no LLM.

**Layer 2 — QA accuracy.** Feed the top-K retrieved sessions and the question
to a model, then judge its answer against the gold answer with an LLM judge.
This is the figure comparable to published LongMemEval results, and the only
part that costs money.

Reporting both is deliberate. A poor QA score has two very different causes —
retrieval missed the evidence, or retrieval found it and the reader model
failed. A single headline number cannot distinguish them, and the distinction
is the one that tells us what to fix.

Build and validate layer 1 first; it exercises the entire pipeline end to end
without spend.

## Files

| File | Responsibility |
|---|---|
| `benchmark/ingest.py` | Stream the dataset, create wings/rooms/drawers, record state |
| `benchmark/retrieve.py` | Search each question, persist raw hits |
| `benchmark/evaluate.py` | Retrieval metrics; optional LLM answer + judge |
| `benchmark/report.py` | Render the results table, including the keyless/embedded pair |
| `benchmark/cleanup.py` | Remove benchmark wings (`config.py` already documents this file) |

`config.py`, `mnemon_client.py` and `setup.sh` exist and are reused unchanged.

## Resumability

~25,000 drawers over an HTTP MCP endpoint will be interrupted — by a timeout,
a rate limit, or an operator. Ingestion writes per-question completion state
under `benchmark/state/`, and a re-run skips completed questions rather than
duplicating their drawers. Duplicate drawers would silently inflate the
haystack and corrupt the measurement, so this is a correctness requirement,
not a convenience.

`benchmark/data/`, `results/` and `state/` are already gitignored.

## Configuration

Runs against the local Docker stack; `config.py` already defaults `MNEMON_URL`
to `http://localhost:8080/mcp`, which is what the shipped quickstart produces.
Auth is a personal access token with the `mcp:use` scope, minted with
`docker compose exec app php artisan tinker`. Personal access tokens carry no
wing restrictions, which is what the harness needs — it creates its own wings
and must read all of them back.

Tuning knobs already present in `config.py`: `INGEST_WORKERS`,
`SEARCH_WORKERS`, `MAX_DRAWER_CHARS` (30,000, to stay under the 8,192-token
embedding limit), `SEARCH_LIMIT`.

## Verified contracts

- `drawer_add` takes `wing`, `room`, `content` (all required), plus optional
  `source` and `metadata`. Wings and rooms are auto-created.
- `drawer_search` takes `query` (**max 500 characters**), optional `wing`, and
  `limit` (1–50, default 10). The longest question in the dataset is 355
  characters, so no truncation is needed — but the harness must assert this
  rather than assume it, because an over-length query returns a validation
  error that would score as a miss rather than fail visibly.
- Search results carry `id`, `content`, `wing`, `room`, `source`, `metadata`
  and `score`. Evidence matching uses `source`/`metadata.session_id`.
- `mnemon:reembed --model=drawer --batch=100` re-embeds using the current driver.

## Failure modes to guard

These are the ways this harness can report a confident, wrong number.

- **Slug mismatch.** `config.wing_slug()` reimplements Laravel's
  `Wing::slugify()` in Python. If they disagree, ingestion writes to one wing
  and retrieval searches another — which returns zero hits and scores 0% on
  everything, looking like a product failure rather than a harness bug.
  Assert agreement against a real server before any long run.
- **Silent truncation.** Sessions over `MAX_DRAWER_CHARS` are cut. Log the
  count; a run that truncated a third of its evidence is not measuring what it
  claims.
- **Duplicate ingestion.** See resumability above.
- **Scoring a denial as a miss.** A wing-restriction error, a 429, or a
  validation failure must be distinguishable from "retrieved nothing relevant".
  Failed searches are recorded as errors and excluded from the denominator,
  never counted as misses.
- **Empty-result success.** A run where every search returns nothing must fail
  loudly, not report 0%.

## Validation before any long run

On a seeded subset of 25 questions:

1. Slug agreement between `config.wing_slug()` and the server's actual wing slug.
2. A known evidence session is retrievable at K=10 for a question whose
   `answer_session_ids` is known.
3. Truncation count is zero or explained.
4. Re-running ingestion is a no-op (resumability actually resumes).

Only then decide, with real timing data, whether to run all 500.

## Open

Scale of the final published run. The subset validates the harness; the
decision to run the full 500 comes after, informed by measured throughput.
