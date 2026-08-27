# Authorization & Audit Correctness Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Mnemon's per-key wing restrictions actually restrict, make wing slugs consistent across every creation path, correct two mis-scoped tools, and make the audit trail record denials as well as successes.

**Architecture:** Wing restrictions currently work as parameter guards — they check a value the caller volunteered, so omitting it bypasses them. This plan converts them into query filters resolved from the key, applied inside the data layer. A single canonical slugify function replaces three divergent implementations, and restriction patterns are normalised into the same slug space before matching. Audit logging moves from success-only (end of `execute()`) to covering denials at each guard.

**Tech Stack:** Laravel 13, PHP 8.3, PHPUnit 12, SQLite for tests, PostgreSQL + pgvector in production.

**Spec:** `docs/superpowers/specs/2026-08-27-release-roadmap.md` (defects D1–D4, D7)

## Global Constraints

- PHP `^8.3`; Laravel `^13.0`.
- Tests run on SQLite. Never write a migration or query that only works on PostgreSQL without a driver guard — follow the existing `PalaceSearchService::isPostgres()` pattern.
- The signature `execute(array $params, ApiKey $apiKey): array` is a fixed contract. 103 existing tests call it directly. Do not change it.
- Run `./vendor/bin/pint` before every commit.
- Full suite command: `php artisan test --compact`.
- Restriction checks must **fail closed**: when a key is restricted and the resolved allow-list is empty, return nothing rather than everything.

---

### Task 1: Centralise wing slugification (D7)

Three code paths generate wing slugs and two of them disagree. `Wing::booted()` uses `Str::slug($name)`, which strips colons entirely (`project:atlas` → `projectatlas`), while `DrawerAddTool` and `WikiCompileTool` use `Str::slug(str_replace(':', '-', $name))` (`project:atlas` → `project-atlas`). The same wing name creates two different wings depending on which path ran first, and no restriction pattern can match both.

**Files:**
- Modify: `app/Models/Wing.php:18-24`
- Modify: `app/Mcp/Tools/DrawerAddTool.php:47`
- Modify: `app/Mcp/Tools/WikiCompileTool.php:36`
- Test: `tests/Feature/WingTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Wing::slugify(string $name): string` — the single canonical wing-slug function. Tasks 2 and 3 depend on it.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/WingTest.php`:

```php
public function test_slugify_converts_namespace_colons_to_dashes(): void
{
    $this->assertSame('project-atlas', Wing::slugify('project:atlas'));
    $this->assertSame('person-jane-doe', Wing::slugify('person:jane-doe'));
    $this->assertSame('work', Wing::slugify('Work'));
    $this->assertSame('project-atlas', Wing::slugify('Project: Atlas'));
}

