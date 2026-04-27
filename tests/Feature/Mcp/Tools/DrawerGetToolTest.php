<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class DrawerGetToolTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_returns_drawer_by_id(): void
    {
        $wing = Wing::factory()->create(['slug' => 'work']);
        $room = Room::factory()->create(['wing_id' => $wing->id]);
        $drawer = Drawer::factory()->create(['room_id' => $room->id, 'content' => 'hello']);

        $r = $this->mcpCall('drawer_get', ['id' => $drawer->id], ['palace.read']);
        $r->assertStatus(200);
        $body = $r->json('result.structuredContent');
        $this->assertEquals($drawer->id, $body['id']);
        $this->assertEquals('hello', $body['content']);
        $this->assertEquals('work', $body['wing']);
    }

    public function test_returns_error_for_unknown_id(): void
    {
        $r = $this->mcpCall('drawer_get', ['id' => 99999], ['palace.read']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }

    public function test_rejects_drawer_outside_wing_restrictions(): void
    {
        $personal = Wing::factory()->create(['slug' => 'personal']);
        $room = Room::factory()->create(['wing_id' => $personal->id]);
        $drawer = Drawer::factory()->create(['room_id' => $room->id]);

        $r = $this->mcpCall('drawer_get', ['id' => $drawer->id], ['palace.read'], wingPatterns: ['work:*']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }

    public function test_rejects_missing_scope(): void
    {
        $drawer = Drawer::factory()->create();
        $r = $this->mcpCall('drawer_get', ['id' => $drawer->id], ['wiki.read']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }
}
