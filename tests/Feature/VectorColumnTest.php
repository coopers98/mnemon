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
        // The column width is fixed by migration. A driver whose vectors are a
        // different size cannot store anything — this is defect D10, and this
        // assertion is what would have caught it without reading two files.
        // `ollama` is excluded on purpose rather than asserted: it declares
        // 768 dimensions and therefore *cannot* satisfy this, which is D10
        // itself. Fixing the schema is out of scope for this piece; the
        // Ollama option is being withdrawn instead, so asserting it here
        // would only encode a known-broken pairing as a red test.
        $this->assertSame(1536, config('mnemon.embedding.drivers.openai.dimensions'),
            'the shipped default driver must declare 1536 dimensions — drawers.embedding is vector(1536)');
    }
}
