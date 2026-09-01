# Mnemon benchmark harness

A [LongMemEval-S](https://huggingface.co/datasets/xiaowu0162/longmemeval-cleaned)
benchmark for Mnemon's palace layer, in two layers of its own. Retrieval
(§1–6): it ingests conversational sessions as drawers, one wing per
question, then measures whether `drawer_search` finds the sessions that
actually contain the answer. QA accuracy (§7): LongMemEval's own headline
metric — an LLM reader answers each question from the retrieved sessions
and an LLM judge grades it against the gold answer, split by whether
retrieval actually found the evidence.

**This measures the palace layer only.** LongMemEval tests recall over
conversational history, which is exactly what the palace stores. The wiki's
compiled synthesis (`wiki_pages`) is never touched by this benchmark, and a
number quoted from this harness says nothing about wiki quality.

Run it against a **dedicated stack** — see the reembed warning under Step 4
below before you point this at anything holding real data.

## Prerequisites

1. **Download the dataset:**

   ```bash
   cd benchmark && ./setup.sh
   ```

   Downloads `data/longmemeval_s_cleaned.json` (~265 MB) and, optionally,
   `data/longmemeval_oracle.json`. Safe to re-run — it skips files already
   present.

2. **Bring up a dedicated stack.** Never the stack that holds your real
   palace content — see Step 4. From the repo root:

   ```bash
   cp .env.docker.example .env.bench
   # edit .env.bench: set DB_PASSWORD, leave MNEMON_EMBEDDING_DRIVER=none for now
   MNEMON_ENV_FILE=.env.bench docker compose -p mnemon-bench --env-file .env.bench up -d --build
   ```

   **Inline `MNEMON_ENV_FILE=.env.bench` on every `docker compose` command
   that creates or recreates a container** — `up`, `up --build`,
   `up --force-recreate`, `run`. `compose.yaml` reads
   `env_file: ${MNEMON_ENV_FILE:-.env}` — without the inline variable it
   silently falls back to your default `.env`. Nothing in `docker compose`'s
   own output names the cause: the `app` container just comes up against
   whatever `DB_HOST`/`DB_PORT` your default `.env` has (possibly none) and
   sits unhealthy, or — worse — comes up healthy against the wrong database
   entirely if your default `.env` also happens to be valid.

   `docker compose exec` does **not** need the prefix: a running container's
   environment was fixed when it was created, and `exec` never re-resolves
   `env_file`. That is why the `exec` commands below omit it. The distinction
   matters — if the rule were "always", you would have no way to tell which
   omissions are safe. An `export` in a
   previous shell does not help: each Bash invocation here is a fresh shell,
   so the variable never carries over between commands. This is not
   hypothetical — it happened during this harness's own validation.

3. **Mint a personal access token.** A fresh install cannot do this in one
   step — `docker/entrypoint.sh` runs `passport:keys` but never creates a
   personal access client, and `User::createToken()` needs one. This is
   tracked as product defect D17 (`docs/superpowers/specs/2026-08-28-release-roadmap.md`);
   until it's fixed, run the client-creation step yourself, once per stack:

   ```bash
   docker compose -p mnemon-bench --env-file .env.bench exec -T app \
     php artisan passport:client --personal --name="Benchmark Personal Access Client" --no-interaction
   ```

   Then mint the token. `createToken()` needs `APP_KEY` in its own process
   environment, which `docker compose exec` does not inherit from the
   container's entrypoint — pass it explicitly, read from where the
   entrypoint persisted it:

   ```bash
   APP_KEY=$(docker compose -p mnemon-bench --env-file .env.bench exec -T app cat /app/storage/app_key)
   docker compose -p mnemon-bench --env-file .env.bench exec -T -e APP_KEY="$APP_KEY" app \
     php artisan tinker --execute='echo App\Models\User::first()->createToken("Benchmark", ["mcp:use"])->accessToken;' \
     | tail -1 > /tmp/.mnemon-token
   ```

   `config.py` reads the token from `/tmp/.mnemon-token` by default (override
   with `MNEMON_TOKEN` or `MNEMON_TOKEN_FILE`). Personal access tokens carry
   no wing restrictions, which is what the benchmark needs since it reads
   back every wing it writes.

## 1. Preflight — not optional

```bash
cd benchmark && python3 preflight.py
```

This exists because of the failure mode most likely to produce a confident
wrong number: `config.wing_slug()` reimplements Laravel's `Wing::slugify()`
in Python, and `DrawerWriteService` stores whatever wing string it's given
verbatim as the slug. If the two ever disagree, ingestion writes to one wing
and retrieval searches another — every question then scores zero, and the
result looks like a product failure rather than a harness bug. Preflight also
checks that no question's text exceeds `drawer_search`'s 500-character query
cap (an over-length query fails silently as "no results," which would be
scored as a retrieval miss rather than an error).

Expected: `[preflight] all checks passed`, exit 0. If slug agreement fails,
stop — nothing downstream is worth running until it passes.

## 2. Ingest the subset

```bash
cd benchmark && python3 ingest.py --subset 25 --seed 1234
```

Writes one wing per question (`benchmark-q{question_id}`), one drawer per
haystack session, room `sessions`. `--subset 25 --seed 1234` is a
deterministic sample used to validate the whole pipeline cheaply before
committing to a run over all 500 questions — see the throughput note below.

Expected: `[ingest] done: N drawers, M truncated`, exit 0. Ingestion is
resumable — re-running the identical command after an interruption skips
sessions already recorded and reports `nothing to do` if the whole subset is
already in.

## 3. The keyless run

```bash
cd benchmark
python3 retrieve.py --tag keyless --subset 25 --seed 1234
python3 evaluate.py --tag keyless
```

`--tag` is required on `retrieve.py` so the keyless and embedded runs can't
overwrite each other's raw hits (`results/hits-{tag}.jsonl`). `retrieve.py`
also records the server's actual embedding configuration (from
`brain_status()`) alongside the hits, and refuses to search any question
that isn't fully ingested unless `--allow-incomplete` is passed. `evaluate.py`
scores the hits into `results/metrics-{tag}.json` — both `hit_rate@{1,3,5,10}`
(did *any* gold session appear in the top k) and standard `recall@{1,3,5,10}`
(what fraction of gold sessions were found), MRR, and a per-question-type
breakdown — and excludes any errored search from the denominator rather than
counting it as a miss.

