<?php

namespace Tests\Feature;

use App\Mcp\McpException;
use App\Mcp\Tools\BrainStatusTool;
use App\Mcp\Tools\ContextGetTool;
use App\Mcp\Tools\ContextListTool;
use App\Mcp\Tools\ContextSetTool;
use App\Mcp\Tools\DrawerAddTool;
use App\Mcp\Tools\PalaceWakeUpTool;
use App\Models\ApiKey;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpToolTest extends TestCase
{
    use RefreshDatabase;

    private ApiKey $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = ApiKey::create([
            'name' => 'Test Key',
            'key_hash' => hash('sha256', 'test-key-value'),
            'scopes' => ['*'],
        ]);
    }

    // ─── brain_status ────────────────────────────────────────────────────────

    public function test_brain_status_returns_correct_counts(): void
    {
        $wing = Wing::create(['name' => 'Work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        Drawer::create(['content' => 'Hello', 'room_id' => $room->id]);
        WikiPage::create(['name' => 'project:x', 'type' => 'project', 'content' => 'Stuff']);

        $tool = app(BrainStatusTool::class);
        $result = $tool->execute([], $this->apiKey);

        $this->assertEquals(1, $result['drawer_count']);
        $this->assertEquals(1, $result['wiki_page_count']);
        $this->assertIsArray($result['wings']);
        $this->assertCount(1, $result['wings']);
        $this->assertEquals('work', $result['wings'][0]['slug']);
        $this->assertArrayHasKey('embedding_driver', $result);
        $this->assertArrayHasKey('last_write', $result);
        $this->assertArrayHasKey('stale_wiki_pages', $result);
    }

    public function test_brain_status_identifies_stale_wiki_pages(): void
    {
        WikiPage::create([
            'name' => 'old-page',
            'type' => 'synthesis',
            'content' => 'Old content',
            'last_compiled_at' => now()->subDays(40),
        ]);

        WikiPage::create([
            'name' => 'fresh-page',
            'type' => 'synthesis',
            'content' => 'New content',
            'last_compiled_at' => now()->subDays(5),
        ]);

        $tool = app(BrainStatusTool::class);
        $result = $tool->execute([], $this->apiKey);

        $staleNames = array_column($result['stale_wiki_pages'], 'name');
        $this->assertContains('old-page', $staleNames);
        $this->assertNotContains('fresh-page', $staleNames);
    }

    public function test_brain_status_logs_brain_session(): void
    {
        $tool = app(BrainStatusTool::class);
        $tool->execute([], $this->apiKey);

        $this->assertDatabaseHas('brain_sessions', [
            'tool_name' => 'brain_status',
            'source' => 'Test Key',
        ]);
    }

    // ─── palace_wake_up ──────────────────────────────────────────────────────

    public function test_palace_wake_up_returns_recent_drawers(): void
    {
        $wing = Wing::create(['name' => 'Work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);

        for ($i = 1; $i <= 12; $i++) {
            Drawer::create(['content' => "Drawer {$i}", 'room_id' => $room->id]);
        }

        $tool = app(PalaceWakeUpTool::class);
        $result = $tool->execute([], $this->apiKey);

        $this->assertArrayHasKey('recent_drawers', $result);
        $this->assertCount(10, $result['recent_drawers']);
        $this->assertArrayHasKey('content_preview', $result['recent_drawers'][0]);
        $this->assertArrayHasKey('wing', $result['recent_drawers'][0]);
        $this->assertArrayHasKey('room', $result['recent_drawers'][0]);
    }

    public function test_palace_wake_up_returns_active_wings(): void
    {
        $wing = Wing::create(['name' => 'Work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        Drawer::create(['content' => 'A note', 'room_id' => $room->id]);

        $tool = app(PalaceWakeUpTool::class);
        $result = $tool->execute([], $this->apiKey);

        $this->assertArrayHasKey('active_wings', $result);
        $this->assertCount(1, $result['active_wings']);
        $this->assertEquals('work', $result['active_wings'][0]['slug']);
    }

    public function test_palace_wake_up_returns_stale_wiki_pages(): void
    {
        WikiPage::create([
            'name' => 'stale-page',
            'type' => 'synthesis',
            'content' => 'Old',
            'last_compiled_at' => now()->subDays(35),
        ]);

        $tool = app(PalaceWakeUpTool::class);
        $result = $tool->execute([], $this->apiKey);

        $staleNames = array_column($result['stale_wiki_pages'], 'name');
        $this->assertContains('stale-page', $staleNames);
    }

    // ─── drawer_add ──────────────────────────────────────────────────────────

    public function test_drawer_add_creates_drawer_with_auto_created_wing_and_room(): void
    {
        $tool = app(DrawerAddTool::class);
        $result = $tool->execute([
            'content' => 'Test note content',
            'wing' => 'My Projects',
        ], $this->apiKey);

        $this->assertArrayHasKey('drawer_id', $result);
        $this->assertEquals('my-projects', $result['wing_slug']);
        $this->assertDatabaseHas('wings', ['slug' => 'my-projects']);
        $this->assertDatabaseHas('drawers', ['id' => $result['drawer_id']]);
    }

    public function test_drawer_add_with_existing_wing_reuses_it(): void
    {
        Wing::create(['name' => 'Work', 'slug' => 'work']);

        $tool = app(DrawerAddTool::class);
        $result = $tool->execute([
            'content' => 'Another note',
            'wing' => 'Work',
        ], $this->apiKey);

        $this->assertEquals(1, Wing::where('slug', 'work')->count());
        $this->assertEquals('work', $result['wing_slug']);
    }

    public function test_drawer_add_auto_creates_room_with_default_yyyy_mm(): void
    {
        $tool = app(DrawerAddTool::class);
        $result = $tool->execute([
            'content' => 'Note',
            'wing' => 'Work',
        ], $this->apiKey);

        $expectedSlug = now()->format('Y-m');
        $this->assertEquals($expectedSlug, $result['room_slug']);
    }

    public function test_drawer_add_requires_content(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('content');

        $tool = app(DrawerAddTool::class);
        $tool->execute(['wing' => 'Work'], $this->apiKey);
    }

    public function test_drawer_add_requires_wing(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('wing');

        $tool = app(DrawerAddTool::class);
        $tool->execute(['content' => 'test'], $this->apiKey);
    }

    // ─── context_get ─────────────────────────────────────────────────────────

    public function test_context_get_returns_wiki_page_by_name(): void
    {
        WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'content' => 'Atlas project content',
            'description' => 'A great project',
        ]);

        $tool = app(ContextGetTool::class);
        $result = $tool->execute(['name' => 'project:atlas'], $this->apiKey);

        $this->assertEquals('project:atlas', $result['name']);
        $this->assertEquals('project', $result['type']);
        $this->assertEquals('Atlas project content', $result['content']);
        $this->assertArrayHasKey('word_count', $result);
    }

    public function test_context_get_returns_error_for_nonexistent_name(): void
    {
        $tool = app(ContextGetTool::class);
        $result = $tool->execute(['name' => 'nonexistent:page'], $this->apiKey);

        $this->assertArrayHasKey('error', $result);
        $this->assertEquals('Wiki page not found', $result['error']);
        $this->assertEquals('nonexistent:page', $result['name']);
    }

    public function test_context_get_requires_name(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('"name"');

        $tool = app(ContextGetTool::class);
        $tool->execute([], $this->apiKey);
    }

    // ─── context_set ─────────────────────────────────────────────────────────

    public function test_context_set_creates_new_wiki_page(): void
    {
        $tool = app(ContextSetTool::class);
        $result = $tool->execute([
            'name' => 'concept:ideas',
            'content' => 'Some ideas',
        ], $this->apiKey);

        $this->assertEquals('created', $result['created_or_updated']);
        $this->assertEquals('concept:ideas', $result['name']);
        $this->assertEquals('concept', $result['type']);
        $this->assertDatabaseHas('wiki_pages', ['name' => 'concept:ideas', 'type' => 'concept']);
    }

    public function test_context_set_updates_existing_wiki_page(): void
    {
        WikiPage::create(['name' => 'project:x', 'type' => 'project', 'content' => 'Original']);

        $tool = app(ContextSetTool::class);
        $result = $tool->execute([
            'name' => 'project:x',
            'content' => 'Updated content',
        ], $this->apiKey);

        $this->assertEquals('updated', $result['created_or_updated']);
        $this->assertDatabaseHas('wiki_pages', [
            'name' => 'project:x',
            'content' => 'Updated content',
        ]);
    }

    public function test_context_set_auto_infers_type_from_name_prefix(): void
    {
        $tool = app(ContextSetTool::class);

        $cases = [
            ['person:alice', 'person'],
            ['project:beta', 'project'],
            ['concept:flow', 'concept'],
            ['decision:arch', 'decision'],
            ['misc-page', 'synthesis'],
        ];

        foreach ($cases as [$name, $expectedType]) {
            $result = $tool->execute(['name' => $name, 'content' => 'content'], $this->apiKey);
            $this->assertEquals($expectedType, $result['type'], "Expected type {$expectedType} for name {$name}");
        }
    }

    public function test_context_set_auto_updates_wiki_index(): void
    {
        $tool = app(ContextSetTool::class);
        $tool->execute(['name' => 'project:gamma', 'content' => 'Gamma project'], $this->apiKey);

        $this->assertDatabaseHas('wiki_pages', ['name' => 'wiki/index']);
        $indexPage = WikiPage::where('name', 'wiki/index')->first();
        $this->assertStringContainsString('project:gamma', $indexPage->content);
    }

    public function test_context_set_appends_to_wiki_log(): void
    {
        $tool = app(ContextSetTool::class);
        $tool->execute(['name' => 'concept:x', 'content' => 'X'], $this->apiKey);
        $tool->execute(['name' => 'concept:y', 'content' => 'Y'], $this->apiKey);

        $logPage = WikiPage::where('name', 'wiki/log')->first();
        $this->assertNotNull($logPage);
        $this->assertStringContainsString('concept:x', $logPage->content);
        $this->assertStringContainsString('concept:y', $logPage->content);
    }

    // ─── context_list ────────────────────────────────────────────────────────

    public function test_context_list_returns_all_pages(): void
    {
        WikiPage::create(['name' => 'person:alice', 'type' => 'person', 'content' => 'Alice']);
        WikiPage::create(['name' => 'project:x', 'type' => 'project', 'content' => 'X']);

        $tool = app(ContextListTool::class);
        $result = $tool->execute([], $this->apiKey);

        $this->assertArrayHasKey('pages', $result);
        $this->assertCount(2, $result['pages']);
    }

    public function test_context_list_filters_by_type(): void
    {
        WikiPage::create(['name' => 'person:alice', 'type' => 'person', 'content' => 'Alice']);
        WikiPage::create(['name' => 'project:x', 'type' => 'project', 'content' => 'X']);
        WikiPage::create(['name' => 'concept:y', 'type' => 'concept', 'content' => 'Y']);

        $tool = app(ContextListTool::class);
        $result = $tool->execute(['type' => 'person'], $this->apiKey);

        $this->assertCount(1, $result['pages']);
        $this->assertEquals('person:alice', $result['pages'][0]['name']);
    }

    public function test_context_list_with_all_type_returns_everything(): void
    {
        WikiPage::create(['name' => 'person:bob', 'type' => 'person', 'content' => 'Bob']);
        WikiPage::create(['name' => 'concept:z', 'type' => 'concept', 'content' => 'Z']);

        $tool = app(ContextListTool::class);
        $result = $tool->execute(['type' => 'all'], $this->apiKey);

        $this->assertCount(2, $result['pages']);
    }

    public function test_context_list_returns_word_count(): void
    {
        WikiPage::create(['name' => 'concept:words', 'type' => 'concept', 'content' => 'one two three four five']);

        $tool = app(ContextListTool::class);
        $result = $tool->execute(['type' => 'concept'], $this->apiKey);

        $this->assertEquals(5, $result['pages'][0]['word_count']);
    }
}
