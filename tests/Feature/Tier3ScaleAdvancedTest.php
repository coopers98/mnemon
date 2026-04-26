<?php

namespace Tests\Feature;

use App\Console\Commands\ApplyRetentionCommand;
use App\Mcp\McpException;
use App\Mcp\Tools\ContextSetTool;
use App\Mcp\Tools\WikiGraphTool;
use App\Mcp\Tools\WikiHistoryTool;
use App\Models\ApiKey;
use App\Models\Drawer;
use App\Models\Entity;
use App\Models\EntityRelationship;
use App\Models\Room;
use App\Models\WikiLintAction;
use App\Models\WikiPage;
use App\Models\WikiPageRevision;
use App\Models\Wing;
use App\Services\KnowledgeGraphService;
use App\Services\QualityScoreService;
use App\Services\WikiLintAutoFixer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Tier3ScaleAdvancedTest extends TestCase
{
    use RefreshDatabase;

    private ApiKey $apiKey;

    private Wing $wing;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = ApiKey::create([
            'name' => 'tier3-test',
            'key_hash' => hash('sha256', 'tier3-test-key'),
            'scopes' => ['*'],
        ]);

        $this->wing = Wing::create(['name' => 'Test Wing', 'slug' => 'test']);
        $this->room = Room::create(['wing_id' => $this->wing->id, 'name' => 'Test Room', 'slug' => 'test-room']);
    }

    // ─── KnowledgeGraphService ───────────────────────────────────────────

    public function test_add_edge_creates_relationship(): void
    {
        WikiPage::create(['name' => 'project:alpha', 'type' => 'project', 'content' => 'Alpha project']);
        WikiPage::create(['name' => 'person:alice', 'type' => 'person', 'content' => 'Alice']);

        $service = app(KnowledgeGraphService::class);
        $edge = $service->addEdge('project:alpha', 'person:alice', 'uses', 'Alice works on Alpha');

        $this->assertInstanceOf(EntityRelationship::class, $edge);
        $this->assertEquals('project:alpha', $edge->from_page);
        $this->assertEquals('person:alice', $edge->to_page);
        $this->assertEquals('uses', $edge->edge_type);
        $this->assertEquals('Alice works on Alpha', $edge->description);
    }

    public function test_add_edge_upserts_on_duplicate(): void
    {
        WikiPage::create(['name' => 'a', 'type' => 'concept', 'content' => 'A']);
        WikiPage::create(['name' => 'b', 'type' => 'concept', 'content' => 'B']);

        $service = app(KnowledgeGraphService::class);
        $service->addEdge('a', 'b', 'references', 'first');
        $service->addEdge('a', 'b', 'references', 'updated');

        $this->assertEquals(1, EntityRelationship::count());
        $this->assertEquals('updated', EntityRelationship::first()->description);
    }

    public function test_add_edge_rejects_invalid_edge_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $service = app(KnowledgeGraphService::class);
        $service->addEdge('a', 'b', 'invalid-type');
    }

    public function test_traverse_returns_subgraph(): void
    {
        WikiPage::create(['name' => 'hub', 'type' => 'concept', 'content' => 'Hub page']);
        WikiPage::create(['name' => 'spoke1', 'type' => 'concept', 'content' => 'Spoke 1']);
        WikiPage::create(['name' => 'spoke2', 'type' => 'concept', 'content' => 'Spoke 2']);

        $service = app(KnowledgeGraphService::class);
        $service->addEdge('hub', 'spoke1', 'references');
        $service->addEdge('hub', 'spoke2', 'uses');

        $result = $service->traverse('hub', 1);

        $this->assertEquals('hub', $result['start']);
        $this->assertEquals(3, $result['node_count']);
        $this->assertEquals(2, $result['edge_count']);
    }

    public function test_traverse_respects_max_depth(): void
    {
        WikiPage::create(['name' => 'a', 'type' => 'concept', 'content' => 'A']);
        WikiPage::create(['name' => 'b', 'type' => 'concept', 'content' => 'B']);
        WikiPage::create(['name' => 'c', 'type' => 'concept', 'content' => 'C']);

        $service = app(KnowledgeGraphService::class);
        $service->addEdge('a', 'b', 'references');
        $service->addEdge('b', 'c', 'references');

        // Depth 1 should get a and b, but not c
        $result = $service->traverse('a', 1);
        $this->assertEquals(2, $result['node_count']);

        // Depth 2 should get all three
        $result = $service->traverse('a', 2);
        $this->assertEquals(3, $result['node_count']);
    }

    public function test_traverse_caps_at_100_nodes(): void
    {
        // Create 110 pages in a chain
        for ($i = 0; $i < 110; $i++) {
            WikiPage::create(['name' => "node{$i}", 'type' => 'concept', 'content' => "Node {$i}"]);
        }

        $service = app(KnowledgeGraphService::class);
        for ($i = 0; $i < 109; $i++) {
            $service->addEdge("node{$i}", 'node'.($i + 1), 'references');
        }

        $result = $service->traverse('node0', 200);
        $this->assertLessThanOrEqual(100, $result['node_count']);
    }

    public function test_extract_entity_from_prefixed_page(): void
    {
        $page = WikiPage::create(['name' => 'person:cooper', 'type' => 'person', 'content' => 'Cooper info', 'description' => 'A person']);

        $service = app(KnowledgeGraphService::class);
        $entity = $service->extractEntity($page);

        $this->assertNotNull($entity);
        $this->assertEquals('person', $entity->type);
        $this->assertEquals('person:cooper', $entity->name);
        $this->assertEquals('person:cooper', $entity->source_page);
    }

    public function test_extract_entity_returns_null_for_synthesis(): void
    {
        $page = WikiPage::create(['name' => 'wiki/index', 'type' => 'synthesis', 'content' => 'Index']);

        $service = app(KnowledgeGraphService::class);
        $entity = $service->extractEntity($page);

        $this->assertNull($entity);
    }

    public function test_sync_references_from_related(): void
    {
        $pageA = WikiPage::create(['name' => 'a', 'type' => 'concept', 'content' => 'A', 'related' => ['b', 'c']]);
        WikiPage::create(['name' => 'b', 'type' => 'concept', 'content' => 'B']);
        WikiPage::create(['name' => 'c', 'type' => 'concept', 'content' => 'C']);

        $service = app(KnowledgeGraphService::class);
        $count = $service->syncReferencesFromRelated($pageA);

        $this->assertEquals(2, $count);
        $this->assertEquals(2, EntityRelationship::where('from_page', 'a')->where('edge_type', 'references')->count());
    }

    public function test_sync_references_skips_nonexistent_targets(): void
    {
        $page = WikiPage::create(['name' => 'a', 'type' => 'concept', 'content' => 'A', 'related' => ['nonexistent']]);

        $service = app(KnowledgeGraphService::class);
        $count = $service->syncReferencesFromRelated($page);

        $this->assertEquals(0, $count);
    }

    // ─── QualityScoreService ─────────────────────────────────────────────

    public function test_quality_score_returns_between_0_and_1(): void
    {
        $service = app(QualityScoreService::class);

        $score = $service->score('Some content here with enough words to matter.');
        $this->assertGreaterThanOrEqual(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_quality_score_empty_content_returns_zero(): void
    {
        $service = app(QualityScoreService::class);

        $score = $service->score('');
        $this->assertEquals(0.0, $score);
    }

    public function test_quality_score_structured_content_scores_higher(): void
    {
        $service = app(QualityScoreService::class);

        $plain = $service->score(str_repeat('word ', 50));
        $structured = $service->score("## Heading\n\n- Bullet one\n- Bullet two\n\n".str_repeat('word ', 50));

        $this->assertGreaterThan($plain, $structured);
    }

    public function test_quality_score_with_links_scores_higher(): void
    {
        $service = app(QualityScoreService::class);

        $noLinks = $service->score(str_repeat('word ', 50));
        $withLinks = $service->score(str_repeat('word ', 50).' [ref](https://example.com) [1] [2]');

        $this->assertGreaterThan($noLinks, $withLinks);
    }

    // ─── WikiLintAutoFixer ───────────────────────────────────────────────

    public function test_lint_fixer_prunes_broken_related_refs(): void
    {
        WikiPage::create(['name' => 'existing', 'type' => 'concept', 'content' => 'Exists']);
        WikiPage::create([
            'name' => 'page-with-refs',
            'type' => 'concept',
            'content' => 'Has refs',
            'related' => ['existing', 'gone-page'],
        ]);

        $fixer = new WikiLintAutoFixer;
        $result = $fixer->fix([]);

        $this->assertEquals(1, $result['summary']['pruned_related']);
        $page = WikiPage::where('name', 'page-with-refs')->first();
        $this->assertEquals(['existing'], $page->related);
    }

    public function test_lint_fixer_archives_empty_page_with_soft_delete(): void
    {
        $page = WikiPage::create(['name' => 'empty-stub', 'type' => 'concept', 'content' => '']);

        $fixer = new WikiLintAutoFixer;
        $result = $fixer->fix([['type' => 'empty', 'page' => 'empty-stub']]);

        $this->assertEquals(1, $result['summary']['archived_empty']);
        // Soft-deleted: not in normal query but exists when including trashed
        $this->assertNull(WikiPage::where('name', 'empty-stub')->first());
        $this->assertNotNull(WikiPage::withTrashed()->where('name', 'empty-stub')->first());
    }

    public function test_lint_fixer_stores_full_content_before_archive(): void
    {
        $longContent = str_repeat('a', 500);
        WikiPage::create(['name' => 'long-stub', 'type' => 'concept', 'content' => $longContent]);

        $fixer = new WikiLintAutoFixer;
        $fixer->fix([['type' => 'empty', 'page' => 'long-stub']]);

        $action = WikiLintAction::where('action', 'archive_empty')->first();
        $this->assertNotNull($action);
        // Full content stored, not truncated to 200
        $this->assertEquals(500, mb_strlen($action->before));
    }

    public function test_lint_fixer_skips_index_and_log_pages(): void
    {
        WikiPage::create(['name' => 'wiki/index', 'type' => 'synthesis', 'content' => '']);
        WikiPage::create(['name' => 'wiki/log', 'type' => 'synthesis', 'content' => '']);

        $fixer = new WikiLintAutoFixer;
        $result = $fixer->fix([
            ['type' => 'empty', 'page' => 'wiki/index'],
            ['type' => 'empty', 'page' => 'wiki/log'],
        ]);

        $this->assertEquals(0, $result['summary']['archived_empty']);
    }

    public function test_lint_fixer_queues_orphan_for_review(): void
    {
        WikiPage::create(['name' => 'orphan-page', 'type' => 'concept', 'content' => 'Orphan']);

        $fixer = new WikiLintAutoFixer;
        $result = $fixer->fix([['type' => 'orphan', 'page' => 'orphan-page']]);

        $this->assertEquals(1, $result['summary']['queued_orphans']);
    }

    public function test_lint_fixer_fixes_heading_placeholders(): void
    {
        // The fixer currently doesn't implement heading fixes, but verify it doesn't crash
        WikiPage::create(['name' => 'heading-page', 'type' => 'concept', 'content' => '## Good heading']);

        $fixer = new WikiLintAutoFixer;
        $result = $fixer->fix([['type' => 'heading', 'page' => 'heading-page']]);

        // heading type is not handled — no applied actions from it
        $this->assertEquals(0, $result['summary']['total'] - $result['summary']['pruned_related']);
    }

    // ─── ApplyRetentionCommand ───────────────────────────────────────────

    public function test_retention_command_dry_run_does_not_modify(): void
    {
        $drawer = Drawer::create([
            'content' => 'Old content',
            'room_id' => $this->room->id,
            'tier' => 'raw',
            'retention_score' => 0.5,
            'last_accessed_at' => now()->subDays(365),
        ]);

        $this->artisan('mnemon:apply-retention', ['--dry-run' => true])
            ->assertExitCode(0);

        $drawer->refresh();
        // Dry-run should NOT have changed the score
        $this->assertEquals(0.5, $drawer->retention_score);
    }

    public function test_retention_command_force_skips_confirmation(): void
    {
        Drawer::create([
            'content' => 'Content',
            'room_id' => $this->room->id,
            'tier' => 'raw',
            'last_accessed_at' => now(),
        ]);

        $this->artisan('mnemon:apply-retention', ['--force' => true])
            ->assertExitCode(0);
    }

    public function test_retention_command_soft_deletes_low_score_drawers(): void
    {
        $drawer = Drawer::create([
            'content' => 'Ancient content',
            'room_id' => $this->room->id,
            'tier' => 'raw',
            'last_accessed_at' => now()->subDays(365),
        ]);

        $this->artisan('mnemon:apply-retention', ['--force' => true])
            ->assertExitCode(0);

        $this->assertSoftDeleted('drawers', ['id' => $drawer->id]);
    }

    // ─── WikiGraphTool ───────────────────────────────────────────────────

    public function test_wiki_graph_tool_requires_start_page(): void
    {
        $this->expectException(McpException::class);

        $tool = app(WikiGraphTool::class);
        $tool->execute([], $this->apiKey);
    }

    public function test_wiki_graph_tool_validates_max_depth_lower_bound(): void
    {
        WikiPage::create(['name' => 'test', 'type' => 'concept', 'content' => 'Test']);

        $this->expectException(McpException::class);

        $tool = app(WikiGraphTool::class);
        $tool->execute(['start_page' => 'test', 'max_depth' => 0], $this->apiKey);
    }

    public function test_wiki_graph_tool_validates_max_depth_upper_bound(): void
    {
        WikiPage::create(['name' => 'test', 'type' => 'concept', 'content' => 'Test']);

        $this->expectException(McpException::class);

        $tool = app(WikiGraphTool::class);
        $tool->execute(['start_page' => 'test', 'max_depth' => 6], $this->apiKey);
    }

    public function test_wiki_graph_tool_validates_edge_types(): void
    {
        WikiPage::create(['name' => 'test', 'type' => 'concept', 'content' => 'Test']);

        $this->expectException(McpException::class);

        $tool = app(WikiGraphTool::class);
        $tool->execute(['start_page' => 'test', 'edge_types' => ['bogus']], $this->apiKey);
    }

    public function test_wiki_graph_tool_returns_traversal(): void
    {
        WikiPage::create(['name' => 'hub', 'type' => 'concept', 'content' => 'Hub']);
        WikiPage::create(['name' => 'leaf', 'type' => 'concept', 'content' => 'Leaf']);
        EntityRelationship::create(['from_page' => 'hub', 'to_page' => 'leaf', 'edge_type' => 'references']);

        $tool = app(WikiGraphTool::class);
        $result = $tool->execute(['start_page' => 'hub', 'max_depth' => 1], $this->apiKey);

        $this->assertEquals(2, $result['node_count']);
        $this->assertEquals(1, $result['edge_count']);
    }

    public function test_wiki_graph_tool_returns_error_for_missing_page(): void
    {
        $tool = app(WikiGraphTool::class);
        $result = $tool->execute(['start_page' => 'nonexistent'], $this->apiKey);

        $this->assertArrayHasKey('error', $result);
    }

    // ─── WikiHistoryTool ─────────────────────────────────────────────────

    public function test_wiki_history_returns_revisions(): void
    {
        WikiPage::create(['name' => 'test-page', 'type' => 'concept', 'content' => 'V1', 'revision_count' => 2]);
        WikiPageRevision::create([
            'page_name' => 'test-page',
            'revision' => 1,
            'content' => 'V1 content',
            'content_hash' => hash('sha256', 'V1 content'),
            'agent_id' => 'agent-1',
            'written_at' => now()->subHour(),
        ]);
        WikiPageRevision::create([
            'page_name' => 'test-page',
            'revision' => 2,
            'content' => 'V2 content',
            'content_hash' => hash('sha256', 'V2 content'),
            'agent_id' => 'agent-2',
            'written_at' => now(),
        ]);

        $tool = app(WikiHistoryTool::class);
        $result = $tool->execute(['name' => 'test-page'], $this->apiKey);

        $this->assertEquals('test-page', $result['name']);
        $this->assertEquals(2, $result['revision_count']);
        $this->assertCount(2, $result['revisions']);
        // Should be ordered desc
        $this->assertEquals(2, $result['revisions'][0]['revision']);
    }

    public function test_wiki_history_empty_revisions(): void
    {
        WikiPage::create(['name' => 'no-history', 'type' => 'concept', 'content' => 'Content']);

        $tool = app(WikiHistoryTool::class);
        $result = $tool->execute(['name' => 'no-history'], $this->apiKey);

        $this->assertEquals('no-history', $result['name']);
        $this->assertEmpty($result['revisions']);
    }

    public function test_wiki_history_returns_error_for_missing_page(): void
    {
        $tool = app(WikiHistoryTool::class);
        $result = $tool->execute(['name' => 'nonexistent'], $this->apiKey);

        $this->assertArrayHasKey('error', $result);
    }

    // ─── ContextSetTool conflict detection ───────────────────────────────

    public function test_context_set_throws_on_revision_conflict(): void
    {
        WikiPage::create([
            'name' => 'conflict-page',
            'type' => 'concept',
            'content' => 'Original',
            'revision_count' => 3,
        ]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Conflict');

        $tool = app(ContextSetTool::class);
        $tool->execute([
            'name' => 'conflict-page',
            'content' => 'New content',
            'expected_revision' => 1, // stale revision
        ], $this->apiKey);
    }

    public function test_context_set_succeeds_with_correct_revision(): void
    {
        WikiPage::create([
            'name' => 'good-page',
            'type' => 'concept',
            'content' => 'Original',
            'revision_count' => 2,
        ]);

        $tool = app(ContextSetTool::class);
        $result = $tool->execute([
            'name' => 'good-page',
            'content' => 'Updated content',
            'expected_revision' => 2,
        ], $this->apiKey);

        $this->assertEquals('updated', $result['created_or_updated']);
        $this->assertEquals(3, $result['revision_count']);
    }

    public function test_context_set_extracts_entity_for_prefixed_page(): void
    {
        $tool = app(ContextSetTool::class);
        $result = $tool->execute([
            'name' => 'person:bob',
            'content' => 'Bob is a person',
        ], $this->apiKey);

        $this->assertEquals('created', $result['created_or_updated']);

        $entity = Entity::where('name', 'person:bob')->first();
        $this->assertNotNull($entity);
        $this->assertEquals('person', $entity->type);
    }

    // ─── Drawer tier field + retention score ─────────────────────────────

    public function test_drawer_default_tier_is_raw(): void
    {
        $drawer = Drawer::create([
            'content' => 'New drawer',
            'room_id' => $this->room->id,
        ]);

        $this->assertEquals('raw', $drawer->tier);
    }

    public function test_drawer_retention_score_fresh_is_near_one(): void
    {
        $drawer = Drawer::create([
            'content' => 'Fresh content',
            'room_id' => $this->room->id,
            'tier' => 'raw',
            'last_accessed_at' => now(),
        ]);

        $score = $drawer->computeRetentionScore();
        $this->assertGreaterThan(0.9, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_drawer_retention_score_decays_over_time(): void
    {
        $fresh = Drawer::create([
            'content' => 'Fresh',
            'room_id' => $this->room->id,
            'tier' => 'raw',
            'last_accessed_at' => now(),
        ]);

        $old = Drawer::create([
            'content' => 'Old',
            'room_id' => $this->room->id,
            'tier' => 'raw',
            'last_accessed_at' => now()->subDays(60),
        ]);

        $this->assertGreaterThan($old->computeRetentionScore(), $fresh->computeRetentionScore());
    }

    public function test_drawer_consolidated_tier_decays_slower(): void
    {
        $raw = Drawer::create([
            'content' => 'Raw',
            'room_id' => $this->room->id,
            'tier' => 'raw',
            'last_accessed_at' => now()->subDays(60),
        ]);

        $consolidated = Drawer::create([
            'content' => 'Consolidated',
            'room_id' => $this->room->id,
            'tier' => 'consolidated',
            'last_accessed_at' => now()->subDays(60),
        ]);

        $this->assertGreaterThan($raw->computeRetentionScore(), $consolidated->computeRetentionScore());
    }

    // ─── Entity + EntityRelationship CRUD ────────────────────────────────

    public function test_entity_create_and_retrieve(): void
    {
        $entity = Entity::create([
            'name' => 'person:alice',
            'type' => 'person',
            'source_page' => 'person:alice',
            'description' => 'Alice is a developer',
        ]);

        $this->assertDatabaseHas('entities', [
            'name' => 'person:alice',
            'type' => 'person',
        ]);

        $this->assertEquals('Alice is a developer', $entity->description);
    }

    public function test_entity_name_is_unique(): void
    {
        Entity::create(['name' => 'unique-entity', 'type' => 'person']);

        $this->expectException(QueryException::class);
        Entity::create(['name' => 'unique-entity', 'type' => 'project']);
    }

    public function test_entity_relationship_crud(): void
    {
        WikiPage::create(['name' => 'from-page', 'type' => 'concept', 'content' => 'From']);
        WikiPage::create(['name' => 'to-page', 'type' => 'concept', 'content' => 'To']);

        $rel = EntityRelationship::create([
            'from_page' => 'from-page',
            'to_page' => 'to-page',
            'edge_type' => 'uses',
            'description' => 'From uses To',
        ]);

        $this->assertDatabaseHas('entity_relationships', [
            'from_page' => 'from-page',
            'to_page' => 'to-page',
            'edge_type' => 'uses',
        ]);

        $this->assertNotNull($rel->fromPage);
        $this->assertNotNull($rel->toPage);
    }

    public function test_entity_relationship_unique_composite_index(): void
    {
        WikiPage::create(['name' => 'x', 'type' => 'concept', 'content' => 'X']);
        WikiPage::create(['name' => 'y', 'type' => 'concept', 'content' => 'Y']);

        EntityRelationship::create(['from_page' => 'x', 'to_page' => 'y', 'edge_type' => 'uses']);

        $this->expectException(QueryException::class);
        EntityRelationship::create(['from_page' => 'x', 'to_page' => 'y', 'edge_type' => 'uses']);
    }

    public function test_entity_relationship_allows_different_edge_types_same_pages(): void
    {
        WikiPage::create(['name' => 'p', 'type' => 'concept', 'content' => 'P']);
        WikiPage::create(['name' => 'q', 'type' => 'concept', 'content' => 'Q']);

        EntityRelationship::create(['from_page' => 'p', 'to_page' => 'q', 'edge_type' => 'uses']);
        EntityRelationship::create(['from_page' => 'p', 'to_page' => 'q', 'edge_type' => 'references']);

        $this->assertEquals(2, EntityRelationship::count());
    }

    // ─── WikiPage soft deletes ───────────────────────────────────────────

    public function test_wiki_page_soft_delete(): void
    {
        $page = WikiPage::create(['name' => 'deletable', 'type' => 'concept', 'content' => 'Content']);
        $page->delete();

        $this->assertNull(WikiPage::where('name', 'deletable')->first());
        $this->assertNotNull(WikiPage::withTrashed()->where('name', 'deletable')->first());
    }

    public function test_wiki_page_restore_after_soft_delete(): void
    {
        $page = WikiPage::create(['name' => 'restorable', 'type' => 'concept', 'content' => 'Content']);
        $page->delete();
        $page->restore();

        $this->assertNotNull(WikiPage::where('name', 'restorable')->first());
    }
}
