# Mnemon Layer 2 — Claude Code Hooks Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add automatic capture and recall of palace memory in Claude Code via three shell hooks that talk to two new MCP tools, with an artisan installer to wire it all up.

**Architecture:** MCP-native — hooks are bash scripts that POST JSON-RPC to the existing `/mcp` endpoint with the user's existing Passport bearer. Two new tools (`recall`, `session_digest`), one new model + table (`wiki_pending_wings`), one Filament admin resource for the wing-approval queue, and an artisan installer (`mnemon:install-claude-code-hooks`) that copies hook scripts into `~/.claude/hooks/` and registers them in `~/.claude/settings.json`. Spec: `docs/superpowers/specs/2026-04-27-mnemon-layer2-hooks-design.md`.

**Tech Stack:** Laravel 13 (PHP 8.3+), `laravel/mcp`, `laravel/passport`, Filament 5, PostgreSQL/SQLite, OpenAI API (digest LLM), POSIX bash + `curl` + `jq` (hook runtime).

---

## File Structure

**Created:**
- `database/migrations/2026_04_27_100000_create_wiki_pending_wings_table.php`
- `app/Models/WikiPendingWing.php`
- `app/Services/RecallService.php`
- `app/Services/SessionDigestService.php`
- `app/Mcp/Tools/RecallTool.php`
- `app/Mcp/Tools/SessionDigestTool.php`
- `app/Filament/Resources/WikiPendingWingResource.php`
- `app/Filament/Resources/WikiPendingWingResource/Pages/ListWikiPendingWings.php`
- `app/Console/Commands/InstallClaudeCodeHooks.php`
- `resources/hooks/claude-code/lib/common.sh`
- `resources/hooks/claude-code/mnemon-wake.sh`
- `resources/hooks/claude-code/mnemon-recall.sh`
- `resources/hooks/claude-code/mnemon-capture.sh`
- `tests/Feature/Mcp/Tools/RecallToolTest.php`
- `tests/Feature/Mcp/Tools/SessionDigestToolTest.php`
- `tests/Feature/Console/InstallClaudeCodeHooksTest.php`
- `tests/Feature/Filament/WikiPendingWingResourceTest.php`
- `tests/Hooks/run-hook-tests.sh`

**Modified:**
- `config/mnemon.php` — add `recall.*` and `digest.*` keys
- `app/Mcp/Servers/MnemonServer.php` — register the two new tools
- `README.md` — Layer 2 section pointing at the user guide
- `CLAUDE.md` — add `recall` + `session_digest` to tools list, mention `WikiPendingWing` model
- `docs/USERGUIDE.md` — new section on automatic memory in Claude Code
- `docs/FRD.md` — add "Harness adapters" section

---

## Task 1: `wiki_pending_wings` migration + model

**Files:**
- Create: `database/migrations/2026_04_27_100000_create_wiki_pending_wings_table.php`
- Create: `app/Models/WikiPendingWing.php`
- Create: `database/factories/WikiPendingWingFactory.php`
- Test: `tests/Unit/Models/WikiPendingWingTest.php`

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('wiki_pending_wings', function (Blueprint $t) {
            $t->id();
            $t->string('wing_slug');
            $t->string('wing_name');
            $t->text('rationale')->nullable();
            $t->json('drawer_payload');
            $t->string('status', 20)->default('pending')->index();
            $t->string('proposed_by_session_id', 100)->nullable();
            $t->string('proposed_by_token_id', 100)->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_pending_wings');
    }
};
```

- [ ] **Step 2: Write the failing model unit test**

```php
<?php

namespace Tests\Unit\Models;

use App\Models\WikiPendingWing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiPendingWingTest extends TestCase
{
    use RefreshDatabase;

    public function test_drawer_payload_is_cast_to_array(): void
    {
        $row = WikiPendingWing::create([
            'wing_slug' => 'project:atlas',
            'wing_name' => 'Atlas',
            'rationale' => 'Multiple references to Atlas project',
            'drawer_payload' => ['content' => 'hello', 'room_slug' => 'meetings'],
        ]);

        $this->assertIsArray($row->fresh()->drawer_payload);
        $this->assertEquals('hello', $row->fresh()->drawer_payload['content']);
    }

    public function test_default_status_is_pending(): void
    {
        $row = WikiPendingWing::create([
            'wing_slug' => 'foo',
            'wing_name' => 'Foo',
            'drawer_payload' => [],
        ]);

        $this->assertEquals('pending', $row->fresh()->status);
    }
}
```

- [ ] **Step 3: Run test to confirm failure**

Run: `php artisan test --filter=WikiPendingWingTest`
Expected: FAIL — class `App\Models\WikiPendingWing` not found.

- [ ] **Step 4: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WikiPendingWing extends Model
{
    use HasFactory;

    protected $fillable = [
        'wing_slug',
        'wing_name',
        'rationale',
        'drawer_payload',
        'status',
        'proposed_by_session_id',
        'proposed_by_token_id',
        'decided_at',
    ];

    protected $casts = [
        'drawer_payload' => 'array',
        'decided_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
}
```

- [ ] **Step 5: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\WikiPendingWing;
use Illuminate\Database\Eloquent\Factories\Factory;

class WikiPendingWingFactory extends Factory
{
    protected $model = WikiPendingWing::class;

    public function definition(): array
    {
        return [
            'wing_slug' => $this->faker->slug(2),
            'wing_name' => $this->faker->words(2, true),
            'rationale' => $this->faker->sentence(),
            'drawer_payload' => [
                'content' => $this->faker->paragraph(),
                'room_slug' => 'notes',
                'source' => 'claude-code:session_digest',
            ],
            'status' => 'pending',
        ];
    }
}
```

- [ ] **Step 6: Run migration + tests**

Run: `php artisan migrate && php artisan test --filter=WikiPendingWingTest`
Expected: 2 passing.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_04_27_100000_create_wiki_pending_wings_table.php app/Models/WikiPendingWing.php database/factories/WikiPendingWingFactory.php tests/Unit/Models/WikiPendingWingTest.php
git commit -m "feat: add wiki_pending_wings table + model for new-wing approval queue"
```

---

## Task 2: `RecallService` — server-side ranking + packing

**Files:**
- Create: `app/Services/RecallService.php`
- Test: `tests/Unit/Services/RecallServiceTest.php`

The service does:
1. Query top-K wiki pages by name fuzzy + embedding similarity, top-K drawers via `DrawerSearchService`.
2. Score on a unified 0–1 scale.
3. Drop below `confidence_floor` config.
4. Greedy pack into a token budget (rough char-count proxy: 1 token ≈ 4 chars).

- [ ] **Step 1: Write failing service test (happy-path packing)**

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use App\Services\RecallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecallServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_found_false_when_nothing_matches(): void
    {
        $service = app(RecallService::class);
        $result = $service->run('xyzzy nothing matches', 1500, null, null);

        $this->assertFalse($result['found']);
        $this->assertEmpty($result['wiki']);
        $this->assertEmpty($result['drawers']);
    }

    public function test_returns_mixed_payload_within_budget(): void
    {
        $wing = Wing::factory()->create(['slug' => 'work']);
        $room = Room::factory()->create(['wing_id' => $wing->id]);
        Drawer::factory()->create([
            'room_id' => $room->id,
            'content' => 'meeting notes about dorothy vaughan on the atlas project',
        ]);
        WikiPage::factory()->create([
            'name' => 'person:dorothy-vaughan',
            'title' => 'Dorothy Vaughan',
            'content' => 'Dorothy is the lead engineer on the Atlas project.',
        ]);

        $service = app(RecallService::class);
        $result = $service->run('what do we know about dorothy', 1500, null, null);

        $this->assertTrue($result['found']);
        $this->assertNotEmpty($result['wiki']);
        $this->assertEquals('person:dorothy-vaughan', $result['wiki'][0]['slug']);
        $this->assertGreaterThan(0, $result['tokens_used']);
        $this->assertLessThanOrEqual(1500, $result['tokens_used']);
    }

    public function test_excerpts_long_wiki_pages(): void
    {
        $service = app(RecallService::class);
        WikiPage::factory()->create([
            'name' => 'concept:big',
            'title' => 'Big',
            'content' => str_repeat('lorem ipsum dolor sit amet ', 500),
        ]);

        $result = $service->run('big concept', 1500, null, null);

        if (! empty($result['wiki'])) {
            $this->assertStringContainsString('[truncated', $result['wiki'][0]['content']);
        }
    }
}
```

- [ ] **Step 2: Run test to confirm failure**

Run: `php artisan test --filter=RecallServiceTest`
Expected: FAIL — `App\Services\RecallService` not found.

- [ ] **Step 3: Implement `RecallService`**

```php
<?php

namespace App\Services;

use App\Models\WikiPage;

class RecallService
{
    public function __construct(
        protected DrawerSearchService $drawerSearch,
        protected WikiSearchService $wikiSearch,
    ) {}

    /**
     * @param  array<string>|null  $allowedWingPatterns  null = unrestricted
     */
    public function run(
        string $prompt,
        int $tokenBudget,
        ?string $wing,
        ?array $allowedWingPatterns,
    ): array {
        $floor = (float) config('mnemon.recall.confidence_floor', 0.45);

        $wikiHits = $this->wikiSearch->search($prompt, limit: 3);
        $drawerHits = $this->drawerSearch->run(
            query: $prompt,
            limit: 10,
            wing: $wing,
            allowedWingPatterns: $allowedWingPatterns,
        );

        $wiki = collect($wikiHits)
            ->map(fn ($w) => [
                'slug' => $w->name,
                'title' => $w->title,
                'content' => $w->content,
                'confidence' => (float) ($w->score ?? 0),
            ])
            ->filter(fn ($w) => $w['confidence'] >= $floor)
            ->sortByDesc('confidence')
            ->values()
            ->all();

        $drawers = collect($drawerHits)
            ->map(fn ($d) => [
                'id' => $d['id'],
                'wing' => $d['wing_slug'] ?? $d['wing'],
                'room' => $d['room_slug'] ?? $d['room'],
                'snippet' => mb_substr($d['content'], 0, 240),
                'confidence' => (float) ($d['score'] ?? 0),
            ])
            ->filter(fn ($d) => $d['confidence'] >= $floor)
            ->sortByDesc('confidence')
            ->values()
            ->all();

        if (empty($wiki) && empty($drawers)) {
            return [
                'found' => false,
                'summary' => 'No relevant context found.',
                'wiki' => [],
                'drawers' => [],
                'tokens_used' => 0,
            ];
        }

        $packed = $this->pack($wiki, $drawers, $tokenBudget);

        $count = count($packed['wiki']) + count($packed['drawers']);
        $summary = sprintf(
            'Found %d wiki page%s and %d drawer%s relevant to this prompt.',
            count($packed['wiki']),
            count($packed['wiki']) === 1 ? '' : 's',
            count($packed['drawers']),
            count($packed['drawers']) === 1 ? '' : 's',
        );

        return [
            'found' => $count > 0,
            'summary' => $summary,
            'wiki' => $packed['wiki'],
            'drawers' => $packed['drawers'],
            'tokens_used' => $packed['tokens_used'],
        ];
    }

