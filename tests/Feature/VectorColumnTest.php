<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VectorColumnTest extends TestCase
{
    use RefreshDatabase;

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Skip on SQLite — unless we are supposed to be on PostgreSQL, in which
     * case fail loudly.
     *
     * A skipped test exits 0. If the workflow's `DB_CONNECTION` override ever
     * stops taking effect — a `force="true"` appearing in `phpunit.xml`, a
     * change in how Collision/Artisan hands env vars to PHPUnit, a bootstrap
     * file that sets the variable itself — the pgsql matrix leg would run
     * SQLite twice and report green forever, which is exactly the false
     * confidence this CI exists to prevent. `CI_REQUIRE_PGSQL` is set to the
     * string "true" only on the pgsql leg; Laravel's `env()` maps "false" to
     * boolean false and an unset variable to null, so the sqlite leg and every
     * local run still skip normally.
     */
    private function requirePostgres(): void
    {
        if ($this->isPostgres()) {
            return;
        }

        if (env('CI_REQUIRE_PGSQL')) {
            $this->fail('the pgsql matrix leg is not on PostgreSQL — the DB_CONNECTION override is broken');
        }

        $this->markTestSkipped('vector columns only exist on PostgreSQL');
    }

    public function test_a_real_vector_round_trips_through_the_embedding_column(): void
    {
        $this->requirePostgres();

        // Avoid embedding-driver hits during drawer creation in tests. The
        // explicit UPDATE below supplies the real vector this test proves
        // round-trips, so disabling the driver here changes nothing about
        // what's being asserted.
        config(['mnemon.embedding.driver' => 'none']);

        $wing = Wing::create(['name' => 'work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        $drawer = Drawer::create(['content' => 'a note', 'room_id' => $room->id]);

        $vector = '['.implode(',', array_fill(0, 1536, 0.01)).']';
        DB::statement('UPDATE drawers SET embedding = ?::vector WHERE id = ?', [$vector, $drawer->id]);

        // Exercise the operator the hybrid search actually uses, not just storage.
        $distance = DB::selectOne(
            'SELECT embedding <=> ?::vector AS d FROM drawers WHERE id = ?',
            [$vector, $drawer->id]
        );

        $this->assertNotNull($distance);
        $this->assertEqualsWithDelta(0.0, (float) $distance->d, 0.0001);
    }

    public function test_the_shipped_default_driver_dimensions_match_the_column_width(): void
    {
        // Deliberately not the *active* driver: tests and CI run with
        // `none`, which declares no dimensions, so reading the ambient driver
        // made this a permanent skip that still looked like a passing guard.
        // This assertion needs no database either — it compares two constants
        // — so it runs on the SQLite leg too, where contributors will see it.
        //
        // This began life as the guard for D10, when `embedding` was a fixed
        // `vector(1536)` and any driver of another width could store nothing.
        // The column is now unconstrained, so a width mismatch is no longer
        // fatal and `ollama` (768) is a supported option rather than an
        // excluded one — see the two tests below.
        //
        // The assertion is kept because the number is still load-bearing: it
        // is what `mnemon:reembed` writes and what semantic queries filter on
        // for the default driver. It needs no database, so it runs on the
        // SQLite leg too, where contributors will see it.
        $this->assertSame(1536, config('mnemon.embedding.drivers.openai.dimensions'),
            'the shipped default driver must declare 1536 dimensions');
    }

    /**
     * D10: the column was `vector(1536)`, so a driver producing any other width
     * could not store anything at all. `nomic-embed-text` emits 768, which made
     * the documented `ollama` option inert — every write failed.
     *
     * pgvector allows an unconstrained `vector` column; the fixed width is only
     * required by HNSW/IVFFlat indexes, and this schema has none on `embedding`
     * (the only indexes are GIN full-text). So the width can simply go.
     */
    public function test_the_embedding_column_accepts_a_768_dimension_vector(): void
    {
        $this->requirePostgres();
        config(['mnemon.embedding.driver' => 'none']);

        $wing = Wing::create(['name' => 'work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        $drawer = Drawer::create(['content' => 'a note', 'room_id' => $room->id]);

        $vector = '['.implode(',', array_fill(0, 768, 0.01)).']';
        DB::statement('UPDATE drawers SET embedding = ?::vector WHERE id = ?', [$vector, $drawer->id]);

        $dims = DB::selectOne('SELECT vector_dims(embedding) AS d FROM drawers WHERE id = ?', [$drawer->id]);

        $this->assertSame(768, (int) $dims->d);
    }

    /**
     * Storing mixed widths is only half the problem. `<=>` throws
     * "different vector dimensions" when the operands disagree, so a single row
     * left over from another driver would take down the whole semantic query
     * rather than simply not matching. Rows at a foreign width must be filtered
     * out, not stumbled over — `mnemon:reembed` is what migrates them.
     */
    public function test_semantic_search_survives_rows_embedded_at_another_dimension(): void
    {
        $this->requirePostgres();
        config(['mnemon.embedding.driver' => 'none']);

        $wing = Wing::create(['name' => 'work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        $stale = Drawer::create(['content' => 'embedded by the previous driver', 'room_id' => $room->id]);
        $current = Drawer::create(['content' => 'embedded by the current driver', 'room_id' => $room->id]);

        DB::statement('UPDATE drawers SET embedding = ?::vector WHERE id = ?',
            ['['.implode(',', array_fill(0, 768, 0.01)).']', $stale->id]);
        DB::statement('UPDATE drawers SET embedding = ?::vector WHERE id = ?',
            ['['.implode(',', array_fill(0, 1536, 0.01)).']', $current->id]);

        $probe = '['.implode(',', array_fill(0, 1536, 0.01)).']';

        $rows = DB::select(
            'SELECT id FROM drawers WHERE embedding IS NOT NULL AND vector_dims(embedding) = ?
             ORDER BY embedding <=> ?::vector',
            [1536, $probe]
        );

        $this->assertCount(1, $rows, 'only the row at the querying driver\'s width may participate');
        $this->assertSame($current->id, (int) $rows[0]->id);
    }
}
