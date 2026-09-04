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

    public function test_repeating_a_turn_range_does_not_persist_it_twice(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);

        $this->mockDigestService([
            ['content' => 'a real note', 'wing_slug' => 'work', 'room_slug' => 'notes',
                'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $args = [
            'session_id' => 'sess-dup',
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 4],
            'transcript' => 'we discussed a real note today',
        ];

        $this->mcpCall('session_digest', $args, ['mcp:use'])->assertStatus(200);
        $this->assertEquals(1, Drawer::count());

        // The same slice arriving again — two capture workers racing, or a
        // resumed session replaying a range it already sent.
        $second = $this->mcpCall('session_digest', $args, ['mcp:use']);

        $second->assertStatus(200);
        $this->assertEquals(1, Drawer::count(),
            'a turn range already digested must not create a second drawer');
    }

    public function test_repeating_a_turn_range_returns_what_was_persisted_before(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);

        $this->mockDigestService([
            ['content' => 'a real note', 'wing_slug' => 'work', 'room_slug' => 'notes',
                'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $args = [
            'session_id' => 'sess-dup-2',
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 4],
            'transcript' => 'we discussed a real note today',
        ];

        $first = $this->mcpCall('session_digest', $args, ['mcp:use'])
            ->json('result.structuredContent');
        $second = $this->mcpCall('session_digest', $args, ['mcp:use'])
            ->json('result.structuredContent');

        // The caller records these ids in recent_drawer_ids, so a replay has to
        // report what exists rather than an empty list.
        $this->assertEquals(
            collect($first['persisted'])->pluck('id')->all(),
            collect($second['persisted'])->pluck('id')->all(),
            'a replayed range should report the drawers it produced the first time'
        );
    }

    public function test_a_different_turn_range_in_the_same_session_still_digests(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);

        $base = ['session_id' => 'sess-seq', 'harness' => 'claude-code',
            'transcript' => 'we discussed a real note today'];

        // Each range yields its own content, as two different slices of a
        // transcript do. Identical content is refused at write time now, so a
        // stub returning one fixed string for both calls would be measuring
        // deduplication rather than range-keyed idempotency.
        $this->mockDigestService([
            ['content' => 'the first half of the conversation', 'wing_slug' => 'work', 'room_slug' => 'notes',
                'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);
        $this->mcpCall('session_digest', $base + ['turn_range' => ['start' => 0, 'end' => 4]], ['mcp:use']);

        $this->mockDigestService([
            ['content' => 'the second half of the conversation', 'wing_slug' => 'work', 'room_slug' => 'notes',
                'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);
        $this->mcpCall('session_digest', $base + ['turn_range' => ['start' => 4, 'end' => 9]], ['mcp:use']);

        $this->assertEquals(2, Drawer::count(),
            'idempotency must key on the range, not suppress the whole session');
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
            $llm = new class($proposals)
            {
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