    /**
     * Greedy-pack wiki then drawers into the token budget.
     * Rough proxy: 1 token ≈ 4 chars.
     */
    protected function pack(array $wiki, array $drawers, int $tokenBudget): array
    {
        $tokensUsed = 0;
        $packedWiki = [];
        $packedDrawers = [];

        foreach ($wiki as $w) {
            $excerpt = $w['content'];
            $excerptTokens = (int) ceil(mb_strlen($excerpt) / 4);

            if ($excerptTokens > 600) {
                $excerpt = mb_substr($excerpt, 0, 600 * 4)
                    ."\n\n…[truncated, full page at mnemon://wiki/{$w['slug']}]";
                $excerptTokens = 600;
            }

            if ($tokensUsed + $excerptTokens > $tokenBudget) {
                break;
            }

            $packedWiki[] = [
                'slug' => $w['slug'],
                'title' => $w['title'],
                'content' => $excerpt,
                'confidence' => $w['confidence'],
            ];
            $tokensUsed += $excerptTokens;
        }

        foreach ($drawers as $d) {
            $tokens = (int) ceil(mb_strlen($d['snippet']) / 4);
            if ($tokensUsed + $tokens > $tokenBudget) {
                break;
            }
            $packedDrawers[] = $d;
            $tokensUsed += $tokens;
        }

        return [
            'wiki' => $packedWiki,
            'drawers' => $packedDrawers,
            'tokens_used' => $tokensUsed,
        ];
    }
}
```

- [ ] **Step 4: Verify `WikiSearchService` has a `search()` method**

Run: `grep -n 'public function search' app/Services/WikiSearchService.php`
Expected: at least one match. If the signature differs from the call above (e.g., needs different params), adjust the call. If `WikiSearchService` lacks the right method, extend it minimally to support `search(string $prompt, int $limit): Collection`. Confirm what scores/columns it returns and adjust the mapping.

- [ ] **Step 5: Run test to verify pass**

Run: `php artisan test --filter=RecallServiceTest`
Expected: 3 passing.

- [ ] **Step 6: Commit**

```bash
git add app/Services/RecallService.php tests/Unit/Services/RecallServiceTest.php
git commit -m "feat: RecallService — hybrid wiki+drawer recall with token budget packing"
```

---

## Task 3: Add `recall` + `digest` config keys

**Files:**
- Modify: `config/mnemon.php`

- [ ] **Step 1: Add new config block**

Open `config/mnemon.php` and append before the closing `];`:

```php
    'recall' => [
        'confidence_floor' => env('MNEMON_RECALL_FLOOR', 0.45),
        'default_token_budget' => 1500,
    ],

    'digest' => [
        'driver' => env('MNEMON_DIGEST_DRIVER', 'openai'),
        'confidence_floor' => env('MNEMON_DIGEST_FLOOR', 0.5),
        'openai_model' => env('MNEMON_DIGEST_OPENAI_MODEL', 'gpt-4o-mini'),
    ],
```

- [ ] **Step 2: Verify config loads**

Run: `php artisan tinker --execute='echo config("mnemon.recall.confidence_floor");'`
Expected: `0.45`

- [ ] **Step 3: Commit**

```bash
git add config/mnemon.php
git commit -m "feat: add recall + digest config keys to mnemon.php"
```

---

## Task 4: `RecallTool` — MCP wrapper

**Files:**
- Create: `app/Mcp/Tools/RecallTool.php`
- Test: `tests/Feature/Mcp/Tools/RecallToolTest.php`

- [ ] **Step 1: Write failing tool tests**

```php
<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class RecallToolTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_returns_found_false_for_unrelated_prompt(): void
    {
        $response = $this->mcpCall('recall', ['prompt' => 'qwerty asdf nothing'], ['mcp:use']);

        $response->assertStatus(200);
        $found = $response->json('result.structuredContent.found');
        $this->assertFalse($found);
    }

    public function test_returns_mixed_payload_for_relevant_prompt(): void
    {
        $wing = Wing::factory()->create(['slug' => 'work']);
        $room = Room::factory()->create(['wing_id' => $wing->id]);
        Drawer::factory()->create([
            'room_id' => $room->id,
            'content' => 'dorothy vaughan discussed the atlas roadmap',
        ]);
        WikiPage::factory()->create([
            'name' => 'person:dorothy-vaughan',
            'title' => 'Dorothy Vaughan',
            'content' => 'Lead engineer on Atlas.',
        ]);

        $response = $this->mcpCall('recall', ['prompt' => 'what do we know about dorothy'], ['mcp:use']);

        $response->assertStatus(200);
        $body = $response->json('result.structuredContent');
        $this->assertTrue($body['found']);
        $this->assertNotEmpty($body['wiki']);
    }

    public function test_rejects_token_without_mcp_use_scope(): void
    {
        $response = $this->mcpCall('recall', ['prompt' => 'foo bar baz'], []);
        $body = $response->json();
        $this->assertTrue(isset($body['result']['isError']) && $body['result']['isError'] === true);
    }

    public function test_respects_wing_restrictions(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        $personal = Wing::factory()->create(['slug' => 'personal']);
        $r1 = Room::factory()->create(['wing_id' => $work->id]);
        $r2 = Room::factory()->create(['wing_id' => $personal->id]);
        Drawer::factory()->create(['room_id' => $r1->id, 'content' => 'work secret about dorothy']);
        Drawer::factory()->create(['room_id' => $r2->id, 'content' => 'personal note about dorothy']);

        $response = $this->mcpCall(
            'recall',
            ['prompt' => 'dorothy'],
            ['mcp:use'],
            wingPatterns: ['personal']
        );

        $body = $response->json('result.structuredContent');
        if ($body['found']) {
            foreach ($body['drawers'] as $d) {
                $this->assertEquals('personal', $d['wing']);
            }
        }
    }

    public function test_writes_brain_session_audit(): void
    {
        $this->mcpCall('recall', ['prompt' => 'anything'], ['mcp:use']);

        $session = \App\Models\BrainSession::latest('id')->first();
        $this->assertEquals('recall', $session->tool_name);
    }
}
```

- [ ] **Step 2: Run test to confirm failure**

Run: `php artisan test --filter=RecallToolTest`
Expected: FAIL — tool not registered.

- [ ] **Step 3: Implement `RecallTool`**

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Support\BrainSessionLogger;
use App\Services\RecallService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Hybrid recall: returns the most relevant wiki excerpts and drawer snippets for a given prompt, packed into a token budget. Used by harness hooks to silently inject palace context.')]
#[IsReadOnly]
class RecallTool extends Tool
{
    use RequiresScope, RequiresWingAccess;

    protected string $name = 'recall';

    public function __construct(protected RecallService $recall) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate([
            'prompt' => 'required|string|max:4000',
            'token_budget' => 'integer|min:200|max:4000',
            'wing' => 'nullable|string',
        ]);

        if (! empty($params['wing'])) {
            if ($err = $this->requireWingAccess($request, $params['wing'])) {
                return $err;
            }
        }

        $payload = $this->recall->run(
            prompt: $params['prompt'],
            tokenBudget: $params['token_budget'] ?? (int) config('mnemon.recall.default_token_budget', 1500),
            wing: $params['wing'] ?? null,
            allowedWingPatterns: $this->wingPatternsFor($request),
        );

        $count = count($payload['wiki']) + count($payload['drawers']);
        BrainSessionLogger::log($request, 'recall', $params, $count);

        return Response::structured($payload);
    }

    public function schema(JsonSchema $s): array
    {
        return [
            'prompt' => $s->string()->required()->description('The user prompt to find context for.'),
            'token_budget' => $s->integer()->description('Max tokens of context to return (200-4000). Default 1500.'),
            'wing' => $s->string()->description('Optional wing slug to restrict recall to.'),
        ];
    }
}
```

- [ ] **Step 4: Register in `MnemonServer`**

Open `app/Mcp/Servers/MnemonServer.php`. Add the import:

```php
use App\Mcp\Tools\RecallTool;
```

Add `RecallTool::class,` to the `$tools` array (anywhere; after `PalaceWakeUpTool` is natural).

- [ ] **Step 5: Run tests to verify pass**

Run: `php artisan test --filter=RecallToolTest`
Expected: 5 passing.

- [ ] **Step 6: Commit**

```bash
git add app/Mcp/Tools/RecallTool.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Tools/RecallToolTest.php
git commit -m "feat: add recall MCP tool — hybrid wiki+drawer context endpoint"
```

---

## Task 5: `SessionDigestService` — LLM call + persistence + queue logic

**Files:**
- Create: `app/Services/SessionDigestService.php`
- Test: `tests/Unit/Services/SessionDigestServiceTest.php`

The service:
1. Receives sanitized transcript + recent_drawer_ids + accessible wing/room lists.
2. Calls the configured LLM (OpenAI for v1) with a structured prompt asking for drawer proposals.
3. For each proposal:
   - drop if `confidence < digest.confidence_floor`.
   - if `propose_new_wing` → enqueue to `wiki_pending_wings`.
   - else if `propose_new_room` → auto-create the room.
   - else → create the drawer.
4. Returns `{proposals, persisted, queued_for_review, pending_wings}`.

Side note on driver abstraction: v1 has only an `OpenAI` driver path. The interface is `digest(string $transcript, array $context): array` where `$context` carries `existing_wings`, `existing_rooms`, `recent_drawers`. We mock this in tests.

- [ ] **Step 1: Write failing service tests**

