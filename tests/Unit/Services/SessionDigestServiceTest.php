<?php

namespace Tests\Unit\Services;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPendingWing;
use App\Models\Wing;
use App\Services\DrawerWriteService;
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

        $captured = new \stdClass;
        $captured->context = null;
        $driver = new class($captured)
        {
            public function __construct(public \stdClass $captured) {}

            public function digest(string $transcript, array $context): array
            {
                $this->captured->context = $context;

                return [];
            }
        };

        $service = new SessionDigestService($driver, app(DrawerWriteService::class));

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

    public function test_session_project_overrides_the_models_project_wing(): void
    {
        // The model sees only the transcript, so it guessed a neighbouring
        // project. The session's own project is known; it decides the wing.
        Wing::factory()->create(['name' => 'project:mnemon', 'slug' => 'project-mnemon']);
        Wing::factory()->create(['name' => 'project:ananke', 'slug' => 'project-ananke']);

        $service = $this->makeServiceWithMockLlm([
            ['content' => 'Fixed the capture payload cap', 'wing_slug' => 'project-ananke',
                'room_slug' => 'decisions', 'confidence' => 0.9,
                'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null, project: 'mnemon');

        $this->assertCount(1, $result['persisted']);
        $this->assertSame('project-mnemon', $result['persisted'][0]['wing']);
        $this->assertSame('notes', $result['persisted'][0]['room']);
    }

    public function test_session_project_keeps_a_room_the_wing_already_has(): void
    {
        $wing = Wing::factory()->create(['name' => 'project:mnemon', 'slug' => 'project-mnemon']);
        Room::factory()->create(['slug' => 'decisions', 'wing_id' => $wing->id]);

        $service = $this->makeServiceWithMockLlm([
            ['content' => 'Chose server-side compilation', 'wing_slug' => 'project-mnemon',
                'room_slug' => 'decisions', 'confidence' => 0.9,
                'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null, project: 'mnemon');

        $this->assertSame('decisions', $result['persisted'][0]['room']);
    }

    public function test_session_project_leaves_person_filings_alone(): void
    {
        Wing::factory()->create(['name' => 'project:mnemon', 'slug' => 'project-mnemon']);
        $person = Wing::factory()->create(['name' => 'person:cooper', 'slug' => 'person-cooper']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $person->id]);

        $service = $this->makeServiceWithMockLlm([
            ['content' => 'Cooper prefers direct commits to main', 'wing_slug' => 'person-cooper',
                'room_slug' => 'notes', 'confidence' => 0.9,
                'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null, project: 'mnemon');

        $this->assertSame('person-cooper', $result['persisted'][0]['wing']);
    }

    public function test_session_project_matches_a_wing_alias(): void
    {
        // Cora's repository is recital-lineup; the wing is named for the product.
        Wing::factory()->create(['name' => 'project:cora', 'slug' => 'project-cora', 'aliases' => ['recital-lineup']]);

        $service = $this->makeServiceWithMockLlm([
            ['content' => 'Lineup optimiser now avoids quick changes', 'wing_slug' => 'project-synergy',
                'room_slug' => 'notes', 'confidence' => 0.9,
                'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null, project: 'recital-lineup');

        $this->assertSame('project-cora', $result['persisted'][0]['wing']);
    }

    public function test_unknown_session_project_gets_its_own_wing(): void
    {
        // Proposing a new wing only queued it for a review nobody did, so the
        // model learned to squeeze unknown projects into existing wings.
        Wing::factory()->create(['name' => 'project:mnemon', 'slug' => 'project-mnemon']);

        $service = $this->makeServiceWithMockLlm([
            ['content' => 'Dispatch board POC scaffolded', 'wing_slug' => 'project-birddog',
                'room_slug' => 'notes', 'confidence' => 0.9,
                'propose_new_wing' => true, 'propose_new_room' => true],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null, project: 'birddog');

        $this->assertSame('project-birddog', $result['persisted'][0]['wing']);
        $this->assertEmpty($result['pending_wings']);
        $this->assertSame('project:birddog', Wing::where('slug', 'project-birddog')->value('name'));
        $this->assertSame(0, WikiPendingWing::count());
    }

    public function test_restricted_token_does_not_create_a_wing_for_an_unknown_project(): void
    {
        $mnemon = Wing::factory()->create(['name' => 'project:mnemon', 'slug' => 'project-mnemon']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $mnemon->id]);

        $service = $this->makeServiceWithMockLlm([
            ['content' => 'Something from birddog', 'wing_slug' => 'project-mnemon',
                'room_slug' => 'notes', 'confidence' => 0.9,
                'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], ['project-mnemon'], project: 'birddog');

        $this->assertFalse(Wing::where('slug', 'project-birddog')->exists());
        $this->assertSame('project-mnemon', $result['persisted'][0]['wing']);
    }

    public function test_session_project_outside_the_tokens_wings_is_ignored(): void
    {
        Wing::factory()->create(['name' => 'project:secret', 'slug' => 'project-secret']);
        $mnemon = Wing::factory()->create(['name' => 'project:mnemon', 'slug' => 'project-mnemon']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $mnemon->id]);

        $service = $this->makeServiceWithMockLlm([
            ['content' => 'note', 'wing_slug' => 'project-mnemon', 'room_slug' => 'notes',
                'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], ['project-mnemon'], project: 'secret');

        $this->assertSame('project-mnemon', $result['persisted'][0]['wing']);
    }

    public function test_session_wing_is_given_to_the_model(): void
    {
        Wing::factory()->create(['name' => 'project:mnemon', 'slug' => 'project-mnemon']);

        $captured = new \stdClass;
        $captured->context = null;
        $driver = new class($captured)
        {
            public function __construct(public \stdClass $captured) {}

            public function digest(string $transcript, array $context): array
            {
                $this->captured->context = $context;

                return [];
            }
        };

        (new SessionDigestService($driver, app(DrawerWriteService::class)))
            ->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null, project: 'mnemon');

        $this->assertSame('project-mnemon', $captured->context['session_wing']);
    }

    public function test_without_a_project_the_models_choice_stands(): void
    {
        $ananke = Wing::factory()->create(['name' => 'project:ananke', 'slug' => 'project-ananke']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $ananke->id]);

        $service = $this->makeServiceWithMockLlm([
            ['content' => 'note', 'wing_slug' => 'project-ananke', 'room_slug' => 'notes',
                'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null);

        $this->assertSame('project-ananke', $result['persisted'][0]['wing']);
    }

    private function makeServiceWithMockLlm(array $proposals): SessionDigestService
    {
        $driver = new class($proposals)
        {
            public function __construct(public array $proposals) {}

            public function digest(string $transcript, array $context): array
            {
                return $this->proposals;
            }
        };

        return new SessionDigestService($driver, app(DrawerWriteService::class));
    }
}
