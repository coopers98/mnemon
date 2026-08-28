# Install Story Group 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the test suite run against PostgreSQL in CI, make a fresh clone's `php artisan test` work without manual setup, and fix the two live product bugs that would otherwise ship with the Docker install.

**Architecture:** Four independent changes, ordered so each is verifiable when it lands. The test-environment key hook comes first because it removes a 140-test cliff that would otherwise mask every later result. The two product-bug fixes follow with ordinary TDD. CI lands last, because its job is to run everything the first three produced — against the driver production actually uses.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit 12, Passport, SQLite (fast path) and PostgreSQL + pgvector (production path), GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-08-28-install-story-design.md`

## Global Constraints

- PHP `^8.4`; Laravel `^13.0`.
- Tests run on SQLite today; production is PostgreSQL. Never write a query or migration that only works on one without a driver guard — follow the existing `PalaceSearchService::isPostgres()` pattern.
- Run `./vendor/bin/pint` before every commit.
- Full suite: `php artisan test --compact`. Baseline entering this plan: **401 passing**.
- Do not change `execute()`-style tool signatures or the MCP wire contract.
- Passport signing keys live in `storage/`. Losing them invalidates every issued token — never delete them outside a test fixture.

---

### Task 1: Self-provision Passport keys in the test environment

A fresh clone cannot run the suite. `tests/Concerns/MakesMcpRequests.php` mints Passport tokens, which requires `storage/oauth-private.key`; nothing creates it, `.gitignore` excludes it, and no doc mentions it. The failure is 140 tests erroring with "Invalid key supplied", which reads as a broken checkout rather than a missing setup step.

**Files:**
- Modify: `tests/TestCase.php`
- Test: verification is the suite itself, run against a deliberately key-less state (Step 2)

**Interfaces:**
- Consumes: nothing.
- Produces: keys guaranteed present before any test boots. Tasks 2–4 rely on this implicitly; Task 4's CI job would otherwise need its own `passport:keys` step.

- [ ] **Step 1: Prove the cliff exists**

Move the keys aside and run a Passport-dependent test:

```bash
mkdir -p /tmp/mnemon-keys-backup
mv storage/oauth-private.key storage/oauth-public.key /tmp/mnemon-keys-backup/
php artisan test --filter=ServerSmokeTest 2>&1 | tail -5
```

Expected: failures mentioning `Invalid key supplied`. This is the RED state — the bug is the absence of setup, so the "test" is the suite's own behaviour on a fresh checkout.

- [ ] **Step 2: Implement the hook**

In `tests/TestCase.php`, generate keys once per process when absent:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    private static bool $passportKeysEnsured = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensurePassportKeys();
        $this->withoutVite();
    }

    /**
     * A fresh clone has no Passport signing keys, and every test that mints a
     * token fails with "Invalid key supplied" — which reads as a broken
     * checkout rather than a missing setup step. Generate them once per
     * process rather than making every contributor discover this.
     */
    private function ensurePassportKeys(): void
    {
        if (self::$passportKeysEnsured) {
            return;
        }

        self::$passportKeysEnsured = true;

        if (! file_exists(storage_path('oauth-private.key'))) {
            Artisan::call('passport:keys', ['--no-interaction' => true]);
        }
    }
}
```

- [ ] **Step 3: Verify GREEN from the key-less state**

The keys are still moved aside from Step 1. Run:

```bash
php artisan test --filter=ServerSmokeTest 2>&1 | tail -3
```

Expected: PASS, and `storage/oauth-private.key` now exists again — the hook regenerated it.

- [ ] **Step 4: Verify the whole suite from a key-less state**

```bash
rm -f storage/oauth-private.key storage/oauth-public.key
php artisan test --compact 2>&1 | grep -E "Tests:"
```

Expected: **401 passing**. If anything fails, the hook is running too late for some test — report it rather than working around it.

- [ ] **Step 5: Clean up the backup**

```bash
rm -rf /tmp/mnemon-keys-backup
```

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add tests/TestCase.php
git commit -m "test: self-provision Passport keys so a fresh clone can run the suite

