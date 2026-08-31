<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use App\Services\PalaceSearchService;
use App\Services\WikiSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D12 — full-text scoring re-tokenised every candidate row.
 *
 * The recorded diagnosis was "sequential scan". That was wrong: the GIN index
 * on the expression was being used and was fast (two bitmap index scans, ~4ms
 * total). The cost was in the ORDER BY, which recomputed
 * `to_tsvector('english', content)` once per query term for every candidate —
 * on documents up to 30,000 characters.
 *
 * Measured on a 23,855-drawer corpus: 31,541ms with the CASE scoring in the
 * ORDER BY, 2,936ms for the identical WHERE ordered by id instead. PHP's
 * 30-second limit made every search fail.
 *
 * The fix is a stored generated column, so the tokenisation happens once at
 * write time instead of per row per query. These assertions are on the emitted
 * SQL: a timing assertion would be flaky on shared CI, and the property that
 * matters is structural — the query either references the stored column or
 * recomputes.
 */
class StoredTsvectorTest extends TestCase
{
    use RefreshDatabase;

    private function requirePostgres(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return;
        }

        if (env('CI_REQUIRE_PGSQL')) {
            $this->fail('the pgsql matrix leg is not on PostgreSQL — the DB_CONNECTION override is broken');
        }

        $this->markTestSkipped('tsvector columns only exist on PostgreSQL');
    }

    /** @return array<int, string> every statement issued against the given table */
    private function captureSql(string $table, callable $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $out = [];
        foreach (DB::getQueryLog() as $entry) {
            if (str_contains($entry['query'], $table)) {
                $out[] = $entry['query'];
            }
        }
        DB::disableQueryLog();

        return $out;
    }

    private function seedDrawer(string $content): void
    {
        $wing = Wing::firstOrCreate(['slug' => 'tsv'], ['name' => 'Tsv']);
        $room = Room::firstOrCreate(
            ['wing_id' => $wing->id, 'slug' => 'notes'],
            ['name' => 'Notes'],
        );
        Drawer::create(['room_id' => $room->id, 'content' => $content, 'source' => 'tsv-test']);
    }

    public function test_drawers_have_a_stored_tsvector_column(): void
    {
        $this->requirePostgres();

        $row = DB::selectOne(
            "SELECT is_generated, generation_expression FROM information_schema.columns
             WHERE table_name = 'drawers' AND column_name = 'content_tsv'"
        );

        $this->assertNotNull($row, 'drawers.content_tsv does not exist');
        $this->assertSame('ALWAYS', $row->is_generated, 'content_tsv must be a generated column');
        $this->assertStringContainsString('to_tsvector', $row->generation_expression);
    }

    public function test_wiki_pages_have_a_stored_tsvector_column(): void
    {
        $this->requirePostgres();

        $row = DB::selectOne(
            "SELECT is_generated FROM information_schema.columns
             WHERE table_name = 'wiki_pages' AND column_name = 'content_tsv'"
        );

        $this->assertNotNull($row, 'wiki_pages.content_tsv does not exist');
        $this->assertSame('ALWAYS', $row->is_generated);
    }

    public function test_the_generated_column_is_populated_without_being_written(): void
    {
        $this->requirePostgres();
        $this->seedDrawer('kestrel migration notes about graduate degrees');

        $row = DB::selectOne('SELECT content_tsv::text AS tsv FROM drawers LIMIT 1');

        $this->assertNotEmpty($row->tsv, 'the stored column should populate from content on insert');
        $this->assertStringContainsString('kestrel', $row->tsv);
    }

    public function test_palace_search_uses_the_stored_column_and_never_recomputes(): void
    {
        $this->requirePostgres();
        $this->seedDrawer('kestrel migration notes');

        $sql = $this->captureSql(
            'drawers',
            fn () => app(PalaceSearchService::class)->search('kestrel migration', limit: 5)
        );

        $this->assertNotEmpty($sql, 'expected a query against drawers');

        foreach ($sql as $q) {
            if (! str_contains($q, 'plainto_tsquery')) {
                continue;
            }
            $this->assertStringContainsString('content_tsv', $q,
                "full-text search must match against the stored column. Query: {$q}");
            $this->assertStringNotContainsString("to_tsvector('english', drawers.content)", $q,
                'recomputing to_tsvector per row is the D12 defect — it cost 31s on a 24k-drawer '
                ."corpus and made every search exceed PHP's 30s limit. Query: {$q}");
        }
    }

    public function test_wiki_search_uses_the_stored_column_and_never_recomputes(): void
    {
        $this->requirePostgres();
        WikiPage::create(['name' => 'kestrels', 'title' => 'Kestrels', 'type' => 'concept', 'content' => 'kestrel migration notes']);

        $sql = $this->captureSql(
            'wiki_pages',
            fn () => app(WikiSearchService::class)->search('kestrel migration', limit: 5)
        );

        foreach ($sql as $q) {
            if (! str_contains($q, 'plainto_tsquery')) {
                continue;
            }
            $this->assertStringContainsString('content_tsv', $q, "Query: {$q}");
            $this->assertStringNotContainsString("to_tsvector('english', wiki_pages.content)", $q, "Query: {$q}");
        }
    }

    public function test_search_still_finds_what_it_found_before(): void
    {
        $this->requirePostgres();
        $this->seedDrawer('the kestrel migration notes');
        $this->seedDrawer('entirely unrelated chatter');

        $results = app(PalaceSearchService::class)->search('kestrel migration', limit: 5);

        $this->assertCount(1, $results, 'the stored column must not change which rows match');
        $this->assertStringContainsString('kestrel', $results->first()->content);
    }
}
