<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class ContextGetToolTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_returns_wiki_page_by_name(): void
    {
        WikiPage::factory()->create(['name' => 'person:cooper', 'content' => 'about cooper']);

        $r = $this->mcpCall('context_get', ['name' => 'person:cooper'], ['wiki.read']);
        $r->assertStatus(200);
        $body = $r->json('result.structuredContent');
        $this->assertEquals('person:cooper', $body['name']);
        $this->assertEquals('about cooper', $body['content']);
    }

    public function test_returns_error_for_unknown_name(): void
    {
        $r = $this->mcpCall('context_get', ['name' => 'missing'], ['wiki.read']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }

    public function test_rejects_missing_scope(): void
    {
        $r = $this->mcpCall('context_get', ['name' => 'foo'], ['palace.read']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }

    public function test_updates_last_accessed_at(): void
    {
        $page = WikiPage::factory()->create(['name' => 'concept:foo', 'last_accessed_at' => null]);
        $this->mcpCall('context_get', ['name' => 'concept:foo'], ['wiki.read']);
        $this->assertNotNull($page->fresh()->last_accessed_at);
    }
}
