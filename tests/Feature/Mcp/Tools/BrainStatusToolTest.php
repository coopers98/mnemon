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

    public function test_reports_the_active_drivers_dimensions_not_null(): void
    {
        config(['mnemon.embedding.driver' => 'openai']);

        $r = $this->mcpCall('brain_status', [], ['mcp:use']);

        $embedding = $r->json('result.structuredContent.embedding');

        $this->assertSame('openai', $embedding['driver']);
        $this->assertSame(1536, $embedding['dimensions']);
    }

    public function test_reports_embedding_coverage(): void
    {
        $r = $this->mcpCall('brain_status', [], ['mcp:use']);

        $embedding = $r->json('result.structuredContent.embedding');

        // With driver=none nothing is embedded, but the counts must be present
        // and numeric so coverage is visible rather than inferred.
        $this->assertIsInt($embedding['embedded_drawers']);
        $this->assertIsInt($embedding['unembedded_drawers']);
    }
}
