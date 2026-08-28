<?php

namespace Tests\Feature;

use App\Models\WikiPage;
use App\Services\WikiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WikiSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_matching_wiki_pages(): void
    {
        WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'title' => 'Atlas ABS',
            'content' => 'Atlas is an asset-backed securities platform built with Laravel',
        ]);
        WikiPage::create([
            'name' => 'project:cora',
            'type' => 'project',
            'title' => 'Cora',
            'content' => 'Cora is a recital optimization system for dance studios',
        ]);

        $service = app(WikiSearchService::class);
        $results = $service->search('Laravel securities');

        $this->assertCount(1, $results);
        $this->assertEquals('project:atlas', $results->first()->name);
    }

    public function test_type_filter_works(): void
    {
        WikiPage::create([
            'name' => 'person:cooper',
            'type' => 'person',
            'title' => 'Cooper',
            'content' => 'Cooper is the human. Timezone: US Central.',
        ]);
        WikiPage::create([
            'name' => 'project:mnemon',
            'type' => 'project',
            'title' => 'Mnemon',
            'content' => 'Mnemon is a self-hosted second brain built with Laravel.',
        ]);

        $service = app(WikiSearchService::class);
        $results = $service->search('Laravel', type: 'project');

        $this->assertCount(1, $results);
        $this->assertEquals('project', $results->first()->type);
    }

    public function test_limit_is_respected(): void
    {
        for ($i = 0; $i < 10; $i++) {
            WikiPage::create([
                'name' => "concept:thing-{$i}",
                'type' => 'concept',
                'title' => "Thing {$i}",
                'content' => "This concept involves testing and validation number {$i}",
            ]);
        }

        $service = app(WikiSearchService::class);
        $results = $service->search('testing validation', limit: 3);

        $this->assertCount(3, $results);
    }

    public function test_empty_query_returns_empty_collection(): void
    {
        WikiPage::create([
            'name' => 'test:page',
            'type' => 'concept',
            'title' => 'Test',
            'content' => 'Some content',
        ]);

        $service = app(WikiSearchService::class);
        $results = $service->search('');

        $this->assertCount(0, $results);
    }

    public function test_results_include_expected_fields(): void
    {
        WikiPage::create([
            'name' => 'decision:use-pgvector',
            'type' => 'decision',
            'title' => 'Use pgvector',
            'content' => 'We decided to use pgvector for vector similarity search.',
            'description' => 'Vector search backend decision',
            'last_compiled_at' => now(),
        ]);

        $service = app(WikiSearchService::class);
        $results = $service->search('pgvector');

        $this->assertCount(1, $results);
        $result = $results->first();
        $this->assertObjectHasProperty('id', $result);
        $this->assertObjectHasProperty('name', $result);
        $this->assertObjectHasProperty('type', $result);
        $this->assertObjectHasProperty('title', $result);
        $this->assertObjectHasProperty('content', $result);
        $this->assertObjectHasProperty('description', $result);
        $this->assertObjectHasProperty('last_compiled_at', $result);
        $this->assertObjectHasProperty('score', $result);
    }

    /**
     * D11: membership is OR across query words. A conversational prompt — the
     * shape `recall` feeds this service — must match a page that contains any
     * one of its words. Reverting to a whole-query `plainto_tsquery` (which ANDs
     * every lexeme) makes this return nothing on PostgreSQL.
     */
    public function test_search_matches_pages_containing_any_query_word(): void
    {
        WikiPage::create([
            'name' => 'person:dorothy-vaughan',
            'type' => 'person',
            'title' => 'Dorothy Vaughan',
            'content' => 'Dorothy is the lead engineer on the Atlas project.',
        ]);
        WikiPage::create([
            'name' => 'project:cora',
            'type' => 'project',
            'title' => 'Cora',
            'content' => 'Cora is a recital optimization system for dance studios.',
        ]);

        $service = app(WikiSearchService::class);
        $results = $service->search('what do we know about dorothy');

        $this->assertCount(1, $results);
        $this->assertSame('person:dorothy-vaughan', $results->first()->name);
    }

    /**
     * D11: score is the number of distinct query words present, normalized by
     * the total number of query words — identical on both engines.
     */
    public function test_search_scores_pages_by_distinct_query_word_hits(): void
    {
        WikiPage::create([
            'name' => 'concept:both',
            'type' => 'concept',
            'title' => 'Both',
            'content' => 'Alpha and bravo appear together in this page.',
        ]);
        WikiPage::create([
            'name' => 'concept:alpha-only',
            'type' => 'concept',
            'title' => 'Alpha only',
            'content' => 'Alpha appears on its own in this page.',
        ]);
        WikiPage::create([
            'name' => 'concept:bravo-only',
            'type' => 'concept',
            'title' => 'Bravo only',
            'content' => 'Bravo appears on its own in this page.',
        ]);

        $service = app(WikiSearchService::class);
        $results = $service->search('alpha bravo');

        $this->assertCount(3, $results);
        $this->assertSame('concept:both', $results->first()->name);
        $this->assertSame([1.0, 0.5, 0.5], $results->pluck('score')->all());
    }

    /**
     * D11 empty case. PostgreSQL's `plainto_tsquery` yields an empty tsquery for
     * a stop word, and `@@` against an empty tsquery is false for every row, so a
     * query made only of stop words carries no searchable term and returns
     * nothing. SQLite's LIKE primitive has no notion of stop words and matches
     * them literally. This divergence is deliberate and documented in the
     * service; it is the same class of difference as PostgreSQL's stemming.
     */
    public function test_stop_words_only_query_returns_nothing_on_postgres(): void
    {
        WikiPage::create([
            'name' => 'concept:stop-words',
            'type' => 'concept',
            'title' => 'Stop words',
            'content' => 'We do know what this page is about.',
        ]);

        $service = app(WikiSearchService::class);
        $results = $service->search('what do we');

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
        WikiPage::create([
            'name' => 'concept:zephyr',
            'type' => 'concept',
            'title' => 'Zephyr',
            'content' => 'Zephyr calibration ledger.',
        ]);
        WikiPage::create([
            'name' => 'concept:quokka',
            'type' => 'concept',
            'title' => 'Quokka',
            'content' => 'Quokka bandwidth ledger.',
        ]);

        $service = app(WikiSearchService::class);

        $this->assertCount(0, $service->search("' OR 1=1 --"));
        $this->assertCount(0, $service->search('!&|:*'));
        // A real word alongside the payload still matches only what it should.
        // (The word is whitespace-separated: Postgres' tsquery parser strips
        // punctuation from a token, SQLite's LIKE does not — an engine
        // difference in tokenization, not in matching semantics.)
        $this->assertCount(1, $service->search("zephyr ' OR 1=1 --"));
    }
}
