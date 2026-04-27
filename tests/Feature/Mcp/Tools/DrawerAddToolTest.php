<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Drawer;
use App\Models\WikiPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class DrawerAddToolTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_creates_drawer_with_oauth_client_as_default_source(): void
    {
        $r = $this->mcpCall('drawer_add', [
            'wing' => 'work',
            'room' => 'notes',
            'content' => 'meeting notes',
        ], ['palace.write']);

        $r->assertStatus(200);
        $drawer = Drawer::first();
        $this->assertEquals('meeting notes', $drawer->content);
        $this->assertEquals('Test Client', $drawer->source);
    }

    public function test_explicit_source_overrides_oauth_client_name(): void
    {
        $r = $this->mcpCall('drawer_add', [
            'wing' => 'work',
            'room' => 'notes',
            'content' => 'foo',
            'source' => 'manual-entry',
        ], ['palace.write']);

        $this->assertEquals('manual-entry', Drawer::first()->source);
    }

    public function test_rejects_write_outside_wing_restrictions(): void
    {
        $r = $this->mcpCall('drawer_add', [
            'wing' => 'personal',
            'room' => 'notes',
            'content' => 'foo',
        ], ['palace.write'], wingPatterns: ['work:*']);

        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
        $this->assertEquals(0, Drawer::count());
    }

    public function test_rejects_missing_scope(): void
    {
        $r = $this->mcpCall('drawer_add', ['wing' => 'work', 'room' => 'notes', 'content' => 'foo'], ['palace.read']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }

    public function test_sanitizes_content_before_storing(): void
    {
        $r = $this->mcpCall('drawer_add', [
            'wing' => 'work',
            'room' => 'notes',
            'content' => 'My API key is sk-abc123def456ghi789jkl012mno345pqr678stu901vwx234yz',
        ], ['palace.write']);

        $r->assertStatus(200);
        $stored = Drawer::first()->content;
        $this->assertStringNotContainsString('sk-abc123def456ghi789jkl012mno345pqr678stu901vwx234yz', $stored);
        $this->assertStringContainsString('[REDACTED:API_KEY]', $stored);
    }

    public function test_increments_pending_drawers_on_related_wiki_page(): void
    {
        $page = WikiPage::create([
            'name' => 'work',
            'type' => 'concept',
            'content' => '',
            'pending_drawers_since_compile' => 0,
        ]);

        $r = $this->mcpCall('drawer_add', [
            'wing' => 'work',
            'room' => 'notes',
            'content' => 'some content',
        ], ['palace.write']);

        $r->assertStatus(200);
        $this->assertEquals(1, $page->fresh()->pending_drawers_since_compile);
    }
}
