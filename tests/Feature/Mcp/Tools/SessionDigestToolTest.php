<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\BrainSession;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\SessionDigest;
use App\Models\Wing;
use App\Services\DrawerWriteService;
use App\Services\SessionDigestService;
use Illuminate\Database\DeadlockException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_project_argument_decides_the_wing(): void
    {
        Wing::factory()->create(['name' => 'project:mnemon', 'slug' => 'project-mnemon']);
        $other = Wing::factory()->create(['name' => 'project:ananke', 'slug' => 'project-ananke']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $other->id]);

        $this->mockDigestService([
            ['content' => 'a mnemon note', 'wing_slug' => 'project-ananke', 'room_slug' => 'notes',
                'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
        ]);

        $response = $this->mcpCall('session_digest', [
            'session_id' => 'sess-p',
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 4],
            'transcript' => 'worked on mnemon',
            'project' => 'mnemon',
        ], ['mcp:use']);

        $response->assertStatus(200);
        $this->assertSame('project-mnemon', $response->json('result.structuredContent.persisted.0.wing'));
    }

    public function test_without_a_project_the_drawer_is_unfiled_unless_filing_is_left_to_the_model(): void
    {
        $other = Wing::factory()->create(['name' => 'project:ananke', 'slug' => 'project-ananke']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $other->id]);
        $proposal = ['content' => 'a note', 'wing_slug' => 'project-ananke', 'room_slug' => 'notes',
            'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false];
        $args = fn (string $sid) => ['session_id' => $sid, 'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 4], 'transcript' => 'worked on something'];

        $this->mockDigestService([$proposal]);
        $response = $this->mcpCall('session_digest', $args('sess-u'), ['mcp:use']);
        $this->assertSame('unfiled', $response->json('result.structuredContent.persisted.0.wing'));

        $this->mockDigestService([$proposal]);
        $response = $this->mcpCall('session_digest', $args('sess-m') + ['project_filing' => 'model'], ['mcp:use']);
        $this->assertSame('project-ananke', $response->json('result.structuredContent.persisted.0.wing'));
    }

    /**
     * A failed model call used to come back from the driver as zero proposals,
     * so the tool recorded the slice as digested and answered success. The
     * client then advanced past those turns, and a retry was served the empty
     * replay: the turns were lost for good.
     *
     * @return array<string, array{0: callable}>
     */
    public static function failedModelCalls(): array
    {
        return [
            'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
            '429' => [fn () => Http::response(['error' => ['message' => 'rate limited']], 429)],
            '500' => [fn () => Http::response('<html>oops</html>', 500)],
        ];
    }

    #[DataProvider('failedModelCalls')]
    public function test_a_failed_model_call_is_a_tool_error_and_persists_nothing(callable $reply): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);
        config(['services.openai.api_key' => 'sk-test', 'mnemon.digest.driver' => 'openai']);
        Http::fake(['api.openai.com/*' => $reply]);

        $response = $this->mcpCall('session_digest', $this->digestArgs('sess-fail'), ['mcp:use']);

        $response->assertStatus(200);
        $this->assertTrue($response->json('result.isError'), 'a failed digest must be a tool error');
        $text = collect($response->json('result.content'))->pluck('text')->join(' ');
        // The client keeps the first 160 characters of a tool error, so the
        // verdict leads.
        $this->assertStringStartsWith('Retryable:', $text);
        $this->assertStringContainsString('did not run', $text);
        $this->assertStringNotContainsString('cURL', $text);
        $this->assertSame(0, SessionDigest::count(), 'a failed digest must not be recorded as digested');
        $this->assertSame(0, Drawer::count());
    }

    public function test_a_failure_that_will_not_clear_by_retrying_says_so(): void
    {
        config(['services.openai.api_key' => '', 'mnemon.digest.driver' => 'openai']);
        Http::fake();

        $response = $this->mcpCall('session_digest', $this->digestArgs('sess-nokey'), ['mcp:use']);

        $this->assertTrue($response->json('result.isError'));
        $text = collect($response->json('result.content'))->pluck('text')->join(' ');
        $this->assertStringStartsWith('Not retryable:', $text);
        $this->assertSame(0, SessionDigest::count());
    }

    public function test_a_valid_empty_proposal_list_is_still_a_successful_digest(): void
    {
        config(['services.openai.api_key' => 'sk-test', 'mnemon.digest.driver' => 'openai']);
        Http::fake(['api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => '{"proposals":[]}']]]])]);

        $response = $this->mcpCall('session_digest', $this->digestArgs('sess-empty'), ['mcp:use']);

        $this->assertNotTrue($response->json('result.isError'));
        $this->assertSame([], $response->json('result.structuredContent.persisted'));
        $this->assertSame(1, SessionDigest::count(), 'nothing worth keeping is a real result and is recorded');
    }

    public function test_a_retry_after_a_failure_digests_the_slice_normally(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);
        config(['services.openai.api_key' => 'sk-test', 'mnemon.digest.driver' => 'openai']);
        Http::fakeSequence('api.openai.com/*')
            ->push('<html>oops</html>', 503)
            ->push(['choices' => [['message' => ['content' => json_encode(['proposals' => [
                ['content' => 'the retry kept this', 'wing_slug' => 'work', 'room_slug' => 'notes',
                    'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
            ]])]]]]);

        $first = $this->mcpCall('session_digest', $this->digestArgs('sess-retry'), ['mcp:use']);
        $this->assertTrue($first->json('result.isError'));

        $second = $this->mcpCall('session_digest', $this->digestArgs('sess-retry'), ['mcp:use']);

        $this->assertNotTrue($second->json('result.isError'));
        $this->assertArrayNotHasKey('replayed', $second->json('result.structuredContent'),
            'the retry must reach the model, not replay a stale empty result');
        $this->assertCount(1, $second->json('result.structuredContent.persisted'));
        $this->assertSame(1, Drawer::count());
    }

    /**
     * Anything else that escapes the digest -- a deadlock, a proposal the
     * driver let through and the service could not persist -- used to reach
     * the client as a bare -32603, with no audit row.
     */
    public function test_an_unexpected_failure_is_an_audited_retryable_tool_error(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);
        $this->app->bind(SessionDigestService::class, function () {
            $llm = new class
            {
                public function digest(string $t, array $c): array
                {
                    return [['content' => 'a note', 'wing_slug' => 'work', 'room_slug' => 'notes',
                        'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false]];
                }
            };
            $writer = \Mockery::mock(DrawerWriteService::class);
            $writer->shouldReceive('createDrawer')->andThrow(new DeadlockException('Deadlock found when trying to get lock'));

            return new SessionDigestService($llm, $writer);
        });

        $response = $this->mcpCall('session_digest', $this->digestArgs('sess-deadlock'), ['mcp:use']);

        $this->assertTrue($response->json('result.isError'));
        $text = collect($response->json('result.content'))->pluck('text')->join(' ');
        $this->assertStringStartsWith('Retryable:', $text);
        $this->assertStringNotContainsString('Deadlock found', $text, 'database internals stay in the log');
        $this->assertSame(0, SessionDigest::count());
        $this->assertSame('failed', BrainSession::latest('id')->first()->outcome);
    }

    private function digestArgs(string $sessionId): array
    {
        return [
            'session_id' => $sessionId,
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 4],
            'transcript' => 'we discussed a real note today',
        ];
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