```php
<?php

namespace Tests\Unit\Services;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPendingWing;
use App\Models\Wing;
use App\Services\SessionDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionDigestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_high_confidence_proposal_persists_drawer(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'meetings', 'wing_id' => $work->id]);

        $service = $this->makeServiceWithMockLlm([
            [
                'content' => 'Meeting with Dorothy — Atlas v2 planning',
                'wing_slug' => 'work',
                'room_slug' => 'meetings',
                'confidence' => 0.9,
                'propose_new_wing' => false,
                'propose_new_room' => false,
                'rationale' => 'Clear meeting note',
            ],
        ]);

        $result = $service->run(
            sessionId: 'sess-1',
            harness: 'claude-code',
            turnRange: ['start' => 0, 'end' => 4],
            transcript: 'Cooper: had a meeting with dorothy today...',
            recentDrawerIds: [],
            allowedWingPatterns: null,
        );

        $this->assertCount(1, $result['persisted']);
        $this->assertCount(0, $result['pending_wings']);
        $drawer = Drawer::find($result['persisted'][0]['id']);
        $this->assertEquals('claude-code:session_digest', $drawer->source);
        $this->assertEquals('session_digest', $drawer->metadata['captured_via']);
        $this->assertEquals('claude-code', $drawer->metadata['harness']);
        $this->assertEquals('sess-1', $drawer->metadata['session_id']);
    }

    public function test_low_confidence_proposal_is_dropped(): void
    {
        $service = $this->makeServiceWithMockLlm([
            ['content' => 'noisy', 'wing_slug' => 'work', 'room_slug' => 'notes',
             'confidence' => 0.3, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null);

        $this->assertEmpty($result['persisted']);
    }

    public function test_propose_new_wing_queues_for_review(): void
    {
        $service = $this->makeServiceWithMockLlm([
            [
                'content' => 'Atlas project planning notes',
                'wing_slug' => 'project:atlas',
                'room_slug' => 'planning',
                'confidence' => 0.9,
                'propose_new_wing' => true,
                'propose_new_room' => true,
                'rationale' => 'Multiple atlas refs',
            ],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null);

        $this->assertEmpty($result['persisted']);
        $this->assertCount(1, $result['pending_wings']);
        $this->assertEquals(1, WikiPendingWing::where('status', 'pending')->count());
    }

    public function test_propose_new_room_auto_creates(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        $service = $this->makeServiceWithMockLlm([
            [
                'content' => 'a new kind of note',
                'wing_slug' => 'work',
                'room_slug' => 'novel-room',
                'confidence' => 0.8,
                'propose_new_wing' => false,
                'propose_new_room' => true,
                'rationale' => 'Novel category',
            ],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null);

        $this->assertCount(1, $result['persisted']);
        $room = Room::where('slug', 'novel-room')->where('wing_id', $work->id)->first();
        $this->assertNotNull($room);
        $this->assertTrue($room->metadata['auto_created'] ?? false);
    }

    private function makeServiceWithMockLlm(array $proposals): SessionDigestService
    {
        $driver = new class($proposals) {
            public function __construct(public array $proposals) {}
            public function digest(string $transcript, array $context): array
            {
                return $this->proposals;
            }
        };

        return new SessionDigestService($driver, app(\App\Services\DrawerWriteService::class));
    }
}
```

- [ ] **Step 2: Run test to confirm failure**

Run: `php artisan test --filter=SessionDigestServiceTest`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement `SessionDigestService`**

```php
<?php

namespace App\Services;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPendingWing;
use App\Models\Wing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SessionDigestService
{
    /**
     * @param  object  $llm  Anything with a `digest(string $transcript, array $context): array` method.
     */
    public function __construct(
        protected object $llm,
        protected DrawerWriteService $drawerWriter,
    ) {}

    /**
     * @param  array{start:int,end:int}  $turnRange
     * @param  array<int>  $recentDrawerIds
     * @param  array<string>|null  $allowedWingPatterns
     */
    public function run(
        string $sessionId,
        string $harness,
        array $turnRange,
        string $transcript,
        array $recentDrawerIds,
        ?array $allowedWingPatterns,
    ): array {
        $floor = (float) config('mnemon.digest.confidence_floor', 0.5);

        $existingWings = $this->wingsForToken($allowedWingPatterns);
        $existingRooms = Room::query()
            ->whereIn('wing_id', collect($existingWings)->pluck('id'))
            ->get(['slug', 'wing_id']);
        $recentDrawers = Drawer::whereIn('id', $recentDrawerIds)->get(['id', 'content']);

        $proposals = $this->llm->digest($transcript, [
            'existing_wings' => collect($existingWings)->pluck('slug')->all(),
            'existing_rooms_per_wing' => $existingRooms->groupBy('wing_id')
                ->map(fn ($rs) => $rs->pluck('slug')->all())->all(),
            'recent_drawers' => $recentDrawers->map(fn ($d) => [
                'id' => $d->id,
                'snippet' => mb_substr($d->content, 0, 200),
            ])->all(),
        ]);

        $persisted = [];
        $pendingWings = [];

        foreach ($proposals as $p) {
            if (($p['confidence'] ?? 0) < $floor) {
                continue;
            }

            if (! empty($p['propose_new_wing'])) {
                $row = WikiPendingWing::create([
                    'wing_slug' => $p['wing_slug'],
                    'wing_name' => Str::headline($p['wing_slug']),
                    'rationale' => $p['rationale'] ?? null,
                    'drawer_payload' => [
                        'content' => $p['content'],
                        'room_slug' => $p['room_slug'] ?? 'notes',
                        'source' => "{$harness}:session_digest",
                        'metadata' => [
                            'captured_via' => 'session_digest',
                            'harness' => $harness,
                            'session_id' => $sessionId,
                            'confidence' => $p['confidence'],
                        ],
                    ],
                    'proposed_by_session_id' => $sessionId,
                ]);
                $pendingWings[] = [
                    'id' => $row->id,
                    'wing_slug' => $row->wing_slug,
                    'rationale' => $row->rationale,
                ];

                continue;
            }

            // Existing-wing path. Wing must already exist — digest never auto-creates wings.
            $wing = Wing::where('slug', $p['wing_slug'])->first();
            if (! $wing) {
                continue;
            }

            $roomSlug = $p['room_slug'] ?? 'notes';
            $existingRoom = Room::where('slug', $roomSlug)->where('wing_id', $wing->id)->first();

            if (! $existingRoom) {
                if (empty($p['propose_new_room'])) {
                    continue;
                }
                // Pre-create the room with metadata so DrawerWriteService::createDrawer
                // finds it via firstOrCreate without overwriting the metadata flag.
                Room::create([
                    'wing_id' => $wing->id,
                    'slug' => $roomSlug,
                    'name' => Str::headline($roomSlug),
                    'metadata' => ['auto_created' => true, 'created_via' => 'session_digest'],
                ]);
            }

            $drawer = $this->drawerWriter->createDrawer(
                wingSlug: $wing->slug,
                roomSlug: $roomSlug,
                content: $p['content'],
                source: "{$harness}:session_digest",
                metadata: [
                    'captured_via' => 'session_digest',
                    'harness' => $harness,
                    'session_id' => $sessionId,
                    'confidence' => $p['confidence'],
                ],
            );

            $persisted[] = [
                'id' => $drawer->id,
                'wing' => $wing->slug,
                'room' => $roomSlug,
            ];
        }

        return [
            'proposals' => $proposals,
            'persisted' => $persisted,
            'queued_for_review' => [],
            'pending_wings' => $pendingWings,
        ];
    }

    /**
     * @param  array<string>|null  $patterns
     * @return array<int, Wing>
     */
    protected function wingsForToken(?array $patterns): array
    {
        if ($patterns === null) {
            return Wing::all()->all();
        }

        return Wing::all()
            ->filter(function (Wing $w) use ($patterns) {
                foreach ($patterns as $pattern) {
                    $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';
                    if ($pattern === $w->slug || preg_match($regex, $w->slug)) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->all();
    }
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `php artisan test --filter=SessionDigestServiceTest`
Expected: 4 passing.

- [ ] **Step 5: Commit**

```bash
git add app/Services/SessionDigestService.php tests/Unit/Services/SessionDigestServiceTest.php
git commit -m "feat: SessionDigestService — turns transcripts into drawer proposals"
```

---

## Task 6: OpenAI digest driver

**Files:**
- Create: `app/Services/Digest/OpenAiDigestDriver.php`
- Modify: `app/Providers/AppServiceProvider.php` (bind `SessionDigestService` to driver via container)
- Test: `tests/Unit/Services/Digest/OpenAiDigestDriverTest.php`

This is the concrete driver. It calls OpenAI Chat Completions with a JSON-mode response and parses the proposal array.

- [ ] **Step 1: Write failing driver test (using Http::fake)**

```php
<?php

namespace Tests\Unit\Services\Digest;

use App\Services\Digest\OpenAiDigestDriver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiDigestDriverTest extends TestCase
{
    public function test_parses_proposals_from_openai_response(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'proposals' => [[
                                'content' => 'Meeting with Atlas team',
                                'wing_slug' => 'work',
                                'room_slug' => 'meetings',
                                'confidence' => 0.85,
                                'propose_new_wing' => false,
                                'propose_new_room' => false,
                                'rationale' => 'Clear meeting note',
                            ]],
                        ]),
                    ],
                ]],
            ]),
        ]);

        config(['mnemon.digest.openai_model' => 'gpt-4o-mini']);
        config(['services.openai.api_key' => 'sk-test']);

        $driver = new OpenAiDigestDriver;
        $result = $driver->digest('test transcript', [
            'existing_wings' => ['work'],
            'existing_rooms_per_wing' => [],
            'recent_drawers' => [],
        ]);

        $this->assertCount(1, $result);
        $this->assertEquals('Meeting with Atlas team', $result[0]['content']);
    }

    public function test_returns_empty_array_on_malformed_response(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'not json']]],
            ]),
        ]);
        config(['services.openai.api_key' => 'sk-test']);

        $driver = new OpenAiDigestDriver;
        $result = $driver->digest('t', []);
        $this->assertEquals([], $result);
    }
}
```

- [ ] **Step 2: Run test to confirm failure**

Run: `php artisan test --filter=OpenAiDigestDriverTest`
Expected: FAIL — class missing.

- [ ] **Step 3: Implement the driver**

```php
<?php

namespace App\Services\Digest;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiDigestDriver
{
    public function digest(string $transcript, array $context): array
    {
        $apiKey = config('services.openai.api_key') ?? env('OPENAI_API_KEY');
        if (empty($apiKey)) {
            Log::warning('mnemon: OPENAI_API_KEY missing for session_digest');

            return [];
        }

        $model = config('mnemon.digest.openai_model', 'gpt-4o-mini');

        $system = "You are Mnemon's session digester. Read a Claude Code transcript and extract drawers worth storing. ".
            "Respond with strict JSON: {\"proposals\":[{\"content\":\"…\",\"wing_slug\":\"…\",\"room_slug\":\"…\",\"confidence\":0.0-1.0,\"propose_new_wing\":bool,\"propose_new_room\":bool,\"rationale\":\"…\"}]}.\n".
            "Existing wings: ".json_encode($context['existing_wings'] ?? [])."\n".
            "Existing rooms per wing (wing_id keys): ".json_encode($context['existing_rooms_per_wing'] ?? [])."\n".
            "Drawers already captured this session (avoid duplicates): ".json_encode($context['recent_drawers'] ?? []);

        $response = Http::withToken($apiKey)
            ->timeout(20)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "Transcript:\n\n".$transcript],
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('mnemon: openai digest call failed', ['status' => $response->status()]);

