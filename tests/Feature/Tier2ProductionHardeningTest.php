<?php

namespace Tests\Feature;

use App\Mcp\McpException;
use App\Mcp\McpToolRegistry;
use App\Mcp\Tools\BrainStatusTool;
use App\Mcp\Tools\ContextGetTool;
use App\Mcp\Tools\ContextSetTool;
use App\Mcp\Tools\DrawerAddTool;
use App\Mcp\Tools\DrawerSearchTool;
use App\Mcp\Tools\WikiCompileTool;
use App\Mcp\Tools\WikiHistoryTool;
use App\Mcp\Tools\WikiLintTool;
use App\Models\ApiKey;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use App\Services\ContentSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Tier2ProductionHardeningTest extends TestCase
{
    use RefreshDatabase;

    private ApiKey $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = ApiKey::create([
            'name' => 'test-key',
            'key_hash' => hash('sha256', 'test-token'),
            'scopes' => ['*'],
        ]);
    }

    // ─── Item 1: Confidence Scoring + Decay ─────────────────────────

    public function test_wiki_page_has_confidence_score_columns(): void
    {
        $page = WikiPage::create([
            'name' => 'test:confidence',
            'type' => 'concept',
            'title' => 'Test Confidence',
            'content' => 'Test content for confidence scoring',
            'last_compiled_at' => now(),
            'source_count' => 3,
        ]);

        $this->assertDatabaseHas('wiki_pages', [
            'id' => $page->id,
            'source_count' => 3,
        ]);
    }

    public function test_calculate_confidence_score_with_sources(): void
    {
        $page = WikiPage::create([
            'name' => 'test:score',
            'type' => 'concept',
            'title' => 'Test Score',
            'content' => 'Some content',
            'last_compiled_at' => now(),
            'source_count' => 5,
        ]);

        $score = $page->calculateConfidenceScore();

        // 5 sources = base 1.0, compiled now = recency ~1.0
        $this->assertGreaterThan(0.9, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_confidence_score_decays_with_age(): void
    {
        $page = WikiPage::create([
            'name' => 'test:old',
            'type' => 'concept',
            'title' => 'Old Page',
            'content' => 'Old content',
            'last_compiled_at' => now()->subDays(45),
            'source_count' => 5,
        ]);

        $score = $page->calculateConfidenceScore();

        // Older page should have lower confidence than a fresh one
        $this->assertGreaterThan(0.3, $score);
        $this->assertLessThan(0.8, $score);
    }

    public function test_confidence_score_low_with_few_sources(): void
    {
        $page = WikiPage::create([
            'name' => 'test:low',
            'type' => 'concept',
            'title' => 'Low Source Page',
            'content' => 'Content with few sources',
            'last_compiled_at' => now(),
            'source_count' => 1,
        ]);

        $score = $page->calculateConfidenceScore();

        // 1 source = base 0.2
        $this->assertLessThan(0.3, $score);
    }

    public function test_confidence_score_minimal_for_never_compiled(): void
    {
        $page = WikiPage::create([
            'name' => 'test:never',
            'type' => 'concept',
            'title' => 'Never Compiled',
            'content' => 'Content that was never compiled',
            'source_count' => 3,
        ]);

        $score = $page->calculateConfidenceScore();

        // Never compiled: recency = 0.1
        $this->assertLessThan(0.2, $score);
    }

    public function test_decay_confidence_command(): void
    {
        WikiPage::create([
            'name' => 'test:decay-a',
            'type' => 'concept',
            'title' => 'Decay A',
            'content' => 'Content A for decay testing',
            'last_compiled_at' => now(),
            'source_count' => 5,
        ]);
        WikiPage::create([
            'name' => 'test:decay-b',
            'type' => 'concept',
            'title' => 'Decay B',
            'content' => 'Content B for decay testing',
            'last_compiled_at' => now()->subDays(60),
            'source_count' => 2,
        ]);

        $this->artisan('mnemon:decay-confidence')
            ->expectsOutputToContain('Recalculated confidence scores for 2 wiki page(s)')
            ->assertSuccessful();

        $pageA = WikiPage::where('name', 'test:decay-a')->first();
        $pageB = WikiPage::where('name', 'test:decay-b')->first();

        $this->assertNotNull($pageA->confidence_score);
        $this->assertNotNull($pageB->confidence_score);
        $this->assertGreaterThan($pageB->confidence_score, $pageA->confidence_score);
    }

    public function test_context_get_updates_last_accessed_at(): void
    {
        $page = WikiPage::create([
            'name' => 'test:access',
            'type' => 'concept',
            'title' => 'Access Test',
            'content' => 'Content for access tracking test',
        ]);

        $this->assertNull($page->last_accessed_at);

        $tool = app(ContextGetTool::class);
        $tool->execute(['name' => 'test:access'], $this->apiKey);

        $page->refresh();
        $this->assertNotNull($page->last_accessed_at);
    }

    public function test_context_get_returns_tier2_fields(): void
    {
        WikiPage::create([
            'name' => 'test:score-return',
            'type' => 'concept',
            'title' => 'Score Return Test',
            'content' => 'Content for score return testing',
            'confidence_score' => 0.75,
            'source_count' => 4,
            'revision_count' => 3,
        ]);

        $tool = app(ContextGetTool::class);
        $result = $tool->execute(['name' => 'test:score-return'], $this->apiKey);

        $this->assertEquals(0.75, $result['confidence_score']);
        $this->assertEquals(4, $result['source_count']);
        $this->assertEquals(3, $result['revision_count']);
        $this->assertArrayHasKey('last_accessed_at', $result);
    }

    public function test_context_set_calculates_confidence_score(): void
    {
        $wing = Wing::create(['name' => 'test', 'slug' => 'test']);
        $room = Room::create(['name' => 'notes', 'slug' => 'notes', 'wing_id' => $wing->id]);
        $drawer1 = Drawer::create(['content' => 'Source 1', 'room_id' => $room->id]);
        $drawer2 = Drawer::create(['content' => 'Source 2', 'room_id' => $room->id]);

        $tool = app(ContextSetTool::class);
        $result = $tool->execute([
            'name' => 'concept:test-score',
            'content' => 'Compiled from two sources about testing',
            'sources' => [$drawer1->id, $drawer2->id],
        ], $this->apiKey);

        $this->assertNotNull($result['confidence_score']);
        $this->assertGreaterThan(0, $result['confidence_score']);

        $page = WikiPage::where('name', 'concept:test-score')->first();
        $this->assertEquals(2, $page->source_count);
    }

    // ─── Item 2: Supersession ────────────────────────────────────────

    public function test_context_set_tracks_revision_count(): void
    {
        WikiPage::create([
            'name' => 'test:revisions',
            'type' => 'concept',
            'title' => 'Revision Test',
            'content' => 'Original content for revision tracking',
            'revision_count' => 1,
        ]);

        $tool = app(ContextSetTool::class);
        $result = $tool->execute([
            'name' => 'test:revisions',
            'content' => 'Updated content - first revision',
        ], $this->apiKey);

        $page = WikiPage::where('name', 'test:revisions')->first();
        $this->assertEquals(2, $page->revision_count);
        $this->assertEquals(2, $result['revision_count']);
    }

    public function test_context_set_stores_previous_content_hash(): void
    {
        $originalContent = 'Original content that will be superseded';
        WikiPage::create([
            'name' => 'test:hash',
            'type' => 'concept',
            'title' => 'Hash Test',
            'content' => $originalContent,
        ]);

        $tool = app(ContextSetTool::class);
        $tool->execute([
            'name' => 'test:hash',
            'content' => 'New content that replaces the original',
        ], $this->apiKey);

        $page = WikiPage::where('name', 'test:hash')->first();
        $this->assertEquals(hash('sha256', $originalContent), $page->previous_content_hash);
    }

    public function test_context_set_new_page_has_no_previous_hash(): void
    {
        $tool = app(ContextSetTool::class);
        $tool->execute([
            'name' => 'concept:brand-new',
            'content' => 'Brand new page content for testing',
        ], $this->apiKey);

        $page = WikiPage::where('name', 'concept:brand-new')->first();
        $this->assertNull($page->previous_content_hash);
        $this->assertEquals(1, $page->revision_count);
    }

    public function test_wiki_history_tool_returns_revision_data(): void
    {
        WikiPage::create([
            'name' => 'test:history',
            'type' => 'concept',
            'title' => 'History Test',
            'content' => 'Content for history tool testing',
            'revision_count' => 5,
            'previous_content_hash' => hash('sha256', 'old content'),
            'confidence_score' => 0.8,
            'source_count' => 4,
            'last_compiled_at' => now(),
        ]);

        $tool = app(WikiHistoryTool::class);
        $result = $tool->execute(['name' => 'test:history'], $this->apiKey);

        $this->assertEquals(5, $result['revision_count']);
        $this->assertEquals(hash('sha256', 'old content'), $result['previous_content_hash']);
        $this->assertEquals(0.8, $result['confidence_score']);
        $this->assertEquals(4, $result['source_count']);
    }

    public function test_wiki_history_tool_not_found(): void
    {
        $tool = app(WikiHistoryTool::class);
        $result = $tool->execute(['name' => 'nonexistent:page'], $this->apiKey);

        $this->assertEquals('Wiki page not found', $result['error']);
    }

    public function test_wiki_history_tool_requires_name(): void
    {
        $tool = app(WikiHistoryTool::class);

        $this->expectException(McpException::class);
        $tool->execute([], $this->apiKey);
    }

    // ─── Item 3: Consolidation Tiers ─────────────────────────────────

    public function test_drawer_has_default_raw_tier(): void
    {
        $wing = Wing::create(['name' => 'test', 'slug' => 'test']);
        $room = Room::create(['name' => 'notes', 'slug' => 'notes', 'wing_id' => $wing->id]);
        $drawer = Drawer::create(['content' => 'New drawer content', 'room_id' => $room->id]);

        $this->assertEquals('raw', $drawer->tier);
    }

    public function test_wiki_compile_marks_drawers_as_reviewed(): void
    {
        $wing = Wing::create(['name' => 'concept:test', 'slug' => 'concept-test']);
        $room = Room::create(['name' => 'notes', 'slug' => 'notes', 'wing_id' => $wing->id]);
        $drawer = Drawer::create(['content' => 'Raw drawer content for compile', 'room_id' => $room->id, 'tier' => 'raw']);

        $this->assertEquals('raw', $drawer->tier);

        $tool = app(WikiCompileTool::class);
        $tool->execute(['name' => 'concept:test'], $this->apiKey);

        $drawer->refresh();
        $this->assertEquals('reviewed', $drawer->tier);
    }

    public function test_context_set_marks_source_drawers_as_consolidated(): void
    {
        $wing = Wing::create(['name' => 'test', 'slug' => 'test']);
        $room = Room::create(['name' => 'notes', 'slug' => 'notes', 'wing_id' => $wing->id]);
        $drawer1 = Drawer::create(['content' => 'Source 1 for consolidation', 'room_id' => $room->id, 'tier' => 'reviewed']);
        $drawer2 = Drawer::create(['content' => 'Source 2 for consolidation', 'room_id' => $room->id, 'tier' => 'raw']);

        $tool = app(ContextSetTool::class);
        $tool->execute([
            'name' => 'concept:consolidated',
            'content' => 'Consolidated content from both sources',
            'sources' => [$drawer1->id, $drawer2->id],
        ], $this->apiKey);

        $drawer1->refresh();
        $drawer2->refresh();
        $this->assertEquals('consolidated', $drawer1->tier);
        $this->assertEquals('consolidated', $drawer2->tier);
    }

    public function test_drawer_search_filters_by_tier(): void
    {
        $wing = Wing::create(['name' => 'test', 'slug' => 'test']);
        $room = Room::create(['name' => 'notes', 'slug' => 'notes', 'wing_id' => $wing->id]);
        Drawer::create(['content' => 'Raw knowledge about testing patterns', 'room_id' => $room->id, 'tier' => 'raw']);
        Drawer::create(['content' => 'Consolidated knowledge about testing patterns', 'room_id' => $room->id, 'tier' => 'consolidated']);

        $tool = app(DrawerSearchTool::class);
        $result = $tool->execute([
            'query' => 'testing patterns',
            'tier' => 'consolidated',
        ], $this->apiKey);

        $this->assertCount(1, $result['results']);
        $this->assertEquals('consolidated', $result['results'][0]['tier']);
    }

    public function test_drawer_search_invalid_tier_rejected(): void
    {
        $tool = app(DrawerSearchTool::class);

        $this->expectException(McpException::class);
        $tool->execute([
            'query' => 'test',
            'tier' => 'invalid',
        ], $this->apiKey);
    }

    public function test_brain_status_includes_drawers_by_tier(): void
    {
        $wing = Wing::create(['name' => 'test', 'slug' => 'test']);
        $room = Room::create(['name' => 'notes', 'slug' => 'notes', 'wing_id' => $wing->id]);
        Drawer::create(['content' => 'Raw drawer 1', 'room_id' => $room->id, 'tier' => 'raw']);
        Drawer::create(['content' => 'Raw drawer 2', 'room_id' => $room->id, 'tier' => 'raw']);
        Drawer::create(['content' => 'Reviewed drawer', 'room_id' => $room->id, 'tier' => 'reviewed']);
        Drawer::create(['content' => 'Consolidated drawer', 'room_id' => $room->id, 'tier' => 'consolidated']);

        $tool = app(BrainStatusTool::class);
        $result = $tool->execute([], $this->apiKey);

        $this->assertArrayHasKey('drawers_by_tier', $result);
        $this->assertEquals(2, $result['drawers_by_tier']['raw']);
        $this->assertEquals(1, $result['drawers_by_tier']['reviewed']);
        $this->assertEquals(1, $result['drawers_by_tier']['consolidated']);
    }

    // ─── Item 4: Automation Hooks ────────────────────────────────────

    public function test_auto_lint_command_outputs_json(): void
    {
        WikiPage::create([
            'name' => 'test:empty',
            'type' => 'concept',
            'title' => 'Empty',
            'content' => 'Hi',
        ]);

        $this->artisan('mnemon:auto-lint')
            ->assertSuccessful();
    }

    public function test_auto_lint_detects_stale_pages(): void
    {
        WikiPage::create([
            'name' => 'test:pending',
            'type' => 'concept',
            'title' => 'Pending',
            'content' => 'Content with pending drawers for lint test',
            'pending_drawers_since_compile' => 3,
        ]);

        $this->artisan('mnemon:auto-lint')
            ->expectsOutputToContain('stale')
            ->assertSuccessful();
    }

    public function test_auto_compile_stale_command_finds_candidates(): void
    {
        WikiPage::create([
            'name' => 'test:stale',
            'type' => 'concept',
            'title' => 'Stale Page',
            'content' => 'Stale content for auto-compile testing',
            'pending_drawers_since_compile' => 3,
            'last_compiled_at' => now()->subDays(10),
        ]);

        $this->artisan('mnemon:auto-compile-stale')
            ->expectsOutputToContain('test:stale')
            ->assertSuccessful();
    }

    public function test_auto_compile_stale_command_empty_output(): void
    {
        WikiPage::create([
            'name' => 'test:fresh',
            'type' => 'concept',
            'title' => 'Fresh Page',
            'content' => 'Fresh content that is up to date',
            'pending_drawers_since_compile' => 0,
            'last_compiled_at' => now(),
        ]);

        $this->artisan('mnemon:auto-compile-stale')
            ->expectsOutputToContain('"count": 0')
            ->assertSuccessful();
    }

    public function test_sync_openclaw_command_fails_on_missing_path(): void
    {
        $this->artisan('mnemon:sync-openclaw', ['path' => '/nonexistent/path'])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
    }

    // ─── Item 5: Security Filtering ──────────────────────────────────

    public function test_sanitizer_redacts_openai_api_keys(): void
    {
        $sanitizer = new ContentSanitizer;
        $input = 'My key is sk-abc123def456ghi789jkl012mno345';
        $output = $sanitizer->sanitize($input);

        $this->assertStringNotContainsString('sk-abc123', $output);
        $this->assertStringContainsString('[REDACTED:API_KEY]', $output);
    }

    public function test_sanitizer_redacts_github_tokens(): void
    {
        $sanitizer = new ContentSanitizer;
        $input = 'Token: ghp_abc123def456ghi789jkl0';
        $output = $sanitizer->sanitize($input);

        $this->assertStringNotContainsString('ghp_abc123', $output);
        $this->assertStringContainsString('[REDACTED:GITHUB_TOKEN]', $output);
    }

    public function test_sanitizer_redacts_github_oauth_tokens(): void
    {
        $sanitizer = new ContentSanitizer;
        $input = 'OAuth: gho_AAAAfakeBBBBfakeCCCCfakeDDDDfakeEEEE';
        $output = $sanitizer->sanitize($input);

        $this->assertStringNotContainsString('gho_AAAA', $output);
        $this->assertStringContainsString('[REDACTED:GITHUB_TOKEN]', $output);
    }

    public function test_sanitizer_redacts_aiven_tokens(): void
    {
        $sanitizer = new ContentSanitizer;
        $input = 'DB connection: AVNS_abc123def456ghi789';
        $output = $sanitizer->sanitize($input);

        $this->assertStringNotContainsString('AVNS_abc123', $output);
        $this->assertStringContainsString('[REDACTED:API_KEY]', $output);
    }

    public function test_sanitizer_redacts_bearer_tokens(): void
    {
        $sanitizer = new ContentSanitizer;
        $input = 'Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.abc123';
        $output = $sanitizer->sanitize($input);

        $this->assertStringNotContainsString('eyJhbGci', $output);
        $this->assertStringContainsString('[REDACTED:BEARER_TOKEN]', $output);
    }

    public function test_sanitizer_redacts_env_passwords(): void
    {
        $sanitizer = new ContentSanitizer;
        $input = "DB_PASSWORD=supersecret123\nAPI_SECRET=mysecret";
        $output = $sanitizer->sanitize($input);

        $this->assertStringNotContainsString('supersecret123', $output);
        $this->assertStringNotContainsString('mysecret', $output);
    }

    public function test_sanitizer_redacts_url_credentials(): void
    {
        $sanitizer = new ContentSanitizer;
        $input = 'postgres://admin:s3cret@db.example.com:5432/mydb';
        $output = $sanitizer->sanitize($input);

        $this->assertStringNotContainsString('admin:s3cret', $output);
        $this->assertStringContainsString('[REDACTED:CREDENTIALS]@', $output);
    }

    public function test_sanitizer_preserves_normal_content(): void
    {
        $sanitizer = new ContentSanitizer;
        $input = "This is normal text about a project.\nIt mentions no secrets at all.\n# Heading\n- bullet point";
        $output = $sanitizer->sanitize($input);

        $this->assertEquals($input, $output);
    }

    public function test_drawer_add_sanitizes_content(): void
    {
        $tool = app(DrawerAddTool::class);
        $result = $tool->execute([
            'wing' => 'test',
            'content' => 'API key is sk-abc123def456ghi789jkl012mno345 and it works',
        ], $this->apiKey);

        $drawer = Drawer::find($result['drawer_id']);

        $this->assertStringNotContainsString('sk-abc123', $drawer->content);
        $this->assertStringContainsString('[REDACTED:API_KEY]', $drawer->content);
    }

    public function test_sanitizer_does_not_redact_short_bearer(): void
    {
        $sanitizer = new ContentSanitizer;
        // Short bearer tokens should not trigger (< 20 chars)
        $input = 'Bearer abc123';
        $output = $sanitizer->sanitize($input);

        $this->assertEquals($input, $output);
    }

    // ─── Wiki Lint with confidence_score ─────────────────────────────

    public function test_wiki_lint_detects_low_confidence_score(): void
    {
        WikiPage::create([
            'name' => 'test:low-score',
            'type' => 'concept',
            'title' => 'Low Score',
            'content' => 'Content with very low confidence score for lint testing',
            'confidence_score' => 0.15,
            'last_compiled_at' => now(),
        ]);

        $tool = app(WikiLintTool::class);
        $result = $tool->execute(['focus' => 'low_confidence'], $this->apiKey);

        $lowScoreFindings = array_filter($result['findings'], fn ($f) => $f['page'] === 'test:low-score');
        $this->assertNotEmpty($lowScoreFindings);

        $finding = array_values($lowScoreFindings)[0];
        $this->assertEquals('warning', $finding['severity']);
        $this->assertStringContainsString('0.15', $finding['description']);
    }

    public function test_wiki_lint_ignores_high_confidence_score(): void
    {
        WikiPage::create([
            'name' => 'test:high-score',
            'type' => 'concept',
            'title' => 'High Score',
            'content' => 'Content with high confidence score for lint testing',
            'confidence_score' => 0.85,
            'last_compiled_at' => now(),
        ]);

        $tool = app(WikiLintTool::class);
        $result = $tool->execute(['focus' => 'low_confidence'], $this->apiKey);

        $highScoreFindings = array_filter($result['findings'], fn ($f) => $f['page'] === 'test:high-score');
        $this->assertEmpty($highScoreFindings);
    }

    // ─── Migration Test ──────────────────────────────────────────────

    public function test_tier2_migration_columns_exist(): void
    {
        // Wiki page columns
        $page = WikiPage::create([
            'name' => 'test:migration',
            'type' => 'concept',
            'title' => 'Migration Test',
            'content' => 'Content for migration column verification',
            'confidence_score' => 0.5,
            'source_count' => 2,
            'last_accessed_at' => now(),
            'revision_count' => 3,
            'previous_content_hash' => 'abc123',
        ]);

        $page->refresh();
        $this->assertEqualsWithDelta(0.5, $page->confidence_score, 0.001);
        $this->assertEquals(2, $page->source_count);
        $this->assertNotNull($page->last_accessed_at);
        $this->assertEquals(3, $page->revision_count);
        $this->assertEquals('abc123', $page->previous_content_hash);

        // Drawer columns
        $wing = Wing::create(['name' => 'test', 'slug' => 'test']);
        $room = Room::create(['name' => 'notes', 'slug' => 'notes', 'wing_id' => $wing->id]);
        $drawer = Drawer::create([
            'content' => 'Content for drawer tier testing',
            'room_id' => $room->id,
            'tier' => 'reviewed',
        ]);

        $drawer->refresh();
        $this->assertEquals('reviewed', $drawer->tier);
    }

    // ─── Tool Registry ───────────────────────────────────────────────

    public function test_wiki_history_tool_is_registered(): void
    {
        $toolNames = McpToolRegistry::toolNames();
        $this->assertContains('wiki_history', $toolNames);
    }

    // ─── Import command --no-sanitize ────────────────────────────────

    public function test_import_memory_has_no_sanitize_option(): void
    {
        $this->artisan('mnemon:import-memory', [
            'path' => '/nonexistent',
            '--no-sanitize' => true,
        ])->assertFailed(); // Fails because path doesn't exist, but option is accepted
    }

    public function test_ingest_sessions_has_no_sanitize_option(): void
    {
        $this->artisan('mnemon:ingest-sessions', [
            'path' => '/nonexistent',
            '--no-sanitize' => true,
        ])->assertFailed(); // Fails because path doesn't exist, but option is accepted
    }

    // ─── DrawerSearchTool tier in response ───────────────────────────

    public function test_drawer_search_returns_tier_in_results(): void
    {
        $wing = Wing::create(['name' => 'test', 'slug' => 'test']);
        $room = Room::create(['name' => 'notes', 'slug' => 'notes', 'wing_id' => $wing->id]);
        Drawer::create(['content' => 'Reviewed knowledge about architecture', 'room_id' => $room->id, 'tier' => 'reviewed']);

        $tool = app(DrawerSearchTool::class);
        $result = $tool->execute(['query' => 'architecture'], $this->apiKey);

        $this->assertNotEmpty($result['results']);
        $this->assertArrayHasKey('tier', $result['results'][0]);
        $this->assertEquals('reviewed', $result['results'][0]['tier']);
    }

    // ─── Context set revision incrementing through multiple updates ──

    public function test_multiple_revisions_increment_correctly(): void
    {
        $tool = app(ContextSetTool::class);

        // Create initial
        $tool->execute([
            'name' => 'concept:multi-rev',
            'content' => 'Version 1 of the content',
        ], $this->apiKey);

        $page = WikiPage::where('name', 'concept:multi-rev')->first();
        $this->assertEquals(1, $page->revision_count);

        // Update 1
        $tool->execute([
            'name' => 'concept:multi-rev',
            'content' => 'Version 2 of the content',
        ], $this->apiKey);

        $page->refresh();
        $this->assertEquals(2, $page->revision_count);
        $this->assertEquals(hash('sha256', 'Version 1 of the content'), $page->previous_content_hash);

        // Update 2
        $tool->execute([
            'name' => 'concept:multi-rev',
            'content' => 'Version 3 of the content',
        ], $this->apiKey);

        $page->refresh();
        $this->assertEquals(3, $page->revision_count);
        $this->assertEquals(hash('sha256', 'Version 2 of the content'), $page->previous_content_hash);
    }
}
