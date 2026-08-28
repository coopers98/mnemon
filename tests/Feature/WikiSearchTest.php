<?php

namespace Tests\Feature;

use App\Models\WikiPage;
use App\Services\WikiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
     * D11 round 2. A query made only of stop words has no dictionary form on
     * PostgreSQL — `plainto_tsquery('english','what')` is an EMPTY tsquery and
     * `@@` against it is false for every row — so it used to return nothing
     * there while matching on SQLite. It now falls through to the substring
     * primitive on both engines, so the two agree.
     */
    public function test_stop_words_only_query_matches_literally_on_both_engines(): void
    {
        WikiPage::create([
            'name' => 'concept:stop-words',
            'type' => 'concept',
            'title' => 'Stop words',
            'content' => 'We do know what this page is about.',
        ]);

        $service = app(WikiSearchService::class);

        $this->assertCount(
            1,
            $service->search('what do we'),
            'a stop-words-only query is matched literally, identically on both engines'
        );
    }

    /**
     * D11 round 2, the case that motivates the literal fallback: in a second
     * brain, English stop words are real search terms — a person named Will, an
     * "IT" wing, a project called "Down". On PostgreSQL every one of these
     * reduced to an empty tsquery and returned nothing at all.
     */
    public function test_single_stop_word_term_still_finds_its_page(): void
    {
        WikiPage::create([
            'name' => 'person:will-babbage',
            'type' => 'person',
            'title' => 'Will Babbage',
            'content' => 'Will Babbage runs the IT wing and owns project Down. No one else does.',
        ]);
        WikiPage::create([
            'name' => 'concept:unrelated',
            'type' => 'concept',
            'title' => 'Unrelated',
            'content' => 'Quokka bandwidth ledger.',
        ]);

        $service = app(WikiSearchService::class);

        foreach (['will', 'it', 'down', 'no'] as $term) {
            $results = $service->search($term);

            $this->assertCount(1, $results, "single stop-word term [{$term}] must still search");
            $this->assertSame('person:will-babbage', $results->first()->name);
            $this->assertSame(1.0, $results->first()->score);
        }
    }

    /**
     * D11 round 2: the word count that reaches the SQL is capped. Uncapped, a
     * pasted document is a hard failure — SQLite raises "Expression tree is too
     * large" at 997 distinct words and PostgreSQL "stack depth limit exceeded"
     * at ~4,063 — and `recall` passes the raw user prompt straight through.
     */
    public function test_very_long_query_is_capped_rather_than_throwing(): void
    {
        WikiPage::create([
            'name' => 'concept:zephyr',
            'type' => 'concept',
            'title' => 'Zephyr',
            'content' => 'Zephyr calibration ledger.',
        ]);

        $service = app(WikiSearchService::class);

        // Straddle both engine limits, and go past the 65,535 parameter ceiling.
        foreach ([996, 997, 4062, 4063, 30000] as $wordCount) {
            $filler = [];
            for ($i = 0; $i < $wordCount; $i++) {
                $filler[] = 'noisewordnumber'.$i;
            }

            $this->assertCount(
                0,
                $service->search(implode(' ', $filler)),
                "a {$wordCount}-word query must not reach the engine uncapped"
            );
        }

        // The cap keeps the longest (most specific) words, not an arbitrary
        // prefix, so a distinctive term survives a wall of short filler.
        $noise = array_map(fn ($i) => 'n'.$i, range(1, 200));
        $noise[] = 'zephyr';

        $results = $service->search(implode(' ', $noise));

        $this->assertCount(1, $results, 'the long distinctive word must survive the cap');
        $this->assertSame('concept:zephyr', $results->first()->name);
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

        // The LIKE metacharacters are the ones that actually produce a
        // tautology, and the payload above contains neither: unescaped,
        // `LIKE '%%%'` matched EVERY row at score 1.0 and `LIKE '%_%'` matched
        // every row with at least one character. `!` is the escape character
        // itself, so it has to survive escaping too.
        WikiPage::create([
            'name' => 'concept:margin',
            'type' => 'concept',
            'title' => 'Margin',
            'content' => 'Gross margin held at 50% this quarter.',
        ]);

        $percent = $service->search('%');
        $this->assertCount(1, $percent, '`%` must match the literal character, not every row');
        $this->assertSame('concept:margin', $percent->first()->name);

        $this->assertCount(0, $service->search('_'), '`_` must not match an arbitrary character');
        $this->assertCount(0, $service->search('%_%'));
        $this->assertCount(0, $service->search('!'), 'the escape character must escape itself');
        // A real word alongside the payload still matches only what it should.
        // (The word is whitespace-separated: Postgres' tsquery parser strips
        // punctuation from a token, SQLite's LIKE does not — an engine
        // difference in tokenization, not in matching semantics.)
        $this->assertCount(1, $service->search("zephyr ' OR 1=1 --"));
    }
}
