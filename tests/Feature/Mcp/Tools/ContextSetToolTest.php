<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\WikiPageRevision;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class ContextSetToolTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_creates_new_wiki_page(): void
    {
        $r = $this->mcpCall('context_set', [
            'name'    => 'concept:second-brain',
            'content' => 'A persistent knowledge store.',
        ], ['wiki.write']);

        $r->assertStatus(200);
        $body = $r->json('result.structuredContent');
        $this->assertEquals('created', $body['created_or_updated']);
        $this->assertEquals(1, $body['revision_count']);
        $this->assertDatabaseHas('wiki_pages', ['name' => 'concept:second-brain', 'type' => 'concept']);
    }

    public function test_revision_audit_uses_oauth_client_name_as_agent_id(): void
    {
        $this->mcpCall('context_set', ['name' => 'foo', 'content' => 'bar'], ['wiki.write']);

        $rev = WikiPageRevision::latest('id')->first();
        $this->assertEquals('Test Client', $rev->agent_id);
    }

    public function test_agent_id_override_stored_in_revision(): void
    {
        $this->mcpCall('context_set', [
            'name'     => 'concept:test',
            'content'  => 'hello',
            'agent_id' => 'my-custom-agent',
        ], ['wiki.write']);

        $rev = WikiPageRevision::latest('id')->first();
        $this->assertEquals('my-custom-agent', $rev->agent_id);
    }

    public function test_conflict_when_expected_revision_stale(): void
    {
        WikiPage::factory()->create(['name' => 'concept:x', 'revision_count' => 3]);

        $r = $this->mcpCall('context_set', [
            'name'              => 'concept:x',
            'content'           => 'new',
            'expected_revision' => 1,
        ], ['wiki.write']);

        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
        $errorText = $body['result']['content'][0]['text'] ?? '';
        $this->assertStringContainsString('Conflict', $errorText);
    }

    public function test_rejects_missing_scope(): void
    {
        $r = $this->mcpCall('context_set', ['name' => 'foo', 'content' => 'bar'], ['wiki.read']);
        $this->assertTrue($r->json('result.isError') ?? false);
    }

    public function test_updates_existing_wiki_page(): void
    {
        WikiPage::factory()->create(['name' => 'project:x', 'type' => 'project', 'content' => 'Original']);

        $r = $this->mcpCall('context_set', [
            'name'    => 'project:x',
            'content' => 'Updated content',
        ], ['wiki.write']);

        $r->assertStatus(200);
        $body = $r->json('result.structuredContent');
        $this->assertEquals('updated', $body['created_or_updated']);
        $this->assertDatabaseHas('wiki_pages', ['name' => 'project:x', 'content' => 'Updated content']);
    }

    public function test_increments_revision_count_on_update(): void
    {
        WikiPage::factory()->create(['name' => 'concept:inc', 'revision_count' => 1]);

        $r = $this->mcpCall('context_set', [
            'name'    => 'concept:inc',
            'content' => 'new content',
        ], ['wiki.write']);

        $body = $r->json('result.structuredContent');
        $this->assertEquals(2, $body['revision_count']);
    }

    public function test_auto_infers_type_from_name_prefix(): void
    {
        $cases = [
            ['person:alice', 'person'],
            ['project:beta', 'project'],
            ['concept:flow', 'concept'],
            ['decision:arch', 'decision'],
            ['misc-page', 'synthesis'],
        ];

        foreach ($cases as [$name, $expectedType]) {
            $r = $this->mcpCall('context_set', ['name' => $name, 'content' => 'content'], ['wiki.write']);
            $body = $r->json('result.structuredContent');
            $this->assertEquals($expectedType, $body['type'], "Expected type {$expectedType} for name {$name}");
        }
    }

    public function test_auto_updates_wiki_index(): void
    {
        $this->mcpCall('context_set', ['name' => 'project:gamma', 'content' => 'Gamma project'], ['wiki.write']);

        $this->assertDatabaseHas('wiki_pages', ['name' => 'wiki/index']);
        $indexPage = WikiPage::where('name', 'wiki/index')->first();
        $this->assertStringContainsString('project:gamma', $indexPage->content);
    }

    public function test_appends_to_wiki_log(): void
    {
        $this->mcpCall('context_set', ['name' => 'concept:x', 'content' => 'X'], ['wiki.write']);
        $this->mcpCall('context_set', ['name' => 'concept:y', 'content' => 'Y'], ['wiki.write']);

        $logPage = WikiPage::where('name', 'wiki/log')->first();
        $this->assertNotNull($logPage);
        $this->assertStringContainsString('concept:x', $logPage->content);
        $this->assertStringContainsString('concept:y', $logPage->content);
    }

    public function test_marks_source_drawers_as_consolidated(): void
    {
        $wing = Wing::create(['name' => 'Work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        $drawer = Drawer::create(['content' => 'Source text', 'room_id' => $room->id, 'tier' => 'raw']);

        $this->mcpCall('context_set', [
            'name'    => 'concept:sourced',
            'content' => 'From a drawer',
            'sources' => [$drawer->id],
        ], ['wiki.write']);

        $this->assertDatabaseHas('drawers', ['id' => $drawer->id, 'tier' => 'consolidated']);
    }

    public function test_restores_soft_deleted_page(): void
    {
        $page = WikiPage::factory()->create(['name' => 'concept:deleted']);
        $page->delete();

        $r = $this->mcpCall('context_set', [
            'name'    => 'concept:deleted',
            'content' => 'Restored content',
        ], ['wiki.write']);

        $r->assertStatus(200);
        $this->assertDatabaseHas('wiki_pages', ['name' => 'concept:deleted', 'deleted_at' => null]);
    }

    public function test_rejects_nonexistent_source_drawer_ids(): void
    {
        $r = $this->mcpCall('context_set', [
            'name'    => 'concept:bad-sources',
            'content' => 'content',
            'sources' => [99999],
        ], ['wiki.write']);

        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
        $errorText = $body['result']['content'][0]['text'] ?? '';
        $this->assertStringContainsString('drawer IDs do not exist', $errorText);
    }

    public function test_rejects_invalid_confidence_value(): void
    {
        $r = $this->mcpCall('context_set', [
            'name'       => 'concept:conf',
            'content'    => 'content',
            'confidence' => 'very-high',
        ], ['wiki.write']);

        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }

    public function test_creates_wiki_page_revision_row(): void
    {
        $this->mcpCall('context_set', [
            'name'    => 'person:alice',
            'content' => 'Alice is a developer.',
        ], ['wiki.write']);

        $this->assertDatabaseHas('wiki_page_revisions', [
            'page_name' => 'person:alice',
            'revision'  => 1,
        ]);
    }
}
