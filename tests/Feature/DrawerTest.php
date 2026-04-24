<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DrawerTest extends TestCase
{
    use RefreshDatabase;

    public function test_drawer_belongs_to_room(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        $drawer = Drawer::create(['content' => 'Test note', 'room_id' => $room->id]);

        $this->assertTrue($drawer->room->is($room));
    }

    public function test_drawer_can_access_wing_through_room(): void
    {
        $wing = Wing::create(['name' => 'Personal']);
        $room = Room::create(['name' => 'Ideas', 'wing_id' => $wing->id]);
        $drawer = Drawer::create(['content' => 'Idea content', 'room_id' => $room->id]);

        $this->assertTrue($drawer->wing()->is($wing));
    }

    public function test_drawer_supports_soft_deletes(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Temp', 'wing_id' => $wing->id]);
        $drawer = Drawer::create(['content' => 'Temporary note', 'room_id' => $room->id]);

        $drawer->delete();

        $this->assertSoftDeleted($drawer);
        $this->assertCount(0, Drawer::all());
        $this->assertCount(1, Drawer::withTrashed()->get());
    }

    public function test_drawer_casts_metadata_to_array(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);

        $drawer = Drawer::create([
            'content' => 'Test',
            'room_id' => $room->id,
            'metadata' => ['author' => 'Alice', 'tags' => ['urgent', 'meeting']],
        ]);

        $drawer->refresh();

        $this->assertIsArray($drawer->metadata);
        $this->assertEquals('Alice', $drawer->metadata['author']);
        $this->assertContains('urgent', $drawer->metadata['tags']);
    }

    public function test_drawer_metadata_can_be_null(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);

        $drawer = Drawer::create([
            'content' => 'Test',
            'room_id' => $room->id,
        ]);

        $this->assertNull($drawer->metadata);
    }

    public function test_drawer_has_optional_source(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);

        $drawer = Drawer::create([
            'content' => 'From email',
            'room_id' => $room->id,
            'source' => 'email:123@example.com',
        ]);

        $this->assertEquals('email:123@example.com', $drawer->source);
    }
}
