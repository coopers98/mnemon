<?php

namespace Tests\Unit\Services;

use App\Contracts\EmbeddingDriverInterface;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\SessionDigest;
use App\Models\WikiPendingWing;
use App\Models\Wing;
use App\Observers\DrawerObserver;
use App\Services\Digest\DigestUnavailable;
use App\Services\DrawerWriteService;
use App\Services\EmbeddingManager;
use App\Services\SessionDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        // Only where the model's wing choice stands; otherwise the drawer is unfiled.
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

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null, projectFiling: 'model');

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

    public function test_unknown_room_without_a_proposal_falls_back_to_notes(): void
    {
        // A model naming a room the wing lacks, without proposing it, used to
        // have the drawer silently dropped.
        $cooper = Wing::factory()->create(['slug' => 'person-cooper']);
        $service = $this->makeServiceWithMockLlm([
            ['content' => 'Cooper prefers ASCII-only GitHub bios', 'wing_slug' => 'person-cooper',
                'room_slug' => 'preferences', 'confidence' => 0.9,
                'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null, projectFiling: 'model');

        $this->assertCount(1, $result['persisted']);
        $drawer = Drawer::find($result['persisted'][0]['id']);
        $this->assertSame('notes', $drawer->room->slug);
        $this->assertSame($cooper->id, $drawer->room->wing_id);
        $this->assertFalse(Room::where('wing_id', $cooper->id)->where('slug', 'preferences')->exists());
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

    public function test_without_a_project_project_drawers_are_unfiled(): void
    {
        // A client older than 0.3.6 sends no project. The model's guess put one
        // device's sessions across two dozen wings, so the guess is kept as a
        // suggestion and the drawer is not filed under someone else's project.
        $ananke = Wing::factory()->create(['name' => 'project:ananke', 'slug' => 'project-ananke']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $ananke->id]);

        $service = $this->makeServiceWithMockLlm([
            ['content' => 'note', 'wing_slug' => 'project-ananke', 'room_slug' => 'notes',
                'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null);

        $this->assertSame('unfiled', $result['persisted'][0]['wing']);
        $this->assertSame('notes', $result['persisted'][0]['room']);
        $this->assertSame('project-ananke', Drawer::find($result['persisted'][0]['id'])->metadata['suggested_wing']);
    }

    public function test_without_a_project_a_new_wing_proposal_is_unfiled_not_queued(): void
    {
        $service = $this->makeServiceWithMockLlm([
            ['content' => 'note', 'wing_slug' => 'project-birddog', 'room_slug' => 'notes',
                'confidence' => 0.9, 'propose_new_wing' => true, 'propose_new_room' => true],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null);

        $this->assertSame('unfiled', $result['persisted'][0]['wing']);
        $this->assertSame(0, WikiPendingWing::count());
    }

    public function test_without_a_project_person_filings_stand(): void
    {
        $person = Wing::factory()->create(['name' => 'person:cooper', 'slug' => 'person-cooper']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $person->id]);

        $service = $this->makeServiceWithMockLlm([
            ['content' => 'Cooper prefers direct commits to main', 'wing_slug' => 'person-cooper',
                'room_slug' => 'notes', 'confidence' => 0.9,
                'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null);

        $this->assertSame('person-cooper', $result['persisted'][0]['wing']);
        $this->assertFalse(Wing::where('slug', 'unfiled')->exists());
    }

    public function test_project_filing_model_keeps_the_models_choice(): void
    {
        // A repository in project_ignore spans many projects; there the guess is wanted.
        $ananke = Wing::factory()->create(['name' => 'project:ananke', 'slug' => 'project-ananke']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $ananke->id]);

        $service = $this->makeServiceWithMockLlm([
            ['content' => 'note', 'wing_slug' => 'project-ananke', 'room_slug' => 'notes',
                'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], null, projectFiling: 'model');

        $this->assertSame('project-ananke', $result['persisted'][0]['wing']);
        $this->assertArrayNotHasKey('suggested_wing', Drawer::find($result['persisted'][0]['id'])->metadata);
    }

    public function test_restricted_token_without_the_unfiled_wing_keeps_the_models_choice(): void
    {
        $mnemon = Wing::factory()->create(['name' => 'project:mnemon', 'slug' => 'project-mnemon']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $mnemon->id]);

        $service = $this->makeServiceWithMockLlm([
            ['content' => 'note', 'wing_slug' => 'project-mnemon', 'room_slug' => 'notes',
                'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], ['project-*']);

        $this->assertSame('project-mnemon', $result['persisted'][0]['wing']);
        $this->assertFalse(Wing::where('slug', 'unfiled')->exists());
    }

    public function test_restricted_token_that_can_see_unfiled_uses_it(): void
    {
        Wing::factory()->create(['name' => 'unfiled', 'slug' => 'unfiled']);
        $mnemon = Wing::factory()->create(['name' => 'project:mnemon', 'slug' => 'project-mnemon']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $mnemon->id]);

        $service = $this->makeServiceWithMockLlm([
            ['content' => 'note', 'wing_slug' => 'project-mnemon', 'room_slug' => 'notes',
                'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $result = $service->run('s', 'claude-code', ['start' => 0, 'end' => 1], 't', [], ['project-*', 'unfiled']);

        $this->assertSame('unfiled', $result['persisted'][0]['wing']);
    }

    public function test_an_unavailable_model_records_nothing_so_the_range_can_be_retried(): void
    {
        $driver = new class
        {
            public function digest(string $transcript, array $context): array
            {
                throw new DigestUnavailable('the model call failed with HTTP 503', retryable: true);
            }
        };
        $service = new SessionDigestService($driver, app(DrawerWriteService::class));

        try {
            $service->run('sess-down', 'claude-code', ['start' => 0, 'end' => 4], 't', [], null);
            $this->fail('a failed model call must not return as a successful digest');
        } catch (DigestUnavailable) {
        }

        $this->assertSame(0, SessionDigest::count(), 'an unrecorded range is digested afresh on retry');

        $retry = $this->makeServiceWithMockLlm([])
            ->run('sess-down', 'claude-code', ['start' => 0, 'end' => 4], 't', [], null);
        $this->assertArrayNotHasKey('replayed', $retry);
    }

    public function test_a_proposal_that_cannot_be_persisted_leaves_nothing_behind(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);
        $service = $this->makeServiceWithMockLlm([
            ['content' => 'written first', 'wing_slug' => 'work', 'room_slug' => 'notes',
                'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
            ['content' => ['not', 'text'], 'wing_slug' => 'work', 'room_slug' => 'notes',
                'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        try {
            $service->run('sess-half', 'claude-code', ['start' => 0, 'end' => 4], 't', [], null);
            $this->fail('a proposal that cannot be persisted must fail the digest');
        } catch (\Throwable) {
        }

        // The client retries a failed digest. A drawer committed before the
        // failure would be written a second time by that retry.
        $this->assertSame(0, Drawer::count());
        $this->assertSame(0, SessionDigest::count());
    }

    /**
     * Devices still on plugin 0.3.10 send hook output, Mnemon's own recall and
     * wake text among it, inside the transcript. Stripped here, it is not
     * digested back into the palace whatever the client sends.
     */
    public function test_strips_mnemon_recall_and_wake_text_before_the_model_sees_it(): void
    {
        $driver = new class
        {
            public string $seen = '';

            public function digest(string $transcript, array $context): array
            {
                $this->seen = $transcript;

                return [];
            }
        };
        $service = new SessionDigestService($driver, app(DrawerWriteService::class));

        $stale = json_encode([
            ['type' => 'attachment', 'attachment' => ['type' => 'hook_success',
                'stdout' => "<system-reminder>\nMnemon palace state:\n  Recent drawers:\n    1) WAKE-LEAK (w/r)\n</system-reminder>"]],
            ['type' => 'attachment', 'attachment' => ['type' => 'hook_additional_context',
                'content' => ["Mnemon recall:\n  Drawers:\n    1) RECALL-LEAK \"quoted\" (w/r)"]]],
            ['type' => 'user', 'message' => ['role' => 'user', 'content' => 'USER-WORDS stay']],
        ]);
        $raw = "Mnemon recall:\n  Drawers:\n    1) RAW-RECALL-LEAK (w/r)\nUSER-RAW-WORDS stay";

        $service->run('sess-stale', 'claude-code', ['start' => 0, 'end' => 3], $stale, [], null);
        $this->assertStringNotContainsString('LEAK', $driver->seen);
        $this->assertStringContainsString('USER-WORDS stay', $driver->seen);
        $this->assertNotNull(json_decode($driver->seen), 'stripping must leave a JSON transcript parseable');

        $service->run('sess-stale', 'claude-code', ['start' => 3, 'end' => 4], $raw, [], null);
        $this->assertStringNotContainsString('LEAK', $driver->seen);
        $this->assertStringContainsString('USER-RAW-WORDS stay', $driver->seen);
    }

    /**
     * Two workers digesting one range: the loser's drawers used to commit
     * alongside the winner's.
     */
    public function test_losing_the_race_to_record_a_slice_rolls_back_and_returns_the_winners_replay(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);
        $driver = new class
        {
            public function digest(string $transcript, array $context): array
            {
                // The other worker records the same range while this one waits on the model.
                SessionDigest::create(['session_id' => 'sess-race', 'turn_start' => 0, 'turn_end' => 4, 'drawer_ids' => []]);

                return [['content' => 'the loser\'s drawer', 'wing_slug' => 'work', 'room_slug' => 'notes',
                    'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false]];
            }
        };
        $service = new SessionDigestService($driver, app(DrawerWriteService::class));

        $result = $service->run('sess-race', 'claude-code', ['start' => 0, 'end' => 4], 't', [], null);

        $this->assertTrue($result['replayed'] ?? false);
        $this->assertSame(0, Drawer::count(), 'the loser must not leave drawers behind');
        $this->assertSame(1, SessionDigest::count());
    }

    public function test_a_long_mnemon_block_is_stripped_rather_than_skipping_every_pattern(): void
    {
        $driver = new class
        {
            public string $seen = '';

            public function digest(string $transcript, array $context): array
            {
                $this->seen = $transcript;

                return [];
            }
        };
        $service = new SessionDigestService($driver, app(DrawerWriteService::class));

        // Past the PCRE JIT stack, a backtracking pattern returns null and
        // preg_replace skipped every pattern -- silently.
        $stale = json_encode([
            ['type' => 'attachment', 'attachment' => ['content' => ["Mnemon recall:\n".str_repeat('drawer text ', 8000).'LONG-RECALL-LEAK']]],
            ['type' => 'attachment', 'attachment' => ['stdout' => '<system-reminder>'.str_repeat('x', 50000).'LONG-REMINDER-LEAK</system-reminder>']],
            ['type' => 'user', 'message' => ['content' => str_repeat('<system-reminder>unclosed ', 2000).'USER-WORDS stay']],
        ]);

        $service->run('sess-long', 'claude-code', ['start' => 0, 'end' => 3], $stale, [], null);

        $this->assertStringNotContainsString('LEAK', $driver->seen);
        $this->assertStringContainsString('USER-WORDS stay', $driver->seen);
    }

    /**
     * Embeddings are an HTTP call per drawer. Inside the digest transaction
     * they held its locks (the wiki page counters among them) for as long as
     * the embedding API took.
     */
    public function test_embeddings_and_wiki_counters_run_after_the_transaction_commits(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);
        $observer = new class(app(EmbeddingManager::class)) extends DrawerObserver
        {
            public array $levels = [];

            protected function shouldEmbed(): bool
            {
                return true;
            }

            protected function embedStored(Drawer $drawer): void
            {
                $this->levels[] = DB::transactionLevel();
            }
        };
        $this->app->instance(DrawerObserver::class, $observer);
        $baseline = DB::transactionLevel();

        $this->makeServiceWithMockLlm([
            ['content' => 'one', 'wing_slug' => 'work', 'room_slug' => 'notes', 'confidence' => 0.9,
                'propose_new_wing' => false, 'propose_new_room' => false],
            ['content' => 'two', 'wing_slug' => 'work', 'room_slug' => 'notes', 'confidence' => 0.9,
                'propose_new_wing' => false, 'propose_new_room' => false],
        ])->run('sess-embed', 'claude-code', ['start' => 0, 'end' => 4], 't', [], null);

        $this->assertSame([$baseline, $baseline], $observer->levels);
    }

    public function test_an_embedding_that_fails_after_commit_does_not_fail_the_digest(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);
        $this->app->instance(DrawerObserver::class, new class(app(EmbeddingManager::class)) extends DrawerObserver
        {
            protected function shouldEmbed(): bool
            {
                return true;
            }

            protected function embedStored(Drawer $drawer): void
            {
                throw new \RuntimeException('embedding API down');
            }
        });

        $result = $this->makeServiceWithMockLlm([
            ['content' => 'kept anyway', 'wing_slug' => 'work', 'room_slug' => 'notes', 'confidence' => 0.9,
                'propose_new_wing' => false, 'propose_new_room' => false],
        ])->run('sess-embed-fail', 'claude-code', ['start' => 0, 'end' => 4], 't', [], null);

        $this->assertCount(1, $result['persisted']);
        $this->assertSame(1, Drawer::count());
    }

    /**
     * The client's call times out after the server committed 0-8. The retry
     * must not digest those turns again, whatever end it now asks for.
     */
    public function test_a_retry_from_a_recorded_start_is_a_replay_that_says_where_it_ended(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);
        $driver = new class
        {
            public int $calls = 0;

            public function digest(string $transcript, array $context): array
            {
                $this->calls++;

                return [['content' => 'from 0-8', 'wing_slug' => 'work', 'room_slug' => 'notes',
                    'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false]];
            }
        };
        $service = new SessionDigestService($driver, app(DrawerWriteService::class));

        $first = $service->run('sess-t', 'claude-code', ['start' => 0, 'end' => 8], 't', [], null);
        $same = $service->run('sess-t', 'claude-code', ['start' => 0, 'end' => 8], 't', [], null);
        $later = $service->run('sess-t', 'claude-code', ['start' => 0, 'end' => 12], 't', [], null);

        $this->assertSame(1, $driver->calls, 'neither retry may reach the model');
        $this->assertSame(1, Drawer::count());
        $this->assertTrue($same['replayed']);
        $this->assertSame(8, $same['turn_end']);
        $this->assertTrue($later['replayed']);
        $this->assertSame(8, $later['turn_end'], 'the client advances to what was recorded, and carries the rest');
        $this->assertSame(collect($first['persisted'])->pluck('id')->all(), collect($later['persisted'])->pluck('id')->all());
        $this->assertSame(8, $first['turn_end']);
    }

    public function test_a_wiki_counter_failure_after_commit_does_not_fail_the_digest(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);
        $this->app->instance(DrawerObserver::class, new class(app(EmbeddingManager::class)) extends DrawerObserver
        {
            protected function countForWikiPages(Drawer $drawer): void
            {
                throw new \RuntimeException('wiki counter failed');
            }
        });

        $result = $this->makeServiceWithMockLlm([
            ['content' => 'stored', 'wing_slug' => 'work', 'room_slug' => 'notes', 'confidence' => 0.9,
                'propose_new_wing' => false, 'propose_new_room' => false],
        ])->run('sess-wiki', 'claude-code', ['start' => 0, 'end' => 4], 't', [], null);

        $this->assertCount(1, $result['persisted']);
        $this->assertSame(1, Drawer::count());
    }

    /**
     * The real embedStored path: the embedding driver is called after commit,
     * and a failure writing it (here SQLite has no embedding column) is
     * logged, not raised.
     */
    public function test_the_real_embedding_path_runs_after_commit_and_cannot_fail_the_digest(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);
        $fake = new class implements EmbeddingDriverInterface
        {
            public array $levels = [];

            public function embed(string $text): ?array
            {
                $this->levels[] = DB::transactionLevel();

                return array_fill(0, 3, 0.1);
            }

            public function dimensions(): int
            {
                return 3;
            }
        };
        $manager = \Mockery::mock(EmbeddingManager::class);
        $manager->shouldReceive('driver')->andReturn($fake);
        $this->app->instance(DrawerObserver::class, new class($manager) extends DrawerObserver
        {
            protected function shouldEmbed(): bool
            {
                return true;
            }
        });
        $baseline = DB::transactionLevel();

        $result = $this->makeServiceWithMockLlm([
            ['content' => 'embedded later', 'wing_slug' => 'work', 'room_slug' => 'notes', 'confidence' => 0.9,
                'propose_new_wing' => false, 'propose_new_room' => false],
        ])->run('sess-real-embed', 'claude-code', ['start' => 0, 'end' => 4], 't', [], null);

        $this->assertSame([$baseline], $fake->levels);
        $this->assertCount(1, $result['persisted']);
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
