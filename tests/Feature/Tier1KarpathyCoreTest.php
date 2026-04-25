<?php

namespace Tests\Feature;

use App\Mcp\McpException;
use App\Mcp\McpToolRegistry;
use App\Mcp\Tools\BrainStatusTool;
use App\Mcp\Tools\ContextGetTool;
use App\Mcp\Tools\ContextListTool;
use App\Mcp\Tools\ContextSetTool;
use App\Mcp\Tools\DrawerAddTool;
use App\Mcp\Tools\PalaceWakeUpTool;
use App\Mcp\Tools\WikiCompileTool;
use App\Mcp\Tools\WikiLintTool;
use App\Models\ApiKey;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Tier1KarpathyCoreTest extends TestCase
{
    use RefreshDatabase;

    private ApiKey $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = ApiKey::create([
            'name' => 'Test Key',
            'key_hash' => hash('sha256', 'tier1-test-key'),
            'scopes' => ['*'],
        ]);
    }

    // ─── Item 1: Structured Metadata ─────────────────────────────────────────

    public function test_context_set_stores_confidence(): void
    {
        $tool = app(ContextSetTool::class);
        $result = $tool->execute([
            'name' => 'project:atlas',
            'content' => 'Atlas project details',
            'confidence' => 'high',
        ], $this->apiKey);

        $page = WikiPage::where('name', 'project:atlas')->first();
        $this->assertEquals('high', $page->confidence);
    }

    public function test_context_set_rejects_invalid_confidence(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('confidence');

        $tool = app(ContextSetTool::class);
        $tool->execute([
            'name' => 'project:test',
            'content' => 'Content',
            'confidence' => 'ultra',
        ], $this->apiKey);
    }

    public function test_context_set_stores_sources_as_array(): void
    {
        $wing = Wing::create(['name' => 'Work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        $d1 = Drawer::create(['content' => 'Source 1', 'room_id' => $room->id]);
        $d2 = Drawer::create(['content' => 'Source 2', 'room_id' => $room->id]);

        $tool = app(ContextSetTool::class);
        $tool->execute([
            'name' => 'project:work',
            'content' => 'Compiled from sources',
            'sources' => [$d1->id, $d2->id],
        ], $this->apiKey);

        $page = WikiPage::where('name', 'project:work')->first();
        $this->assertIsArray($page->sources);
        $this->assertCount(2, $page->sources);
        $this->assertContains($d1->id, $page->sources);
    }

    public function test_context_set_validates_source_drawer_ids_exist(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('drawer IDs do not exist');

        $tool = app(ContextSetTool::class);
        $tool->execute([
            'name' => 'project:test',
            'content' => 'Content',
            'sources' => [99999],
        ], $this->apiKey);
    }

    public function test_context_set_stores_related_pages(): void
    {
        $tool = app(ContextSetTool::class);
        $tool->execute([
            'name' => 'project:atlas',
            'content' => 'Atlas info',
            'related' => ['person:cooper', 'concept:microservices'],
        ], $this->apiKey);

        $page = WikiPage::where('name', 'project:atlas')->first();
        $this->assertIsArray($page->related);
        $this->assertContains('person:cooper', $page->related);
        $this->assertContains('concept:microservices', $page->related);
    }

    public function test_context_set_rejects_non_array_sources(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('sources');

        $tool = app(ContextSetTool::class);
        $tool->execute([
            'name' => 'project:test',
            'content' => 'Content',
            'sources' => 'not-an-array',
        ], $this->apiKey);
    }

    public function test_context_set_rejects_non_array_related(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('related');

        $tool = app(ContextSetTool::class);
        $tool->execute([
            'name' => 'project:test',
            'content' => 'Content',
            'related' => 'not-an-array',
        ], $this->apiKey);
    }

    public function test_context_get_returns_metadata_fields(): void
    {
        $wing = Wing::create(['name' => 'Work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        $drawer = Drawer::create(['content' => 'Source content here for testing', 'room_id' => $room->id]);

        WikiPage::create([
            'name' => 'project:test',
            'type' => 'project',
            'title' => 'Test',
            'content' => 'Test content goes here for the wiki page',
            'confidence' => 'medium',
            'sources' => [$drawer->id],
            'related' => ['concept:testing'],
        ]);

        $tool = app(ContextGetTool::class);
        $result = $tool->execute(['name' => 'project:test'], $this->apiKey);

        $this->assertEquals('medium', $result['confidence']);
        $this->assertEquals([$drawer->id], $result['sources']);
        $this->assertEquals(['concept:testing'], $result['related']);
        $this->assertArrayHasKey('source_details', $result);
        $this->assertCount(1, $result['source_details']);
        $this->assertEquals($drawer->id, $result['source_details'][0]['id']);
        $this->assertStringStartsWith('Source content', $result['source_details'][0]['content_preview']);
    }

    public function test_context_get_returns_pending_drawers_count(): void
    {
        WikiPage::create([
            'name' => 'project:test',
            'type' => 'project',
            'title' => 'Test',
            'content' => 'Content for the pending drawers test page here',
            'pending_drawers_since_compile' => 3,
        ]);

        $tool = app(ContextGetTool::class);
        $result = $tool->execute(['name' => 'project:test'], $this->apiKey);

        $this->assertEquals(3, $result['pending_drawers_since_compile']);
    }

    public function test_context_list_returns_confidence(): void
    {
        WikiPage::create([
            'name' => 'project:rated',
            'type' => 'project',
            'title' => 'Rated',
            'content' => 'This is a rated page with confidence level set',
            'confidence' => 'high',
        ]);

        $tool = app(ContextListTool::class);
        $result = $tool->execute([], $this->apiKey);

        $this->assertArrayHasKey('confidence', $result['pages'][0]);
        $this->assertEquals('high', $result['pages'][0]['confidence']);
    }

    public function test_context_list_returns_pending_drawers(): void
    {
        WikiPage::create([
            'name' => 'project:pending',
            'type' => 'project',
            'title' => 'Pending',
            'content' => 'This page has pending drawer updates to process',
            'pending_drawers_since_compile' => 5,
        ]);

        $tool = app(ContextListTool::class);
        $result = $tool->execute([], $this->apiKey);

        $this->assertEquals(5, $result['pages'][0]['pending_drawers_since_compile']);
    }

    // ─── Item 2: Source Citations ────────────────────────────────────────────

    public function test_context_get_returns_source_drawer_previews(): void
    {
        $wing = Wing::create(['name' => 'Research', 'slug' => 'research']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Papers', 'slug' => 'papers']);
        $d1 = Drawer::create(['content' => str_repeat('A', 300), 'room_id' => $room->id, 'source' => 'paper.pdf']);
        $d2 = Drawer::create(['content' => 'Short note', 'room_id' => $room->id, 'source' => 'manual']);

        WikiPage::create([
            'name' => 'concept:research-topic',
            'type' => 'concept',
            'title' => 'Research Topic',
            'content' => 'Synthesized research content for this topic page here',
            'sources' => [$d1->id, $d2->id],
        ]);

        $tool = app(ContextGetTool::class);
        $result = $tool->execute(['name' => 'concept:research-topic'], $this->apiKey);

        $this->assertCount(2, $result['source_details']);

        // First source should be truncated to 200 chars
        $this->assertEquals(200, mb_strlen($result['source_details'][0]['content_preview']));
        $this->assertEquals('paper.pdf', $result['source_details'][0]['source']);

        // Second source is short so full content returned
        $this->assertEquals('Short note', $result['source_details'][1]['content_preview']);
        $this->assertEquals('manual', $result['source_details'][1]['source']);
    }

    public function test_context_get_returns_empty_source_details_when_no_sources(): void
    {
        WikiPage::create([
            'name' => 'concept:nosource',
            'type' => 'concept',
            'title' => 'No Source',
            'content' => 'Page without any source drawer references at all',
        ]);

        $tool = app(ContextGetTool::class);
        $result = $tool->execute(['name' => 'concept:nosource'], $this->apiKey);

        $this->assertArrayNotHasKey('source_details', $result);
    }

    // ─── Item 3: Cascade Awareness ───────────────────────────────────────────

    public function test_drawer_add_increments_pending_counter_on_matching_wiki_page(): void
    {
        WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'title' => 'Atlas',
            'content' => 'Atlas project wiki page content for testing drawer cascade',
            'pending_drawers_since_compile' => 0,
        ]);

        $tool = app(DrawerAddTool::class);
        $result = $tool->execute([
            'content' => 'New info about Atlas project',
            'wing' => 'project:atlas',
        ], $this->apiKey);

        $page = WikiPage::where('name', 'project:atlas')->first();
        $this->assertEquals(1, $page->pending_drawers_since_compile);
        $this->assertEquals(1, $result['wiki_pages_flagged']);
    }

    public function test_drawer_add_increments_counter_multiple_times(): void
    {
        WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'title' => 'Atlas',
            'content' => 'Atlas project page for testing multiple drawer additions',
            'pending_drawers_since_compile' => 0,
        ]);

        $tool = app(DrawerAddTool::class);
        $tool->execute(['content' => 'Info 1', 'wing' => 'project:atlas'], $this->apiKey);
        $tool->execute(['content' => 'Info 2', 'wing' => 'project:atlas'], $this->apiKey);
        $tool->execute(['content' => 'Info 3', 'wing' => 'project:atlas'], $this->apiKey);

        $page = WikiPage::where('name', 'project:atlas')->first();
        $this->assertEquals(3, $page->pending_drawers_since_compile);
    }

    public function test_drawer_add_does_not_flag_when_no_matching_wiki_page(): void
    {
        $tool = app(DrawerAddTool::class);
        $result = $tool->execute([
            'content' => 'Some content',
            'wing' => 'random:stuff',
        ], $this->apiKey);

        $this->assertEquals(0, $result['wiki_pages_flagged']);
    }

    public function test_context_set_resets_pending_counter(): void
    {
        WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'title' => 'Atlas',
            'content' => 'Old content for the Atlas project wiki page here',
            'pending_drawers_since_compile' => 5,
        ]);

        $tool = app(ContextSetTool::class);
        $tool->execute([
            'name' => 'project:atlas',
            'content' => 'Newly compiled content incorporating recent drawers',
        ], $this->apiKey);

        $page = WikiPage::where('name', 'project:atlas')->first();
        $this->assertEquals(0, $page->pending_drawers_since_compile);
    }

    public function test_brain_status_shows_pending_update_pages(): void
    {
        WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'title' => 'Atlas',
            'content' => 'Atlas content for testing brain status pending pages view',
            'pending_drawers_since_compile' => 3,
        ]);

        $tool = app(BrainStatusTool::class);
        $result = $tool->execute([], $this->apiKey);

        $this->assertArrayHasKey('pending_update_pages', $result);
        $this->assertCount(1, $result['pending_update_pages']);
        $this->assertEquals('project:atlas', $result['pending_update_pages'][0]['name']);
        $this->assertEquals(3, $result['pending_update_pages'][0]['pending_drawers_since_compile']);
    }

    public function test_palace_wake_up_shows_pending_update_pages(): void
    {
        WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'title' => 'Atlas',
            'content' => 'Atlas content for testing palace wake up pending pages',
            'pending_drawers_since_compile' => 2,
        ]);

        $tool = app(PalaceWakeUpTool::class);
        $result = $tool->execute([], $this->apiKey);

        $this->assertArrayHasKey('pending_update_pages', $result);
        $this->assertCount(1, $result['pending_update_pages']);
        $this->assertEquals('project:atlas', $result['pending_update_pages'][0]['name']);
    }

    // ─── Item 4: wiki_lint ───────────────────────────────────────────────────

    public function test_wiki_lint_detects_stale_pages_by_date(): void
    {
        WikiPage::create([
            'name' => 'project:old',
            'type' => 'project',
            'title' => 'Old',
            'content' => 'This is a stale wiki page that has not been compiled recently at all',
            'last_compiled_at' => now()->subDays(40),
        ]);

        $tool = app(WikiLintTool::class);
        $result = $tool->execute([], $this->apiKey);

        $staleFindings = array_filter($result['findings'], fn ($f) => $f['type'] === 'stale');
        $names = array_column($staleFindings, 'page');
        $this->assertContains('project:old', $names);
    }

    public function test_wiki_lint_detects_stale_pages_by_pending_drawers(): void
    {
        WikiPage::create([
            'name' => 'project:pending',
            'type' => 'project',
            'title' => 'Pending',
            'content' => 'Page with pending drawers that wiki lint should detect as stale',
            'last_compiled_at' => now(),
            'pending_drawers_since_compile' => 3,
        ]);

        $tool = app(WikiLintTool::class);
        $result = $tool->execute([], $this->apiKey);

        $staleFindings = array_filter($result['findings'], fn ($f) => $f['type'] === 'stale');
        $names = array_column($staleFindings, 'page');
        $this->assertContains('project:pending', $names);
    }

    public function test_wiki_lint_detects_orphan_pages(): void
    {
        WikiPage::create([
            'name' => 'project:lonely',
            'type' => 'project',
            'title' => 'Lonely',
            'content' => 'This page is not referenced by any other wiki page in related arrays',
        ]);

        WikiPage::create([
            'name' => 'project:connected',
            'type' => 'project',
            'title' => 'Connected',
            'content' => 'This page references the lonely page to make it non-orphan test',
            'related' => ['project:connected'],  // self-reference, not lonely
        ]);

        $tool = app(WikiLintTool::class);
        $result = $tool->execute([], $this->apiKey);

        $orphanFindings = array_filter($result['findings'], fn ($f) => $f['type'] === 'orphan');
        $names = array_column($orphanFindings, 'page');
        $this->assertContains('project:lonely', $names);
    }

    public function test_wiki_lint_excludes_index_and_log_from_orphan_check(): void
    {
        WikiPage::create([
            'name' => 'wiki/index',
            'type' => 'synthesis',
            'title' => 'Index',
            'content' => 'Wiki Index page that should be excluded from orphan detection checks',
        ]);

        WikiPage::create([
            'name' => 'wiki/log',
            'type' => 'synthesis',
            'title' => 'Log',
            'content' => 'Wiki Log page that should also be excluded from orphan detection',
        ]);

        $tool = app(WikiLintTool::class);
        $result = $tool->execute([], $this->apiKey);

        $orphanFindings = array_filter($result['findings'], fn ($f) => $f['type'] === 'orphan');
        $names = array_column($orphanFindings, 'page');
        $this->assertNotContains('wiki/index', $names);
        $this->assertNotContains('wiki/log', $names);
    }

    public function test_wiki_lint_detects_empty_pages(): void
    {
        WikiPage::create([
            'name' => 'concept:stub',
            'type' => 'concept',
            'title' => 'Stub',
            'content' => 'Short',
        ]);

        $tool = app(WikiLintTool::class);
        $result = $tool->execute([], $this->apiKey);

        $emptyFindings = array_filter($result['findings'], fn ($f) => $f['type'] === 'empty');
        $names = array_column($emptyFindings, 'page');
        $this->assertContains('concept:stub', $names);
    }

    public function test_wiki_lint_detects_low_confidence_pages(): void
    {
        WikiPage::create([
            'name' => 'concept:uncertain',
            'type' => 'concept',
            'title' => 'Uncertain',
            'content' => 'This concept has low confidence and needs more verification from sources',
            'confidence' => 'low',
        ]);

        $tool = app(WikiLintTool::class);
        $result = $tool->execute([], $this->apiKey);

        $lcFindings = array_filter($result['findings'], fn ($f) => $f['type'] === 'low_confidence');
        $names = array_column($lcFindings, 'page');
        $this->assertContains('concept:uncertain', $names);
    }

    public function test_wiki_lint_returns_summary_counts(): void
    {
        WikiPage::create([
            'name' => 'concept:stub',
            'type' => 'concept',
            'title' => 'Stub',
            'content' => 'x',
        ]);

        $tool = app(WikiLintTool::class);
        $result = $tool->execute([], $this->apiKey);

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('total', $result['summary']);
        $this->assertArrayHasKey('warnings', $result['summary']);
        $this->assertArrayHasKey('info', $result['summary']);
        $this->assertGreaterThan(0, $result['summary']['total']);
    }

    public function test_wiki_lint_logs_brain_session(): void
    {
        $tool = app(WikiLintTool::class);
        $tool->execute([], $this->apiKey);

        $this->assertDatabaseHas('brain_sessions', [
            'tool_name' => 'wiki_lint',
            'source' => 'Test Key',
        ]);
    }

    public function test_wiki_lint_is_registered_in_tool_registry(): void
    {
        $this->assertContains('wiki_lint', McpToolRegistry::toolNames());
    }

    // ─── Item 5: wiki_compile ────────────────────────────────────────────────

    public function test_wiki_compile_returns_drawers_from_matching_wing(): void
    {
        $wing = Wing::create(['name' => 'project:atlas', 'slug' => 'project-atlas']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => '2026-04', 'slug' => '2026-04']);
        Drawer::create(['content' => 'Atlas info 1', 'room_id' => $room->id, 'source' => 'meeting']);
        Drawer::create(['content' => 'Atlas info 2', 'room_id' => $room->id, 'source' => 'slack']);

        $tool = app(WikiCompileTool::class);
        $result = $tool->execute(['name' => 'project:atlas'], $this->apiKey);

        $this->assertEquals(2, $result['drawer_count']);
        $this->assertCount(2, $result['drawers']);
        $this->assertArrayHasKey('content', $result['drawers'][0]);
        $this->assertArrayHasKey('source', $result['drawers'][0]);
        $this->assertArrayHasKey('created_at', $result['drawers'][0]);
    }

    public function test_wiki_compile_returns_existing_page_content(): void
    {
        $wing = Wing::create(['name' => 'project:atlas', 'slug' => 'project-atlas']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => '2026-04', 'slug' => '2026-04']);
        Drawer::create(['content' => 'New drawer', 'room_id' => $room->id]);

        WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'title' => 'Atlas',
            'content' => 'Current compiled content for the Atlas project wiki page',
            'last_compiled_at' => now()->subDays(10),
        ]);

        $tool = app(WikiCompileTool::class);
        $result = $tool->execute(['name' => 'project:atlas'], $this->apiKey);

        $this->assertNotNull($result['page']);
        $this->assertEquals('project:atlas', $result['page']['name']);
        $this->assertStringContainsString('Current compiled content', $result['page']['current_content']);
    }

    public function test_wiki_compile_returns_null_page_when_not_exists(): void
    {
        $wing = Wing::create(['name' => 'project:new', 'slug' => 'project-new']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => '2026-04', 'slug' => '2026-04']);
        Drawer::create(['content' => 'Some content', 'room_id' => $room->id]);

        $tool = app(WikiCompileTool::class);
        $result = $tool->execute(['name' => 'project:new'], $this->apiKey);

        $this->assertNull($result['page']);
        $this->assertGreaterThan(0, $result['drawer_count']);
    }

    public function test_wiki_compile_respects_limit_parameter(): void
    {
        $wing = Wing::create(['name' => 'project:big', 'slug' => 'project-big']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => '2026-04', 'slug' => '2026-04']);

        for ($i = 0; $i < 10; $i++) {
            Drawer::create(['content' => "Drawer {$i}", 'room_id' => $room->id]);
        }

        $tool = app(WikiCompileTool::class);
        $result = $tool->execute(['name' => 'project:big', 'limit' => 3], $this->apiKey);

        $this->assertEquals(3, $result['drawer_count']);
        $this->assertCount(3, $result['drawers']);
    }

    public function test_wiki_compile_returns_error_when_no_matching_wing(): void
    {
        $tool = app(WikiCompileTool::class);
        $result = $tool->execute(['name' => 'project:nonexistent'], $this->apiKey);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('No wing found', $result['error']);
    }

    public function test_wiki_compile_requires_name_parameter(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('name');

        $tool = app(WikiCompileTool::class);
        $tool->execute([], $this->apiKey);
    }

    public function test_wiki_compile_logs_brain_session(): void
    {
        $wing = Wing::create(['name' => 'project:test', 'slug' => 'project-test']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => '2026-04', 'slug' => '2026-04']);
        Drawer::create(['content' => 'Test', 'room_id' => $room->id]);

        $tool = app(WikiCompileTool::class);
        $tool->execute(['name' => 'project:test'], $this->apiKey);

        $this->assertDatabaseHas('brain_sessions', [
            'tool_name' => 'wiki_compile',
            'source' => 'Test Key',
        ]);
    }

    public function test_wiki_compile_is_registered_in_tool_registry(): void
    {
        $this->assertContains('wiki_compile', McpToolRegistry::toolNames());
    }

    public function test_wiki_compile_returns_drawers_sorted_by_newest_first(): void
    {
        $wing = Wing::create(['name' => 'project:sorted', 'slug' => 'project-sorted']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => '2026-04', 'slug' => '2026-04']);

        // Use travel() to ensure distinct timestamps
        $this->travel(-5)->days();
        Drawer::create(['content' => 'Old drawer', 'room_id' => $room->id]);

        $this->travelBack();
        Drawer::create(['content' => 'New drawer', 'room_id' => $room->id]);

        $tool = app(WikiCompileTool::class);
        $result = $tool->execute(['name' => 'project:sorted'], $this->apiKey);

        $this->assertEquals('New drawer', $result['drawers'][0]['content']);
        $this->assertEquals('Old drawer', $result['drawers'][1]['content']);
    }

    // ─── Migration Test ──────────────────────────────────────────────────────

    public function test_wiki_page_has_new_metadata_columns(): void
    {
        $page = WikiPage::create([
            'name' => 'test:schema',
            'type' => 'synthesis',
            'title' => 'Schema Test',
            'content' => 'Testing that all new metadata columns exist and work correctly',
            'confidence' => 'high',
            'sources' => [1, 2, 3],
            'related' => ['person:alice', 'project:beta'],
            'pending_drawers_since_compile' => 7,
        ]);

        $page->refresh();

        $this->assertEquals('high', $page->confidence);
        $this->assertEquals([1, 2, 3], $page->sources);
        $this->assertEquals(['person:alice', 'project:beta'], $page->related);
        $this->assertEquals(7, $page->pending_drawers_since_compile);
    }

    public function test_pending_drawers_defaults_to_zero(): void
    {
        $page = WikiPage::create([
            'name' => 'test:default',
            'type' => 'synthesis',
            'title' => 'Default Test',
            'content' => 'Testing that pending drawers since compile defaults to zero',
        ]);

        $page->refresh();
        $this->assertEquals(0, $page->pending_drawers_since_compile);
    }
}
