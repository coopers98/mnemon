<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\BrainSession;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class PalaceWakeUpToolTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_returns_recent_activity_summary(): void
    {
        $w = Wing::factory()->create(['slug' => 'work']);
        $r = Room::factory()->create(['wing_id' => $w->id]);
        Drawer::factory()->count(3)->create(['room_id' => $r->id]);

        $resp = $this->mcpCall('palace_wake_up', [], ['mcp:use']);
        $resp->assertStatus(200);
        $body = $resp->json('result.structuredContent');
        $this->assertArrayHasKey('greeting', $body);
        $this->assertArrayHasKey('recent_drawers', $body);
        $this->assertCount(3, $body['recent_drawers']);
    }

    public function test_filters_recent_activity_by_wing_restrictions(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        $personal = Wing::factory()->create(['slug' => 'personal']);
        $workRoom = Room::factory()->create(['wing_id' => $work->id]);
        $personalRoom = Room::factory()->create(['wing_id' => $personal->id]);
        Drawer::factory()->create(['room_id' => $workRoom->id, 'content' => 'work-thing']);
        Drawer::factory()->create(['room_id' => $personalRoom->id, 'content' => 'personal-thing']);

        $resp = $this->mcpCall('palace_wake_up', [], ['mcp:use'], wingPatterns: ['work:*', 'work']);
        $body = $resp->json('result.structuredContent');
        $contents = collect($body['recent_drawers'])->pluck('content')->all();
        // partial-match check since content may be truncated/decorated
        $hasWorkContent = collect($contents)->contains(fn ($c) => str_contains($c, 'work-thing'));
        $hasPersonalContent = collect($contents)->contains(fn ($c) => str_contains($c, 'personal-thing'));
        $this->assertTrue($hasWorkContent);
        $this->assertFalse($hasPersonalContent);
    }

    public function test_rejects_token_without_mcp_use_scope(): void
    {
        $resp = $this->mcpCall('palace_wake_up', [], []);
        $body = $resp->json();
        $this->assertTrue(
            isset($body['result']['isError']) && $body['result']['isError'] === true,
            'Expected MCP error response for missing mcp:use scope'
        );
    }

    public function test_includes_greeting_text(): void
    {
        $resp = $this->mcpCall('palace_wake_up', [], ['mcp:use']);
        $greeting = $resp->json('result.structuredContent.greeting');
        $this->assertNotEmpty($greeting);
        $this->assertIsString($greeting);
    }

    public function test_writes_brain_session_audit_row(): void
    {
        $w = Wing::factory()->create(['slug' => 'work']);
        $r = Room::factory()->create(['wing_id' => $w->id]);
        Drawer::factory()->create(['room_id' => $r->id]);

        $this->mcpCall('palace_wake_up', [], ['mcp:use']);

        $session = BrainSession::latest('id')->first();
        $this->assertEquals('palace_wake_up', $session->tool_name);
        $this->assertNotNull($session->access_token_id);
        $this->assertNotNull($session->user_id);
    }
}
