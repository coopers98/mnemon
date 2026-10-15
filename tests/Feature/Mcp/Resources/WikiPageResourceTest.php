<?php

namespace Tests\Feature\Mcp\Resources;

use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class WikiPageResourceTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_resource_lists_for_wiki_read_token(): void
    {
        $r = $this->mcpResourceList(['mcp:use']);
        $r->assertStatus(200);
        $uris = collect($r->json('result.resources') ?? $r->json('result.resourceTemplates') ?? [])->pluck('uriTemplate')->all();
        $this->assertContains('mnemon://wiki/{slug}', $uris);
    }

    public function test_resource_hidden_for_token_without_mcp_use(): void
    {
        $r = $this->mcpResourceList([]);
        $uris = collect($r->json('result.resources') ?? $r->json('result.resourceTemplates') ?? [])->pluck('uriTemplate')->all();
        $this->assertNotContains('mnemon://wiki/{slug}', $uris);
    }

    public function test_resource_read_returns_wiki_page_content(): void
    {
        // WikiPage names use colon notation (e.g. person:cooper).
        // The URI template slot {slug} is passed URL-encoded (%3A) to avoid
        // ambiguity with URI scheme separators; handle() calls urldecode() to recover it.
        $page = WikiPage::factory()->create([
            'name' => 'person:cooper',
            'content' => '# Cooper\n\nA synthesized wiki page.',
        ]);

        // URL-encode the colon so the URI template parser receives a plain slug token.
        $encodedName = urlencode($page->name); // person%3Acooper
        $r = $this->mcpResourceRead("mnemon://wiki/{$encodedName}", ['mcp:use']);
        $r->assertStatus(200);
        $body = $r->json('result.contents.0');
        $this->assertEquals("mnemon://wiki/{$encodedName}", $body['uri']);
        $this->assertStringContainsString('Cooper', $body['text']);
    }

    // -----------------------------------------------------------------------
    // Wing restrictions
    //
    // context_get hides a page outside the token's wings; the resource must
    // apply the same rule, or `resources/read` becomes a side door to the
    // full page body. An unreadable page must look exactly like a missing one.
    // -----------------------------------------------------------------------

    /**
     * @return array{0: string, 1: ?string} [page text, JSON-RPC error message]
     */
    private function readPage(string $name, ?array $wingPatterns): array
    {
        $r = $this->mcpResourceRead('mnemon://wiki/'.urlencode($name), ['mcp:use'], $wingPatterns);
        $r->assertStatus(200);

        return [$r->json('result.contents.0.text') ?? '', $r->json('error.message')];
    }

    public function test_restricted_token_cannot_read_a_page_outside_its_wings(): void
    {
        WikiPage::factory()->create([
            'name' => 'person:secret',
            'content' => 'SECRET body a work token must never read.',
        ]);

        [$text, $error] = $this->readPage('person:secret', ['work']);

        $this->assertStringNotContainsString('SECRET body', $text);
        $this->assertSame('Wiki page not found: person:secret', $error);
    }

    public function test_unreadable_page_is_indistinguishable_from_a_missing_one(): void
    {
        WikiPage::factory()->create(['name' => 'person:secret', 'content' => 'SECRET']);

        $hidden = $this->mcpResourceRead('mnemon://wiki/'.urlencode('person:secret'), ['mcp:use'], ['work']);
        WikiPage::where('name', 'person:secret')->forceDelete();
        $missing = $this->mcpResourceRead('mnemon://wiki/'.urlencode('person:secret'), ['mcp:use'], ['work']);

        $this->assertSame($missing->json(), $hidden->json());
    }

    public function test_restricted_token_still_reads_a_page_inside_its_wings(): void
    {
        WikiPage::factory()->create(['name' => 'work', 'content' => 'Synthesis of work drawers.']);

        [$text, $error] = $this->readPage('work', ['work']);

        $this->assertStringContainsString('Synthesis of work drawers.', $text);
        $this->assertNull($error);
    }

    public function test_unrestricted_token_reads_every_page(): void
    {
        WikiPage::factory()->create(['name' => 'person:secret', 'content' => 'SECRET body.']);

        [$text, $error] = $this->readPage('person:secret', null);

        $this->assertStringContainsString('SECRET body.', $text);
        $this->assertNull($error);
    }
}
