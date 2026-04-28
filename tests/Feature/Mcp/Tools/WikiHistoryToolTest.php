<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\WikiPage;
use App\Models\WikiPageRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class WikiHistoryToolTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makePage(string $name, array $attrs = []): WikiPage
    {
        return WikiPage::factory()->create(array_merge(['name' => $name], $attrs));
    }

    private function makeRevision(string $pageName, int $revision, array $attrs = []): WikiPageRevision
    {
        return WikiPageRevision::factory()->create(array_merge([
            'page_name' => $pageName,
            'revision' => $revision,
        ], $attrs));
    }

    // -----------------------------------------------------------------------
    // Scope enforcement
    // -----------------------------------------------------------------------

    public function test_rejects_token_without_mcp_use_scope(): void
    {
        $r = $this->mcpCall('wiki_history', ['name' => 'person:alice'], []);
        $this->assertTrue($r->json('result.isError') ?? false);
    }

    // -----------------------------------------------------------------------
    // Parameter validation
    // -----------------------------------------------------------------------

    public function test_name_parameter_is_required(): void
    {
        $r = $this->mcpCall('wiki_history', [], ['mcp:use']);
        $this->assertTrue($r->json('result.isError') ?? false);
    }

    // -----------------------------------------------------------------------
    // Error cases
    // -----------------------------------------------------------------------

    public function test_returns_error_for_unknown_page(): void
    {
        $r = $this->mcpCall('wiki_history', ['name' => 'person:nobody'], ['mcp:use']);

        $this->assertTrue($r->json('result.isError') ?? false);
        $errorText = $r->json('result.content.0.text') ?? '';
        $this->assertStringContainsString('person:nobody', $errorText);
    }

    public function test_logs_brain_session_for_missing_page(): void
    {
        $this->mcpCall('wiki_history', ['name' => 'person:ghost'], ['mcp:use']);

        $this->assertDatabaseHas('brain_sessions', ['tool_name' => 'wiki_history']);
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function test_returns_revisions_ordered_descending(): void
    {
        $this->makePage('project:atlas', ['revision_count' => 3]);
        $this->makeRevision('project:atlas', 1, ['agent_id' => 'agent-a', 'content_hash' => hash('sha256', 'v1')]);
        $this->makeRevision('project:atlas', 2, ['agent_id' => 'agent-b', 'content_hash' => hash('sha256', 'v2')]);
        $this->makeRevision('project:atlas', 3, ['agent_id' => 'agent-c', 'content_hash' => hash('sha256', 'v3')]);

        $r = $this->mcpCall('wiki_history', ['name' => 'project:atlas'], ['mcp:use']);
        $r->assertStatus(200);

        $body = $r->json('result.structuredContent');
        $revisions = $body['revisions'];

        $this->assertCount(3, $revisions);
        $this->assertEquals(3, $revisions[0]['revision']);
        $this->assertEquals(2, $revisions[1]['revision']);
        $this->assertEquals(1, $revisions[2]['revision']);
    }

    public function test_returns_page_metadata_fields(): void
    {
        $this->makePage('concept:flow', [
            'revision_count' => 5,
            'confidence_score' => 0.85,
            'quality_score' => 0.72,
            'source_count' => 4,
        ]);
        $this->makeRevision('concept:flow', 1);

        $r = $this->mcpCall('wiki_history', ['name' => 'concept:flow'], ['mcp:use']);
        $r->assertStatus(200);

        $body = $r->json('result.structuredContent');
        $this->assertEquals('concept:flow', $body['name']);
        $this->assertEquals(5, $body['revision_count']);
        $this->assertEquals(0.85, $body['confidence_score']);
        $this->assertEquals(0.72, $body['quality_score']);
        $this->assertEquals(4, $body['source_count']);
    }

    public function test_each_revision_row_contains_expected_fields(): void
    {
        $this->makePage('person:alice');
        $hash = hash('sha256', 'content-v1');
        $this->makeRevision('person:alice', 1, [
            'content_hash' => $hash,
            'agent_id' => 'my-agent',
            'written_at' => '2026-04-01 12:00:00',
        ]);

        $r = $this->mcpCall('wiki_history', ['name' => 'person:alice'], ['mcp:use']);
        $r->assertStatus(200);

        $rev = $r->json('result.structuredContent.revisions.0');
        $this->assertEquals(1, $rev['revision']);
        $this->assertEquals($hash, $rev['content_hash']);
        $this->assertEquals('my-agent', $rev['agent_id']);
        $this->assertStringContainsString('2026-04-01', $rev['written_at']);
    }

    public function test_limit_parameter_caps_returned_revisions(): void
    {
        $this->makePage('decision:arch', ['revision_count' => 5]);
        foreach (range(1, 5) as $rev) {
            $this->makeRevision('decision:arch', $rev);
        }

        $r = $this->mcpCall('wiki_history', ['name' => 'decision:arch', 'limit' => 2], ['mcp:use']);
        $r->assertStatus(200);

        $this->assertCount(2, $r->json('result.structuredContent.revisions'));
    }

    public function test_returns_empty_revisions_array_when_page_has_no_history(): void
    {
        $this->makePage('synthesis:base');

        $r = $this->mcpCall('wiki_history', ['name' => 'synthesis:base'], ['mcp:use']);
        $r->assertStatus(200);

        $body = $r->json('result.structuredContent');
        $this->assertEquals('synthesis:base', $body['name']);
        $this->assertIsArray($body['revisions']);
        $this->assertCount(0, $body['revisions']);
    }

    // -----------------------------------------------------------------------
    // BrainSession audit
    // -----------------------------------------------------------------------

    public function test_writes_brain_session_row_on_success(): void
    {
        $this->makePage('person:bob');

        $this->mcpCall('wiki_history', ['name' => 'person:bob'], ['mcp:use']);

        $this->assertDatabaseHas('brain_sessions', ['tool_name' => 'wiki_history']);
    }
}