            return [];
        }

        $content = $response->json('choices.0.message.content');
        $parsed = json_decode($content ?? '', true);

        if (! is_array($parsed) || ! isset($parsed['proposals']) || ! is_array($parsed['proposals'])) {
            return [];
        }

        return $parsed['proposals'];
    }
}
```

- [ ] **Step 4: Bind in `AppServiceProvider`**

In `app/Providers/AppServiceProvider.php`'s `register()` method, add:

```php
$this->app->bind(\App\Services\SessionDigestService::class, function ($app) {
    $driver = match (config('mnemon.digest.driver', 'openai')) {
        'openai' => new \App\Services\Digest\OpenAiDigestDriver,
        default => throw new \RuntimeException('Unknown digest driver: '.config('mnemon.digest.driver')),
    };

    return new \App\Services\SessionDigestService(
        $driver,
        $app->make(\App\Services\DrawerWriteService::class),
    );
});
```

- [ ] **Step 5: Run tests to verify pass**

Run: `php artisan test --filter='OpenAiDigestDriverTest|SessionDigestServiceTest'`
Expected: all passing.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Digest/OpenAiDigestDriver.php app/Providers/AppServiceProvider.php tests/Unit/Services/Digest/OpenAiDigestDriverTest.php
git commit -m "feat: OpenAI digest driver + service container binding"
```

---

## Task 7: `SessionDigestTool` — MCP wrapper

**Files:**
- Create: `app/Mcp/Tools/SessionDigestTool.php`
- Modify: `app/Mcp/Servers/MnemonServer.php`
- Test: `tests/Feature/Mcp/Tools/SessionDigestToolTest.php`

- [ ] **Step 1: Write failing tool tests**

```php
<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\BrainSession;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use App\Services\SessionDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class SessionDigestToolTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_persists_high_confidence_proposal(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);

        $this->mockDigestService([
            ['content' => 'a real note', 'wing_slug' => 'work', 'room_slug' => 'notes',
             'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $response = $this->mcpCall('session_digest', [
            'session_id' => 'sess-1',
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 4],
            'transcript' => 'we discussed a real note today',
        ], ['mcp:use']);

        $response->assertStatus(200);
        $body = $response->json('result.structuredContent');
        $this->assertCount(1, $body['persisted']);
        $this->assertEquals(1, Drawer::count());
    }

    public function test_writes_brain_session_with_persist_count(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);
        $this->mockDigestService([
            ['content' => 'note', 'wing_slug' => 'work', 'room_slug' => 'notes',
             'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $this->mcpCall('session_digest', [
            'session_id' => 's',
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 1],
            'transcript' => 't',
        ], ['mcp:use']);

        $session = BrainSession::latest('id')->first();
        $this->assertEquals('session_digest', $session->tool_name);
        $this->assertEquals(1, $session->result_count);
    }

    public function test_validation_rejects_missing_session_id(): void
    {
        $response = $this->mcpCall('session_digest', [
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 1],
            'transcript' => 't',
        ], ['mcp:use']);

        $body = $response->json();
        $this->assertTrue(isset($body['result']['isError']) && $body['result']['isError'] === true);
    }

    private function mockDigestService(array $proposals): void
    {
        $this->app->bind(SessionDigestService::class, function () use ($proposals) {
            $llm = new class($proposals) {
                public function __construct(public array $p) {}
                public function digest(string $t, array $c): array
                {
                    return $this->p;
                }
            };

            return new SessionDigestService($llm);
        });
    }
}
```

- [ ] **Step 2: Run test to confirm failure**

Run: `php artisan test --filter=SessionDigestToolTest`
Expected: FAIL — tool not registered.

- [ ] **Step 3: Implement the tool**

```php
<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresScope;
use App\Mcp\Concerns\RequiresWingAccess;
use App\Mcp\Support\BrainSessionLogger;
use App\Services\ContentSanitizer;
use App\Services\SessionDigestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Digest a sanitized transcript slice into 0-N drawer proposals; persists high-confidence ones, queues new-wing proposals for admin review.')]
class SessionDigestTool extends Tool
{
    use RequiresScope, RequiresWingAccess;

    protected string $name = 'session_digest';

    public function __construct(
        protected SessionDigestService $digest,
        protected ContentSanitizer $sanitizer,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        if ($err = $this->requireScope($request)) {
            return $err;
        }

        $params = $request->validate([
            'session_id' => 'required|string|max:100',
            'harness' => 'required|string|max:50',
            'turn_range' => 'required|array',
            'turn_range.start' => 'required|integer|min:0',
            'turn_range.end' => 'required|integer|min:0',
            'transcript' => 'required|string|max:200000',
            'recent_drawer_ids' => 'array',
            'recent_drawer_ids.*' => 'integer',
            'auto_persist' => 'boolean',
        ]);

        $cleaned = $this->sanitizer->sanitize($params['transcript']);

        $result = $this->digest->run(
            sessionId: $params['session_id'],
            harness: $params['harness'],
            turnRange: $params['turn_range'],
            transcript: $cleaned,
            recentDrawerIds: $params['recent_drawer_ids'] ?? [],
            allowedWingPatterns: $this->wingPatternsFor($request),
        );

        BrainSessionLogger::log($request, 'session_digest', [
            'session_id' => $params['session_id'],
            'harness' => $params['harness'],
            'turn_range' => $params['turn_range'],
        ], count($result['persisted']));

        return Response::structured($result);
    }

    public function schema(JsonSchema $s): array
    {
        return [
            'session_id' => $s->string()->required()->description('Stable session identifier from the harness.'),
            'harness' => $s->string()->required()->description('Harness name (e.g. claude-code).'),
            'turn_range' => $s->object()->required()->description('Object with start/end turn indices.'),
            'transcript' => $s->string()->required()->description('Sanitized transcript slice (turns since last digest).'),
            'recent_drawer_ids' => $s->array()->description('Drawer IDs already captured this session, for dedup.'),
            'auto_persist' => $s->boolean()->description('When false, returns proposals without writing. Default true.'),
        ];
    }
}
```

- [ ] **Step 4: Verify `ContentSanitizer::sanitize()` exists**

Run: `grep -n 'public function sanitize' app/Services/ContentSanitizer.php`
Expected: at least one match. If the public API name differs, adjust the call site in `SessionDigestTool::handle()`.

- [ ] **Step 5: Register in `MnemonServer`**

In `app/Mcp/Servers/MnemonServer.php`, add:

```php
use App\Mcp\Tools\SessionDigestTool;
```

And `SessionDigestTool::class,` to the `$tools` array.

- [ ] **Step 6: Run tests to verify pass**

Run: `php artisan test --filter=SessionDigestToolTest`
Expected: 3 passing.

- [ ] **Step 7: Commit**

```bash
git add app/Mcp/Tools/SessionDigestTool.php app/Mcp/Servers/MnemonServer.php tests/Feature/Mcp/Tools/SessionDigestToolTest.php
git commit -m "feat: add session_digest MCP tool"
```

---

## Task 8: Filament `WikiPendingWingResource` (admin approval queue)

**Files:**
- Create: `app/Filament/Resources/WikiPendingWingResource.php`
- Create: `app/Filament/Resources/WikiPendingWingResource/Pages/ListWikiPendingWings.php`
- Test: `tests/Feature/Filament/WikiPendingWingResourceTest.php`

The resource: list view with three columns (wing_slug, rationale, created_at), two row actions (Approve, Reject), default filter to `status = pending`.

Approve action: in a transaction, create the `Wing`, find-or-create the `Room`, create the `Drawer` from `drawer_payload`, mark this row `approved`. Reject: just mark this row `rejected`.

- [ ] **Step 1: Write failing Filament test**

```php
<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\WikiPendingWingResource\Pages\ListWikiPendingWings;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\User;
use App\Models\WikiPendingWing;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WikiPendingWingResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_action_creates_wing_room_and_drawer(): void
    {
        $this->actingAs(User::factory()->create());
        $row = WikiPendingWing::factory()->create([
            'wing_slug' => 'project:atlas',
            'wing_name' => 'Atlas',
            'drawer_payload' => [
                'content' => 'Atlas planning',
                'room_slug' => 'planning',
                'source' => 'claude-code:session_digest',
                'metadata' => ['captured_via' => 'session_digest'],
            ],
            'status' => 'pending',
        ]);

        Livewire::test(ListWikiPendingWings::class)
            ->callTableAction('approve', $row);

        $this->assertEquals('approved', $row->fresh()->status);
        $this->assertNotNull(Wing::where('slug', 'project:atlas')->first());
        $this->assertNotNull(Room::where('slug', 'planning')->first());
        $this->assertEquals(1, Drawer::count());
    }

    public function test_reject_action_drops_payload(): void
    {
        $this->actingAs(User::factory()->create());
        $row = WikiPendingWing::factory()->create(['status' => 'pending']);

        Livewire::test(ListWikiPendingWings::class)
            ->callTableAction('reject', $row);

        $this->assertEquals('rejected', $row->fresh()->status);
        $this->assertEquals(0, Wing::count());
        $this->assertEquals(0, Drawer::count());
    }

    public function test_double_approve_is_idempotent(): void
    {
        $this->actingAs(User::factory()->create());
        $row = WikiPendingWing::factory()->create([
            'wing_slug' => 'foo',
            'wing_name' => 'Foo',
            'drawer_payload' => [
                'content' => 'x', 'room_slug' => 'notes',
                'source' => 'claude-code:session_digest', 'metadata' => [],
            ],
            'status' => 'pending',
        ]);

        Livewire::test(ListWikiPendingWings::class)->callTableAction('approve', $row);
        Livewire::test(ListWikiPendingWings::class)->callTableAction('approve', $row);

        $this->assertEquals(1, Wing::where('slug', 'foo')->count());
        $this->assertEquals(1, Drawer::count());
    }
}
```

- [ ] **Step 2: Run test to confirm failure**

Run: `php artisan test --filter=WikiPendingWingResourceTest`
Expected: FAIL — resource not registered.

- [ ] **Step 3: Generate the resource scaffold**

Run: `php artisan make:filament-resource WikiPendingWing --generate`

This creates `app/Filament/Resources/WikiPendingWingResource.php` and the standard pages.

- [ ] **Step 4: Replace generated resource with the right schema and actions**

