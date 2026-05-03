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
        $this->assertEmpty($result['pending_wings']);
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

    public function test_llm_context_includes_recent_drawers_existing_wings_and_turn_range(): void
    {
        // Seed environment so context fields are non-empty.
        $work = Wing::factory()->create(['slug' => 'work']);
        $personal = Wing::factory()->create(['slug' => 'personal']);
        Room::factory()->create(['slug' => 'meetings', 'wing_id' => $work->id]);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $personal->id]);
        $existing = Drawer::factory()->create([
            'room_id' => Room::where('slug', 'meetings')->where('wing_id', $work->id)->first()->id,
            'content' => str_repeat('a', 500), // longer than 200 to verify truncation
        ]);

        $captured = new \stdClass();
        $captured->context = null;
        $driver = new class($captured) {
            public function __construct(public \stdClass $captured) {}
            public function digest(string $transcript, array $context): array
            {
                $this->captured->context = $context;
                return [];
            }
        };

        $service = new SessionDigestService($driver, app(\App\Services\DrawerWriteService::class));

        $service->run(
            sessionId: 'sess-x',
            harness: 'claude-code',
            turnRange: ['start' => 4, 'end' => 9],
            transcript: 'whatever',
            recentDrawerIds: [$existing->id],
            allowedWingPatterns: null,
        );

        $ctx = $captured->context;
        $this->assertNotNull($ctx, 'LLM context should have been captured');

        // turn_range round-trips intact
        $this->assertEquals(['start' => 4, 'end' => 9], $ctx['turn_range']);

        // existing_wings — both seeded slugs are present
        $this->assertContains('work', $ctx['existing_wings']);
        $this->assertContains('personal', $ctx['existing_wings']);

        // existing_rooms_per_wing keyed by wing id
        $this->assertIsArray($ctx['existing_rooms_per_wing']);
        $allRooms = collect($ctx['existing_rooms_per_wing'])->flatten()->all();
        $this->assertContains('meetings', $allRooms);
        $this->assertContains('notes', $allRooms);

        // recent_drawers shape: id + truncated snippet
        $this->assertCount(1, $ctx['recent_drawers']);
        $this->assertEquals($existing->id, $ctx['recent_drawers'][0]['id']);
        $this->assertEquals(200, mb_strlen($ctx['recent_drawers'][0]['snippet']));
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
