# Mnemon benchmark harness

A [LongMemEval-S](https://huggingface.co/datasets/xiaowu0162/longmemeval-cleaned)
retrieval benchmark for Mnemon's palace layer: it ingests conversational
sessions as drawers, one wing per question, then measures whether
`drawer_search` finds the sessions that actually contain the answer.

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

### Results from this validation run (subset of 25, seed 1234)

```
## LongMemEval-S — retrieval

Measured against Mnemon's **palace** layer only — LongMemEval tests recall over conversational history. The wiki's compiled synthesis is not exercised by this benchmark.

| Metric | keyless | embedded |
|---|---|---|
| hit_rate@1 | 0.840 | 0.960 |
| hit_rate@3 | 0.960 | 1.000 |
| hit_rate@5 | 1.000 | 1.000 |
| hit_rate@10 | 1.000 | 1.000 |
| recall@1 | 0.620 | 0.720 |
| recall@3 | 0.900 | 0.960 |
| recall@5 | 0.920 | 0.960 |
| recall@10 | 1.000 | 0.980 |
| MRR | 0.910 | 0.980 |
| questions scored | 25 | 25 |
| errors | 0 | 0 |
| embedding driver | n/a | n/a |
```

The "embedding driver" row reads `n/a` here because these two runs predate
I1's `brain_status()` wiring — their `hits-{tag}.jsonl` files have no
companion `meta-{tag}.json` for `evaluate.py` to carry forward. A run
produced with the current `retrieve.py` records this automatically (see
Step 4).

Two metric families are published, and they are not interchangeable.
`hit_rate@k` is "did *any* gold session appear in the top k"; `recall@k` is
the standard `|retrieved ∩ gold| / |gold|`. They coincide only when a
question has exactly one gold session — 13 of these 25 don't. An earlier
version of this harness computed only hit_rate and published it under the
name "recall," which is why the numbers above don't match older copies of
this table.

**What this shows.** Under standard recall, embeddings improved rank-1
placement on both conventions — hit_rate@1 went from 0.840 to 0.960 (21/25
to 24/25 questions) and recall@1 from 0.620 to 0.720 — but did **not**
uniformly improve coverage at higher k: recall@10 is **1.000 keyless vs.
0.980 embedded**, i.e. the embedded leg found *less* of the gold evidence at
k=10, not more. Concretely, question `3c1045c8` has two gold sessions
(`answer_c8cc60d6_1`, `answer_c8cc60d6_2`); keyless's top 10 contains both,
embedded's top 10 contains only `_2`. (An earlier version of this section
claimed recall@5/@10 were saturated for both legs and concluded embeddings
"only re-rank" and cannot find more evidence — that claim is false on this
harness's own hits files, `3c1045c8` above is the counter-example, and it
has been retracted.)

Treat the rank-1 gap as suggestive, not conclusive: the Wilson 95%
confidence interval on keyless hit_rate@1 (21/25) is **[0.65, 0.94]**, a
width of 0.28 — wide enough that a 3-question swing at n=25 is within noise.
Prefer "keyless found evidence for 21 of 25 questions at rank 1, embedded
for 24" over quoting three decimal places as if they were precise. This
says nothing about the LLM-judged QA layer LongMemEval's own headline
metric uses, which this harness deliberately does not implement yet (see
below).

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

## 7. Cleanup

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

## What's not here yet

LongMemEval's own headline metric is LLM-judged QA accuracy over a generated
answer, not retrieval recall. This harness deliberately stops at retrieval:
it's the layer that validates the whole pipeline (wing addressing, ingestion,
search) for zero marginal cost, and it's a prerequisite for interpreting any
QA number the judged layer would produce. The QA layer is deferred to its
own plan, to be picked up once a full 500-question run has produced retrieval
figures worth building on.
