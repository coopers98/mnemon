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

    public function test_a_real_vector_round_trips_through_the_embedding_column(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('vector columns only exist on PostgreSQL');
        }

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

    public function test_the_active_driver_dimensions_match_the_column_width(): void
    {
        if (! $this->isPostgres()) {
            $this->markTestSkipped('vector columns only exist on PostgreSQL');
        }

        $driver = config('mnemon.embedding.driver');
        $declared = config("mnemon.embedding.drivers.{$driver}.dimensions");

        if ($declared === null) {
            $this->markTestSkipped("driver '{$driver}' declares no dimensions");
        }

        // The column width is fixed by migration. A driver whose vectors are a
        // different size cannot store anything — this is defect D10, and this
        // assertion is what would have caught it without reading two files.
        $this->assertSame(1536, $declared,
            "driver '{$driver}' declares {$declared} dimensions but drawers.embedding is vector(1536)");
    }
}
