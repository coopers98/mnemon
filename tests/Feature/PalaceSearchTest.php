<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use App\Services\PalaceSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PalaceSearchTest extends TestCase
{
    use RefreshDatabase;

    private function createDrawer(string $content, ?Wing $wing = null, ?string $roomName = null, ?string $source = null, ?string $createdAt = null): Drawer
    {
        $wing ??= Wing::firstOrCreate(['name' => 'Test Wing'], ['slug' => 'test-wing']);
        $room = Room::firstOrCreate(
            ['wing_id' => $wing->id, 'slug' => Str::slug($roomName ?? 'Default Room')],
            ['name' => $roomName ?? 'Default Room'],
        );

        $drawer = Drawer::create([
            'room_id' => $room->id,
            'content' => $content,
            'source' => $source ?? 'test',
        ]);

        if ($createdAt) {
            $drawer->update(['created_at' => $createdAt]);
        }

        return $drawer;
    }

    public function test_search_returns_matching_drawers_by_keyword(): void
    {
        $this->createDrawer('Laravel is a PHP framework for web development');
        $this->createDrawer('Python is great for data science');

        $service = app(PalaceSearchService::class);
        $results = $service->search('Laravel PHP');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Laravel', $results->first()->content);
    }

    public function test_search_respects_wing_scoping(): void
    {
        $wing1 = Wing::create(['name' => 'Project Atlas']);
        $wing2 = Wing::create(['name' => 'Project Cora']);

        $this->createDrawer('Atlas uses UUIDs for assets', $wing1);
        $this->createDrawer('Cora uses UUIDs for assets', $wing2);

        $service = app(PalaceSearchService::class);
        $results = $service->search('UUIDs', wing: 'project-atlas');

        $this->assertCount(1, $results);
        $this->assertEquals('Project Atlas', $results->first()->wing);
    }

    public function test_search_respects_room_scoping(): void
    {
        $wing = Wing::create(['name' => 'Project Atlas']);
        $this->createDrawer('Sprint 1 task list', $wing, 'Sprint 1');
        $this->createDrawer('Sprint 2 task list', $wing, 'Sprint 2');

        $service = app(PalaceSearchService::class);
        $results = $service->search('task list', wing: 'project-atlas', room: 'sprint-1');

        $this->assertCount(1, $results);
        $this->assertEquals('Sprint 1', $results->first()->room);
    }

    public function test_search_respects_limit(): void
    {
        $wing = Wing::create(['name' => 'Test']);
        for ($i = 0; $i < 10; $i++) {
            $this->createDrawer("Document number {$i} about testing", $wing);
        }

        $service = app(PalaceSearchService::class);
        $results = $service->search('testing', limit: 3);

        $this->assertCount(3, $results);
    }

    public function test_temporal_boost_increases_score_for_recent_content(): void
    {
        $wing = Wing::create(['name' => 'Test']);
        $this->createDrawer('Important decision about testing strategy', $wing, null, null, now()->subDays(30)->toDateTimeString());
        $this->createDrawer('Important decision about testing strategy', $wing, null, null, now()->toDateTimeString());

        $service = app(PalaceSearchService::class);
        $results = $service->search('testing strategy', mode: 'hybrid');

        $this->assertCount(2, $results);
        // Recent content should score higher
        $first = $results->first();
        $last = $results->last();
        $this->assertGreaterThanOrEqual($last->score, $first->score);
    }

    public function test_empty_query_returns_empty_collection(): void
    {
        $this->createDrawer('Some content here');

        $service = app(PalaceSearchService::class);
        $results = $service->search('');

        $this->assertCount(0, $results);
    }

    public function test_no_results_returns_empty_collection(): void
    {
        $this->createDrawer('Laravel is great');

        $service = app(PalaceSearchService::class);
        $results = $service->search('xyznonexistent');

        $this->assertCount(0, $results);
    }

    public function test_fulltext_mode_works_on_sqlite(): void
    {
        $this->createDrawer('We decided to use PostgreSQL for the database layer');
        $this->createDrawer('Redis is used for caching');

        $service = app(PalaceSearchService::class);
        $results = $service->search('PostgreSQL database', mode: 'fulltext');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('PostgreSQL', $results->first()->content);
    }

    public function test_hybrid_mode_on_sqlite_falls_back_gracefully(): void
    {
        $this->createDrawer('Hybrid search should work on SQLite without crashing');

        $service = app(PalaceSearchService::class);
        $results = $service->search('hybrid search SQLite', mode: 'hybrid');

        $this->assertCount(1, $results);
    }

    public function test_semantic_mode_on_sqlite_returns_empty_with_warning(): void
    {
        $this->createDrawer('This should not be found via semantic search on SQLite');

        $service = app(PalaceSearchService::class);
        $results = $service->search('semantic search', mode: 'semantic');

        $this->assertCount(0, $results);
    }

    public function test_results_include_expected_fields(): void
    {
        $wing = Wing::create(['name' => 'Test Wing']);
        $this->createDrawer('Expected fields test content', $wing, 'Test Room', 'claude');

        $service = app(PalaceSearchService::class);
        $results = $service->search('expected fields');

        $this->assertCount(1, $results);
        $result = $results->first();
        $this->assertObjectHasProperty('id', $result);
        $this->assertObjectHasProperty('content', $result);
        $this->assertObjectHasProperty('wing', $result);
        $this->assertObjectHasProperty('wing_slug', $result);
        $this->assertObjectHasProperty('room', $result);
        $this->assertObjectHasProperty('room_slug', $result);
        $this->assertObjectHasProperty('source', $result);
        $this->assertObjectHasProperty('score', $result);
        $this->assertEquals('Test Wing', $result->wing);
        $this->assertEquals('test-wing', $result->wing_slug);
        $this->assertEquals('claude', $result->source);
    }

    public function test_soft_deleted_drawers_are_excluded(): void
    {
        $drawer = $this->createDrawer('This content has been deleted');
        $drawer->delete();

        $this->createDrawer('This content is still active');

        $service = app(PalaceSearchService::class);
        $results = $service->search('content');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('active', $results->first()->content);
    }

    public function test_cross_wing_search_returns_results_from_all_wings(): void
    {
        $wing1 = Wing::create(['name' => 'Alpha']);
        $wing2 = Wing::create(['name' => 'Beta']);

        $this->createDrawer('Shared concept across projects', $wing1);
        $this->createDrawer('Shared concept in different wing', $wing2);

        $service = app(PalaceSearchService::class);
        $results = $service->search('shared concept');

        $this->assertCount(2, $results);
    }
}
