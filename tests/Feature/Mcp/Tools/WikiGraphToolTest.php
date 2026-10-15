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

    public function test_rejects_token_without_mcp_use_scope(): void
    {
        $r = $this->mcpCall('wiki_graph', ['start_page' => 'person:alice'], []);
        $this->assertTrue($r->json('result.isError') ?? false);
    }

    // -----------------------------------------------------------------------
    // Parameter validation
    // -----------------------------------------------------------------------

    public function test_start_page_parameter_is_required(): void
    {
        $r = $this->mcpCall('wiki_graph', [], ['mcp:use']);
        $this->assertTrue($r->json('result.isError') ?? false);
    }

    // -----------------------------------------------------------------------
    // Error cases
    // -----------------------------------------------------------------------

    public function test_returns_error_for_unknown_start_page(): void
    {
        $r = $this->mcpCall('wiki_graph', ['start_page' => 'person:nobody'], ['mcp:use']);

        $this->assertTrue($r->json('result.isError') ?? false);
        $errorText = $r->json('result.content.0.text') ?? '';
        $this->assertStringContainsString('person:nobody', $errorText);
    }

    public function test_logs_brain_session_for_missing_page(): void
    {
        $this->mcpCall('wiki_graph', ['start_page' => 'person:ghost'], ['mcp:use']);

        $this->assertDatabaseHas('brain_sessions', ['tool_name' => 'wiki_graph']);
    }

    // -----------------------------------------------------------------------
    // Happy path — nodes and edges
    // -----------------------------------------------------------------------

    public function test_returns_start_page_as_node(): void
    {
        $this->makePage('person:alice');

        $r = $this->mcpCall('wiki_graph', ['start_page' => 'person:alice'], ['mcp:use']);
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

        $r = $this->mcpCall('wiki_graph', ['start_page' => 'person:alice'], ['mcp:use']);
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

        $r = $this->mcpCall('wiki_graph', ['start_page' => 'concept:flow'], ['mcp:use']);
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

        $r = $this->mcpCall('wiki_graph', ['start_page' => 'person:alice', 'max_depth' => 1], ['mcp:use']);
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

        $r = $this->mcpCall('wiki_graph', ['start_page' => 'person:alice', 'max_depth' => 2], ['mcp:use']);
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
        ], ['mcp:use']);
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

        $this->mcpCall('wiki_graph', ['start_page' => 'decision:arch'], ['mcp:use']);

        $this->assertDatabaseHas('brain_sessions', ['tool_name' => 'wiki_graph']);
    }

    // -----------------------------------------------------------------------
    // Wing restrictions
    // -----------------------------------------------------------------------

    public function test_restricted_token_gets_not_found_for_a_start_page_outside_its_wings(): void
    {
        $this->makePage('person:secret');

        $hidden = $this->mcpCall('wiki_graph', ['start_page' => 'person:secret'], ['mcp:use'], ['project-*']);

        $this->assertTrue($hidden->json('result.isError') ?? false);

        WikiPage::where('name', 'person:secret')->forceDelete();
        $missing = $this->mcpCall('wiki_graph', ['start_page' => 'person:secret'], ['mcp:use'], ['project-*']);

        $this->assertSame($missing->json('result'), $hidden->json('result'));
    }

    public function test_restricted_token_sees_no_unreadable_nodes_or_edges(): void
    {
        $this->makePage('project:atlas');
        $this->makePage('project:hermes');
        $this->makePage('person:secret');
        $this->makePage('project:behind-secret');
        $this->makeEdge('project:atlas', 'project:hermes', 'uses');
        $this->makeEdge('project:atlas', 'person:secret', 'references');
        $this->makeEdge('person:secret', 'project:behind-secret', 'references');

        $r = $this->mcpCall('wiki_graph', ['start_page' => 'project:atlas', 'max_depth' => 3], ['mcp:use'], ['project-*']);
        $r->assertStatus(200);
        $this->assertFalse($r->json('result.isError') ?? false);

        $graph = $r->json('result.structuredContent');
        $names = array_column($graph['nodes'], 'name');

        $this->assertContains('project:hermes', $names);
        $this->assertStringNotContainsString('person:secret', $r->getContent());
        // Reachable only through the unreadable page: its existence would
        // reveal the hidden page's links, so traversal must not pass through.
        $this->assertNotContains('project:behind-secret', $names);
        $this->assertSame(2, $graph['node_count']);
        $this->assertSame(1, $graph['edge_count']);
    }

    public function test_unrestricted_token_sees_the_whole_graph(): void
    {
        $this->makePage('project:atlas');
        $this->makePage('person:secret');
        $this->makeEdge('project:atlas', 'person:secret', 'references');

        $r = $this->mcpCall('wiki_graph', ['start_page' => 'project:atlas'], ['mcp:use'], null);

        $names = array_column($r->json('result.structuredContent.nodes'), 'name');
        $this->assertContains('person:secret', $names);
    }
}
