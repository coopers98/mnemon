<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Wings\Pages\CreateWing;
use App\Filament\Resources\Wings\Pages\EditWing;
use App\Filament\Resources\Wings\Pages\ListWings;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\User;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WingResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_list_page_renders_for_authenticated_user(): void
    {
        Livewire::test(ListWings::class)
            ->assertSuccessful();
    }

    public function test_list_page_shows_wings(): void
    {
        $wing1 = Wing::create(['name' => 'Work']);
        $wing2 = Wing::create(['name' => 'Personal']);

        Livewire::test(ListWings::class)
            ->assertCanSeeTableRecords([$wing1, $wing2]);
    }

    public function test_list_page_renders_room_and_drawer_counts(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        Drawer::create(['content' => 'first', 'room_id' => $room->id]);
        Drawer::create(['content' => 'second', 'room_id' => $room->id]);

        Livewire::test(ListWings::class)
            ->assertCanSeeTableRecords([$wing])
            ->assertTableColumnStateSet('rooms_count', 1, $wing)
            ->assertTableColumnStateSet('drawers_count', 2, $wing);
    }

    public function test_create_form_persists_a_wing_with_auto_generated_slug(): void
    {
        Livewire::test(CreateWing::class)
            ->fillForm([
                'name' => 'Research Lab',
                'description' => 'Experiments and ideas',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('wings', [
            'name' => 'Research Lab',
            'slug' => 'research-lab',
            'description' => 'Experiments and ideas',
        ]);
    }

    public function test_create_form_requires_name(): void
    {
        Livewire::test(CreateWing::class)
            ->fillForm([
                'name' => '',
                'description' => 'No name',
            ])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_edit_form_updates_a_wing(): void
    {
        $wing = Wing::create(['name' => 'Old Name', 'description' => 'old']);

        Livewire::test(EditWing::class, ['record' => $wing->getRouteKey()])
            ->fillForm([
                'name' => 'New Name',
                'description' => 'new description',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('wings', [
            'id' => $wing->id,
            'name' => 'New Name',
            'description' => 'new description',
        ]);
    }

    public function test_edit_form_does_not_overwrite_slug(): void
    {
        $wing = Wing::create(['name' => 'Original']);

        $this->assertEquals('original', $wing->slug);

        Livewire::test(EditWing::class, ['record' => $wing->getRouteKey()])
            ->fillForm([
                'name' => 'Renamed',
                'description' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('wings', [
            'id' => $wing->id,
            'name' => 'Renamed',
            'slug' => 'original',
        ]);
    }

    public function test_delete_removes_a_wing(): void
    {
        $wing = Wing::create(['name' => 'Disposable']);

        Livewire::test(EditWing::class, ['record' => $wing->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseMissing('wings', ['id' => $wing->id]);
    }

    public function test_default_sort_is_last_activity_descending(): void
    {
        $older = Wing::create(['name' => 'Older']);
        $olderRoom = Room::create(['name' => 'r1', 'wing_id' => $older->id]);
        $oldDrawer = Drawer::create(['content' => 'old', 'room_id' => $olderRoom->id]);
        $oldDrawer->forceFill(['created_at' => now()->subDays(5)])->save();

        $newer = Wing::create(['name' => 'Newer']);
        $newerRoom = Room::create(['name' => 'r2', 'wing_id' => $newer->id]);
        $newDrawer = Drawer::create(['content' => 'fresh', 'room_id' => $newerRoom->id]);
        $newDrawer->forceFill(['created_at' => now()->subDay()])->save();

        Livewire::test(ListWings::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }
}