**The keyless number is the shipped default.** A fresh Mnemon install ships
`MNEMON_EMBEDDING_DRIVER=none` — no OpenAI account, no spend, works
out of the box. This tag measures exactly that configuration: full-text +
temporal ranking, no semantic leg. Ollama is not a substitute today — product
defect D10 means the Ollama driver cannot store an embedding at all
(`nomic-embed-text`'s 768 dimensions don't fit the `vector(1536)` column), so
OpenAI is the only working embedding path.

## 4. Switch to the embedded driver and re-embed

**`mnemon:reembed` re-embeds every drawer in the database, not just the
benchmark's wings.** There is no way to scope it to a wing or a tag. Run this
only against a dedicated benchmark stack — pointing it at a stack holding
real palace content will spend API credit re-embedding all of it and there is
no undo.

```bash
cd /root/projects/mnemon
sed -i 's/^MNEMON_EMBEDDING_DRIVER=none/MNEMON_EMBEDDING_DRIVER=openai/' .env.bench
grep -q '^OPENAI_API_KEY=.\+' .env.bench || echo "OPENAI_API_KEY=${OPENAI_API_KEY}" >> .env.bench
MNEMON_ENV_FILE=.env.bench docker compose -p mnemon-bench --env-file .env.bench up -d
docker compose -p mnemon-bench --env-file .env.bench exec -T app \
  php artisan mnemon:reembed --model=drawer --batch=100
```

Expected: a progress bar to 100% and a summary table with `Failed: 0`.
Re-embedding the 1,201-drawer subset costs about $0.05 of OpenAI usage and
takes a couple of minutes. Do **not** re-ingest — the point of this step is
that only the embedding changes underneath the same content.

