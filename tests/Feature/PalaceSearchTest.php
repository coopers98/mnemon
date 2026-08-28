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
     * D11 round 2 — see WikiSearchTest for the reasoning. A query made only of
     * stop words has no dictionary form on PostgreSQL, so it falls through to
     * the substring primitive and the two engines agree.
     */
    public function test_stop_words_only_query_matches_literally_on_both_engines(): void
    {
        $this->createDrawer('We do know what this drawer is about.');

        $service = app(PalaceSearchService::class);

        $this->assertCount(
            1,
            $service->search('what do we', mode: 'fulltext'),
            'a stop-words-only query is matched literally, identically on both engines'
        );
    }

    /**
     * D11 round 2: an English stop word is a real search term in a second brain
     * — a person named Will, an "IT" wing, a project called "Down". On
     * PostgreSQL each of these reduced to an empty tsquery and matched nothing.
     */
    public function test_single_stop_word_term_still_finds_its_drawer(): void
    {
        $this->createDrawer('Will Babbage runs the IT wing and owns project Down. No one else does.');
        $this->createDrawer('Quokka bandwidth ledger.');

        $service = app(PalaceSearchService::class);

        foreach (['will', 'it', 'down', 'no'] as $term) {
            $results = $service->search($term, mode: 'fulltext');

            $this->assertCount(1, $results, "single stop-word term [{$term}] must still search");
            $this->assertStringContainsString('Babbage', $results->first()->content);
        }
    }

    /**
     * D11 round 2: the word count that reaches the SQL is capped. Uncapped,
     * SQLite raises "Expression tree is too large" at 997 distinct words and
     * PostgreSQL "stack depth limit exceeded" at ~4,063 — and `recall` passes
     * the raw user prompt straight into this path through hybridSearch().
     */
    public function test_very_long_query_is_capped_rather_than_throwing(): void
    {
        $this->createDrawer('Zephyr calibration ledger.');

        $service = app(PalaceSearchService::class);

        foreach ([996, 997, 4062, 4063, 30000] as $wordCount) {
            $filler = [];
            for ($i = 0; $i < $wordCount; $i++) {
                $filler[] = 'noisewordnumber'.$i;
            }
            $query = implode(' ', $filler);

            $this->assertCount(
                0,
                $service->search($query, mode: 'fulltext'),
                "a {$wordCount}-word query must not reach the engine uncapped"
            );
            $this->assertCount(
                0,
                $service->search($query, mode: 'hybrid'),
                "a {$wordCount}-word hybrid query must not reach the engine uncapped"
            );
        }

        // The cap keeps the longest (most specific) words, not an arbitrary
        // prefix, so a distinctive term survives a wall of short filler.
        $noise = array_map(fn ($i) => 'n'.$i, range(1, 200));
        $noise[] = 'zephyr';

        $results = $service->search(implode(' ', $noise), mode: 'fulltext');

        $this->assertCount(1, $results, 'the long distinctive word must survive the cap');
        $this->assertStringContainsString('Zephyr', $results->first()->content);
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

        // The LIKE metacharacters are the ones that actually produce a
        // tautology, and the payload above contains neither: unescaped,
        // `LIKE '%%%'` matched EVERY row at score 1.0 and `LIKE '%_%'` matched
        // every row with at least one character. `!` is the escape character
        // itself, so it has to survive escaping too.
        $this->createDrawer('Gross margin held at 50% this quarter.');

        $percent = $service->search('%', mode: 'fulltext');
        $this->assertCount(1, $percent, '`%` must match the literal character, not every row');
        $this->assertStringContainsString('50%', $percent->first()->content);

        $this->assertCount(0, $service->search('_', mode: 'fulltext'), '`_` must not match an arbitrary character');
        $this->assertCount(0, $service->search('%_%', mode: 'fulltext'));
        $this->assertCount(0, $service->search('!', mode: 'fulltext'), 'the escape character must escape itself');
        // A real word alongside the payload still matches only what it should.
        // (The word is whitespace-separated: Postgres' tsquery parser strips
        // punctuation from a token, SQLite's LIKE does not — an engine
        // difference in tokenization, not in matching semantics.)
        $this->assertCount(1, $service->search("zephyr ' OR 1=1 --", mode: 'fulltext'));
    }
}
