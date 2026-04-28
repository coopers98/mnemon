<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class ContextGetToolTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_returns_wiki_page_by_name(): void
    {
        WikiPage::factory()->create(['name' => 'person:cooper', 'content' => 'about cooper']);

        $r = $this->mcpCall('context_get', ['name' => 'person:cooper'], ['mcp:use']);
        $r->assertStatus(200);
        $body = $r->json('result.structuredContent');
        $this->assertEquals('person:cooper', $body['name']);
        $this->assertEquals('about cooper', $body['content']);
    }

    public function test_returns_error_for_unknown_name(): void
    {
        $r = $this->mcpCall('context_get', ['name' => 'missing'], ['mcp:use']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }

    public function test_rejects_token_without_mcp_use_scope(): void
    {
        $r = $this->mcpCall('context_get', ['name' => 'foo'], []);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }

    public function test_updates_last_accessed_at(): void
    {
        $page = WikiPage::factory()->create(['name' => 'concept:foo', 'last_accessed_at' => null]);
        $this->mcpCall('context_get', ['name' => 'concept:foo'], ['mcp:use']);
        $this->assertNotNull($page->fresh()->last_accessed_at);
    }
}
