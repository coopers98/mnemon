<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\BrainSession;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use App\Services\DrawerWriteService;
use App\Services\SessionDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class SessionDigestToolTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_persists_high_confidence_proposal(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);

        $this->mockDigestService([
            ['content' => 'a real note', 'wing_slug' => 'work', 'room_slug' => 'notes',
             'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $response = $this->mcpCall('session_digest', [
            'session_id' => 'sess-1',
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 4],
            'transcript' => 'we discussed a real note today',
        ], ['mcp:use']);

        $response->assertStatus(200);
        $body = $response->json('result.structuredContent');
        $this->assertCount(1, $body['persisted']);
        $this->assertEquals(1, Drawer::count());
    }

    public function test_writes_brain_session_with_persist_count(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);
        $this->mockDigestService([
            ['content' => 'note', 'wing_slug' => 'work', 'room_slug' => 'notes',
             'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $this->mcpCall('session_digest', [
            'session_id' => 's',
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 1],
            'transcript' => 't',
        ], ['mcp:use']);

        $session = BrainSession::latest('id')->first();
        $this->assertEquals('session_digest', $session->tool_name);
        $this->assertEquals(1, $session->result_count);
    }

    public function test_validation_rejects_missing_session_id(): void
    {
        $response = $this->mcpCall('session_digest', [
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 1],
            'transcript' => 't',
        ], ['mcp:use']);

        $body = $response->json();
        $this->assertTrue(isset($body['result']['isError']) && $body['result']['isError'] === true);
    }

    private function mockDigestService(array $proposals): void
    {
        $this->app->bind(SessionDigestService::class, function ($app) use ($proposals) {
            $llm = new class($proposals) {
                public function __construct(public array $p) {}
                public function digest(string $t, array $c): array
                {
                    return $this->p;
                }
            };

            return new SessionDigestService($llm, $app->make(DrawerWriteService::class));
        });
    }
}
