<?php

namespace Tests\Feature\Mcp\Resources;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class DrawerResourceTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_resource_lists_for_palace_read_token(): void
    {
        $r = $this->mcpResourceList(['mcp:use']);
        $r->assertStatus(200);
        $uris = collect($r->json('result.resources') ?? $r->json('result.resourceTemplates') ?? [])->pluck('uriTemplate')->all();
        $this->assertContains('mnemon://drawer/{id}', $uris);
    }

    public function test_resource_hidden_for_token_without_mcp_use(): void
    {
        $r = $this->mcpResourceList([]);
        $uris = collect($r->json('result.resources') ?? $r->json('result.resourceTemplates') ?? [])->pluck('uriTemplate')->all();
        $this->assertNotContains('mnemon://drawer/{id}', $uris);
    }

    public function test_resource_read_returns_drawer_content(): void
    {
        $w = Wing::factory()->create(['slug' => 'work']);
        $room = Room::factory()->create(['wing_id' => $w->id]);
        $drawer = Drawer::factory()->create(['room_id' => $room->id, 'content' => 'foo']);

        $r = $this->mcpResourceRead("mnemon://drawer/{$drawer->id}", ['mcp:use']);
        $r->assertStatus(200);
        $body = $r->json('result.contents.0');
        $this->assertEquals("mnemon://drawer/{$drawer->id}", $body['uri']);
        $this->assertStringContainsString('foo', $body['text']);
    }

    public function test_resource_read_rejects_drawer_outside_wing_restrictions(): void
    {
        $w = Wing::factory()->create(['slug' => 'personal']);
        $room = Room::factory()->create(['wing_id' => $w->id]);
        $drawer = Drawer::factory()->create(['room_id' => $room->id]);

        $r = $this->mcpResourceRead("mnemon://drawer/{$drawer->id}", ['mcp:use'], wingPatterns: ['work:*']);
        $body = $r->json();
        $this->assertTrue(($body['result']['isError'] ?? false) || isset($body['error']));
    }
}
