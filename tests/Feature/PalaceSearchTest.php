<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use App\Services\PalaceSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * D11: membership is OR across query words, same contract as
     * WikiSearchService. Reverting to a whole-query `plainto_tsquery` makes this
     * return nothing on PostgreSQL.
     */
    public function test_fulltext_matches_drawers_containing_any_query_word(): void
    {
        $this->createDrawer('Dorothy is the lead engineer on the Atlas project.');
        $this->createDrawer('Cora is a recital optimization system for dance studios.');

        $service = app(PalaceSearchService::class);
        $results = $service->search('what do we know about dorothy', mode: 'fulltext');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Dorothy', $results->first()->content);
    }

    /**
     * D11: score is the number of distinct query words present, normalized by
     * the total number of query words — identical on both engines.
     */
    public function test_fulltext_scores_drawers_by_distinct_query_word_hits(): void
    {
        $this->createDrawer('alpha bravo charlie together');
        $this->createDrawer('alpha on its own');
        $this->createDrawer('bravo on its own');

        $service = app(PalaceSearchService::class);
        $results = $service->search('alpha bravo', mode: 'fulltext');

        $this->assertCount(3, $results);
        $this->assertStringContainsString('charlie', $results->first()->content);
        $this->assertSame([1.0, 0.5, 0.5], $results->pluck('score')->all());
    }

    /**
     * D11: hybrid mode inherits the fixed fulltext membership on PostgreSQL —
     * this is the path the Filament search page and `recall` actually use.
     */
    public function test_hybrid_matches_drawers_containing_any_query_word(): void
    {
        $this->createDrawer('Dorothy is the lead engineer on the Atlas project.');
        $this->createDrawer('Cora is a recital optimization system for dance studios.');

        $service = app(PalaceSearchService::class);
        $results = $service->search('what do we know about dorothy', mode: 'hybrid');

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Dorothy', $results->first()->content);
    }

    /**
     * D11 empty case — see WikiSearchTest for the reasoning. A query made only
     * of stop words yields an empty tsquery per word on PostgreSQL, which
     * matches nothing; SQLite's LIKE matches the words literally.
     */
    public function test_stop_words_only_query_returns_nothing_on_postgres(): void
    {
        $this->createDrawer('We do know what this drawer is about.');

        $service = app(PalaceSearchService::class);
        $results = $service->search('what do we', mode: 'fulltext');

        if (DB::connection()->getDriverName() === 'pgsql') {
            $this->assertTrue(
                $results->isEmpty(),
                'a stop-words-only query has no searchable term on PostgreSQL'
            );
        } else {
            $this->assertCount(1, $results, 'SQLite LIKE matches stop words literally');
        }
    }

    /**
     * D11 safety: the per-word form builds N SQL fragments from a user-supplied
     * string, so every word must be a binding. A payload shaped like a tsquery /
     * SQL break-out must not become a tautology that returns every row, and must
     * not raise a syntax error on either engine.
     */
    public function test_query_with_sql_and_tsquery_metacharacters_is_bound_not_interpolated(): void
    {
        $this->createDrawer('Zephyr calibration ledger.');
        $this->createDrawer('Quokka bandwidth ledger.');

        $service = app(PalaceSearchService::class);

        $this->assertCount(0, $service->search("' OR 1=1 --", mode: 'fulltext'));
        $this->assertCount(0, $service->search('!&|:*', mode: 'fulltext'));
        // A real word alongside the payload still matches only what it should.
        // (The word is whitespace-separated: Postgres' tsquery parser strips
        // punctuation from a token, SQLite's LIKE does not — an engine
        // difference in tokenization, not in matching semantics.)
        $this->assertCount(1, $service->search("zephyr ' OR 1=1 --", mode: 'fulltext'));
    }
}
