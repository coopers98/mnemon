<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\BrainSession;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class DrawerSearchToolTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_returns_drawer_results_for_query(): void
    {
        $wing = Wing::factory()->create(['slug' => 'work']);
        $room = Room::factory()->create(['wing_id' => $wing->id]);
        Drawer::factory()->create(['room_id' => $room->id, 'content' => 'meeting with dorothy vaughan']);

        $response = $this->mcpCall('drawer_search', ['query' => 'dorothy', 'limit' => 5], ['mcp:use']);

        $response->assertStatus(200);
        $results = $response->json('result.structuredContent.results');
        $this->assertNotEmpty($results);
        $this->assertStringContainsString('dorothy', strtolower($results[0]['content']));
    }

    public function test_rejects_token_without_mcp_use_scope(): void
    {
        $response = $this->mcpCall('drawer_search', ['query' => 'foo'], []);
        $body = $response->json();
        $this->assertTrue(
            isset($body['result']['isError']) && $body['result']['isError'] === true,
            'Expected MCP error response for missing mcp:use scope'
        );
    }

    public function test_rejects_wing_filter_outside_token_restrictions(): void
    {
        $response = $this->mcpCall(
            'drawer_search',
            ['query' => 'foo', 'wing' => 'personal'],
            ['mcp:use'],
            wingPatterns: ['work:*']
        );

        $body = $response->json();
        $this->assertTrue(
            isset($body['result']['isError']) && $body['result']['isError'] === true,
            'Expected error when filtering wing outside restrictions'
        );
    }

    public function test_writes_brain_session_audit_row(): void
    {
        $wing = Wing::factory()->create(['slug' => 'work']);
        $room = Room::factory()->create(['wing_id' => $wing->id]);
        Drawer::factory()->create(['room_id' => $room->id, 'content' => 'foo']);

        $this->mcpCall('drawer_search', ['query' => 'foo'], ['mcp:use']);

        $session = BrainSession::latest('id')->first();
        $this->assertEquals('drawer_search', $session->tool_name);
        $this->assertNotNull($session->access_token_id);
        $this->assertNotNull($session->user_id);
    }
}
