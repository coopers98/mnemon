<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DrawerEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_drawer_with_none_driver_sets_embedding_to_null(): void
    {
        config(['mnemon.embedding.driver' => 'none']);

        $wing = Wing::create(['name' => 'Test Wing', 'slug' => 'test-wing']);
        $room = Room::create(['name' => 'Test Room', 'slug' => 'test-room', 'wing_id' => $wing->id]);

        $drawer = Drawer::create([
            'content' => 'Test content',
            'room_id' => $room->id,
        ]);

        $this->assertNull($drawer->embedding);
    }

    public function test_updating_drawer_content_with_none_driver_keeps_embedding_null(): void
    {
        config(['mnemon.embedding.driver' => 'none']);

        $wing = Wing::create(['name' => 'Test Wing', 'slug' => 'test-wing']);
        $room = Room::create(['name' => 'Test Room', 'slug' => 'test-room', 'wing_id' => $wing->id]);
        $drawer = Drawer::create([
            'content' => 'Original content',
            'room_id' => $room->id,
        ]);

        $drawer->update(['content' => 'Updated content']);

        $this->assertNull($drawer->fresh()->embedding);
    }

    public function test_updating_drawer_without_content_change_does_not_reembed(): void
    {
        config(['mnemon.embedding.driver' => 'none']);

        $wing = Wing::create(['name' => 'Test Wing', 'slug' => 'test-wing']);
        $room = Room::create(['name' => 'Test Room', 'slug' => 'test-room', 'wing_id' => $wing->id]);
        $drawer = Drawer::create([
            'content' => 'Test content',
            'room_id' => $room->id,
            'source' => 'test',
        ]);

        $originalEmbedding = $drawer->embedding;

        $drawer->update(['source' => 'updated']);

        $this->assertEquals($originalEmbedding, $drawer->fresh()->embedding);
    }
}