storage/oauth-private.key is gitignored and nothing created it, so a fresh
clone failed 140 tests with 'Invalid key supplied' — a message that reads as
a broken checkout rather than a missing setup step. TestCase now generates
the keys once per process when they are absent."
```

---

### Task 2: Give the admin seeder a random password

`AdminUserSeeder` hard-codes `Hash::make('password')` and prints the credential. `docs/USERGUIDE.md` already claims "a generated password", so the docs are wrong today. Once Docker is the primary install path, every Mnemon on the internet would share one admin login — and `User::canAccessPanel()` returns `true` for any user, while the panel manages OAuth clients and access tokens. That credential effectively mints MCP access to the whole palace.

**Files:**
- Modify: `database/seeders/AdminUserSeeder.php`
- Create: `tests/Feature/AdminUserSeederTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: the seeder writes the generated password to `storage/admin-password.txt` (mode 0600) and no longer prints it to stdout. Task 4's CI does not depend on this; the Docker entrypoint (group 2) will.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/AdminUserSeederTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::delete(storage_path('admin-password.txt'));
        parent::tearDown();
    }

    public function test_seeded_admin_password_is_not_the_literal_string_password(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', 'admin@mnemon.local')->first();

        $this->assertNotNull($admin);
        $this->assertFalse(
            Hash::check('password', $admin->password),
            'the seeder must not ship a known password'
        );
    }

    public function test_generated_password_is_written_to_a_file_and_works(): void
    {
        $this->seed(AdminUserSeeder::class);

        $path = storage_path('admin-password.txt');
        $this->assertFileExists($path, 'the credential must be recoverable — nobody watches `compose up -d`');

        $password = trim(File::get($path));
        $admin = User::where('email', 'admin@mnemon.local')->first();

        $this->assertTrue(Hash::check($password, $admin->password));
    }

    public function test_reseeding_does_not_replace_an_existing_admin(): void
    {
        $this->seed(AdminUserSeeder::class);
        $original = User::where('email', 'admin@mnemon.local')->first()->password;

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(
            $original,
            User::where('email', 'admin@mnemon.local')->first()->password,
            'the entrypoint runs on every boot; reseeding must not rotate the password'
        );
        $this->assertSame(1, User::where('email', 'admin@mnemon.local')->count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminUserSeederTest --compact`
Expected: FAIL — the first test fails because `Hash::check('password', …)` returns true, and the second because `storage/admin-password.txt` does not exist.

- [ ] **Step 3: Write minimal implementation**

Replace `database/seeders/AdminUserSeeder.php`:

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $existing = User::where('email', 'admin@mnemon.local')->first();

        if ($existing !== null) {
            $this->command?->info('Admin user already exists — leaving it alone.');

            return;
        }

        $password = Str::password(24);

        User::create([
            'email' => 'admin@mnemon.local',
            'name' => 'Admin',
            'password' => Hash::make($password),
        ]);

        // Written to a file rather than printed: nobody is watching the output
        // of `docker compose up -d`, and stdout would persist the credential in
        // `docker logs` forever.
        $path = storage_path('admin-password.txt');
        File::put($path, $password.PHP_EOL);
        chmod($path, 0600);

        $this->command?->info('Admin user created: admin@mnemon.local');
        $this->command?->info("Password written to {$path} — store it somewhere safe and delete the file.");
        $this->command?->warn('There is no password reset flow. Losing it means resetting via `php artisan tinker`.');
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AdminUserSeederTest --compact`
Expected: PASS (3 tests).

- [ ] **Step 5: Run the full suite**

Run: `php artisan test --compact`
Expected: **401 passing** plus the 3 new tests = 404. `DatabaseSeeder` may call `AdminUserSeeder`; if any existing test asserted the old credential, that test encoded the bug — update it and say so in the commit body.

- [ ] **Step 6: Correct the USERGUIDE claim**

`docs/USERGUIDE.md` says the seeder prints a generated password. It now writes one to a file. Update that sentence to name `storage/admin-password.txt` and state that there is no reset flow.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint
git add database/seeders/AdminUserSeeder.php tests/Feature/AdminUserSeederTest.php docs/USERGUIDE.md
git commit -m "fix: generate a random admin password instead of shipping a known one

AdminUserSeeder hard-coded Hash::make('password') and printed it, while the
USERGUIDE already claimed a generated password. With Docker becoming the
primary install path every instance would have shared one admin login — and
canAccessPanel() returns true for any user, while the panel manages OAuth
clients and tokens.

The password is written to storage/admin-password.txt (0600) rather than
stdout: nobody watches \`compose up -d\`, and stdout would persist it in
docker logs. Reseeding leaves an existing admin untouched, since the
entrypoint runs on every boot."
```

---

### Task 3: Fix `brain_status` embedding reporting

`BrainStatusTool` reads `config('mnemon.embedding.dimensions')`, which resolves to `null` — the real key is `mnemon.embedding.drivers.{driver}.dimensions`. Verified by execution. With `driver=none` about to become the default, embedding coverage also stops being inferable, so the same payload should say how many rows actually have an embedding.

**Files:**
- Modify: `app/Mcp/Tools/BrainStatusTool.php:79-82`
- Test: `tests/Feature/Mcp/Tools/BrainStatusToolTest.php`

**Interfaces:**
- Consumes: the MCP request helpers in `Tests\Concerns\MakesMcpRequests` (`mcpCall`).
- Produces: `result.structuredContent.embedding` gains real `dimensions` plus `embedded_drawers` and `unembedded_drawers` integers.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Mcp/Tools/BrainStatusToolTest.php` (inside the class):

```php
    public function test_reports_the_active_drivers_dimensions_not_null(): void
    {
        config(['mnemon.embedding.driver' => 'openai']);

        $r = $this->mcpCall('brain_status', [], ['mcp:use']);

        $embedding = $r->json('result.structuredContent.embedding');

        $this->assertSame('openai', $embedding['driver']);
        $this->assertSame(1536, $embedding['dimensions']);
    }

    public function test_reports_embedding_coverage(): void
    {
        $r = $this->mcpCall('brain_status', [], ['mcp:use']);

        $embedding = $r->json('result.structuredContent.embedding');

        // With driver=none nothing is embedded, but the counts must be present
        // and numeric so coverage is visible rather than inferred.
        $this->assertIsInt($embedding['embedded_drawers']);
        $this->assertIsInt($embedding['unembedded_drawers']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BrainStatusToolTest --compact`
Expected: FAIL — `dimensions` is `null` rather than `1536`, and `embedded_drawers` is an undefined index.

- [ ] **Step 3: Write minimal implementation**

In `app/Mcp/Tools/BrainStatusTool.php`, replace the `'embedding' => [...]` block:

```php
            'embedding' => $this->embeddingStatus(),
```

and add the method to the class:

```php
    /**
     * @return array<string, mixed>
     */
    private function embeddingStatus(): array
    {
        $driver = config('mnemon.embedding.driver');

        // The dimensions live under the driver, not at the top of the
        // embedding config — reading the top-level key always returned null.
        $dimensions = config("mnemon.embedding.drivers.{$driver}.dimensions");

        $embedded = Drawer::whereNotNull('embedding')->count();

        return [
            'driver' => $driver,
            'dimensions' => $dimensions,
            'embedded_drawers' => $embedded,
            'unembedded_drawers' => Drawer::count() - $embedded,
        ];
    }
```

`Drawer` is already imported in this file.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=BrainStatusToolTest --compact`
Expected: PASS.

- [ ] **Step 5: Verify the count works on SQLite**

The `embedding` column is only added on PostgreSQL — the migrations guard on driver — so `whereNotNull('embedding')` must not error on SQLite, where the column does not exist. Confirm the filter above passed on SQLite in Step 4. If it errored with "no such column", guard the count:

```php
$hasColumn = Schema::hasColumn('drawers', 'embedding');
$embedded = $hasColumn ? Drawer::whereNotNull('embedding')->count() : 0;
```

adding `use Illuminate\Support\Facades\Schema;`. Report which branch you needed.

- [ ] **Step 6: Run the full suite**

Run: `php artisan test --compact`
Expected: 404 passing plus the 2 new = 406.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint
git add app/Mcp/Tools/BrainStatusTool.php tests/Feature/Mcp/Tools/BrainStatusToolTest.php
git commit -m "fix: brain_status reported dimensions as null

config('mnemon.embedding.dimensions') does not exist — the value lives under
mnemon.embedding.drivers.{driver}.dimensions — so every brain_status response
reported null. Adds embedded/unembedded drawer counts alongside it, so that
embedding coverage is visible once driver=none becomes the default."
```

---

### Task 4: CI on PostgreSQL and SQLite

The gap this whole piece exists to close: three defects reached `main` because the suite cannot execute PostgreSQL — the PHP floor that broke `composer install`, a `varchar(255)` overflow that turned a 401 into a 500, and the D10 dimension mismatch. None were visible to 400 green tests on SQLite.

**Files:**
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: the Passport key hook from Task 1 (so the workflow needs no `passport:keys` step), and the fixes from Tasks 2–3 (so the suite is green on both drivers).
- Produces: nothing later in this plan depends on it. Group 2 adds a third `compose` job to this same file.

- [ ] **Step 1: Write the workflow**

Create `.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  tests:
    name: tests (${{ matrix.database }})
    runs-on: ubuntu-latest

    strategy:
      fail-fast: false
      matrix:
        database: [sqlite, pgsql]

    services:
      postgres:
        image: pgvector/pgvector:pg17
        env:
          POSTGRES_USER: mnemon
          POSTGRES_PASSWORD: mnemon
          POSTGRES_DB: mnemon_test
        ports:
          - 5432:5432
        options: >-
          --health-cmd pg_isready
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          extensions: pdo_pgsql, pgsql, intl, zip, bcmath, gd
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Prepare environment
        run: |
          cp .env.example .env
          php artisan key:generate

      - name: Run tests
        # phpunit.xml pins DB_CONNECTION=sqlite. PHPUnit's <env> does not
        # override an already-exported variable unless force="true", so
        # exporting here is what selects the driver.
        env:
          DB_CONNECTION: ${{ matrix.database }}
          DB_DATABASE: ${{ matrix.database == 'pgsql' && 'mnemon_test' || ':memory:' }}
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_USERNAME: mnemon
          DB_PASSWORD: mnemon
        run: php artisan test --compact

  lint:
    name: lint
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Check formatting
        run: ./vendor/bin/pint --test
```

- [ ] **Step 2: Verify the PostgreSQL path locally before trusting CI**

`pdo_pgsql` is not installed in the dev container, so the pgsql leg cannot be run locally. Verify what you can — that the workflow is valid YAML and the env-override mechanism is right:

```bash
python3 -c "import yaml,sys; yaml.safe_load(open('.github/workflows/ci.yml')); print('workflow YAML valid')"
DB_CONNECTION=sqlite php artisan test --compact 2>&1 | grep -E "Tests:"
```

Expected: valid YAML, and the SQLite leg passes with the variable exported — which proves the override mechanism the pgsql leg depends on.

- [ ] **Step 3: Confirm the exported-variable override actually takes effect**

This is the load-bearing assumption of the whole workflow. Prove PHPUnit honours the exported value rather than `phpunit.xml`:

```bash
DB_CONNECTION=pgsql php artisan test --filter=ExampleTest 2>&1 | tail -5
```

Expected: a **connection error mentioning pgsql or a missing pgsql driver** — not a passing SQLite run. A pass here would mean `phpunit.xml` is winning and the pgsql matrix leg would silently test SQLite twice. If it passes, stop and report: the workflow needs `force="true"` removed from nothing, but rather a dedicated `phpunit.pgsql.xml`, and that is a design change worth escalating.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/ci.yml
git commit -m "ci: run the suite against PostgreSQL as well as SQLite

Three defects reached main because the suite cannot execute PostgreSQL: the
PHP floor that made composer install fail, a varchar(255) overflow that turned
an unauthenticated 401 into a 500, and the Ollama dimension mismatch (D10).
None were visible to 400 green tests on SQLite.

Adds a matrix leg on pgvector/pgvector:pg17 so to_tsvector, the pgvector <=>
operator, and column constraints actually run. The SQLite leg stays, so the
fast contributor loop is unchanged. PHP is pinned to 8.4 to match composer.json."
```

- [ ] **Step 5: Confirm CI is green after pushing**

The workflow only proves itself on GitHub. After the branch is pushed, check the run and report the result. If the pgsql leg fails, that is not a workflow bug to paper over — it is the first real signal from the driver production uses, and the failure should be reported with its output.

---

### Task 5: Make the PostgreSQL leg prove something

With `driver=none` in tests and factories leaving embeddings null, the new
pgsql matrix leg proves the vector SQL *parses* — not that a real vector
round-trips. And nothing anywhere would have caught D10 (the Ollama driver
declares 768 dimensions against a `vector(1536)` column) except a human
reading two files. Two tests close both.

**Files:**
- Create: `tests/Feature/VectorColumnTest.php`

**Interfaces:**
- Consumes: the pgsql matrix leg from Task 4.
- Produces: nothing later depends on it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/VectorColumnTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VectorColumnTest extends TestCase
{
    use RefreshDatabase;

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    public function test_a_real_vector_round_trips_through_the_embedding_column(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('vector columns only exist on PostgreSQL');
        }

        $wing = Wing::create(['name' => 'work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        $drawer = Drawer::create(['content' => 'a note', 'room_id' => $room->id]);

        $vector = '['.implode(',', array_fill(0, 1536, 0.01)).']';
        DB::statement('UPDATE drawers SET embedding = ?::vector WHERE id = ?', [$vector, $drawer->id]);

        // Exercise the operator the hybrid search actually uses, not just storage.
        $distance = DB::selectOne(
            'SELECT embedding <=> ?::vector AS d FROM drawers WHERE id = ?',
            [$vector, $drawer->id]
        );

        $this->assertNotNull($distance);
        $this->assertEqualsWithDelta(0.0, (float) $distance->d, 0.0001);
    }

    public function test_the_active_driver_dimensions_match_the_column_width(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('vector columns only exist on PostgreSQL');
        }

        $driver = config('mnemon.embedding.driver');
        $declared = config("mnemon.embedding.drivers.{$driver}.dimensions");

        if ($declared === null) {
            $this->markTestSkipped("driver '{$driver}' declares no dimensions");
        }

        // The column width is fixed by migration. A driver whose vectors are a
        // different size cannot store anything — this is defect D10, and this
        // assertion is what would have caught it without reading two files.
        $this->assertSame(1536, $declared,
            "driver '{$driver}' declares {$declared} dimensions but drawers.embedding is vector(1536)");
    }
}
```

- [ ] **Step 2: Run on SQLite — both must skip, not fail**

Run: `php artisan test --filter=VectorColumnTest --compact`
Expected: 2 skipped. A failure here means the driver guard is wrong.

- [ ] **Step 3: Note that GREEN can only be observed in CI**

`pdo_pgsql` is not installed locally, so the PostgreSQL assertions cannot run
on this machine. That is the whole reason this task exists. Record in your
report that verification happens in the Task 4 workflow, and check the pgsql
leg's output once pushed.

- [ ] **Step 4: Commit**

```bash
./vendor/bin/pint
git add tests/Feature/VectorColumnTest.php
git commit -m "test: prove a real vector round-trips, and guard the driver dimensions

The pgsql matrix leg otherwise only proves the vector SQL parses — factories
leave embeddings null and the test driver is none. These insert a genuine
1536-d vector, exercise the <=> operator hybrid search depends on, and assert
the active driver's declared dimensions match the column width, which is the
check that would have caught D10 without a human diffing two files."
```

---

## Completion

- [ ] Run the full suite one final time: `php artisan test --compact` — expect **406 passing, 2 skipped** on SQLite (Task 5's two tests only run on the pgsql leg)
- [ ] `./vendor/bin/pint --test` clean
- [ ] Update `docs/superpowers/specs/2026-08-28-install-story-design.md`: mark group 1 complete
- [ ] Note for group 2: the Docker entrypoint reads `storage/admin-password.txt` from Task 2, and adds a third `compose` job to `.github/workflows/ci.yml` from Task 4