Sanity check before trusting the number: confirm the driver actually
switched inside the container —

```bash
docker compose -p mnemon-bench --env-file .env.bench exec -T app \
  php artisan tinker --execute='echo config("mnemon.embedding.driver");'
```

`retrieve.py` also records this for you now: it calls `brain_status()` once
per run and writes the server's actual embedding configuration to
`results/meta-{tag}.json`, which `evaluate.py` carries into
`metrics-{tag}.json` and `report.py` prints as an "embedding driver" row —
so the report says which configuration each column actually measured,
independent of whatever you typed for `--tag`. (An earlier version of this
section warned that identical `recall@10` between the two legs was a red
flag for a failed re-embed. It isn't: in a real, correct run `hit_rate@10`
*is* identical between legs — see the results below — so that heuristic fires on
the good case. Use the embedding driver row instead of a coincidence in the
numbers.)

## 5. The embedded run

```bash
cd benchmark
python3 retrieve.py --tag embedded --subset 25 --seed 1234
python3 evaluate.py --tag embedded
```

Same commands as Step 3, different tag — `retrieve.py` picks up whatever
driver the server is currently configured for.

## 6. The report

```bash
cd benchmark && python3 report.py --tags keyless embedded
```

Renders both metrics files as a markdown table to stdout and to
`results/report.md`. It always includes the palace-only caveat, and renders
a missing metric as `n/a` rather than `0.000` so an incomplete run can't be
misread as a bad score.

If `results/qa-metrics-{tag}.json` exists for a requested `--tag` (produced
by the QA layer, §7 below), `report.py` appends a second "QA accuracy"
section for it automatically — same command, no extra flag. A tag with only
a retrieval run and no QA pass yet simply doesn't get that section; the
retrieval table is unaffected either way.

### Results from this validation run (subset of 25, seed 1234)

```
## LongMemEval-S — retrieval

Measured against Mnemon's **palace** layer only — LongMemEval tests recall over conversational history. The wiki's compiled synthesis is not exercised by this benchmark.

| Metric | keyless | embedded |
|---|---|---|
| hit_rate@1 | 0.840 | 0.960 |
| hit_rate@3 | 1.000 | 1.000 |
| hit_rate@5 | 1.000 | 1.000 |
| hit_rate@10 | 1.000 | 1.000 |
| recall@1 | 0.620 | 0.720 |
| recall@3 | 0.940 | 0.960 |
| recall@5 | 0.960 | 0.960 |
| recall@10 | 0.980 | 0.980 |
| MRR | 0.907 | 0.980 |
| questions scored | 25 | 25 |
| errors | 0 | 0 |
| embedding driver | none | openai (1536d) |
```

These figures are **reproducible**, which earlier ones were not. They were
produced after defect D19 — full-text search ordered by score with no
secondary key — was fixed. Two completely independent ingests of identical
content, into separate databases with fresh volumes and 8-way concurrent
writes, returned **identical top-10 rankings for all 25 questions**. Before
that fix the same comparison moved keyless hit_rate@1 from 0.880 to 0.840.

Two metric families are published, and they are not interchangeable.
`hit_rate@k` is "did *any* gold session appear in the top k"; `recall@k` is
the standard `|retrieved ∩ gold| / |gold|`. They coincide only when a
question has exactly one gold session — 13 of these 25 don't. An earlier
version of this harness computed only hit_rate and published it under the
name "recall," which is why the numbers above don't match older copies of
this table.

**What this shows.** Embeddings improve where the evidence *ranks*, and not
how much of it is found. hit_rate@1 goes from 0.840 to 0.960 (21 of 25
questions to 24), recall@1 from 0.620 to 0.720, and MRR from 0.907 to 0.980.
From k=3 onward the two legs are identical on every metric, and **no
question differs between them at recall@10**.

This section has been wrong twice, in opposite directions, and both errors
are worth knowing about because they were caused by the measurement rather
than by the system.

The first version claimed saturation at recall@5/@10 and concluded
embeddings "only re-rank". The reasoning was invalid: it read a saturated
`hit_rate` under the label `recall`, which hides everything about questions
with more than one gold session.

