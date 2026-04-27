<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class ContextListToolTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_lists_wiki_pages(): void
    {
        WikiPage::factory()->count(3)->create();

        $r = $this->mcpCall('context_list', [], ['wiki.read']);
        $r->assertStatus(200);
        $this->assertCount(3, $r->json('result.structuredContent.pages'));
    }

    public function test_filters_by_type(): void
    {
        WikiPage::factory()->create(['name' => 'person:a', 'type' => 'person']);
        WikiPage::factory()->create(['name' => 'concept:b', 'type' => 'concept']);

        $r = $this->mcpCall('context_list', ['type' => 'person'], ['wiki.read']);
        $pages = $r->json('result.structuredContent.pages');
        $this->assertCount(1, $pages);
        $this->assertEquals('person:a', $pages[0]['name']);
    }

    public function test_rejects_missing_scope(): void
    {
        $r = $this->mcpCall('context_list', [], ['palace.read']);
        $this->assertTrue($r->json('result.isError') ?? false);
    }

    public function test_returns_expected_fields(): void
    {
        WikiPage::factory()->create([
            'name'        => 'concept:test',
            'type'        => 'concept',
            'title'       => 'Test Concept',
            'description' => 'A test concept',
            'confidence'  => 'high',
            'content'     => 'one two three',
        ]);

        $r = $this->mcpCall('context_list', [], ['wiki.read']);
        $page = $r->json('result.structuredContent.pages.0');

        $this->assertEquals('concept:test', $page['name']);
        $this->assertEquals('concept', $page['type']);
        $this->assertEquals('Test Concept', $page['title']);
        $this->assertEquals('A test concept', $page['description']);
        $this->assertEquals('high', $page['confidence']);
        $this->assertEquals(3, $page['word_count']);
        $this->assertArrayHasKey('last_compiled_at', $page);
        $this->assertArrayHasKey('pending_drawers_since_compile', $page);
    }

    public function test_limit_parameter(): void
    {
        WikiPage::factory()->count(10)->create();

        $r = $this->mcpCall('context_list', ['limit' => 3], ['wiki.read']);
        $r->assertStatus(200);
        $this->assertCount(3, $r->json('result.structuredContent.pages'));
    }
}
