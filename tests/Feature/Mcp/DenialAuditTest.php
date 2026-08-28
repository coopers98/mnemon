<?php

namespace Tests\Feature\Mcp;

use App\Models\BrainSession;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

/**
 * D4 — the audit trail recorded only successes.
 *
 * Denials are the invocations an audit trail exists to capture. With scopes
 * collapsed to a single mcp:use, a wing denial is the main signal that an
 * agent reached for something it should not have.
 */
class DenialAuditTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    private function seedWing(string $slug): void
    {
        $wing = Wing::create(['name' => $slug, 'slug' => $slug]);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        Drawer::create(['content' => 'a note', 'room_id' => $room->id]);
    }

    public function test_wing_denial_is_recorded_in_the_audit_log(): void
    {
        $this->seedWing('personal');

        $r = $this->mcpCall('drawer_search', [
            'query' => 'note',
            'wing' => 'personal',
        ], ['mcp:use'], ['work']);

        $this->assertTrue($r->json('result.isError') ?? false, 'expected a denial');

        $session = BrainSession::latest('id')->first();

        $this->assertNotNull($session, 'a denial must leave an audit row');
        $this->assertSame('drawer_search', $session->tool_name);
        $this->assertSame('denied', $session->outcome);
        $this->assertStringContainsString('personal', (string) $session->error);
    }

    public function test_denial_row_still_carries_token_provenance(): void
    {
        $this->seedWing('personal');

        $this->mcpCall('drawer_search', [
            'query' => 'note',
            'wing' => 'personal',
        ], ['mcp:use'], ['work']);

        $session = BrainSession::latest('id')->first();

        // A denial without provenance is nearly useless — it must say who.
        $this->assertNotNull($session->access_token_id);
        $this->assertNotNull($session->user_id);
        $this->assertStringContainsString('Test Client', (string) $session->source);
    }

    public function test_successful_call_is_still_recorded_as_success(): void
    {
        $this->mcpCall('brain_status', [], ['mcp:use']);

        $session = BrainSession::latest('id')->first();

        $this->assertNotNull($session);
        $this->assertSame('success', $session->outcome);
        $this->assertNull($session->error);
    }
}