The second version corrected the metric and then claimed the embedded leg
found *less* evidence at k=10 — 1.000 keyless against 0.980 — naming
question `3c1045c8` as the counter-example. That was true of the data at the
time and is no longer true of this code. The keyless 1.000 depended on a
tied score resolving favourably, which is exactly the nondeterminism D19
describes. With the tie-break in place both legs score 1/2 on `3c1045c8` and
0.980 at recall@10. The counter-example is withdrawn.

Treat the rank-1 gap as suggestive, not conclusive: the Wilson 95%
confidence interval on keyless hit_rate@1 (21/25) is **[0.65, 0.94]**, a
width of 0.28 — wide enough that a 3-question swing at n=25 is within noise.
Prefer "keyless found evidence for 21 of 25 questions at rank 1, embedded
for 24" over quoting three decimal places as if they were precise.

**This subset table is retained for its reproducibility story, not as the
published retrieval figure.** It is also not the full story: at n=25 the
keyless and embedded legs are identical on every metric from k=3 onward,
which reads like "embeddings only re-rank, they don't find more evidence."
Run against the full 500-question corpus, that conclusion doesn't hold —
embeddings improve every metric at every depth, including recall@10
(0.912 → 0.983). See the roadmap
(`docs/superpowers/specs/2026-08-28-release-roadmap.md`, Piece 3) for the
full 500-question retrieval table. The lesson: a saturated small-n subset
can look like agreement between two configurations that a bigger run shows
clearly apart — worth remembering before trusting any subset figure,
including a QA one (§7 below).

This subset table also says nothing about the LLM-judged QA layer
LongMemEval's own headline metric uses — see §7, "The QA layer."

### Reproducibility

This table comes from a full teardown-and-rebuild run: `docker compose down
-v`, rebuild, fresh migrations, mint a new token, and every step above from
nothing. A first full run (recorded separately, under this harness's old
metric name — see above) measured keyless hit_rate@1 0.880 / MRR 0.930
against the same 25 questions and seed; this run measured 0.840 / 0.910.
**hit_rate@3, @5, @10 and the embedded column reproduced exactly both
times; only keyless hit_rate@1 and MRR moved.** (Standard recall was not
computed for that first run — its raw hits were not kept — so this
reproducibility comparison is hit_rate-only.)

The cause is a real, verified product characteristic, not a harness bug:
`PalaceSearchService::fulltextSearch()` (`app/Services/PalaceSearchService.php`)
orders results by `{score} DESC` with no secondary sort key, and for 3 of the
25 questions the gold session and one or more distractor sessions score
*exactly* equal (confirmed live — e.g. `0.34` vs `0.34`, and one case with a
three-way tie at `0.325`). PostgreSQL does not guarantee
a stable order among exactly-tied rows without an explicit tiebreaker, and
which of the pair sorts first depends on physical row order, which in turn
depends on insertion order — `ingest.py` writes with 8 concurrent workers, so
insertion order is not identical between two separate ingestion runs of the
same content. hit_rate@3+ is unaffected because both tied candidates land in
the top 2 regardless of order; only "which one is rank 1" moves. The embedded
leg is unaffected because cosine similarity scores are effectively continuous
and do not produce exact ties. Recorded as D19 in the roadmap.

