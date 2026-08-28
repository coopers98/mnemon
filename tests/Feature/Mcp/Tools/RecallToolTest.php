<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\BrainSession;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class RecallToolTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_returns_found_false_for_unrelated_prompt(): void
    {
        $response = $this->mcpCall('recall', ['prompt' => 'qwerty asdf nothing'], ['mcp:use']);

        $response->assertStatus(200);
        $found = $response->json('result.structuredContent.found');
        $this->assertFalse($found);
    }

    public function test_returns_mixed_payload_for_relevant_prompt(): void
    {
        $wing = Wing::factory()->create(['slug' => 'work']);
        $room = Room::factory()->create(['wing_id' => $wing->id]);
        Drawer::factory()->create([
            'room_id' => $room->id,
            'content' => 'dorothy vaughan discussed the atlas roadmap',
        ]);
        WikiPage::factory()->create([
            'name' => 'person:dorothy-vaughan',
            'title' => 'Dorothy Vaughan',
            'content' => 'Dorothy Vaughan is the lead engineer on the Atlas project.',
        ]);

        $response = $this->mcpCall('recall', ['prompt' => 'what do we know about dorothy'], ['mcp:use']);

        $response->assertStatus(200);
        $body = $response->json('result.structuredContent');
        $this->assertTrue($body['found']);
        $this->assertNotEmpty($body['wiki']);
    }

    public function test_rejects_token_without_mcp_use_scope(): void
    {
        $response = $this->mcpCall('recall', ['prompt' => 'foo bar baz'], []);
        $body = $response->json();
        $this->assertTrue(isset($body['result']['isError']) && $body['result']['isError'] === true);
    }

    public function test_respects_wing_restrictions(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        $personal = Wing::factory()->create(['slug' => 'personal']);
        $r1 = Room::factory()->create(['wing_id' => $work->id]);
        $r2 = Room::factory()->create(['wing_id' => $personal->id]);
        Drawer::factory()->create(['room_id' => $r1->id, 'content' => 'work secret about dorothy']);
        Drawer::factory()->create(['room_id' => $r2->id, 'content' => 'personal note about dorothy']);

        $response = $this->mcpCall(
            'recall',
            ['prompt' => 'dorothy'],
            ['mcp:use'],
            wingPatterns: ['personal']
        );

        $body = $response->json('result.structuredContent');
        if ($body['found']) {
            foreach ($body['drawers'] as $d) {
                $this->assertEquals('personal', $d['wing']);
            }
        }
    }

    public function test_writes_brain_session_audit(): void
    {
        $this->mcpCall('recall', ['prompt' => 'anything'], ['mcp:use']);

        $session = BrainSession::latest('id')->first();
        $this->assertEquals('recall', $session->tool_name);
    }
}
