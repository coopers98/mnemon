<?php

namespace Tests\Unit\Services;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPendingWing;
use App\Models\Wing;
use App\Services\SessionDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionDigestServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_high_confidence_proposal_persists_drawer(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'meetings', 'wing_id' => $work->id]);

        $service = $this->makeServiceWithMockLlm([
            [
                'content' => 'Meeting with Dorothy — Atlas v2 planning',
                'wing_slug' => 'work',
                'room_slug' => 'meetings',
                'confidence' => 0.9,
                'propose_new_wing' => false,
                'propose_new_room' => false,
                'rationale' => 'Clear meeting note',
            ],
        ]);

        $result = $service->run(
            sessionId: 'sess-1',
            harness: 'claude-code',
            turnRange: ['start' => 0, 'end' => 4],
            transcript: 'Cooper: had a meeting with dorothy today...',
            recentDrawerIds: [],
            allowedWingPatterns: null,
        );

        $this->assertCount(1, $result['persisted']);
        $this->assertCount(0, $result['pending_wings']);
        $drawer = Drawer::find($result['persisted'][0]['id']);
        $this->assertEquals('claude-code:session_digest', $drawer->source);
        $this->assertEquals('session_digest', $drawer->metadata['captured_via']);
        $this->assertEquals('claude-code', $drawer->metadata['harness']);
        $this->assertEquals('sess-1', $drawer->metadata['session_id']);
    }

    public function test_low_confidence_proposal_is_dropped(): void
    {
        $service = $this->makeServiceWithMockLlm([
            ['content' => 'noisy', 'wing_slug' => 'work', 'room_slug' => 'notes',
             'confidence' => 0.3, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null);

        $this->assertEmpty($result['persisted']);
    }

    public function test_propose_new_wing_queues_for_review(): void
    {
        $service = $this->makeServiceWithMockLlm([
            [
                'content' => 'Atlas project planning notes',
                'wing_slug' => 'project:atlas',
                'room_slug' => 'planning',
                'confidence' => 0.9,
                'propose_new_wing' => true,
                'propose_new_room' => true,
                'rationale' => 'Multiple atlas refs',
            ],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null);

        $this->assertEmpty($result['persisted']);
        $this->assertCount(1, $result['pending_wings']);
        $this->assertEquals(1, WikiPendingWing::where('status', 'pending')->count());
    }

    public function test_propose_new_room_auto_creates(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        $service = $this->makeServiceWithMockLlm([
            [
                'content' => 'a new kind of note',
                'wing_slug' => 'work',
                'room_slug' => 'novel-room',
                'confidence' => 0.8,
                'propose_new_wing' => false,
                'propose_new_room' => true,
                'rationale' => 'Novel category',
            ],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null);

        $this->assertCount(1, $result['persisted']);
        $room = Room::where('slug', 'novel-room')->where('wing_id', $work->id)->first();
        $this->assertNotNull($room);
        $this->assertTrue($room->metadata['auto_created'] ?? false);
    }

    private function makeServiceWithMockLlm(array $proposals): SessionDigestService
    {
        $driver = new class($proposals) {
            public function __construct(public array $proposals) {}
            public function digest(string $transcript, array $context): array
            {
                return $this->proposals;
            }
        };

        return new SessionDigestService($driver, app(\App\Services\DrawerWriteService::class));
    }
}
