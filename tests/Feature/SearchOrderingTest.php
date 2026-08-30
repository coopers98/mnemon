<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use App\Services\PalaceSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * D19 — full-text search ordered by score with no secondary key.
 *
 * Ties are the normal case rather than an edge case: the score is a coarse
 * count of matched terms, so distinct drawers land on identical values
 * routinely. With no tie-break, `ORDER BY score DESC LIMIT n` lets PostgreSQL
 * return tied rows in any order it likes — its sort is not stable — so the
 * same query on unchanged data can put a different drawer first, and a LIMIT
 * can take a different subset.
 *
 * The assertion here is on the generated SQL rather than on returned order,
 * deliberately. Tied rows *may* come back in id order without the fix — that
 * is the sort getting lucky, not a guarantee — so a test that asserts on
 * output order passes with or without the tie-break and proves nothing. The
 * SQL either contains a deterministic secondary key or it does not.
 */
class SearchOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function seedTiedDrawers(int $count): array
    {
        $wing = Wing::create(['name' => 'Ordering', 'slug' => 'ordering']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = Drawer::create([
                'room_id' => $room->id,
                'content' => 'kestrel migration notes',
                'source' => "seed-{$i}",
            ])->id;
        }

        return $ids;
    }

    /** @return array<int, string> every ORDER BY clause issued against `drawers` */
    private function captureOrderByClauses(callable $work): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $work();

        $clauses = [];
        foreach (DB::getQueryLog() as $entry) {
            $sql = $entry['query'];
            if (! str_contains($sql, 'drawers')) {
                continue;
            }
            if (preg_match('/order by (.+?)(?: limit |$)/is', $sql, $m)) {
                $clauses[] = $m[1];
            }
        }
        DB::disableQueryLog();

        return $clauses;
    }

    public function test_fulltext_search_orders_by_a_deterministic_secondary_key(): void
    {
        $this->seedTiedDrawers(3);

        $clauses = $this->captureOrderByClauses(
            fn () => app(PalaceSearchService::class)->search('kestrel migration', limit: 5)
        );

        $this->assertNotEmpty($clauses, 'expected the search to issue an ordered query against drawers');

        foreach ($clauses as $clause) {
            $this->assertMatchesRegularExpression(
                // Quoted by the grammar as "drawers"."id" on PostgreSQL and
                // `drawers`.`id` on MySQL, so match either form.
                '/["`]?drawers["`]?\.["`]?id["`]?/i',
                $clause,
                'ORDER BY has no tie-break, so tied rows come back in whatever order the sort '
                ."happens to produce and a LIMIT takes an arbitrary subset. Clause was: {$clause}"
            );
        }
    }

    public function test_repeated_identical_searches_agree(): void
    {
        $this->seedTiedDrawers(8);

        $service = app(PalaceSearchService::class);
        $first = $service->search('kestrel migration', limit: 4)->pluck('id')->all();
        $second = $service->search('kestrel migration', limit: 4)->pluck('id')->all();

        $this->assertSame($first, $second, 'the same query on unchanged data must return the same rows in the same order');
    }

    public function test_tied_rows_are_ordered_by_id(): void
    {
        $ids = $this->seedTiedDrawers(5);

        $results = app(PalaceSearchService::class)
            ->search('kestrel migration', limit: 5)
            ->pluck('id')
            ->all();

        // Documents the intended resolution. On its own this can pass without
        // the fix when the sort happens to emit id order, which is why the SQL
        // assertion above is the one that actually guards the behaviour.
        $this->assertSame($ids, $results, 'tied results should resolve by id');
    }
}