public function test_wing_creation_uses_the_same_slug_as_drawer_add(): void
{
    $wing = Wing::create(['name' => 'project:atlas']);

    $this->assertSame('project-atlas', $wing->slug);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WingTest --compact`
Expected: FAIL — `Call to undefined method App\Models\Wing::slugify()`.

- [ ] **Step 3: Add the canonical slugify and use it in the model**

In `app/Models/Wing.php`, add the static method and change `booted()`:

```php
/**
 * The single canonical wing-slug function.
 *
 * Namespace colons ("project:atlas") become dashes before slugging, so that
 * every creation path — model events, drawer_add, wiki_compile — agrees.
 */
public static function slugify(string $name): string
{
    return Str::slug(str_replace(':', '-', $name));
}

protected static function booted(): void
{
    static::creating(function (Wing $wing) {
        if (! $wing->slug) {
            $wing->slug = static::slugify($wing->name);
        }
    });
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WingTest --compact`
Expected: PASS

- [ ] **Step 5: Replace both duplicated call sites**

In `app/Mcp/Tools/DrawerAddTool.php`, replace line 47:

```php
$wingSlug = Wing::slugify($wingName);
```

In `app/Mcp/Tools/WikiCompileTool.php`, replace the equivalent line 36 expression with:

```php
$wingSlug = Wing::slugify($name);
```

`Wing` is already imported in `DrawerAddTool`. Add `use App\Models\Wing;` to `WikiCompileTool` if absent.

- [ ] **Step 6: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS. If any test asserts a colon-stripped slug such as `projectatlas`, that test encoded the bug — update it to the dashed form and note it in the commit body.

- [ ] **Step 7: Check the deployed instance for split wings**

This is a behaviour change for wings created through Filament or seeders with a colon in the name. Before deploying, run against production:

```bash
php artisan tinker --execute="App\Models\Wing::whereRaw(\"name LIKE '%:%'\")->get(['id','name','slug'])->each(fn(\$w) => print(\"{\$w->id}\t{\$w->name}\t{\$w->slug}\n\"));"
```

Any row whose `slug` lacks the dash was created by the old path. Record the list in the commit body. Merging split wings is out of scope for this plan — it is a data task, and the instance may have none.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint
git add app/Models/Wing.php app/Mcp/Tools/DrawerAddTool.php app/Mcp/Tools/WikiCompileTool.php tests/Feature/WingTest.php
git commit -m "fix: single canonical wing slugify across all creation paths

Wing::booted() stripped namespace colons while drawer_add converted them
to dashes, so 'project:atlas' produced either 'projectatlas' or
'project-atlas' depending on the path. Restriction patterns could match
neither reliably."
```

---

### Task 2: Fix wildcard restriction matching (D2)

`canAccessWing()` compiles the documented pattern `project:*` to `/^project:.*$/`, but no wing slug contains a colon after Task 1, so the pattern can never match. Patterns must be normalised into slug space before matching, without destroying the `*`.

**Files:**
- Modify: `app/Models/ApiKey.php:33-56`
- Test: `tests/Feature/ApiKeyTest.php`

**Interfaces:**
- Consumes: nothing at the code level. Depends on Task 1 having made wing slugs deterministic — matching a pattern against a slug is meaningless while the slug varies by creation path.
- Produces: `ApiKey::normalizeWingPattern(string $pattern): string`, and a corrected `canAccessWing(string $wingSlug): bool`. Task 3 depends on both.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ApiKeyTest.php`:

```php
public function test_wildcard_pattern_matches_real_wing_slugs(): void
{
    $key = ApiKey::create([
        'name' => 'Projects',
        'key_hash' => hash('sha256', 'projects'),
        'scopes' => ['*'],
        'wing_restrictions' => ['project:*'],
    ]);

    // Slugs as the system actually stores them.
    $this->assertTrue($key->canAccessWing('project-atlas'));
    $this->assertTrue($key->canAccessWing('project-mnemon'));
    $this->assertFalse($key->canAccessWing('personal'));
}

public function test_wildcard_does_not_match_a_similarly_prefixed_wing(): void
{
    $key = ApiKey::create([
        'name' => 'Projects',
        'key_hash' => hash('sha256', 'projects-2'),
        'scopes' => ['*'],
        'wing_restrictions' => ['project:*'],
    ]);

    // 'projects' shares the prefix but is not in the 'project' namespace.
    $this->assertFalse($key->canAccessWing('projects'));
}

public function test_exact_pattern_is_normalised_before_matching(): void
{
    $key = ApiKey::create([
        'name' => 'Atlas',
        'key_hash' => hash('sha256', 'atlas'),
        'scopes' => ['*'],
        'wing_restrictions' => ['project:atlas'],
    ]);

    $this->assertTrue($key->canAccessWing('project-atlas'));
}

public function test_unrestricted_key_accesses_any_wing(): void
{
    $key = ApiKey::create([
        'name' => 'Open',
        'key_hash' => hash('sha256', 'open'),
        'scopes' => ['*'],
        'wing_restrictions' => null,
    ]);

    $this->assertTrue($key->canAccessWing('anything'));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ApiKeyTest --compact`
Expected: FAIL — `test_wildcard_pattern_matches_real_wing_slugs` asserts true but gets false.

- [ ] **Step 3: Implement normalisation and rewrite the matcher**

Replace `canAccessWing()` in `app/Models/ApiKey.php` and add the normaliser:

```php
/**
 * Normalise a stored restriction pattern into wing-slug space, preserving
 * any '*' wildcard.
 *
 * Stored patterns use the human namespace convention ('project:*') while
 * wing slugs use dashes ('project-atlas'), so the two must be reconciled
 * before matching. Existing stored patterns keep working; no data
 * migration is required.
 */
public static function normalizeWingPattern(string $pattern): string
{
    $pattern = mb_strtolower(str_replace(':', '-', $pattern));
    $pattern = preg_replace('/[^a-z0-9*-]+/', '-', $pattern);
    $pattern = preg_replace('/-+/', '-', $pattern);

    return trim($pattern, '-');
}

public function canAccessWing(string $wingSlug): bool
{
    $restrictions = $this->wing_restrictions;

    if (empty($restrictions)) {
        return true;
    }

    foreach ($restrictions as $pattern) {
        $normalized = static::normalizeWingPattern($pattern);

        if (! str_contains($normalized, '*')) {
            if ($normalized === $wingSlug) {
                return true;
            }

            continue;
        }

        $regex = '/^'.str_replace('\*', '[a-z0-9-]*', preg_quote($normalized, '/')).'$/';

        if (preg_match($regex, $wingSlug)) {
            return true;
        }
    }

    return false;
}
```

Note that `project:*` normalises to `project-*`, so the trailing dash is what stops `projects` from matching.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ApiKeyTest --compact`
Expected: PASS

- [ ] **Step 5: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS. `ApiKeyTest` previously asserted against colon-bearing slugs the system cannot produce; if those older assertions now fail, replace their expected values with dashed slugs.

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add app/Models/ApiKey.php tests/Feature/ApiKeyTest.php
git commit -m "fix: wildcard wing restrictions can now match real slugs

'project:*' compiled to /^project:.*\$/ but wing slugs contain no colons,
so the documented wildcard convention matched nothing. Patterns are now
normalised into slug space, and '*' expands to [a-z0-9-]* so that
'project-*' does not match 'projects'."
```

---

### Task 3: Resolve a key's allowed wing slugs

The tools need a concrete allow-list to filter queries with, not a per-value predicate.

**Files:**
- Modify: `app/Models/ApiKey.php`
- Test: `tests/Feature/ApiKeyTest.php`

**Interfaces:**
- Consumes: `canAccessWing()` from Task 2.
- Produces: `ApiKey::allowedWingSlugs(): ?array` — `null` when the key is unrestricted (meaning "no filter"), otherwise an array of concrete existing wing slugs, possibly empty. Tasks 4, 5 and 6 all consume this.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ApiKeyTest.php`:

```php
public function test_allowed_wing_slugs_is_null_when_unrestricted(): void
{
    $key = ApiKey::create([
        'name' => 'Open',
        'key_hash' => hash('sha256', 'open-slugs'),
        'scopes' => ['*'],
        'wing_restrictions' => null,
    ]);

    $this->assertNull($key->allowedWingSlugs());
}

public function test_allowed_wing_slugs_resolves_wildcards_against_existing_wings(): void
{
    Wing::create(['name' => 'project:atlas']);
    Wing::create(['name' => 'project:mnemon']);
    Wing::create(['name' => 'Personal']);

    $key = ApiKey::create([
        'name' => 'Projects',
        'key_hash' => hash('sha256', 'projects-slugs'),
        'scopes' => ['*'],
        'wing_restrictions' => ['project:*'],
    ]);

    $allowed = $key->allowedWingSlugs();

    sort($allowed);
    $this->assertSame(['project-atlas', 'project-mnemon'], $allowed);
}

public function test_allowed_wing_slugs_is_empty_when_nothing_matches(): void
{
    Wing::create(['name' => 'Personal']);

    $key = ApiKey::create([
        'name' => 'Ghost',
        'key_hash' => hash('sha256', 'ghost'),
        'scopes' => ['*'],
        'wing_restrictions' => ['nonexistent:*'],
    ]);

    $this->assertSame([], $key->allowedWingSlugs());
}
```

Add `use App\Models\Wing;` to the test file if absent.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ApiKeyTest --compact`
Expected: FAIL — `Call to undefined method App\Models\ApiKey::allowedWingSlugs()`.

- [ ] **Step 3: Implement the resolver**

Add to `app/Models/ApiKey.php`:

```php
/**
 * Resolve this key's restrictions into concrete wing slugs.
 *
 * Returns null when the key is unrestricted, which callers must treat as
 * "apply no filter". An empty array means the key is restricted and no
 * existing wing matches — callers must return nothing, not everything.
 *
 * @return array<int, string>|null
 */
public function allowedWingSlugs(): ?array
{
    if (empty($this->wing_restrictions)) {
        return null;
    }

    return Wing::query()
        ->pluck('slug')
        ->filter(fn (string $slug) => $this->canAccessWing($slug))
        ->values()
        ->all();
}
```

No import is needed: `ApiKey` and `Wing` both live in `App\Models`, so `Wing::` resolves directly.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ApiKeyTest --compact`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
./vendor/bin/pint
git add app/Models/ApiKey.php tests/Feature/ApiKeyTest.php
git commit -m "feat: resolve API key wing restrictions to concrete slugs

allowedWingSlugs() returns null for unrestricted keys and an explicit
allow-list otherwise, so query layers can filter rather than guard."
```

---

### Task 4: Filter palace search by allowed wings (D1, core)

`DrawerSearchTool` calls `requireWingAccess()` only when the caller supplies `wing` (line 49), and `PalaceSearchService::baseQuery()` applies a wing filter only when `$wing !== null` (line 317). A restricted key that omits `wing` searches everything and receives full verbatim content. Under MCP the model writes the parameters, so this is the common case rather than the edge case.

**Files:**
- Modify: `app/Services/PalaceSearchService.php:27-45` (`search`), `:50`, `:105`, `:122` (the three mode methods), `:310-333` (`baseQuery`)
- Modify: `app/Mcp/Tools/DrawerSearchTool.php:52`
- Test: `tests/Feature/PalaceSearchTest.php`, `tests/Feature/McpAuthTest.php`

**Interfaces:**
- Consumes: `ApiKey::allowedWingSlugs(): ?array` from Task 3.
- Produces: `PalaceSearchService::search(string $query, ?string $wing, ?string $room, int $limit, string $mode, ?string $tier, ?array $allowedWings = null): Collection`. The new parameter is last and defaults to `null`, so existing callers are unaffected.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/McpAuthTest.php`:

```php
public function test_restricted_key_searching_without_a_wing_cannot_see_other_wings(): void
{
    $work = Wing::create(['name' => 'Work']);
    $workRoom = Room::create(['wing_id' => $work->id, 'name' => 'Notes', 'slug' => 'notes']);
    Drawer::create(['content' => 'shared secret plan', 'room_id' => $workRoom->id]);

    $personal = Wing::create(['name' => 'Personal']);
    $personalRoom = Room::create(['wing_id' => $personal->id, 'name' => 'Notes', 'slug' => 'notes']);
    Drawer::create(['content' => 'shared secret diary', 'room_id' => $personalRoom->id]);

    $key = ApiKey::create([
        'name' => 'Work Only Search',
        'key_hash' => hash('sha256', 'work-only-search'),
        'scopes' => ['*'],
        'wing_restrictions' => ['work'],
    ]);

    // Note: no 'wing' parameter — this is the bypass.
    $result = app(DrawerSearchTool::class)->execute(['query' => 'shared secret'], $key);

    $slugs = array_column($result['results'], 'wing_slug');
    $this->assertNotContains('personal', $slugs);
    $this->assertContains('work', $slugs);
}

public function test_restricted_key_matching_no_existing_wing_gets_no_results(): void
{
    $personal = Wing::create(['name' => 'Personal']);
    $room = Room::create(['wing_id' => $personal->id, 'name' => 'Notes', 'slug' => 'notes']);
    Drawer::create(['content' => 'diary entry', 'room_id' => $room->id]);

    $key = ApiKey::create([
        'name' => 'Ghost Search',
        'key_hash' => hash('sha256', 'ghost-search'),
        'scopes' => ['*'],
        'wing_restrictions' => ['nonexistent:*'],
    ]);

    $result = app(DrawerSearchTool::class)->execute(['query' => 'diary'], $key);

    $this->assertSame([], $result['results']);
}
```

Add `use App\Mcp\Tools\DrawerSearchTool;` to the test file.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=McpAuthTest --compact`
Expected: FAIL — the personal-wing drawer appears in the results.

- [ ] **Step 3: Thread the allow-list through the service**

In `app/Services/PalaceSearchService.php`, add the parameter to `search()` and pass it down:

```php
public function search(
    string $query,
    ?string $wing = null,
    ?string $room = null,
    int $limit = 5,
    string $mode = 'hybrid',
    ?string $tier = null,
    ?array $allowedWings = null,
): Collection {
    if (trim($query) === '') {
        return collect();
    }

    return match ($mode) {
        'semantic' => $this->semanticSearch($query, $wing, $room, $limit, $tier, $allowedWings),
        'fulltext' => $this->fulltextSearch($query, $wing, $room, $limit, $tier, $allowedWings),
        default => $this->hybridSearch($query, $wing, $room, $limit, $tier, $allowedWings),
    };
}
```

Add `?array $allowedWings = null` as the final parameter of `semanticSearch()`, `fulltextSearch()`, `hybridSearch()`, `postgresFulltext()` and `sqliteFulltext()`, and pass it through every internal call to `baseQuery()`. Then change `baseQuery()`:

```php
protected function baseQuery(
    ?string $wing,
    ?string $room,
    ?string $tier = null,
    ?array $allowedWings = null,
): Builder {
    $query = DB::table('drawers')
        ->join('rooms', 'drawers.room_id', '=', 'rooms.id')
        ->join('wings', 'rooms.wing_id', '=', 'wings.id')
        ->whereNull('drawers.deleted_at');

    // Key-level restriction. Null means unrestricted; an empty array means
    // restricted with nothing matching, which must return no rows.
    if ($allowedWings !== null) {
        $query->whereIn('wings.slug', $allowedWings);
    }

    if ($wing !== null) {
        $query->where('wings.slug', $wing);
    }

    if ($room !== null) {
        $query->where('rooms.slug', $room);
    }

    if ($tier !== null) {
        $query->where('drawers.tier', $tier);
    }

    return $query;
}
```

- [ ] **Step 4: Pass the allow-list from the tool**

In `app/Mcp/Tools/DrawerSearchTool.php`, replace the `$results = ...` call at line 52:

```php
$results = $this->searchService->search(
    $query,
    $wing,
    $room,
    $limit,
    $mode,
    $tier,
    $apiKey->allowedWingSlugs(),
);
```

Leave the existing `requireWingAccess()` check above it in place — it still gives a clear error when a caller explicitly names a forbidden wing, rather than silently returning nothing.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=McpAuthTest --compact`
Expected: PASS

- [ ] **Step 6: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS. `PalaceSearchTest` calls `search()` positionally; the new parameter is last and optional, so those calls are unaffected.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint
git add app/Services/PalaceSearchService.php app/Mcp/Tools/DrawerSearchTool.php tests/Feature/McpAuthTest.php
git commit -m "fix: enforce wing restrictions as query filters in palace search

A restricted key that omitted the optional 'wing' parameter searched the
entire palace and received full verbatim content. Restrictions are now
resolved from the key and applied in baseQuery(), failing closed when the
allow-list is empty."
```

---

### Task 5: Filter orientation tools by allowed wings (D1)

`PalaceWakeUpTool` returns 200-character previews of the ten most recent drawers across every wing, and lists every active wing. `BrainStatusTool` enumerates every wing with drawer counts. Neither consults wing restrictions.

**Files:**
- Modify: `app/Mcp/Tools/PalaceWakeUpTool.php:21-48`
- Modify: `app/Mcp/Tools/BrainStatusTool.php:25-33`
- Test: `tests/Feature/McpAuthTest.php`

**Interfaces:**
- Consumes: `ApiKey::allowedWingSlugs(): ?array` from Task 3.
- Produces: nothing new.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/McpAuthTest.php`:

```php
public function test_wake_up_hides_drawers_from_forbidden_wings(): void
{
    $work = Wing::create(['name' => 'Work']);
    $workRoom = Room::create(['wing_id' => $work->id, 'name' => 'Notes', 'slug' => 'notes']);
    Drawer::create(['content' => 'work item', 'room_id' => $workRoom->id]);

    $personal = Wing::create(['name' => 'Personal']);
    $personalRoom = Room::create(['wing_id' => $personal->id, 'name' => 'Notes', 'slug' => 'notes']);
    Drawer::create(['content' => 'private diary entry', 'room_id' => $personalRoom->id]);

    $key = ApiKey::create([
        'name' => 'Work Only Wake',
        'key_hash' => hash('sha256', 'work-only-wake'),
        'scopes' => ['*'],
        'wing_restrictions' => ['work'],
    ]);

    $result = app(PalaceWakeUpTool::class)->execute([], $key);

    $previews = array_column($result['recent_drawers'], 'content_preview');
    $this->assertNotContains('private diary entry', $previews);

    $wingSlugs = array_column($result['active_wings'], 'slug');
    $this->assertNotContains('personal', $wingSlugs);
}

public function test_brain_status_hides_forbidden_wings(): void
{
    Wing::create(['name' => 'Work']);
    Wing::create(['name' => 'Personal']);

    $key = ApiKey::create([
        'name' => 'Work Only Status',
        'key_hash' => hash('sha256', 'work-only-status'),
        'scopes' => ['*'],
        'wing_restrictions' => ['work'],
    ]);

    $result = app(BrainStatusTool::class)->execute([], $key);

    $slugs = array_column($result['wings'], 'slug');
    $this->assertSame(['work'], $slugs);
}
```

Add `use App\Mcp\Tools\PalaceWakeUpTool;` and `use App\Mcp\Tools\BrainStatusTool;` to the test file.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=McpAuthTest --compact`
Expected: FAIL — the personal drawer preview and wing are present.

- [ ] **Step 3: Filter the wake-up queries**

In `app/Mcp/Tools/PalaceWakeUpTool.php`, immediately after the `requireScope()` call add:

```php
$allowedWings = $apiKey->allowedWingSlugs();
```

Then constrain the two wing-bearing queries. Replace the `$recentDrawers` query opening:

```php
$recentDrawers = Drawer::with(['room.wing'])
    ->when($allowedWings !== null, fn ($q) => $q->whereHas(
        'room.wing',
        fn ($w) => $w->whereIn('slug', $allowedWings)
    ))
    ->orderByDesc('created_at')
    ->take(10)
    ->get()
```

and the `$activeWings` query, adding the constraint before `->groupBy('wings.id')`:

```php
->when($allowedWings !== null, fn ($q) => $q->whereIn('wings.slug', $allowedWings))
```

Leave the wiki queries unfiltered — wiki pages carry no wing association. That gap is recorded as an open question in the roadmap spec and is out of scope here.

- [ ] **Step 4: Filter the status query**

In `app/Mcp/Tools/BrainStatusTool.php`, after `requireScope()` add:

```php
$allowedWings = $apiKey->allowedWingSlugs();
```

and constrain the wing listing:

```php
$wings = Wing::withCount(['rooms as drawer_count' => function ($query) {
    $query->join('drawers', 'drawers.room_id', '=', 'rooms.id')
        ->whereNull('drawers.deleted_at');
}])
    ->when($allowedWings !== null, fn ($q) => $q->whereIn('slug', $allowedWings))
    ->get()->map(fn ($w) => [
        'name' => $w->name,
        'slug' => $w->slug,
        'drawer_count' => (int) $w->drawer_count,
    ])->values()->all();
```

Leave `drawer_count` and `wiki_page_count` as global totals — they are aggregates that disclose no content, and making them per-key would misrepresent the size of the brain.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=McpAuthTest --compact`
Expected: PASS

- [ ] **Step 6: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint
git add app/Mcp/Tools/PalaceWakeUpTool.php app/Mcp/Tools/BrainStatusTool.php tests/Feature/McpAuthTest.php
git commit -m "fix: apply wing restrictions to wake_up and brain_status

Both returned content and wing enumerations across every wing regardless
of the key's restrictions."
```

---

### Task 6: Filter wiki source previews by allowed wings (D1)

`ContextGetTool` returns `source_details` — 200-character drawer previews — for any drawer id listed in a page's `sources`, gated on `palace:read` scope only (line 55). A restricted key reads previews of drawers in wings it cannot otherwise see.

**Files:**
- Modify: `app/Mcp/Tools/ContextGetTool.php:54-67`
- Test: `tests/Feature/McpAuthTest.php`

**Interfaces:**
- Consumes: `ApiKey::allowedWingSlugs(): ?array` from Task 3.
- Produces: nothing new.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/McpAuthTest.php`:

```php
public function test_context_get_hides_source_previews_from_forbidden_wings(): void
{
    $personal = Wing::create(['name' => 'Personal']);
    $room = Room::create(['wing_id' => $personal->id, 'name' => 'Notes', 'slug' => 'notes']);
    $drawer = Drawer::create(['content' => 'private diary entry', 'room_id' => $room->id]);

    WikiPage::create([
        'name' => 'concept:memory',
        'type' => 'concept',
        'title' => 'Memory',
        'content' => 'A page.',
        'sources' => [$drawer->id],
    ]);

    $key = ApiKey::create([
        'name' => 'Work Only Context',
        'key_hash' => hash('sha256', 'work-only-context'),
        'scopes' => ['*'],
        'wing_restrictions' => ['work'],
    ]);

    $result = app(ContextGetTool::class)->execute(['name' => 'concept:memory'], $key);

    $previews = array_column($result['source_details'] ?? [], 'content_preview');
    $this->assertNotContains('private diary entry', $previews);
}
```

Add `use App\Mcp\Tools\ContextGetTool;` and `use App\Models\WikiPage;` to the test file.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=McpAuthTest --compact`
Expected: FAIL — the private preview is present.

- [ ] **Step 3: Constrain the source lookup**

In `app/Mcp/Tools/ContextGetTool.php`, replace the `source_details` block:

```php
// Only include source_details if the API key has palace:read scope, and
// only for drawers in wings the key is allowed to see.
if (! empty($page->sources) && $apiKey->hasScope('palace:read')) {
    $allowedWings = $apiKey->allowedWingSlugs();

    $sourceDetails = [];
    $drawers = Drawer::with('room.wing')
        ->whereIn('id', $page->sources)
        ->when($allowedWings !== null, fn ($q) => $q->whereHas(
            'room.wing',
            fn ($w) => $w->whereIn('slug', $allowedWings)
        ))
        ->get();

    foreach ($drawers as $drawer) {
        $sourceDetails[] = [
            'id' => $drawer->id,
            'content_preview' => mb_substr($drawer->content, 0, 200),
            'source' => $drawer->source,
        ];
    }
    $result['source_details'] = $sourceDetails;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=McpAuthTest --compact`
Expected: PASS

- [ ] **Step 5: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
./vendor/bin/pint
git add app/Mcp/Tools/ContextGetTool.php tests/Feature/McpAuthTest.php
git commit -m "fix: filter wiki source_details previews by wing restrictions

context_get leaked drawer content previews from restricted wings via a
wiki page's sources list."
```

---

### Task 7: Correct tool scope classification (D3)

`WikiCompileTool::requiredScope()` returns `palace:read` but the tool mutates data — it promotes gathered drawers from `raw` to `reviewed`, changing subsequent tier-filtered search results. `README.md:97` already documents this tool as `wiki:write`. `WikiLintTool` requires only `wiki:read` but writes when `auto_fix` is true.

This matters because the shipped recommendation is a read-only agent key, which today can silently mutate drawer tiers. It also determines which tools may carry `#[IsReadOnly]` in piece 1.

**Files:**
- Modify: `app/Mcp/Tools/WikiCompileTool.php:16-23`
- Modify: `app/Mcp/Tools/WikiLintTool.php:16-37`
- Test: `tests/Feature/McpAuthTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing new.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/McpAuthTest.php`:

```php
public function test_read_only_key_cannot_run_wiki_compile(): void
{
    $key = ApiKey::create([
        'name' => 'Reader',
        'key_hash' => hash('sha256', 'reader-compile'),
        'scopes' => ['palace:read', 'wiki:read'],
    ]);

    $this->expectException(McpException::class);
    $this->expectExceptionMessage('wiki:write');

    app(WikiCompileTool::class)->execute(['name' => 'project:atlas'], $key);
}

public function test_read_only_key_can_lint_but_not_auto_fix(): void
{
    $key = ApiKey::create([
        'name' => 'Reader Lint',
        'key_hash' => hash('sha256', 'reader-lint'),
        'scopes' => ['palace:read', 'wiki:read'],
    ]);

    // Plain lint is permitted.
    $result = app(WikiLintTool::class)->execute([], $key);
    $this->assertIsArray($result);

    // auto_fix requires write.
    $this->expectException(McpException::class);
    $this->expectExceptionMessage('wiki:write');

    app(WikiLintTool::class)->execute(['auto_fix' => true], $key);
}
```

Add `use App\Mcp\Tools\WikiCompileTool;` and `use App\Mcp\Tools\WikiLintTool;` to the test file.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=McpAuthTest --compact`
Expected: FAIL — no exception is thrown; both tools run under read-only scopes.

- [ ] **Step 3: Correct the compile tool's scope**

In `app/Mcp/Tools/WikiCompileTool.php`:

```php
public function requiredScope(): string
{
    return 'wiki:write';
}
```

and change the guard at the top of `execute()` from `$this->requireScope($apiKey, 'palace:read');` to:

```php
$this->requireScope($apiKey, $this->requiredScope());
```

- [ ] **Step 4: Make the lint tool's scope conditional**

In `app/Mcp/Tools/WikiLintTool.php`, replace the opening of `execute()` so the write scope is demanded only when the tool will actually write:

```php
public function execute(array $params, ApiKey $apiKey): array
{
    $this->requireScope($apiKey, $this->requiredScope());

    $autoFix = (bool) ($params['auto_fix'] ?? false);

    if ($autoFix) {
        $this->requireScope($apiKey, 'wiki:write');
    }
```

Keep `requiredScope()` returning `wiki:read`. Remove any now-duplicated `$autoFix` assignment further down the method.

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=McpAuthTest --compact`
Expected: PASS

- [ ] **Step 6: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS. Tier 1 and Tier 3 tests call `wiki_compile`; any that construct a key without `wiki:write` must be updated to include it. That is a corrected expectation, not a regression — note it in the commit body.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint
git add app/Mcp/Tools/WikiCompileTool.php app/Mcp/Tools/WikiLintTool.php tests/Feature/McpAuthTest.php
git commit -m "fix: wiki_compile and wiki_lint now require write scope when they write

wiki_compile promotes drawers raw -> reviewed but required only
palace:read, so the recommended read-only agent key could mutate state.
wiki_lint now demands wiki:write only when auto_fix is set."
```

---

### Task 8: Extend brain_sessions to record outcomes (D4)

`BaseTool::logSession()` runs at the end of `execute()`, after every throw path, so denials are never recorded. `McpController::call()` logs nothing on error, and middleware rejections never reach a tool. `README.md:45` claims every invocation is logged. The table also declares `source` NOT NULL, which cannot hold a rejection that carried no valid key.

**Files:**
- Create: `database/migrations/2026_08_27_100000_add_outcome_to_brain_sessions_table.php`
- Modify: `app/Models/BrainSession.php:11-22`
- Test: `tests/Feature/BrainSessionTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `brain_sessions.outcome` (string, default `'success'`), `brain_sessions.error` (nullable text), and a nullable `source`. `BrainSession::$fillable` gains `outcome` and `error`. Task 9 writes to these.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/BrainSessionTest.php`:

```php
public function test_a_session_can_record_a_denial_without_a_source(): void
{
    $session = BrainSession::create([
        'tool_name' => 'drawer_search',
        'source' => null,
        'input' => ['query' => 'x'],
        'result_count' => 0,
        'outcome' => 'denied',
        'error' => 'API key missing required scope: palace:read',
    ]);

    $this->assertSame('denied', $session->fresh()->outcome);
    $this->assertNull($session->fresh()->source);
}

public function test_outcome_defaults_to_success(): void
{
    $session = BrainSession::create([
        'tool_name' => 'brain_status',
        'source' => 'Test Key',
        'input' => [],
        'result_count' => 1,
    ]);

    $this->assertSame('success', $session->fresh()->outcome);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=BrainSessionTest --compact`
Expected: FAIL — no `outcome` column; the null `source` violates NOT NULL.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_27_100000_add_outcome_to_brain_sessions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brain_sessions', function (Blueprint $table) {
            $table->string('outcome')->default('success')->after('tool_name');
            $table->text('error')->nullable()->after('result_count');
            $table->string('source')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('brain_sessions', function (Blueprint $table) {
            $table->dropColumn(['outcome', 'error']);
            $table->string('source')->nullable(false)->change();
        });
    }
};
```

`->change()` on SQLite requires `doctrine/dbal` on older Laravel versions; Laravel 13 handles column changes natively on SQLite, so no extra dependency is needed. If the migration fails on SQLite, run `php artisan migrate:fresh` in the test environment and confirm before proceeding.

- [ ] **Step 4: Add the new columns to the model**

In `app/Models/BrainSession.php`, extend `$fillable`:

```php
protected $fillable = [
    'tool_name',
    'source',
    'input',
    'result_count',
    'outcome',
    'error',
];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=BrainSessionTest --compact`
Expected: PASS

- [ ] **Step 6: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS. `MigrationTest` asserts table shapes — add `outcome` and `error` to its `brain_sessions` column expectations if it enumerates them.

- [ ] **Step 7: Commit**

```bash
./vendor/bin/pint
git add database/migrations/2026_08_27_100000_add_outcome_to_brain_sessions_table.php app/Models/BrainSession.php tests/Feature/BrainSessionTest.php
git commit -m "feat: record outcome and error on brain_sessions

Adds outcome (default 'success') and a nullable error column, and makes
source nullable so rejections that carried no valid key can be logged."
```

---

### Task 9: Log denials at every guard (D4)

**Files:**
- Modify: `app/Mcp/BaseTool.php:29-59`
- Modify: `app/Http/Middleware/AuthenticateApiKey.php:13-40`
- Modify: `app/Http/Controllers/McpController.php:41-55`
- Test: `tests/Feature/McpAuthTest.php`

**Interfaces:**
- Consumes: `brain_sessions.outcome` / `.error` from Task 8.
- Produces: `BaseTool::logDenial(string $toolName, ?string $source, array $input, string $reason): void` (protected).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/McpAuthTest.php`:

```php
public function test_scope_denial_is_recorded_in_the_audit_log(): void
{
    $key = ApiKey::create([
        'name' => 'No Write',
        'key_hash' => hash('sha256', 'no-write'),
        'scopes' => ['palace:read'],
    ]);

    try {
        app(DrawerAddTool::class)->execute(['content' => 'x', 'wing' => 'Work'], $key);
    } catch (McpException $e) {
        // expected
    }

    $session = BrainSession::where('tool_name', 'drawer_add')->latest('id')->first();

    $this->assertNotNull($session);
    $this->assertSame('denied', $session->outcome);
    $this->assertSame('No Write', $session->source);
    $this->assertStringContainsString('palace:write', $session->error);
}

public function test_invalid_api_key_is_recorded_in_the_audit_log(): void
{
    $this->postJson('/api/mcp/call', [
        'tool' => 'brain_status',
        'params' => [],
    ], ['X-API-Key' => 'not-a-real-key']);

    $session = BrainSession::latest('id')->first();

    $this->assertNotNull($session);
    $this->assertSame('denied', $session->outcome);
    $this->assertNull($session->source);
}
```

Add `use App\Models\BrainSession;` to the test file.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=McpAuthTest --compact`
Expected: FAIL — no `brain_sessions` row is written for either case.

- [ ] **Step 3: Add denial logging to the tool guards**

In `app/Mcp/BaseTool.php`, add the helper and use it in both guards. The guards do not currently know the tool name, so derive it from the class:

```php
/**
 * The registry name for this tool, derived from the class name.
 * DrawerSearchTool -> drawer_search
 */
protected function toolName(): string
{
    $short = class_basename(static::class);

    return Str::snake(Str::beforeLast($short, 'Tool'));
}

/**
 * Record a refused invocation. Denials are the invocations an audit trail
 * exists to capture, so they must be logged before the throw.
 *
 * @param  array<string, mixed>  $input
 */
protected function logDenial(string $toolName, ?string $source, array $input, string $reason): void
{
    BrainSession::create([
        'tool_name' => $toolName,
        'source' => $source,
        'input' => $input,
        'result_count' => 0,
        'outcome' => 'denied',
        'error' => $reason,
    ]);
}

protected function requireScope(ApiKey $apiKey, string $scope): void
{
    if (! $apiKey->hasScope($scope)) {
        $reason = "API key missing required scope: {$scope}";
        $this->logDenial($this->toolName(), $apiKey->name, [], $reason);

        throw McpException::forbidden($reason);
    }
}

protected function requireWingAccess(ApiKey $apiKey, string $wingSlug): void
{
    if (! $apiKey->canAccessWing($wingSlug)) {
        $reason = "API key does not have access to wing: {$wingSlug}";
        $this->logDenial($this->toolName(), $apiKey->name, ['wing' => $wingSlug], $reason);

        throw McpException::forbidden($reason);
    }
}
```

Add `use Illuminate\Support\Str;` to the file.

- [ ] **Step 4: Log rejected keys in the middleware**

In `app/Http/Middleware/AuthenticateApiKey.php`, record a denial before each 401 return. Add a private helper and call it in all three rejection branches:

```php
private function deny(Request $request, ?string $source, string $reason): Response
{
    BrainSession::create([
        'tool_name' => (string) ($request->json('tool') ?? 'unknown'),
        'source' => $source,
        'input' => (array) $request->json('params', []),
        'result_count' => 0,
        'outcome' => 'denied',
        'error' => $reason,
    ]);

    return response()->json(['error' => $reason], 401);
}
```

Replace the three `return response()->json([...], 401);` statements with, respectively:

```php
return $this->deny($request, null, 'Missing API key. Provide X-API-Key header.');
return $this->deny($request, null, 'Invalid API key.');
return $this->deny($request, $apiKey->name, 'API key has been revoked.');
```

Add `use App\Models\BrainSession;` to the file.

- [ ] **Step 5: Log errors in the REST controller**

In `app/Http/Controllers/McpController.php`, inside the `catch (McpException $e)` block, add this before the return.

The tool guards from Step 3 already log scope and wing denials before throwing, so this must skip forbidden errors (`-32003`) or every denial is recorded twice — once by the guard and once here:

```php
if ($e->getMcpCode() !== -32003) {
    BrainSession::create([
        'tool_name' => $tool,
        'source' => $apiKey->name,
        'input' => $params,
        'result_count' => 0,
        'outcome' => 'error',
        'error' => $e->getMessage(),
    ]);
}
```

Add `use App\Models\BrainSession;` to the file.

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=McpAuthTest --compact`
Expected: PASS

- [ ] **Step 7: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS. `BrainSessionTest` and the Filament `BrainSessionResourceTest` may assert row counts; denial rows now exist where they previously did not. Update counts to the corrected expectation.

- [ ] **Step 8: Commit**

```bash
./vendor/bin/pint
git add app/Mcp/BaseTool.php app/Http/Middleware/AuthenticateApiKey.php app/Http/Controllers/McpController.php tests/Feature/McpAuthTest.php
git commit -m "fix: record denials and errors in the audit trail

logSession() ran at the end of execute(), after every throw path, so the
audit log captured only successful invocations — precisely the opposite of
what an audit trail is for. Scope and wing denials, rejected keys, and
tool errors are now logged with an outcome."
```

---

## Completion

- [ ] Run the full suite one final time: `php artisan test --compact`
- [ ] Run `./vendor/bin/pint --test` and confirm it is clean
- [ ] Update `docs/superpowers/specs/2026-08-27-release-roadmap.md`: mark D1, D2, D3, D4 and D7 resolved, and record that the public-repo gate is now satisfied
- [ ] Note for piece 4: `README.md:44`, `:45`, `:97` and `:101` describe the pre-fix behaviour and still need correcting during the truth-up pass
- [ ] Note for piece 1: read/write classification is now settled, so `#[IsReadOnly]` may be applied to every tool except `drawer_add`, `context_set`, `wiki_compile`, and `wiki_lint`
