<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use App\Services\DrawerWriteService;
use App\Services\SessionDigestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class OccurredAtTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    public function test_drawer_add_stores_occurred_at(): void
    {
        $this->mcpCall('drawer_add', [
            'wing' => 'work', 'room' => 'notes', 'content' => 'an old note',
            'occurred_at' => '2026-01-05T10:30:00Z',
        ])->assertStatus(200);

        $drawer = Drawer::firstOrFail();
        $this->assertSame('2026-01-05T10:30:00+00:00', $drawer->occurred_at->toIso8601String());

        $got = $this->mcpCall('drawer_get', ['id' => $drawer->id]);
        $this->assertSame('2026-01-05T10:30:00+00:00', $got->json('result.structuredContent.occurred_at'));
    }

    public function test_drawer_without_occurred_at_reports_null(): void
    {
        $this->mcpCall('drawer_add', ['wing' => 'work', 'room' => 'notes', 'content' => 'no date']);

        $drawer = Drawer::firstOrFail();
        $this->assertNull($drawer->occurred_at);
        $got = $this->mcpCall('drawer_get', ['id' => $drawer->id]);
        $this->assertNull($got->json('result.structuredContent.occurred_at'));
    }

    public function test_a_future_occurred_at_is_clamped_to_now(): void
    {
        $this->travelTo(now()->startOfSecond());

        $this->mcpCall('drawer_add', [
            'wing' => 'work', 'room' => 'notes', 'content' => 'from the future',
            'occurred_at' => now()->addYear()->toIso8601String(),
        ])->assertStatus(200);

        $this->assertSame(now()->timestamp, Drawer::firstOrFail()->occurred_at->timestamp);
    }

    public function test_an_unparseable_occurred_at_is_a_validation_error(): void
    {
        $r = $this->mcpCall('drawer_add', [
            'wing' => 'work', 'room' => 'notes', 'content' => 'x', 'occurred_at' => 'not a time',
        ]);

        $this->assertTrue(($r->json('result.isError') ?? false) || $r->json('error') !== null);
        $this->assertSame(0, Drawer::count());
    }

    public function test_session_digest_applies_occurred_at_to_its_drawers(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        Room::factory()->create(['slug' => 'notes', 'wing_id' => $work->id]);

        $this->app->bind(SessionDigestService::class, function ($app) {
            $llm = new class
            {
                public function digest(string $t, array $c): array
                {
                    return [
                        ['content' => 'first fact', 'wing_slug' => 'work', 'room_slug' => 'notes',
                            'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
                        ['content' => 'second fact', 'wing_slug' => 'work', 'room_slug' => 'notes',
                            'confidence' => 0.9, 'propose_new_wing' => false, 'propose_new_room' => false],
                    ];
                }
            };

            return new SessionDigestService($llm, $app->make(DrawerWriteService::class));
        });

        $this->mcpCall('session_digest', [
            'session_id' => 'sess-old',
            'harness' => 'claude-code',
            'turn_range' => ['start' => 0, 'end' => 4],
            'transcript' => 'we talked about facts a long while ago',
            'occurred_at' => '2025-03-02T08:00:00Z',
        ])->assertStatus(200);

        $this->assertSame(2, Drawer::count());
        foreach (Drawer::all() as $drawer) {
            $this->assertSame('2025-03-02T08:00:00+00:00', $drawer->occurred_at->toIso8601String());
        }
    }

    public function test_temporal_boost_uses_occurred_at(): void
    {
        $room = Room::factory()->create(['wing_id' => Wing::factory()->create(['slug' => 'work'])->id]);
        $old = Drawer::factory()->create([
            'room_id' => $room->id, 'content' => 'quarterly zebra plan',
            'occurred_at' => now()->subDays(90),
        ]);
        $fresh = Drawer::factory()->create(['room_id' => $room->id, 'content' => 'quarterly zebra plan']);
        // Hash dedup is the writer's job; the factory may share a hash, so keep the ids distinct.
        $this->assertNotSame($old->id, $fresh->id);

        $results = $this->mcpCall('drawer_search', ['query' => 'zebra', 'limit' => 5])
            ->json('result.structuredContent.results');

        $this->assertCount(2, $results);
        $this->assertSame($fresh->id, $results[0]['id']);
        $this->assertNull($results[0]['occurred_at']);
        $this->assertNotNull($results[1]['occurred_at']);
    }

    public function test_wiki_compile_prefers_recent_occurred_at(): void
    {
        $wing = Wing::create(['name' => 'Atlas', 'slug' => 'project-atlas']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        // Stored last but happened long ago.
        $imported = Drawer::create(['content' => 'imported history', 'room_id' => $room->id, 'occurred_at' => now()->subYear()]);
        $current = Drawer::create(['content' => 'current work', 'room_id' => $room->id]);
        $current->forceFill(['created_at' => now()->subDay()])->save();
        $imported->forceFill(['created_at' => now()])->save();

        $r = $this->mcpCall('wiki_compile', ['name' => 'project:atlas', 'limit' => 1]);

        $ids = collect($r->json('result.structuredContent.drawers'))->pluck('id')->all();
        $this->assertSame([$current->id], $ids);
    }

    public function test_retention_still_decays_from_created_at(): void
    {
        Drawer::factory()->create([
            'room_id' => Room::factory()->create()->id,
            'occurred_at' => now()->subYear(),
        ]);

        $this->artisan('mnemon:apply-retention', ['--dry-run' => true])
            ->expectsOutputToContain('Would soft-delete 0 drawer(s)')
            ->assertSuccessful();
    }
}
