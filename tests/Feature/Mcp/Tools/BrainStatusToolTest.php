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
        // Avoid embedding-driver hits during drawer creation. The observers
        // only embed on PostgreSQL, so this was inert until CI grew a pgsql
        // leg; there it would POST three fixtures to the configured provider
        // for a test that only counts rows.
        config(['mnemon.embedding.driver' => 'none']);

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
        // Pin the driver rather than inheriting it. On PostgreSQL with a
        // network driver these three drawers would be sent to the provider,
        // and `embedded_drawers === 0` below would then be true only because
        // the unauthenticated request failed — an assertion that flips red the
        // day someone adds a working API key to CI, for a reason nobody would
        // guess.
        config(['mnemon.embedding.driver' => 'none']);

        $w = Wing::factory()->create();
        $room = Room::factory()->create(['wing_id' => $w->id]);
        Drawer::factory()->count(3)->create(['room_id' => $room->id]);

        $r = $this->mcpCall('brain_status', [], ['mcp:use']);

        $embedding = $r->json('result.structuredContent.embedding');

        // With driver=none (and on SQLite, where the embedding column does
        // not exist at all) nothing is embedded. Asserting concrete values
        // here — not just assertIsInt — is what makes this test fail if the
        // Schema::hasColumn guard regresses: without it, SQLite's
        // quoted-identifier quirk makes whereNotNull('embedding') match
        // every row, reporting embedded_drawers === 3 instead of 0.
        $this->assertSame(0, $embedding['embedded_drawers']);
        $this->assertSame(3, $embedding['unembedded_drawers']);
    }
}