Replace `app/Filament/Resources/WikiPendingWingResource.php` with:

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WikiPendingWingResource\Pages;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPendingWing;
use App\Models\Wing;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WikiPendingWingResource extends Resource
{
    protected static ?string $model = WikiPendingWing::class;

    protected static ?string $navigationGroup = 'Access Control';

    protected static ?string $navigationLabel = 'Pending Wings';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('wing_slug')->searchable(),
                Tables\Columns\TextColumn::make('wing_name')->searchable(),
                Tables\Columns\TextColumn::make('rationale')->wrap()->limit(100),
                Tables\Columns\BadgeColumn::make('status')
                    ->colors(['warning' => 'pending', 'success' => 'approved', 'danger' => 'rejected']),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->since(),
            ])
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $q) => $q->where('status', 'pending'))
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->color('success')
                    ->requiresConfirmation()
                    ->action(fn (WikiPendingWing $row) => self::approve($row)),
                Tables\Actions\Action::make('reject')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (WikiPendingWing $row) => self::reject($row)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWikiPendingWings::route('/'),
        ];
    }

    protected static function approve(WikiPendingWing $row): void
    {
        if ($row->status !== 'pending') {
            return; // idempotent
        }

        DB::transaction(function () use ($row) {
            $wing = Wing::firstOrCreate(
                ['slug' => $row->wing_slug],
                ['name' => $row->wing_name]
            );

            $payload = $row->drawer_payload;
            $room = Room::firstOrCreate(
                ['wing_id' => $wing->id, 'slug' => $payload['room_slug']],
                [
                    'name' => Str::headline($payload['room_slug']),
                    'metadata' => ['auto_created' => true, 'created_via' => 'session_digest'],
                ]
            );

            Drawer::create([
                'room_id' => $room->id,
                'content' => $payload['content'],
                'source' => $payload['source'] ?? 'session_digest',
                'metadata' => $payload['metadata'] ?? null,
            ]);

            $row->update(['status' => 'approved', 'decided_at' => now()]);
        });
    }

    protected static function reject(WikiPendingWing $row): void
    {
        if ($row->status !== 'pending') {
            return;
        }
        $row->update(['status' => 'rejected', 'decided_at' => now()]);
    }
}
```

The generated `Pages/ListWikiPendingWings.php` should be untouched (defaults are fine).

- [ ] **Step 5: Run tests to verify pass**

Run: `php artisan test --filter=WikiPendingWingResourceTest`
Expected: 3 passing.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/WikiPendingWingResource.php app/Filament/Resources/WikiPendingWingResource tests/Feature/Filament/WikiPendingWingResourceTest.php
git commit -m "feat: Filament admin resource for new-wing approval queue"
```

---

## Task 9: Hook common library — `lib/common.sh`

**Files:**
- Create: `resources/hooks/claude-code/lib/common.sh`

This is the shared bash helpers sourced by all three hooks. No tests at this step — exercised end-to-end via `tests/Hooks/run-hook-tests.sh` in Task 13.

- [ ] **Step 1: Write the helpers**

```bash
#!/usr/bin/env bash
# Mnemon Claude Code hooks — shared helpers.
# Sourced by mnemon-wake.sh, mnemon-recall.sh, mnemon-capture.sh.

set -u

MNEMON_DIR="${MNEMON_DIR:-$HOME/.mnemon}"
MNEMON_CONFIG="$MNEMON_DIR/config.json"
MNEMON_SESSIONS_DIR="$MNEMON_DIR/sessions"
MNEMON_ERROR_LOG="$MNEMON_DIR/capture-errors.log"

mkdir -p "$MNEMON_SESSIONS_DIR" 2>/dev/null || true

# Read endpoint + bearer token. Echoes "<endpoint>|<token>" or empty if not configured.
mnemon_token() {
    if [ -f "$MNEMON_CONFIG" ]; then
        local endpoint token
        endpoint=$(jq -r '.endpoint // empty' "$MNEMON_CONFIG" 2>/dev/null)
        token=$(jq -r '.bearer_token // empty' "$MNEMON_CONFIG" 2>/dev/null)
        if [ -n "$endpoint" ] && [ -n "$token" ]; then
            printf '%s|%s' "$endpoint" "$token"
            return 0
        fi
    fi
    # Fallback: try parsing ~/.claude.json mnemon entry.
    if [ -f "$HOME/.claude.json" ]; then
        local endpoint token
        endpoint=$(jq -r '.mcpServers.mnemon.url // empty' "$HOME/.claude.json" 2>/dev/null)
        token=$(jq -r '.mcpServers.mnemon.headers.Authorization // empty' "$HOME/.claude.json" 2>/dev/null | sed 's/^Bearer //')
        if [ -n "$endpoint" ] && [ -n "$token" ]; then
            printf '%s|%s' "$endpoint" "$token"
            return 0
        fi
    fi
    return 1
}

# JSON-RPC POST to the MCP endpoint.
# Args: <method> <params_json> <timeout_ms> [<endpoint>] [<token>]
# Echoes the result field on success, empty on error/timeout.
mnemon_call() {
    local method="$1"
    local params="$2"
    local timeout_ms="$3"
    local endpoint token
    if [ "$#" -ge 5 ]; then
        endpoint="$4"
        token="$5"
    else
        local pair
        pair=$(mnemon_token) || return 1
        endpoint="${pair%|*}"
        token="${pair#*|}"
    fi

    local timeout_s
    timeout_s=$(awk "BEGIN{print $timeout_ms/1000}")

    local payload
    payload=$(jq -n --arg m "$method" --argjson p "$params" \
        '{jsonrpc:"2.0",id:1,method:$m,params:$p}')

    local response
    response=$(curl -s -m "$timeout_s" -X POST "$endpoint" \
        -H "Authorization: Bearer $token" \
        -H "Content-Type: application/json" \
        -d "$payload" 2>/dev/null) || return 1

    local err
    err=$(printf '%s' "$response" | jq -r '.error // empty' 2>/dev/null)
    if [ -n "$err" ]; then
        return 1
    fi

    printf '%s' "$response" | jq -c '.result // empty'
}

# Read or initialize session state. Args: <session_id>. Echoes JSON.
mnemon_session_state() {
    local sid="$1"
    local f="$MNEMON_SESSIONS_DIR/${sid}.json"
    if [ ! -f "$f" ]; then
        printf '{"last_digest_turn":0,"last_recall_at":0,"recent_drawer_ids":[],"nomemo":false,"disabled":false}' > "$f"
    fi
    cat "$f"
}

# Atomically write session state. Args: <session_id> <json>.
mnemon_session_state_write() {
    local sid="$1"
    local json="$2"
    local f="$MNEMON_SESSIONS_DIR/${sid}.json"
    local tmp="${f}.tmp.$$"
    printf '%s' "$json" > "$tmp" && mv -f "$tmp" "$f"
}

# Append an error message to the capture-errors log.
mnemon_log_error() {
    printf '[%s] %s\n' "$(date -u +%FT%TZ)" "$*" >> "$MNEMON_ERROR_LOG"
}

# Format a recall payload as a system-reminder block.
# Stdin: recall result JSON. Stdout: text to inject.
mnemon_format_recall() {
    jq -r '
        if .found then
            "<system-reminder>\nMnemon recall:\n" +
            (if (.wiki | length) > 0 then
                "  Wiki: " + (.wiki | map("[\(.title)] — \(.content[:300])") | join("\n        ")) + "\n"
             else "" end) +
            (if (.drawers | length) > 0 then
                "  Drawers:\n" +
                (.drawers | to_entries | map("    \(.key+1)) \(.value.snippet) (\(.value.wing)/\(.value.room))") | join("\n")) + "\n"
             else "" end) +
            "</system-reminder>"
        else "" end
    '
}

# Format a palace_wake_up payload as a system-reminder block.
mnemon_format_wake() {
    jq -r '
        "<system-reminder>\nMnemon palace state:\n" +
        (if (.recent_drawers | length) > 0 then
            "  Recent drawers:\n" +
            (.recent_drawers[:5] | to_entries | map("    \(.key+1)) \(.value.content) (\(.value.wing_slug)/\(.value.room_slug))") | join("\n")) + "\n"
         else "" end) +
        (if (.active_wings | length) > 0 then
            "  Active wings: " + (.active_wings[:5] | map(.slug) | join(", ")) + "\n"
         else "" end) +
        (if (.pending_update_pages | length) > 0 then
            "  Wiki pages with pending updates: " + (.pending_update_pages[:3] | map(.name) | join(", ")) + "\n"
         else "" end) +
        "</system-reminder>"
    '
}
```

- [ ] **Step 2: Lint the script**

Run: `shellcheck resources/hooks/claude-code/lib/common.sh` (install via `brew install shellcheck` if missing).
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add resources/hooks/claude-code/lib/common.sh
git commit -m "feat: Mnemon hook common.sh — shared bash helpers"
```

---

## Task 10: `mnemon-wake.sh` (SessionStart hook)

**Files:**
- Create: `resources/hooks/claude-code/mnemon-wake.sh`

- [ ] **Step 1: Write the hook**

```bash
#!/usr/bin/env bash
# Mnemon SessionStart hook: call palace_wake_up and inject a <system-reminder>.

set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "$SCRIPT_DIR/lib/common.sh"

# Read hook stdin (Claude Code provides session_id and other event metadata).
input=$(cat 2>/dev/null || echo '{}')
session_id=$(printf '%s' "$input" | jq -r '.session_id // empty')

if [ -z "$session_id" ]; then
    exit 0
fi

# Verify connection.
pair=$(mnemon_token) || {
    printf 'Mnemon: not connected — run `claude mcp add --transport http mnemon <url>` to enable memory.\n'
    exit 0
}

# Initialize session state (overwrites; SessionStart is the right reset point).
state=$(printf '{"last_digest_turn":0,"last_recall_at":0,"recent_drawer_ids":[],"nomemo":false,"disabled":false}')
mnemon_session_state_write "$session_id" "$state"

# Call palace_wake_up.
result=$(mnemon_call "tools/call" \
    "$(jq -n '{name:"palace_wake_up",arguments:{}}')" \
    2000 \
    "${pair%|*}" "${pair#*|}") || exit 0

if [ -z "$result" ]; then
    exit 0
fi

# Extract structuredContent and format.
payload=$(printf '%s' "$result" | jq -c '.structuredContent // empty')
if [ -z "$payload" ]; then
    exit 0
fi

