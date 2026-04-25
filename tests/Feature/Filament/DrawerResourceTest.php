<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Drawers\Pages\CreateDrawer;
use App\Filament\Resources\Drawers\Pages\EditDrawer;
use App\Filament\Resources\Drawers\Pages\ListDrawers;
use App\Filament\Resources\Drawers\Pages\ViewDrawer;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\User;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DrawerResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mnemon.embedding.driver' => 'none']);

        $this->actingAs(User::factory()->create());
    }

    public function test_list_page_renders_for_authenticated_user(): void
    {
        Livewire::test(ListDrawers::class)
            ->assertSuccessful();
    }

    public function test_list_page_shows_drawers(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        $drawerOne = Drawer::create(['content' => 'first content', 'room_id' => $room->id]);
        $drawerTwo = Drawer::create(['content' => 'second content', 'room_id' => $room->id]);

        Livewire::test(ListDrawers::class)
            ->assertCanSeeTableRecords([$drawerOne, $drawerTwo]);
    }

    public function test_content_preview_column_truncates_long_content(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        $longContent = str_repeat('abcdefghij ', 30); // ~330 characters
        $drawer = Drawer::create(['content' => $longContent, 'room_id' => $room->id]);

        Livewire::test(ListDrawers::class)
            ->assertCanSeeTableRecords([$drawer])
            // The full text is far longer than 80 chars; it should not appear verbatim.
            ->assertDontSee($longContent);
    }

    public function test_wing_name_column_displays_wing_through_room(): void
    {
        $wing = Wing::create(['name' => 'Important Work Wing']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        $drawer = Drawer::create(['content' => 'hello', 'room_id' => $room->id]);

        Livewire::test(ListDrawers::class)
            ->assertCanSeeTableRecords([$drawer])
            ->assertSee('Important Work Wing');
    }

    public function test_source_filter_narrows_results(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        $emailDrawer = Drawer::create(['content' => 'from email', 'room_id' => $room->id, 'source' => 'email']);
        $slackDrawer = Drawer::create(['content' => 'from slack', 'room_id' => $room->id, 'source' => 'slack']);

        Livewire::test(ListDrawers::class)
            ->filterTable('source', 'email')
            ->assertCanSeeTableRecords([$emailDrawer])
            ->assertCanNotSeeTableRecords([$slackDrawer]);
    }

    public function test_wing_filter_narrows_results(): void
    {
        $work = Wing::create(['name' => 'Work']);
        $personal = Wing::create(['name' => 'Personal']);
        $workRoom = Room::create(['name' => 'Notes', 'wing_id' => $work->id]);
        $personalRoom = Room::create(['name' => 'Journal', 'wing_id' => $personal->id]);
        $workDrawer = Drawer::create(['content' => 'work item', 'room_id' => $workRoom->id]);
        $personalDrawer = Drawer::create(['content' => 'personal item', 'room_id' => $personalRoom->id]);

        Livewire::test(ListDrawers::class)
            ->filterTable('wing_id', $work->id)
            ->assertCanSeeTableRecords([$workDrawer])
            ->assertCanNotSeeTableRecords([$personalDrawer]);
    }

    public function test_date_range_filter_narrows_results(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);

        $oldDrawer = Drawer::create(['content' => 'old', 'room_id' => $room->id]);
        $oldDrawer->forceFill(['created_at' => now()->subDays(30)])->save();

        $recentDrawer = Drawer::create(['content' => 'recent', 'room_id' => $room->id]);
        $recentDrawer->forceFill(['created_at' => now()->subDays(2)])->save();

        Livewire::test(ListDrawers::class)
            ->filterTable('created_at', [
                'from' => now()->subDays(7)->toDateString(),
                'until' => now()->toDateString(),
            ])
            ->assertCanSeeTableRecords([$recentDrawer])
            ->assertCanNotSeeTableRecords([$oldDrawer]);
    }

    public function test_trashed_filter_can_show_soft_deleted_drawers(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        $alive = Drawer::create(['content' => 'alive', 'room_id' => $room->id]);
        $dead = Drawer::create(['content' => 'dead', 'room_id' => $room->id]);
        $dead->delete();

        // Default: trashed records are hidden.
        Livewire::test(ListDrawers::class)
            ->assertCanSeeTableRecords([$alive])
            ->assertCanNotSeeTableRecords([$dead]);

        // With trashed filter set to "with trashed", the deleted record appears.
        Livewire::test(ListDrawers::class)
            ->filterTable('trashed', true)
            ->assertCanSeeTableRecords([$alive, $dead]);
    }

    public function test_create_form_persists_a_drawer(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);

        Livewire::test(CreateDrawer::class)
            ->fillForm([
                'content' => 'My fresh drawer',
                'room_id' => $room->id,
                'source' => 'manual',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('drawers', [
            'content' => 'My fresh drawer',
            'room_id' => $room->id,
            'source' => 'manual',
        ]);
    }

    public function test_create_form_requires_content(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);

        Livewire::test(CreateDrawer::class)
            ->fillForm([
                'content' => '',
                'room_id' => $room->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['content']);
    }

    public function test_edit_form_updates_content(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        $drawer = Drawer::create(['content' => 'original', 'room_id' => $room->id]);

        Livewire::test(EditDrawer::class, ['record' => $drawer->getRouteKey()])
            ->fillForm([
                'content' => 'updated content',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('drawers', [
            'id' => $drawer->id,
            'content' => 'updated content',
        ]);
    }

    public function test_soft_delete_hides_record_from_default_list(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        $drawer = Drawer::create(['content' => 'expendable', 'room_id' => $room->id]);

        Livewire::test(EditDrawer::class, ['record' => $drawer->getRouteKey()])
            ->callAction('delete');

        // Soft-deleted: row exists but with deleted_at populated, and it disappears from the default list.
        $this->assertSoftDeleted('drawers', ['id' => $drawer->id]);

        Livewire::test(ListDrawers::class)
            ->assertCanNotSeeTableRecords([$drawer]);
    }

    public function test_force_delete_removes_record_entirely(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        $drawer = Drawer::create(['content' => 'gone for good', 'room_id' => $room->id]);
        $drawer->delete();

        Livewire::test(EditDrawer::class, ['record' => $drawer->getRouteKey()])
            ->callAction('forceDelete');

        $this->assertDatabaseMissing('drawers', ['id' => $drawer->id]);
    }

    public function test_restore_brings_soft_deleted_drawer_back(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        $drawer = Drawer::create(['content' => 'rescue me', 'room_id' => $room->id]);
        $drawer->delete();

        $this->assertSoftDeleted('drawers', ['id' => $drawer->id]);

        Livewire::test(EditDrawer::class, ['record' => $drawer->getRouteKey()])
            ->callAction('restore');

        $this->assertDatabaseHas('drawers', [
            'id' => $drawer->id,
            'deleted_at' => null,
        ]);
    }

    public function test_view_page_renders_drawer_details(): void
    {
        $wing = Wing::create(['name' => 'Work Wing']);
        $room = Room::create(['name' => 'Notes Room', 'wing_id' => $wing->id]);
        $drawer = Drawer::create([
            'content' => 'A fully visible body of drawer content that should not be truncated on the view page.',
            'room_id' => $room->id,
            'source' => 'manual-entry',
            'metadata' => ['author' => 'Cooper', 'tag' => 'inbox'],
        ]);

        Livewire::test(ViewDrawer::class, ['record' => $drawer->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('A fully visible body of drawer content that should not be truncated on the view page.')
            ->assertSee('Work Wing')
            ->assertSee('Notes Room')
            ->assertSee('manual-entry');
    }

    public function test_default_sort_is_created_at_descending(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);

        // Insert records OUT of chronological order so insertion order alone
        // wouldn't satisfy the assertion — only an actual created_at sort will.
        $a = Drawer::create(['content' => 'a', 'room_id' => $room->id]);
        $a->forceFill(['created_at' => now()->subDays(2)])->save();

        $b = Drawer::create(['content' => 'b', 'room_id' => $room->id]);
        $b->forceFill(['created_at' => now()->subDay()])->save();

        $c = Drawer::create(['content' => 'c', 'room_id' => $room->id]);
        $c->forceFill(['created_at' => now()->subDays(5)])->save();

        Livewire::test(ListDrawers::class)
            ->assertCanSeeTableRecords([$b, $a, $c], inOrder: true);
    }
}
