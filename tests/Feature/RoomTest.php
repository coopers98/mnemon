<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoomTest extends TestCase
{
    use RefreshDatabase;

    public function test_room_auto_generates_slug_on_create(): void
    {
        $wing = Wing::create(['name' => 'Work']);

        $room = Room::create([
            'name' => 'Meeting Notes',
            'wing_id' => $wing->id,
        ]);

        $this->assertEquals('meeting-notes', $room->slug);
    }

    public function test_room_slug_can_be_manually_set(): void
    {
        $wing = Wing::create(['name' => 'Work']);

        $room = Room::create([
            'name' => 'Meeting Notes',
            'slug' => 'custom-slug',
            'wing_id' => $wing->id,
        ]);

        $this->assertEquals('custom-slug', $room->slug);
    }

    public function test_room_belongs_to_wing(): void
    {
        $wing = Wing::create(['name' => 'Personal']);
        $room = Room::create(['name' => 'Ideas', 'wing_id' => $wing->id]);

        $this->assertTrue($room->wing->is($wing));
    }

    public function test_room_has_many_drawers(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);

        $drawer1 = Drawer::create(['content' => 'First note', 'room_id' => $room->id]);
        $drawer2 = Drawer::create(['content' => 'Second note', 'room_id' => $room->id]);

        $this->assertCount(2, $room->drawers);
        $this->assertTrue($room->drawers->contains($drawer1));
    }

    public function test_room_slug_is_unique_per_wing(): void
    {
        $wing = Wing::create(['name' => 'Work']);

        Room::create(['name' => 'Ideas', 'slug' => 'ideas', 'wing_id' => $wing->id]);

        $this->expectException(QueryException::class);

        Room::create(['name' => 'More Ideas', 'slug' => 'ideas', 'wing_id' => $wing->id]);
    }

    public function test_room_slug_can_duplicate_across_different_wings(): void
    {
        $work = Wing::create(['name' => 'Work']);
        $personal = Wing::create(['name' => 'Personal']);

        $room1 = Room::create(['name' => 'Ideas', 'slug' => 'ideas', 'wing_id' => $work->id]);
        $room2 = Room::create(['name' => 'Ideas', 'slug' => 'ideas', 'wing_id' => $personal->id]);

        $this->assertEquals('ideas', $room1->slug);
        $this->assertEquals('ideas', $room2->slug);
        $this->assertNotEquals($room1->id, $room2->id);
    }
}