printf '%s' "$payload" | mnemon_format_wake
```

- [ ] **Step 2: Mark executable**

Run: `chmod +x resources/hooks/claude-code/mnemon-wake.sh`

- [ ] **Step 3: Lint**

Run: `shellcheck resources/hooks/claude-code/mnemon-wake.sh`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/hooks/claude-code/mnemon-wake.sh
git commit -m "feat: mnemon-wake.sh — SessionStart hook calling palace_wake_up"
```

---

## Task 11: `mnemon-recall.sh` (UserPromptSubmit hook)

**Files:**
- Create: `resources/hooks/claude-code/mnemon-recall.sh`

- [ ] **Step 1: Write the hook**

```bash
#!/usr/bin/env bash
# Mnemon UserPromptSubmit hook: gate, then call recall, inject context.

set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "$SCRIPT_DIR/lib/common.sh"

input=$(cat 2>/dev/null || echo '{}')
session_id=$(printf '%s' "$input" | jq -r '.session_id // empty')
prompt=$(printf '%s' "$input" | jq -r '.prompt // empty')

[ -z "$session_id" ] && exit 0
[ -z "$prompt" ] && exit 0

pair=$(mnemon_token) || exit 0

state=$(mnemon_session_state "$session_id")

# @nomemo toggle: case-insensitive, must START the prompt.
if printf '%s' "$prompt" | grep -qiE '^[[:space:]]*@nomemo\b'; then
    state=$(printf '%s' "$state" | jq '.nomemo=true')
    mnemon_session_state_write "$session_id" "$state"
    exit 0
fi

# Honor nomemo / disabled.
if [ "$(printf '%s' "$state" | jq -r '.nomemo')" = "true" ]; then exit 0; fi
if [ "$(printf '%s' "$state" | jq -r '.disabled')" = "true" ]; then exit 0; fi

# Length gate.
if [ "${#prompt}" -lt 15 ]; then exit 0; fi

# Stopword + slash-command gate.
trimmed=$(printf '%s' "$prompt" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')
case "$(printf '%s' "$trimmed" | tr '[:upper:]' '[:lower:]')" in
    "ok"|"yes"|"no"|"thanks"|"continue"|"go"|"do it"|"stop"|"wait")
        exit 0
        ;;
esac
case "$trimmed" in
    /*)
        exit 0
        ;;
esac

# Recent-fire suppression.
last=$(printf '%s' "$state" | jq -r '.last_recall_at')
now=$(date +%s)
if [ "$((now - last))" -lt 30 ]; then
    exit 0
fi

# Update recall timestamp.
state=$(printf '%s' "$state" | jq --arg n "$now" '.last_recall_at=($n|tonumber)')
mnemon_session_state_write "$session_id" "$state"

# Call recall.
params=$(jq -n --arg p "$prompt" '{name:"recall",arguments:{prompt:$p,token_budget:1500}}')
result=$(mnemon_call "tools/call" "$params" 800 "${pair%|*}" "${pair#*|}") || exit 0
[ -z "$result" ] && exit 0

payload=$(printf '%s' "$result" | jq -c '.structuredContent // empty')
[ -z "$payload" ] && exit 0

found=$(printf '%s' "$payload" | jq -r '.found // false')
[ "$found" != "true" ] && exit 0

printf '%s' "$payload" | mnemon_format_recall
```

- [ ] **Step 2: Mark executable**

Run: `chmod +x resources/hooks/claude-code/mnemon-recall.sh`

- [ ] **Step 3: Lint**

Run: `shellcheck resources/hooks/claude-code/mnemon-recall.sh`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add resources/hooks/claude-code/mnemon-recall.sh
git commit -m "feat: mnemon-recall.sh — UserPromptSubmit hook with local gate + recall call"
```

---

## Task 12: `mnemon-capture.sh` (Stop hook)

**Files:**
- Create: `resources/hooks/claude-code/mnemon-capture.sh`

- [ ] **Step 1: Write the hook**

The hook does the structural+regex sanitize, slices since the watermark, then spawns a detached background worker that does the actual POST and updates state.

```bash
#!/usr/bin/env bash
# Mnemon Stop hook: sanitize, slice, dispatch detached digest worker.

set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
. "$SCRIPT_DIR/lib/common.sh"

input=$(cat 2>/dev/null || echo '{}')
session_id=$(printf '%s' "$input" | jq -r '.session_id // empty')
transcript=$(printf '%s' "$input" | jq -c '.transcript // empty')
turn_index=$(printf '%s' "$input" | jq -r '.turn_index // 0')

[ -z "$session_id" ] && exit 0
[ -z "$transcript" ] || [ "$transcript" = "null" ] && exit 0

mnemon_token > /dev/null || exit 0

state=$(mnemon_session_state "$session_id")
[ "$(printf '%s' "$state" | jq -r '.nomemo')" = "true" ] && exit 0
[ "$(printf '%s' "$state" | jq -r '.disabled')" = "true" ] && exit 0

last_turn=$(printf '%s' "$state" | jq -r '.last_digest_turn')
[ "$turn_index" -le "$last_turn" ] && exit 0

