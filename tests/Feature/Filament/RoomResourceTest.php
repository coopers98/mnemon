<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\User;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoomResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_list_page_renders_for_authenticated_user(): void
    {
        Livewire::test(ListRooms::class)
            ->assertSuccessful();
    }

    public function test_list_page_shows_rooms(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room1 = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        $room2 = Room::create(['name' => 'Ideas', 'wing_id' => $wing->id]);

        Livewire::test(ListRooms::class)
            ->assertCanSeeTableRecords([$room1, $room2]);
    }

    public function test_list_page_renders_drawer_counts(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        Drawer::create(['content' => 'first', 'room_id' => $room->id]);
        Drawer::create(['content' => 'second', 'room_id' => $room->id]);
        Drawer::create(['content' => 'third', 'room_id' => $room->id]);

        Livewire::test(ListRooms::class)
            ->assertCanSeeTableRecords([$room])
            ->assertTableColumnStateSet('drawers_count', 3, $room);
    }

    public function test_wing_filter_narrows_results(): void
    {
        $work = Wing::create(['name' => 'Work']);
        $personal = Wing::create(['name' => 'Personal']);
        $workRoom = Room::create(['name' => 'Meetings', 'wing_id' => $work->id]);
        $personalRoom = Room::create(['name' => 'Journal', 'wing_id' => $personal->id]);

        Livewire::test(ListRooms::class)
            ->filterTable('wing_id', $work->id)
            ->assertCanSeeTableRecords([$workRoom])
            ->assertCanNotSeeTableRecords([$personalRoom]);
    }

    public function test_create_form_persists_a_room_with_auto_generated_slug(): void
    {
        $wing = Wing::create(['name' => 'Work']);

        Livewire::test(CreateRoom::class)
            ->fillForm([
                'name' => 'Meeting Notes',
                'wing_id' => $wing->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('rooms', [
            'name' => 'Meeting Notes',
            'slug' => 'meeting-notes',
            'wing_id' => $wing->id,
        ]);
    }

    public function test_create_form_requires_name(): void
    {
        $wing = Wing::create(['name' => 'Work']);

        Livewire::test(CreateRoom::class)
            ->fillForm([
                'name' => '',
                'wing_id' => $wing->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_edit_form_updates_name(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Old Name', 'wing_id' => $wing->id]);

        Livewire::test(EditRoom::class, ['record' => $room->getRouteKey()])
            ->fillForm([
                'name' => 'New Name',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('rooms', [
            'id' => $room->id,
            'name' => 'New Name',
        ]);
    }

    public function test_edit_form_does_not_overwrite_slug(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Original', 'wing_id' => $wing->id]);

        $this->assertEquals('original', $room->slug);

        Livewire::test(EditRoom::class, ['record' => $room->getRouteKey()])
            ->fillForm([
                'name' => 'Renamed',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('rooms', [
            'id' => $room->id,
            'name' => 'Renamed',
            'slug' => 'original',
        ]);
    }

    public function test_delete_removes_a_room(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Disposable', 'wing_id' => $wing->id]);

        Livewire::test(EditRoom::class, ['record' => $room->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    }

    public function test_default_sort_is_last_activity_descending(): void
    {
        $wing = Wing::create(['name' => 'Work']);

        $older = Room::create(['name' => 'Older', 'wing_id' => $wing->id]);
        $oldDrawer = Drawer::create(['content' => 'old', 'room_id' => $older->id]);
        $oldDrawer->forceFill(['created_at' => now()->subDays(5)])->save();

        $newer = Room::create(['name' => 'Newer', 'wing_id' => $wing->id]);
        $newDrawer = Drawer::create(['content' => 'fresh', 'room_id' => $newer->id]);
        $newDrawer->forceFill(['created_at' => now()->subDay()])->save();

        $empty = Room::create(['name' => 'Empty', 'wing_id' => $wing->id]);

        Livewire::test(ListRooms::class)
            ->assertCanSeeTableRecords([$newer, $older, $empty], inOrder: true);
    }
}
