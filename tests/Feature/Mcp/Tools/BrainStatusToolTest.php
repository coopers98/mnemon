<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class BrainStatusToolTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_returns_aggregate_counts(): void
    {
        $w = Wing::factory()->create();
        $r = Room::factory()->create(['wing_id' => $w->id]);
        Drawer::factory()->count(3)->create(['room_id' => $r->id]);

        $resp = $this->mcpCall('brain_status', [], ['mcp:use']);
        $resp->assertStatus(200);
        $body = $resp->json('result.structuredContent');
        $this->assertEquals(1, $body['wings']);
        $this->assertEquals(1, $body['rooms']);
        $this->assertEquals(3, $body['drawers']);
    }

    public function test_rejects_token_without_mcp_use_scope(): void
    {
        $r = $this->mcpCall('brain_status', [], []);
        $this->assertTrue($r->json('result.isError') ?? false);
    }
}