**Practical consequence:** treat keyless hit_rate@1/MRR as accurate to
roughly ±0.04 (one question's worth) run-to-run rather than as an exact
figure, and don't read small keyless hit_rate@1 deltas across runs as a
regression without checking for this first.

### Measured throughput, for the scale decision

Ingesting this 25-question subset took **~48 drawers/question** at
**~2 drawers/sec**, 1,201 drawers total, 0 truncated (`MAX_DRAWER_CHARS`
30,000 chars was never hit), 10m18s wall-clock. Extrapolating linearly to all
500 LongMemEval-S questions: **~24,000 drawers, ~3.3–3.5 hours of ingest**.
Add the OpenAI re-embed of ~24k drawers and a second retrieval pass and the
full A/B lands around **4.5–5 hours**, feasible overnight, at roughly
**$1–3** of OpenAI usage. This is the number the scale decision should be
made on — it comes from an actual timed run, not a guess.

## 7. The QA layer (LLM-judged answer accuracy)

Retrieval says whether relevant evidence was found. It doesn't say whether
the system produced a correct answer — that's LongMemEval's own headline
metric, and it's a separate measurement: feed each question's retrieved
sessions to a reader model, grade the reader's answer against the gold
answer with an LLM judge, and report accuracy split by whether retrieval
actually surfaced the evidence (`retrieval_hit`, computed in the reader
stage from what the reader was actually shown at K, not from the full
retrieved list).

This layer never talks to the Mnemon server — it reads an existing
`results/hits-{tag}.jsonl` (produced by `retrieve.py`, §3/§5 above) and
`data/longmemeval_s_cleaned.json` directly, and calls OpenAI. No docker
compose stack needs to be up to run it.

```bash
cd benchmark
export OPENAI_API_KEY=sk-...   # never write this to results/ or a committed file

python3 qa_run.py    --tag full-keyless  --k 5
python3 qa_judge.py  --tag full-keyless
python3 qa_evaluate.py --tag full-keyless

python3 qa_run.py    --tag full-embedded --k 5
python3 qa_judge.py  --tag full-embedded
python3 qa_evaluate.py --tag full-embedded

python3 report.py --tags full-keyless full-embedded
```

`--tag` must match an existing `hits-{tag}.jsonl` — this stage grades the
retrieval already recorded, it does not re-run `retrieve.py`. Each command:

- **`qa_run.py`** (the reader) — one call per question at `temperature=0`,
  answering only from the top-`k` retrieved sessions or replying exactly
  `NOT FOUND`. Writes `results/answers-{tag}.jsonl` and a companion
  `results/qa-meta-{tag}.json` recording the `k` and model that actually
  produced the run (mirroring `retrieve.py`'s `meta-{tag}.json` for the
  embedding driver) — so a report built later reads the real invocation
  instead of a value someone typed into documentation and forgot to update.
- **`qa_judge.py`** (the judge) — grades each answer against the gold answer
  **twice**, with two independent calls to the identical prompt. Not
  redundancy for its own sake: a temperature-0 model is still not
  bit-deterministic, and running it twice turns "we assume the judge is
  stable" into a measured `disagreement_rate` that ships with the accuracy
  number rather than being assumed away. Writes `results/verdicts-{tag}.jsonl`.
- **`qa_evaluate.py`** — joins answers and verdicts by `question_id`, scores
  only the cleanly-answered-and-judged rows, and writes
  `results/qa-metrics-{tag}.json`. Every other outcome (a retrieval error
  carried through from the hits file, a reader failure, a question not yet
  judged, a judge-side failure, an orphaned verdict row with no matching
  answer) is counted in the output rather than silently excluded, and a
  duplicate `question_id` within either input file — which a naive resume
  can produce — is detected, reported (`duplicate_answer_ids` /
  `duplicate_verdict_ids`), and resolved last-row-wins rather than
  collapsed with no trace. A prior version of this scorer collapsed
  duplicates silently, which can flip a correct verdict to incorrect while
  every count in the output still looks internally consistent — this run's
  output was checked and both duplicate lists are empty.

All three stages are resumable, the same discipline `ingest.py` and
`retrieve.py` use: each writes its output file incrementally
(`fh.flush()` per row) and skips `question_id`s already recorded, so a
network blip or a killed process loses at most the one in-flight request. A
*retrieval* error inherited from the hits file is treated as terminal (this
stage never re-runs retrieval, so retrying can't fix it); a reader- or
judge-side error is treated as transient and retried on the next invocation.
This was exercised for real, not just asserted: the `full-keyless` reader
run was interrupted mid-flight by a shell timeout partway through, and
re-running the identical command picked up where it left off. The finished
500-row `answers-full-keyless.jsonl` has zero duplicate `question_id`s
(`duplicate_answer_ids: []` in the metrics file, independently re-checked
line by line) — a resumed run added exactly the missing rows, not extra
copies of ones already written.

### Three facts the accuracy number depends on

The headline `accuracy` figure is not interpretable without these. All
three are recorded in the results files, not just asserted here.

1. **A split judge verdict contributes 0.5 to the headline.** Each question
   is judged twice; `accuracy` pools both calls as equally-weighted votes
   rather than picking one call arbitrarily. A question where the two calls
   disagree (`agreed: false`) contributes half a point, not a coin flip and
   not a discard. This is mathematically equivalent to averaging
   `accuracy_verdict_a` and `accuracy_verdict_b` — which is why both are
   published alongside the headline: together with `disagreement_rate` they
   bound how far the judge's own noise could have moved the number actually
   reported. Without stating the pooling rule, a reader cannot reconstruct
   the figure from `verdicts-{tag}.jsonl` themselves.
2. **The reader is instructed to abstain rather than guess.** The reader
   prompt says: answer only from the excerpts shown, and reply exactly `NOT
   FOUND` if they don't contain the answer. A system whose reader guesses
   instead scores higher on ambiguous or partially-evidenced items purely by
   luck. This harness's figure is therefore **systematically conservative**
   relative to such a baseline — a prompt-policy difference from a
   guess-happy reader, not a memory-quality difference. (Concretely: one
   question in the earlier validation run, `eaca4986`, came back `NOT FOUND`
   with `retrieval_hit: true` — the evidence was shown, but a "two sad
   songs" premise didn't cleanly match a transcript containing one sad song
   followed by a romantic revision, and the reader correctly declined to
   guess. Scored `INCORRECT`, and correctly so under this policy.)
3. **Reader and judge are the same model** (`gpt-4o`, `config.QA_MODEL`) —
   gpt-4o grading gpt-4o's own answers. This matches LongMemEval's published
   methodology, which is *why* it's done this way: it's what makes this
   figure comparable to other systems' published LongMemEval numbers.
   Self-preference bias is a known effect in LLM-as-judge setups and points
   **the opposite direction** from caveat 2 — toward a slightly generous
   score. Swapping in a different judge model would trade this known, shared
   bias for an unknown, unshared one, at the cost of the comparability that
   is the entire reason to match LongMemEval's methodology here.

### K trade-off

`k=5` is the default because it's a measured trade-off, not an arbitrary
choice. Reader context is billed on input tokens, and K controls how many
retrieved sessions the reader sees:

| K | ≈ tokens/question (measured) | ~cost / leg (500 questions) |
|---|---|---|
| 5 | ~17,000 (plan estimate); **14,294 measured** | ~$21 (plan estimate); **~$17.7–18.3 measured** |
| 10 | ~32,000 (plan estimate, not run) | ~$40 (plan estimate, not run) |

K=10 would feed the reader everything retrieval found, roughly doubling
reader cost — but on the full 500-question run, retrieval's own recall@5
vs. recall@10 differ by only **+0.080** (keyless, 0.833→0.912) and **+0.031**
(embedded, 0.952→0.983): the second five sessions rarely add evidence and
always add cost. K=5 was run for both legs; K=10 was not, on that basis.

### Measured cost, against the plan's estimate

The implementation plan estimated **~$0.037/question/leg** for the reader
(from a 2-question live smoke test extrapolated to 500). The actual full
500-question, both-legs run:

| | reader | judge | total |
|---|---|---|---|
| full-keyless | $18.2567 | $0.4212 | $18.6779 |
| full-embedded | $17.6521 | $0.4321 | $18.0842 |
| **total** | **$35.9088** | **$0.8533** | **$36.7621** |

**Actual: $36.76 against a plan estimate of ~$37 for the pair — within 1%.**
Per-question-per-leg (reader + judge): $0.0374 (full-keyless) and $0.0362
(full-embedded), averaging $0.0368 against the $0.037 estimate. The judge
adds under $1 total across 2,000 judge calls (500 questions × 2 legs × 2
calls each) — a rounding error next to the reader cost, because judge
prompts are ~150 tokens against the reader's ~14,300.

### Results (full 500-question corpus, both legs, K=5, gpt-4o)

```
## LongMemEval-S — QA accuracy (LLM-judged)

| Metric | full-keyless | full-embedded |
|---|---|---|
| accuracy | 0.557 | 0.627 |
| accuracy (verdict_a alone) | 0.558 | 0.626 |
| accuracy (verdict_b alone) | 0.556 | 0.628 |
| judge disagreement rate | 0.010 | 0.002 |
| accuracy | retrieval_hit=True | 0.610 | 0.637 |
| n (retrieval_hit=True) | 455 | 489 |
| accuracy | retrieval_hit=False | 0.022 | 0.182 |
| n (retrieval_hit=False) | 45 | 11 |
| questions scored | 500 | 500 |
| model | gpt-4o | gpt-4o |
| k | 5 | 5 |
```

**The keyless/embedded pair separates on QA accuracy — 0.557 vs 0.627, a
+0.070 (12.6% relative) gap — the same direction retrieval separates on at
n=500** (unlike the n=25 subset above, where retrieval was saturated from
k=3 onward and looked nearly identical between legs). Judge disagreement is
low for both (1.0% keyless, 0.2% embedded), so that gap is not judge noise.

**The conditional split is the reason this layer exists.** A poor QA score
has two different causes, and only the split tells them apart: when
retrieval found the evidence (`retrieval_hit=True`), accuracy is 0.610
(keyless) / 0.637 (embedded) — still well short of 1.0, meaning the reader
itself is a real source of error even with the right sessions in hand. When
retrieval missed (`retrieval_hit=False`), accuracy collapses to 0.022
(keyless, 1 of 45 correct) / 0.182 (embedded, 2 of 11 correct) — almost
entirely the abstention policy working as intended (caveat 2 above): no
evidence shown, reader correctly says `NOT FOUND`, judge correctly scores it
incorrect. Treat embedded's miss-group figure as thin: n=11, so it's exactly
2 correct answers, not a stable rate — a Wilson 95% interval on 2/11 is wide
enough that this cell shouldn't be read to two decimal places.

## 8. Cleanup

```bash
cd benchmark && python3 cleanup.py --yes
```

Deletes every wing matching the default prefix `benchmark-q` (and their
drawers) plus the local ingestion-state cache, so the subset can be
re-ingested cleanly or the stack reused for a bigger run.

**Caveat: `--prefix` only matches dash-form slugs.** `cleanup.py`'s guard is
a `LIKE 'prefix%'` match against wing *slugs*. Normal ingestion always goes
through `config.wing_slug()`, which converts the colon form to dashes before
anything reaches the server, so a clean end-to-end run never creates
colon-form wings and the default cleanup removes everything it made. But test
residue from earlier failure-injection runs against `preflight.py` (which
manually construct a colon-form wing name to prove `check_slug_agreement`
fails correctly) leaves wings like `benchmark:qpreflight-induced2` on the
stack, and those **survive** a default cleanup silently, since `benchmark:%`
does not match `LIKE 'benchmark-q%'`. Check for stragglers and remove them
with an explicit prefix if you've been doing this kind of manual testing:

```bash
docker compose -p mnemon-bench --env-file .env.bench exec -T app php artisan tinker \
  --execute='echo App\Models\Wing::where("slug","like","benchmark:%")->pluck("slug")->implode(", ");'
# if anything comes back:
cd benchmark && python3 cleanup.py --prefix "benchmark:q" --yes
```

## What's not here

Both layers — retrieval (§1–6) and LLM-judged QA accuracy (§7) — are
implemented, tested, and have been run against the full 500-question
LongMemEval-S corpus. What remains out of scope, by design, is stated once
at the top of this document rather than repeated per section: **this
harness measures Mnemon's palace layer only.** LongMemEval exercises
conversational recall, which is what the palace stores; the wiki's compiled
synthesis (`wiki_pages`) is never touched by either layer, and no number
from this harness says anything about wiki quality.
