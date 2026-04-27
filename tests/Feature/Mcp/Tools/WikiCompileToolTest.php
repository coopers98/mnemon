<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class WikiCompileToolTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeWing(string $name, string $slug): Wing
    {
        return Wing::create(['name' => $name, 'slug' => $slug]);
    }

    private function makeRoom(int $wingId): Room
    {
        return Room::create(['wing_id' => $wingId, 'name' => 'Notes', 'slug' => 'notes']);
    }

    private function makeDrawer(int $roomId, string $tier = 'raw'): Drawer
    {
        return Drawer::create(['content' => 'Drawer content', 'room_id' => $roomId, 'tier' => $tier]);
    }

    // -----------------------------------------------------------------------
    // Scope enforcement
    // -----------------------------------------------------------------------

    public function test_rejects_wiki_read_scope(): void
    {
        $r = $this->mcpCall('wiki_compile', ['name' => 'project:atlas'], ['wiki.read']);
        $this->assertTrue($r->json('result.isError') ?? false);
    }

    public function test_rejects_palace_read_scope(): void
    {
        $r = $this->mcpCall('wiki_compile', ['name' => 'project:atlas'], ['palace.read']);
        $this->assertTrue($r->json('result.isError') ?? false);
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function test_returns_drawers_for_existing_wing(): void
    {
        $wing = $this->makeWing('Project Atlas', 'project-atlas');
        $room = $this->makeRoom($wing->id);
        $this->makeDrawer($room->id, 'raw');
        $this->makeDrawer($room->id, 'reviewed');

        $r = $this->mcpCall('wiki_compile', ['name' => 'project:atlas'], ['wiki.write']);
        $r->assertStatus(200);

        $body = $r->json('result.structuredContent');
        $this->assertEquals(2, $body['drawer_count']);
        $this->assertCount(2, $body['drawers']);
        $this->assertEquals('project-atlas', $body['wing']['slug']);
        $this->assertNull($body['page']); // no wiki page yet
    }

    public function test_returns_existing_wiki_page_metadata(): void
    {
        $wing = $this->makeWing('Person Alice', 'person-alice');
        $room = $this->makeRoom($wing->id);
        $this->makeDrawer($room->id);

        WikiPage::factory()->create([
            'name' => 'person:alice',
            'content' => 'Alice is a developer.',
            'revision_count' => 3,
        ]);

        $r = $this->mcpCall('wiki_compile', ['name' => 'person:alice'], ['wiki.write']);
        $r->assertStatus(200);

        $page = $r->json('result.structuredContent.page');
        $this->assertNotNull($page);
        $this->assertEquals('person:alice', $page['name']);
        $this->assertEquals('Alice is a developer.', $page['current_content']);
        $this->assertEquals(3, $page['revision_count']);
    }

    public function test_marks_raw_drawers_as_reviewed(): void
    {
        $wing = $this->makeWing('Concept Flow', 'concept-flow');
        $room = $this->makeRoom($wing->id);
        $drawer = $this->makeDrawer($room->id, 'raw');

        $this->mcpCall('wiki_compile', ['name' => 'concept:flow'], ['wiki.write']);

        $this->assertDatabaseHas('drawers', ['id' => $drawer->id, 'tier' => 'reviewed']);
    }

    public function test_does_not_downgrade_reviewed_drawers(): void
    {
        $wing = $this->makeWing('Concept Loop', 'concept-loop');
        $room = $this->makeRoom($wing->id);
        $drawer = $this->makeDrawer($room->id, 'reviewed');

        $this->mcpCall('wiki_compile', ['name' => 'concept:loop'], ['wiki.write']);

        $this->assertDatabaseHas('drawers', ['id' => $drawer->id, 'tier' => 'reviewed']);
    }

    // -----------------------------------------------------------------------
    // Error cases
    // -----------------------------------------------------------------------

    public function test_returns_error_when_wing_not_found(): void
    {
        $r = $this->mcpCall('wiki_compile', ['name' => 'project:nonexistent'], ['wiki.write']);

        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
        $errorText = $body['result']['content'][0]['text'] ?? '';
        $this->assertStringContainsString('No wing found', $errorText);
    }

    // -----------------------------------------------------------------------
    // agent_id stamping
    // -----------------------------------------------------------------------

    public function test_agent_id_defaults_to_oauth_client_name(): void
    {
        $wing = $this->makeWing('Decision Arch', 'decision-arch');
        $room = $this->makeRoom($wing->id);
        $this->makeDrawer($room->id);

        $r = $this->mcpCall('wiki_compile', ['name' => 'decision:arch'], ['wiki.write']);
        $r->assertStatus(200);

        $agent = $r->json('result.structuredContent.agent');
        $this->assertEquals('Test Client', $agent);
    }

    public function test_agent_id_override_respected(): void
    {
        $wing = $this->makeWing('Decision Build', 'decision-build');
        $room = $this->makeRoom($wing->id);
        $this->makeDrawer($room->id);

        $r = $this->mcpCall('wiki_compile', [
            'name' => 'decision:build',
            'agent_id' => 'custom-agent-v2',
        ], ['wiki.write']);
        $r->assertStatus(200);

        $agent = $r->json('result.structuredContent.agent');
        $this->assertEquals('custom-agent-v2', $agent);
    }

    // -----------------------------------------------------------------------
    // BrainSession audit
    // -----------------------------------------------------------------------

    public function test_writes_brain_session_row(): void
    {
        $wing = $this->makeWing('Synthesis Base', 'synthesis-base');
        $room = $this->makeRoom($wing->id);
        $this->makeDrawer($room->id);

        $this->mcpCall('wiki_compile', ['name' => 'synthesis:base'], ['wiki.write']);

        $this->assertDatabaseHas('brain_sessions', ['tool_name' => 'wiki_compile']);
    }

    public function test_brain_session_written_even_for_missing_wing(): void
    {
        $this->mcpCall('wiki_compile', ['name' => 'project:ghost'], ['wiki.write']);

        $this->assertDatabaseHas('brain_sessions', ['tool_name' => 'wiki_compile']);
    }

    // -----------------------------------------------------------------------
    // Optimistic locking: two sequential compiles on same page
    // -----------------------------------------------------------------------

    public function test_sequential_compiles_both_succeed_and_preserve_revision(): void
    {
        $wing = $this->makeWing('Concept Seq', 'concept-seq');
        $room = $this->makeRoom($wing->id);
        $this->makeDrawer($room->id);
        $this->makeDrawer($room->id);

        WikiPage::factory()->create(['name' => 'concept:seq', 'revision_count' => 5]);

        $r1 = $this->mcpCall('wiki_compile', ['name' => 'concept:seq'], ['wiki.write']);
        $r2 = $this->mcpCall('wiki_compile', ['name' => 'concept:seq'], ['wiki.write']);

        $r1->assertStatus(200);
        $r2->assertStatus(200);

        // Revision count on the returned page metadata must match what's in the DB
        $rev1 = $r1->json('result.structuredContent.page.revision_count');
        $rev2 = $r2->json('result.structuredContent.page.revision_count');
        $this->assertEquals(5, $rev1);
        $this->assertEquals(5, $rev2);
    }
}
