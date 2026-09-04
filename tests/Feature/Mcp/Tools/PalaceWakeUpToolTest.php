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

    public function test_reports_the_plugin_version_the_server_ships(): void
    {
        // Nothing told a device it was running old hooks. This machine sat two
        // plugin versions behind for days and only manual inspection caught it,
        // which matters more now that hooks carry credential-refresh logic:
        // a stale copy fails in ways the new one was written to prevent.
        $resp = $this->mcpCall('palace_wake_up', [], ['mcp:use']);

        $resp->assertStatus(200);
        $this->assertSame(
            json_decode(file_get_contents(base_path('plugins/mnemon/.claude-plugin/plugin.json')), true)['version'],
            $resp->json('result.structuredContent.plugin_version'),
            'wake must advertise the plugin version this instance ships'
        );
    }

    public function test_records_the_client_version_a_device_reports(): void
    {
        // The device sends what it is running; the audit trail keeps it, so a
        // stale device is visible server-side without waiting for someone to
        // notice on the machine itself.
        $this->mcpCall('palace_wake_up', ['client_version' => '0.1.0'], ['mcp:use'])
            ->assertStatus(200);

        $this->assertSame(
            '0.1.0',
            BrainSession::where('tool_name', 'palace_wake_up')->latest('id')->first()?->input['client_version'] ?? null,
        );
    }

    public function test_works_for_a_client_that_reports_no_version(): void
    {
        // A PAT install with no plugin at all still calls this tool.
        $resp = $this->mcpCall('palace_wake_up', [], ['mcp:use']);

        $resp->assertStatus(200);
        $this->assertArrayNotHasKey(
            'client_version',
            BrainSession::where('tool_name', 'palace_wake_up')->latest('id')->first()?->input ?? [],
        );
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
