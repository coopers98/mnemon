<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class WikiLintToolTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    // -----------------------------------------------------------------------
    // Scope enforcement
    // -----------------------------------------------------------------------

    public function test_rejects_wiki_read_only_scope(): void
    {
        $r = $this->mcpCall('wiki_lint', [], ['wiki.read']);
        $this->assertTrue($r->json('result.isError') ?? false);
    }

    public function test_rejects_palace_read_scope(): void
    {
        $r = $this->mcpCall('wiki_lint', [], ['palace.read']);
        $this->assertTrue($r->json('result.isError') ?? false);
    }

    // -----------------------------------------------------------------------
    // Happy path — no pages
    // -----------------------------------------------------------------------

    public function test_returns_empty_report_when_no_pages(): void
    {
        $r = $this->mcpCall('wiki_lint', [], ['wiki.write']);
        $r->assertStatus(200);

        $body = $r->json('result.structuredContent');
        $this->assertIsArray($body['findings']);
        $this->assertCount(0, $body['findings']);
        $this->assertArrayHasKey('summary', $body);
        $this->assertEquals(0, $body['summary']['total']);
        $this->assertEquals('all', $body['summary']['focus']);
    }

    // -----------------------------------------------------------------------
    // Detector: empty pages
    // -----------------------------------------------------------------------

    public function test_detects_empty_page(): void
    {
        WikiPage::factory()->create(['name' => 'concept:stub', 'content' => 'too short']);

        $r = $this->mcpCall('wiki_lint', ['focus' => 'empty'], ['wiki.write']);
        $r->assertStatus(200);

        $body = $r->json('result.structuredContent');
        $types = array_column($body['findings'], 'type');
        $this->assertContains('empty', $types);
    }

    // -----------------------------------------------------------------------
    // Detector: stale pages
    // -----------------------------------------------------------------------

    public function test_detects_stale_page_never_compiled(): void
    {
        WikiPage::factory()->create([
            'name' => 'concept:old',
            'last_compiled_at' => null,
            'content' => str_repeat('x', 200),
        ]);

        $r = $this->mcpCall('wiki_lint', ['focus' => 'stale'], ['wiki.write']);
        $r->assertStatus(200);

        $body = $r->json('result.structuredContent');
        $types = array_column($body['findings'], 'type');
        $this->assertContains('stale', $types);
    }

    // -----------------------------------------------------------------------
    // Detector: low_confidence
    // -----------------------------------------------------------------------

    public function test_detects_low_confidence_numeric(): void
    {
        WikiPage::factory()->create([
            'name' => 'concept:uncertain',
            'confidence_score' => 0.1,
            'content' => str_repeat('x', 200),
            'last_compiled_at' => now(),
        ]);

        $r = $this->mcpCall('wiki_lint', ['focus' => 'low_confidence'], ['wiki.write']);
        $r->assertStatus(200);

        $body = $r->json('result.structuredContent');
        $types = array_column($body['findings'], 'type');
        $this->assertContains('low_confidence', $types);
    }

    // -----------------------------------------------------------------------
    // Summary shape
    // -----------------------------------------------------------------------

    public function test_summary_counts_warnings_and_info(): void
    {
        // empty page → warning
        WikiPage::factory()->create(['name' => 'concept:stub2', 'content' => 'tiny', 'last_compiled_at' => now(), 'confidence_score' => 0.9]);

        $r = $this->mcpCall('wiki_lint', ['focus' => 'empty'], ['wiki.write']);
        $r->assertStatus(200);

        $summary = $r->json('result.structuredContent.summary');
        $this->assertGreaterThanOrEqual(1, $summary['warnings']);
        $this->assertEquals($summary['warnings'] + $summary['info'], $summary['total']);
    }

    // -----------------------------------------------------------------------
    // auto_fix flag
    // -----------------------------------------------------------------------

    public function test_auto_fix_returns_auto_fixes_key(): void
    {
        // Create a short-content page that will be flagged as empty
        WikiPage::factory()->create([
            'name' => 'concept:empty-stub',
            'content' => 'stub',
            'last_compiled_at' => now(),
            'confidence_score' => 0.9,
        ]);

        $r = $this->mcpCall('wiki_lint', ['focus' => 'empty', 'auto_fix' => true], ['wiki.write']);
        $r->assertStatus(200);

        $body = $r->json('result.structuredContent');
        $this->assertArrayHasKey('auto_fixes', $body);
        $this->assertArrayHasKey('applied', $body['auto_fixes']);
        $this->assertArrayHasKey('summary', $body['auto_fixes']);
    }

    public function test_without_auto_fix_no_auto_fixes_key(): void
    {
        WikiPage::factory()->create(['name' => 'concept:stub3', 'content' => 'stub']);

        $r = $this->mcpCall('wiki_lint', [], ['wiki.write']);
        $r->assertStatus(200);

        $body = $r->json('result.structuredContent');
        $this->assertArrayNotHasKey('auto_fixes', $body);
    }

    // -----------------------------------------------------------------------
    // BrainSession audit row
    // -----------------------------------------------------------------------

    public function test_writes_brain_session_row(): void
    {
        $r = $this->mcpCall('wiki_lint', [], ['wiki.write']);
        $r->assertStatus(200);

        $this->assertDatabaseHas('brain_sessions', ['tool_name' => 'wiki_lint']);
    }
}
