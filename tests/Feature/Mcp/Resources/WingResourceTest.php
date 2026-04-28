<?php

namespace Tests\Feature\Mcp\Resources;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class WingResourceTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_resource_lists_for_palace_read_token(): void
    {
        $r = $this->mcpResourceList(['mcp:use']);
        $r->assertStatus(200);
        $uris = collect($r->json('result.resources') ?? $r->json('result.resourceTemplates') ?? [])->pluck('uriTemplate')->all();
        $this->assertContains('mnemon://wing/{slug}', $uris);
    }

    public function test_resource_hidden_for_token_without_mcp_use(): void
    {
        $r = $this->mcpResourceList([]);
        $uris = collect($r->json('result.resources') ?? $r->json('result.resourceTemplates') ?? [])->pluck('uriTemplate')->all();
        $this->assertNotContains('mnemon://wing/{slug}', $uris);
    }

    public function test_resource_read_returns_wing_index(): void
    {
        $wing = Wing::factory()->create(['slug' => 'work', 'name' => 'Work']);
        $room1 = Room::factory()->create(['wing_id' => $wing->id, 'slug' => 'meetings']);
        $room2 = Room::factory()->create(['wing_id' => $wing->id, 'slug' => 'ideas']);
        Drawer::factory()->count(3)->create(['room_id' => $room1->id]);
        Drawer::factory()->count(1)->create(['room_id' => $room2->id]);

        $r = $this->mcpResourceRead('mnemon://wing/work', ['mcp:use']);
        $r->assertStatus(200);
        $body = $r->json('result.contents.0');
        $this->assertEquals('mnemon://wing/work', $body['uri']);
        $this->assertStringContainsString('work', $body['text']);
        $this->assertStringContainsString('meetings', $body['text']);
        $this->assertStringContainsString('3 drawers', $body['text']);
        $this->assertStringContainsString('ideas', $body['text']);
        $this->assertStringContainsString('1 drawers', $body['text']);
    }

    public function test_resource_read_rejects_wing_outside_restrictions(): void
    {
        Wing::factory()->create(['slug' => 'personal', 'name' => 'Personal']);

        $r = $this->mcpResourceRead('mnemon://wing/personal', ['mcp:use'], wingPatterns: ['work:*']);
        $body = $r->json();
        $this->assertTrue(($body['result']['isError'] ?? false) || isset($body['error']));
    }
}
