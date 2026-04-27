<?php

namespace Tests\Feature\Mcp\Resources;

use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class WikiPageResourceTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_resource_lists_for_wiki_read_token(): void
    {
        $r = $this->mcpResourceList(['wiki.read']);
        $r->assertStatus(200);
        $uris = collect($r->json('result.resources') ?? $r->json('result.resourceTemplates') ?? [])->pluck('uriTemplate')->all();
        $this->assertContains('mnemon://wiki/{slug}', $uris);
    }

    public function test_resource_hidden_for_token_without_wiki_read(): void
    {
        $r = $this->mcpResourceList(['palace.read']);
        $uris = collect($r->json('result.resources') ?? $r->json('result.resourceTemplates') ?? [])->pluck('uriTemplate')->all();
        $this->assertNotContains('mnemon://wiki/{slug}', $uris);
    }

    public function test_resource_read_returns_wiki_page_content(): void
    {
        // WikiPage names use colon notation (e.g. person:cooper).
        // The URI template slot {slug} is passed URL-encoded (%3A) to avoid
        // ambiguity with URI scheme separators; handle() calls urldecode() to recover it.
        $page = WikiPage::factory()->create([
            'name'    => 'person:cooper',
            'content' => '# Cooper\n\nA synthesized wiki page.',
        ]);

        // URL-encode the colon so the URI template parser receives a plain slug token.
        $encodedName = urlencode($page->name); // person%3Acooper
        $r = $this->mcpResourceRead("mnemon://wiki/{$encodedName}", ['wiki.read']);
        $r->assertStatus(200);
        $body = $r->json('result.contents.0');
        $this->assertEquals("mnemon://wiki/{$encodedName}", $body['uri']);
        $this->assertStringContainsString('Cooper', $body['text']);
    }
}