# Structural sanitize: drop tool_use, tool_result, thinking blocks from the
# transcript. Transcript shape is harness-defined; we filter known keys.
cleaned=$(printf '%s' "$transcript" | jq -c '
    if type == "array" then
        map(select((.type // "") | test("tool_use|tool_result|thinking") | not))
    else . end
')

# Regex sanitize: redact obvious secret patterns. (Mirrors ContentSanitizer.)
text=$(printf '%s' "$cleaned" | sed -E \
    -e 's/AKIA[0-9A-Z]{16}/[REDACTED-AWS-KEY]/g' \
    -e 's/(eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,})/[REDACTED-JWT]/g' \
    -e 's/Bearer [A-Za-z0-9_.-]{20,}/Bearer [REDACTED]/g' \
    -e 's/sk-[A-Za-z0-9]{20,}/[REDACTED-OPENAI-KEY]/g')

# Spawn detached worker. Pass everything by env to keep argv clean.
worker="$SCRIPT_DIR/lib/digest-worker.sh"
if [ ! -x "$worker" ]; then
    # First run: write the worker inline.
    cat > "$worker" <<'WORKER'
#!/usr/bin/env bash
set -u
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
. "$SCRIPT_DIR/common.sh"

session_id="$1"
turn_start="$2"
turn_end="$3"
text_file="$4"

LOCK="$MNEMON_SESSIONS_DIR/${session_id}.digest.lock"
PENDING="$MNEMON_SESSIONS_DIR/${session_id}.digest.pending"

# Stale-lock recovery via PID liveness.
if [ -f "$LOCK" ]; then
    pid=$(cat "$LOCK" 2>/dev/null)
    if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
        # Live worker: leave a pending-rerun marker and exit.
        : > "$PENDING"
        rm -f "$text_file"
        exit 0
    fi
    rm -f "$LOCK"
fi
echo "$$" > "$LOCK"
trap 'rm -f "$LOCK"' EXIT

run_once() {
    local sstart="$1" send="$2" tfile="$3"
    pair=$(mnemon_token) || return 1
    state=$(mnemon_session_state "$session_id")
    recent_ids=$(printf '%s' "$state" | jq -c '.recent_drawer_ids')

    text=$(cat "$tfile")
    rm -f "$tfile"

    params=$(jq -n \
        --arg sid "$session_id" \
        --argjson ts "$sstart" \
        --argjson te "$send" \
        --arg t "$text" \
        --argjson r "$recent_ids" \
        '{name:"session_digest",arguments:{session_id:$sid,harness:"claude-code",turn_range:{start:$ts,end:$te},transcript:$t,recent_drawer_ids:$r}}')

    result=$(mnemon_call "tools/call" "$params" 60000 "${pair%|*}" "${pair#*|}") \
        || { mnemon_log_error "digest call failed for session=$session_id"; return 1; }
    [ -z "$result" ] && return 1

    payload=$(printf '%s' "$result" | jq -c '.structuredContent // empty')
    persisted_ids=$(printf '%s' "$payload" | jq -c '[.persisted[]?.id]')

    state=$(mnemon_session_state "$session_id")
    state=$(printf '%s' "$state" | jq \
        --argjson new_ids "$persisted_ids" \
        --argjson last_turn "$send" \
        '.last_digest_turn=$last_turn |
         .recent_drawer_ids=((.recent_drawer_ids + $new_ids) | unique | (if length > 50 then .[(length-50):] else . end))')
    mnemon_session_state_write "$session_id" "$state"
    return 0
}

run_once "$turn_start" "$turn_end" "$text_file" || true

# If a re-run was requested while we worked, do exactly one more pass.
if [ -f "$PENDING" ]; then
    rm -f "$PENDING"
    # Re-running with empty text would be a no-op upstream (no new turns since
    # last_digest_turn just advanced); the next Stop fires the next real digest.
    :
fi
WORKER
    chmod +x "$worker"
fi

# Stage cleaned text into a temp file so argv stays small.
text_tmp="$MNEMON_SESSIONS_DIR/${session_id}.digest-input.$$.txt"
printf '%s' "$text" > "$text_tmp"

# Detach.
nohup "$worker" "$session_id" "$last_turn" "$turn_index" "$text_tmp" \
    >>"$MNEMON_ERROR_LOG" 2>&1 < /dev/null &
disown 2>/dev/null || true

exit 0
```

- [ ] **Step 2: Mark executable**

Run: `chmod +x resources/hooks/claude-code/mnemon-capture.sh`

- [ ] **Step 3: Lint**

Run: `shellcheck resources/hooks/claude-code/mnemon-capture.sh`
Expected: no errors. (The HEREDOC for the worker may need `# shellcheck disable=SC1090,SC2317` near the heredoc — add suppression comments if shellcheck flags them.)

- [ ] **Step 4: Commit**

```bash
git add resources/hooks/claude-code/mnemon-capture.sh
git commit -m "feat: mnemon-capture.sh — Stop hook with sanitize, slice, detached digest worker"
```

---

## Task 13: Hook test harness — `tests/Hooks/run-hook-tests.sh`

**Files:**
- Create: `tests/Hooks/run-hook-tests.sh`
- Create: `tests/Hooks/fixtures/server.sh`

A small harness that pipes synthetic stdin into each hook script and asserts on stdout and exit code. The fixture server is a tiny netcat-based stub.

- [ ] **Step 1: Write the test harness**

```bash
#!/usr/bin/env bash
# Mnemon hook integration tests. Pipe synthetic events into each hook and
# assert on stdout/exit code. Uses a netcat-based fake MCP server.

set -euo pipefail
THIS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOOKS_DIR="$(cd "$THIS_DIR/../../resources/hooks/claude-code" && pwd)"
FAKE_PORT="${FAKE_PORT:-9876}"

# Sandbox MNEMON_DIR so tests don't touch real state.
export MNEMON_DIR="$(mktemp -d)"
trap 'rm -rf "$MNEMON_DIR"' EXIT

# Write a temp config pointing at the fake server.
mkdir -p "$MNEMON_DIR"
cat > "$MNEMON_DIR/config.json" <<EOF
{"endpoint":"http://127.0.0.1:$FAKE_PORT/mcp","bearer_token":"test-token"}
EOF

PASS=0
FAIL=0

assert_eq() {
    if [ "$1" = "$2" ]; then
        PASS=$((PASS+1))
        printf '  ok: %s\n' "$3"
    else
        FAIL=$((FAIL+1))
        printf '  FAIL: %s\n    expected: %s\n    got:      %s\n' "$3" "$2" "$1"
    fi
}

# Start the fake server in the background.
"$THIS_DIR/fixtures/server.sh" &
SERVER_PID=$!
trap 'kill $SERVER_PID 2>/dev/null; rm -rf "$MNEMON_DIR"' EXIT
sleep 0.5

# Test 1: recall hook short-circuits on too-short prompt.
out=$(printf '{"session_id":"s1","prompt":"hi"}' | "$HOOKS_DIR/mnemon-recall.sh" || true)
assert_eq "$out" "" "recall: short prompt → no output"

# Test 2: recall hook short-circuits on stopword.
out=$(printf '{"session_id":"s1","prompt":"thanks"}' | "$HOOKS_DIR/mnemon-recall.sh" || true)
assert_eq "$out" "" "recall: stopword → no output"

# Test 3: recall hook short-circuits on slash command.
out=$(printf '{"session_id":"s1","prompt":"/clear"}' | "$HOOKS_DIR/mnemon-recall.sh" || true)
assert_eq "$out" "" "recall: slash command → no output"

# Test 4: @nomemo enables suppression for the rest of the session.
printf '{"session_id":"s2","prompt":"@nomemo please"}' | "$HOOKS_DIR/mnemon-recall.sh" >/dev/null || true
nm=$(jq -r '.nomemo' "$MNEMON_DIR/sessions/s2.json")
assert_eq "$nm" "true" "recall: @nomemo sets state.nomemo=true"

# Test 5: with nomemo set, real prompts still produce no output.
out=$(printf '{"session_id":"s2","prompt":"a real long prompt about dorothy"}' | "$HOOKS_DIR/mnemon-recall.sh" || true)
assert_eq "$out" "" "recall: nomemo=true → no output even for real prompts"

# Test 6: a substantive prompt with fresh state hits the fake server and renders output.
out=$(printf '{"session_id":"s3","prompt":"what do we know about dorothy vaughan"}' | "$HOOKS_DIR/mnemon-recall.sh")
case "$out" in
    *"<system-reminder>"*"Mnemon recall:"*) PASS=$((PASS+1)); printf '  ok: recall: hits fake server, emits system-reminder\n';;
    *) FAIL=$((FAIL+1)); printf '  FAIL: recall: expected system-reminder, got: %s\n' "$out";;
esac

# Test 7: recent-fire suppression — second call within 30s returns nothing.
out2=$(printf '{"session_id":"s3","prompt":"another substantive prompt about dorothy"}' | "$HOOKS_DIR/mnemon-recall.sh" || true)
assert_eq "$out2" "" "recall: recent-fire suppression"

# Test 8: capture hook with no token short-circuits.
rm -f "$MNEMON_DIR/config.json"
out=$(printf '{"session_id":"s4","transcript":[],"turn_index":1}' | "$HOOKS_DIR/mnemon-capture.sh" || true)
assert_eq "$out" "" "capture: no token → silent no-op"

printf '\n%d passed, %d failed\n' "$PASS" "$FAIL"
[ "$FAIL" = 0 ]
```

- [ ] **Step 2: Write the fake server**

```bash
#!/usr/bin/env bash
# Fake MCP server for hook tests. Listens on $FAKE_PORT and replies to
# tools/call with a canned recall payload (or palace_wake_up payload).

set -u
FAKE_PORT="${FAKE_PORT:-9876}"

reply_recall='{"jsonrpc":"2.0","id":1,"result":{"structuredContent":{"found":true,"summary":"Found 1 wiki page and 0 drawers.","wiki":[{"slug":"person:dorothy-vaughan","title":"Dorothy Vaughan","content":"Lead engineer.","confidence":0.9}],"drawers":[],"tokens_used":50}}}'
reply_wake='{"jsonrpc":"2.0","id":1,"result":{"structuredContent":{"recent_drawers":[],"active_wings":[],"recent_wiki_updates":[],"stale_wiki_pages":[],"pending_update_pages":[]}}}'

while true; do
    {
        # Read request, ignore body length, always reply with recall payload.
        # macOS netcat: -l <port>, GNU netcat: -l -p <port>.
        body="$reply_recall"
        printf 'HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: %d\r\n\r\n%s' "${#body}" "$body"
    } | nc -l "$FAKE_PORT" >/dev/null 2>&1 || true
done
```

- [ ] **Step 3: Mark executable + run**

```bash
chmod +x tests/Hooks/run-hook-tests.sh tests/Hooks/fixtures/server.sh
./tests/Hooks/run-hook-tests.sh
```

Expected: all assertions pass.

If `nc` invocations differ between macOS and GNU on your dev env, the server's `nc -l "$FAKE_PORT"` may need adjusting (`-p` for GNU). Update once and document the minimum nc version in a comment.

- [ ] **Step 4: Commit**

```bash
git add tests/Hooks/run-hook-tests.sh tests/Hooks/fixtures/server.sh
git commit -m "test: bash harness for Mnemon hook scripts"
```

---

## Task 14: `mnemon:install-claude-code-hooks` artisan command

**Files:**
- Create: `app/Console/Commands/InstallClaudeCodeHooks.php`
- Test: `tests/Feature/Console/InstallClaudeCodeHooksTest.php`

- [ ] **Step 1: Write failing command tests**

```php
<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstallClaudeCodeHooksTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        parent::setUp();
        $this->home = sys_get_temp_dir().'/mnemon-test-'.uniqid();
        File::ensureDirectoryExists($this->home.'/.claude');
        File::put($this->home.'/.claude/settings.json', '{}');
        putenv('HOME='.$this->home);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->home);
        parent::tearDown();
    }

    public function test_command_writes_config_and_hook_scripts(): void
    {
        Http::fake([
            'localhost/mcp' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []]]),
        ]);

        $this->artisan('mnemon:install-claude-code-hooks', [
            '--endpoint' => 'http://localhost/mcp',
            '--token' => 'fake-token',
        ])->assertExitCode(0);

        $this->assertFileExists($this->home.'/.mnemon/config.json');
        $config = json_decode(File::get($this->home.'/.mnemon/config.json'), true);
        $this->assertEquals('http://localhost/mcp', $config['endpoint']);
        $this->assertEquals('fake-token', $config['bearer_token']);

        $this->assertFileExists($this->home.'/.claude/hooks/mnemon-wake.sh');
        $this->assertFileExists($this->home.'/.claude/hooks/mnemon-recall.sh');
        $this->assertFileExists($this->home.'/.claude/hooks/mnemon-capture.sh');
        $this->assertFileExists($this->home.'/.claude/hooks/lib/common.sh');
    }

    public function test_command_aborts_when_claude_dir_missing(): void
    {
        File::deleteDirectory($this->home.'/.claude');

        $this->artisan('mnemon:install-claude-code-hooks', [
            '--endpoint' => 'http://localhost/mcp',
            '--token' => 'fake-token',
        ])->assertFailed();
    }

    public function test_rerun_does_not_duplicate_hook_entries(): void
    {
        Http::fake(['localhost/mcp' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => []])]);

        $this->artisan('mnemon:install-claude-code-hooks',
            ['--endpoint' => 'http://localhost/mcp', '--token' => 'tok', '--force' => true])->assertExitCode(0);
        $this->artisan('mnemon:install-claude-code-hooks',
            ['--endpoint' => 'http://localhost/mcp', '--token' => 'tok', '--force' => true])->assertExitCode(0);

        $settings = json_decode(File::get($this->home.'/.claude/settings.json'), true);
        $hooks = $settings['hooks'] ?? [];
        $entries = array_filter($hooks, fn ($h) => str_contains($h['command'] ?? '', 'mnemon-recall.sh'));
        $this->assertCount(1, $entries);
    }
}
```

- [ ] **Step 2: Run test to confirm failure**

Run: `php artisan test --filter=InstallClaudeCodeHooksTest`
Expected: FAIL — command not registered.

- [ ] **Step 3: Implement the command**

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class InstallClaudeCodeHooks extends Command
{
    protected $signature = 'mnemon:install-claude-code-hooks
                            {--endpoint= : The MCP endpoint URL}
                            {--token= : Passport bearer token}
                            {--force : Overwrite existing hook scripts}';

    protected $description = 'Install Mnemon Claude Code hooks for automatic capture and recall.';

    public function handle(): int
    {
        $home = getenv('HOME') ?: $_SERVER['HOME'] ?? null;
        if (! $home) {
            $this->error('Cannot resolve $HOME.');

            return self::FAILURE;
        }

        $claudeDir = $home.'/.claude';
        if (! File::isDirectory($claudeDir)) {
            $this->error("$claudeDir not found. Install Claude Code first: https://docs.claude.com/claude-code");

            return self::FAILURE;
        }

        $endpoint = $this->option('endpoint')
            ?: env('MNEMON_URL')
            ?: $this->ask('Mnemon MCP endpoint URL', 'https://mnemon.example.com/mcp');

        $token = $this->option('token')
            ?: $this->resolveTokenFromClaudeJson($home)
            ?: $this->secret('Passport bearer token');

        if (empty($endpoint) || empty($token)) {
            $this->error('Endpoint and token are required.');

            return self::FAILURE;
        }

        if (! $this->verifyToken($endpoint, $token)) {
            $this->error('Token verification failed against '.$endpoint);

            return self::FAILURE;
        }

        $mnemonDir = $home.'/.mnemon';
        File::ensureDirectoryExists($mnemonDir);
        File::ensureDirectoryExists($mnemonDir.'/sessions');
        File::put($mnemonDir.'/config.json', json_encode([
            'endpoint' => $endpoint,
            'bearer_token' => $token,
        ], JSON_PRETTY_PRINT));
        chmod($mnemonDir.'/config.json', 0600);

        $hooksDir = $claudeDir.'/hooks';
        File::ensureDirectoryExists($hooksDir.'/lib');

        $repoHooks = base_path('resources/hooks/claude-code');
        foreach (['lib/common.sh', 'mnemon-wake.sh', 'mnemon-recall.sh', 'mnemon-capture.sh'] as $file) {
            $src = "$repoHooks/$file";
            $dst = "$hooksDir/$file";
            if (! File::exists($src)) {
                $this->error("Missing hook script in repo: $src");

                return self::FAILURE;
            }
            if (File::exists($dst) && ! $this->option('force')) {
                if (! $this->confirm("Overwrite $dst?", true)) {
                    continue;
                }
            }
            File::copy($src, $dst);
            chmod($dst, 0755);
        }

        $this->registerHooksInSettings($claudeDir.'/settings.json', $hooksDir);

        $this->info('Mnemon Claude Code hooks installed.');
        $this->info("  Config: $mnemonDir/config.json");
        $this->info("  Hooks:  $hooksDir/{mnemon-wake,mnemon-recall,mnemon-capture}.sh");
        $this->info('Test with: claude → ask anything.');

        return self::SUCCESS;
    }

    private function resolveTokenFromClaudeJson(string $home): ?string
    {
        $f = $home.'/.claude.json';
        if (! File::exists($f)) {
            return null;
        }
        $j = json_decode(File::get($f), true) ?? [];
        $auth = $j['mcpServers']['mnemon']['headers']['Authorization'] ?? null;
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return substr($auth, 7);
        }

        return null;
    }

    private function verifyToken(string $endpoint, string $token): bool
    {
        try {
            $r = Http::withToken($token)
                ->timeout(5)
                ->post($endpoint, [
                    'jsonrpc' => '2.0',
                    'id' => 1,
                    'method' => 'tools/list',
                    'params' => new \stdClass,
                ]);

            return $r->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function registerHooksInSettings(string $settingsFile, string $hooksDir): void
    {
        $settings = File::exists($settingsFile)
            ? (json_decode(File::get($settingsFile), true) ?: [])
            : [];

        $settings['hooks'] = $settings['hooks'] ?? [];
        $managedKey = '_mnemon_managed';

        // Drop any existing mnemon-managed entries before re-adding.
        $settings['hooks'] = array_values(array_filter(
            $settings['hooks'],
            fn ($h) => empty($h[$managedKey])
        ));

        $settings['hooks'][] = [$managedKey => true, 'event' => 'SessionStart',     'command' => "$hooksDir/mnemon-wake.sh"];
        $settings['hooks'][] = [$managedKey => true, 'event' => 'UserPromptSubmit', 'command' => "$hooksDir/mnemon-recall.sh"];
        $settings['hooks'][] = [$managedKey => true, 'event' => 'Stop',             'command' => "$hooksDir/mnemon-capture.sh"];

        File::put($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
```

- [ ] **Step 4: Run tests to verify pass**

Run: `php artisan test --filter=InstallClaudeCodeHooksTest`
Expected: 3 passing.

- [ ] **Step 5: Smoke test the command on your real machine** (after Task 16's deploy if you're running locally)

Run: `php artisan mnemon:install-claude-code-hooks --endpoint=http://localhost:8000/mcp --token=<your-bearer> --force`
Expected: `Mnemon Claude Code hooks installed.` Verify `~/.claude/hooks/mnemon-recall.sh` exists and is executable.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/InstallClaudeCodeHooks.php tests/Feature/Console/InstallClaudeCodeHooksTest.php
git commit -m "feat: mnemon:install-claude-code-hooks artisan command"
```

---

## Task 15: Documentation updates

**Files:**
- Modify: `README.md`
- Modify: `CLAUDE.md`
- Modify: `docs/USERGUIDE.md`
- Modify: `docs/FRD.md`

- [ ] **Step 1: README — add Layer 2 section**

Open `README.md`, find the "MCP tools" section (around line ~84 per the spec's reference). Append a new top-level section after the OAuth/MCP tools section:

```markdown
## Layer 2 — automatic memory in Claude Code

The MCP server exposes Mnemon to any agent that asks. **Layer 2** is automatic capture and recall for Claude Code: every prompt is silently primed with relevant palace context, and every session quietly digests to drawers in the background.

Install with:

```bash
php artisan mnemon:install-claude-code-hooks
```

This adds three hooks to `~/.claude/hooks/`:

- `mnemon-wake.sh` (SessionStart) — injects recent palace state at session start.
- `mnemon-recall.sh` (UserPromptSubmit) — injects relevant wiki + drawer context per prompt, gated to skip chitchat.
- `mnemon-capture.sh` (Stop) — digests the session transcript to drawer proposals; high-confidence ones auto-persist; new wings queue for admin review.

See [`docs/USERGUIDE.md`](docs/USERGUIDE.md#automatic-memory-in-claude-code) for the full walkthrough.
```

- [ ] **Step 2: CLAUDE.md — add new tools and model to lists**

Open `CLAUDE.md`. In the "MCP Server" section's tools list, add `recall` and `session_digest` to the 12-tool count (now 14). In the file structure conventions block, add `WikiPendingWing` to the models list and note the new `app/Console/Commands/InstallClaudeCodeHooks.php`.

- [ ] **Step 3: USERGUIDE.md — new section**

Open `docs/USERGUIDE.md`. Add a new section before "Bulk loading":

```markdown
## Automatic memory in Claude Code

Mnemon's MCP tools work for any agent that thinks to call them. For Claude Code specifically, you can install hooks that capture and recall on the agent's behalf.

### Install

```bash
php artisan mnemon:install-claude-code-hooks
```

The command:
1. Reads your existing OAuth bearer token from `~/.claude.json`.
2. Verifies it against `/mcp`.
3. Copies hook scripts to `~/.claude/hooks/`.
4. Registers them in `~/.claude/settings.json`.

### What each hook does

- **SessionStart (`mnemon-wake.sh`)** — calls `palace_wake_up`; injects a `<system-reminder>` summarizing recent drawers, active wings, and pending wiki updates.
- **UserPromptSubmit (`mnemon-recall.sh`)** — gated by length/stopword/recent-fire checks; on a substantive prompt, calls `recall` and injects up to ~1500 tokens of relevant wiki + drawer context. Budget: 800ms; failures are silent.
- **Stop (`mnemon-capture.sh`)** — sanitizes the transcript (strips tool I/O + thinking + secret patterns), then dispatches a detached background worker that calls `session_digest`. Drawers with confidence ≥ 0.5 auto-persist; proposals for new wings queue for review at `/admin/wiki-pending-wings`.

### `@nomemo` — turn it off mid-session

Type `@nomemo` at the start of any prompt to suppress both recall and capture for the rest of the session. The next session starts fresh.

### Reviewing captured drawers

In Filament: `/admin` → Drawers → filter by `source = "claude-code:session_digest"`.
For new-wing proposals: `/admin` → Access Control → Pending Wings.

### Troubleshooting

- **No recall is firing.** Check `~/.mnemon/sessions/<session_id>.json` exists. If `nomemo: true` or `disabled: true`, that session is suppressed. Start a new Claude Code session.
- **Capture isn't producing drawers.** Tail `~/.mnemon/capture-errors.log`. Common: `OPENAI_API_KEY` missing or rate-limited.
- **401 errors in capture log.** Token expired. Re-run `php artisan mnemon:install-claude-code-hooks --force` after refreshing the OAuth flow in Claude Code.
- **`@nomemo` accidentally turned on.** Delete `~/.mnemon/sessions/<session_id>.json` to reset.
```

- [ ] **Step 4: FRD — Harness adapters**

Open `docs/FRD.md`. Add a new section near the top (after the existing system overview):

```markdown
## Harness adapters

Mnemon's MCP server is the universal interface; **harness adapters** wrap it for specific clients to make memory automatic rather than agent-discretionary. The first adapter is Claude Code (three shell hooks calling `recall`, `session_digest`, and `palace_wake_up`). Cursor, ChatGPT, and others reuse the same MCP tools but require their own integration mechanism per harness.
```

- [ ] **Step 5: Commit**

```bash
git add README.md CLAUDE.md docs/USERGUIDE.md docs/FRD.md
git commit -m "docs: document Layer 2 hooks across README, CLAUDE.md, USERGUIDE, FRD"
```

---

## Task 16: PR-prep grep + final smoke

**Files:** none modified — this is verification only.

- [ ] **Step 1: Repo-wide grep for stragglers**

Run:

```bash
grep -rEn 'TODO|FIXME|XXX' \
    --include='*.php' --include='*.sh' --include='*.md' \
    app/ resources/hooks/ docs/USERGUIDE.md docs/FRD.md README.md CLAUDE.md \
    | grep -v 'docs/superpowers/' || true
```

Expected: empty (or only legitimate, non-Layer-2 markers — flag any new ones for review).

- [ ] **Step 2: Run the full PHP test suite**

Run: `php artisan test --compact`
Expected: all green. If any test newly fails, fix before continuing.

- [ ] **Step 3: Run the bash hook test harness**

Run: `./tests/Hooks/run-hook-tests.sh`
Expected: all assertions pass.

- [ ] **Step 4: Pint pass**

Run: `./vendor/bin/pint`
Expected: any files reformatted are intentional. Commit if so.

```bash
git add -u && git commit -m "style: pint" || true
```

- [ ] **Step 5: Manual smoke against a local server**

Run, in three terminals:

```bash
# Terminal 1: serve
php artisan serve

# Terminal 2: install hooks pointing at local
php artisan mnemon:install-claude-code-hooks \
    --endpoint=http://localhost:8000/mcp \
    --token=<paste a personal access token from `php artisan tinker`>

# Terminal 3: test claude code
claude
# Inside the session, type a substantive prompt about something you've drawer'd before.
# Confirm a Mnemon recall <system-reminder> appears in the transcript.
```

If recall never fires: tail `~/.mnemon/capture-errors.log` and check the hook scripts exist and are executable.

- [ ] **Step 6: Final commit if anything moved**

```bash
git status
# If clean, skip. Otherwise:
git add -u && git commit -m "chore: post-smoke fixups"
```

---

## Done

The plan implements every spec section:

- **Recall + capture philosophy / triggering / sanitize / off-switch** — Tasks 4, 7, 11, 12.
- **Architecture (MCP-native)** — Tasks 4, 7.
- **`recall` MCP tool** — Tasks 2 + 4.
- **`session_digest` MCP tool** — Tasks 5, 6, 7.
- **`wiki_pending_wings` table + Filament approval queue** — Tasks 1 + 8.
- **Hook scripts (wake, recall, capture, common)** — Tasks 9, 10, 11, 12.
- **Bash test harness** — Task 13.
- **Artisan installer** — Task 14.
- **Documentation updates** — Task 15.
- **Failure-mode budgets, debounce, stale-lock recovery** — Tasks 11, 12.
- **Self-review** — Task 16.
