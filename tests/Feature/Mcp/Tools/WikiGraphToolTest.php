<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\EntityRelationship;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class WikiGraphToolTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makePage(string $name, array $attrs = []): WikiPage
    {
        return WikiPage::factory()->create(array_merge(['name' => $name], $attrs));
    }

    private function makeEdge(string $from, string $to, string $type = 'references', ?string $description = null): EntityRelationship
    {
        return EntityRelationship::create([
            'from_page' => $from,
            'to_page' => $to,
            'edge_type' => $type,
            'description' => $description,
        ]);
    }

    // -----------------------------------------------------------------------
    // Scope enforcement
    // -----------------------------------------------------------------------

    public function test_rejects_palace_read_scope(): void
    {
        $r = $this->mcpCall('wiki_graph', ['start_page' => 'person:alice'], ['palace.read']);
        $this->assertTrue($r->json('result.isError') ?? false);
    }

    public function test_rejects_wiki_write_scope(): void
    {
        $r = $this->mcpCall('wiki_graph', ['start_page' => 'person:alice'], ['wiki.write']);
        $this->assertTrue($r->json('result.isError') ?? false);
    }

    // -----------------------------------------------------------------------
    // Parameter validation
    // -----------------------------------------------------------------------

    public function test_start_page_parameter_is_required(): void
    {
        $r = $this->mcpCall('wiki_graph', [], ['wiki.read']);
        $this->assertTrue($r->json('result.isError') ?? false);
    }

    // -----------------------------------------------------------------------
    // Error cases
    // -----------------------------------------------------------------------

    public function test_returns_error_for_unknown_start_page(): void
    {
        $r = $this->mcpCall('wiki_graph', ['start_page' => 'person:nobody'], ['wiki.read']);

        $this->assertTrue($r->json('result.isError') ?? false);
        $errorText = $r->json('result.content.0.text') ?? '';
        $this->assertStringContainsString('person:nobody', $errorText);
    }

    public function test_logs_brain_session_for_missing_page(): void
    {
        $this->mcpCall('wiki_graph', ['start_page' => 'person:ghost'], ['wiki.read']);

        $this->assertDatabaseHas('brain_sessions', ['tool_name' => 'wiki_graph']);
    }

    // -----------------------------------------------------------------------
    // Happy path — nodes and edges
    // -----------------------------------------------------------------------

    public function test_returns_start_page_as_node(): void
    {
        $this->makePage('person:alice');

        $r = $this->mcpCall('wiki_graph', ['start_page' => 'person:alice'], ['wiki.read']);
        $r->assertStatus(200);

        $body = $r->json('result.structuredContent');
        $names = array_column($body['nodes'], 'name');
        $this->assertContains('person:alice', $names);
    }

    public function test_returns_entities_and_edges(): void
    {
        $this->makePage('person:alice');
        $this->makePage('project:atlas');
        $this->makeEdge('person:alice', 'project:atlas', 'uses');

        $r = $this->mcpCall('wiki_graph', ['start_page' => 'person:alice'], ['wiki.read']);
        $r->assertStatus(200);

        $body = $r->json('result.structuredContent');

        $names = array_column($body['nodes'], 'name');
        $this->assertContains('person:alice', $names);
        $this->assertContains('project:atlas', $names);

        $this->assertGreaterThanOrEqual(1, $body['edge_count']);
        $edge = $body['edges'][0];
        $this->assertEquals('person:alice', $edge['from']);
        $this->assertEquals('project:atlas', $edge['to']);
        $this->assertEquals('uses', $edge['type']);
    }

    public function test_graph_response_contains_required_keys(): void
    {
        $this->makePage('concept:flow');

        $r = $this->mcpCall('wiki_graph', ['start_page' => 'concept:flow'], ['wiki.read']);
        $r->assertStatus(200);

        $body = $r->json('result.structuredContent');
        $this->assertArrayHasKey('start', $body);
        $this->assertArrayHasKey('max_depth', $body);
        $this->assertArrayHasKey('node_count', $body);
        $this->assertArrayHasKey('edge_count', $body);
        $this->assertArrayHasKey('nodes', $body);
        $this->assertArrayHasKey('edges', $body);
        $this->assertEquals('concept:flow', $body['start']);
    }

    // -----------------------------------------------------------------------
    // Depth parameter
    // -----------------------------------------------------------------------

    public function test_depth_one_does_not_traverse_beyond_direct_neighbours(): void
    {
        $this->makePage('person:alice');
        $this->makePage('project:atlas');
        $this->makePage('concept:deep');
        $this->makeEdge('person:alice', 'project:atlas', 'uses');
        $this->makeEdge('project:atlas', 'concept:deep', 'references');

        $r = $this->mcpCall('wiki_graph', ['start_page' => 'person:alice', 'max_depth' => 1], ['wiki.read']);
        $r->assertStatus(200);

        $names = array_column($r->json('result.structuredContent.nodes'), 'name');
        $this->assertContains('person:alice', $names);
        $this->assertContains('project:atlas', $names);
        $this->assertNotContains('concept:deep', $names);
    }

    public function test_depth_two_traverses_two_hops(): void
    {
        $this->makePage('person:alice');
        $this->makePage('project:atlas');
        $this->makePage('concept:deep');
        $this->makeEdge('person:alice', 'project:atlas', 'uses');
        $this->makeEdge('project:atlas', 'concept:deep', 'references');

        $r = $this->mcpCall('wiki_graph', ['start_page' => 'person:alice', 'max_depth' => 2], ['wiki.read']);
        $r->assertStatus(200);

        $names = array_column($r->json('result.structuredContent.nodes'), 'name');
        $this->assertContains('concept:deep', $names);
    }

    // -----------------------------------------------------------------------
    // Edge-type filter
    // -----------------------------------------------------------------------

    public function test_edge_type_filter_excludes_other_types(): void
    {
        $this->makePage('person:alice');
        $this->makePage('project:atlas');
        $this->makePage('concept:other');
        $this->makeEdge('person:alice', 'project:atlas', 'uses');
        $this->makeEdge('person:alice', 'concept:other', 'references');

        $r = $this->mcpCall('wiki_graph', [
            'start_page' => 'person:alice',
            'edge_types' => ['uses'],
        ], ['wiki.read']);
        $r->assertStatus(200);

        $names = array_column($r->json('result.structuredContent.nodes'), 'name');
        $this->assertContains('project:atlas', $names);
        $this->assertNotContains('concept:other', $names);
    }

    // -----------------------------------------------------------------------
    // BrainSession audit
    // -----------------------------------------------------------------------

    public function test_writes_brain_session_row_on_success(): void
    {
        $this->makePage('decision:arch');

        $this->mcpCall('wiki_graph', ['start_page' => 'decision:arch'], ['wiki.read']);

        $this->assertDatabaseHas('brain_sessions', ['tool_name' => 'wiki_graph']);
    }
}
