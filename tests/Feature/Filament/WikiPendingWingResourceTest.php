<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\WikiPendingWings\Pages\ListWikiPendingWings;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\User;
use App\Models\WikiPendingWing;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WikiPendingWingResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_action_creates_wing_room_and_drawer(): void
    {
        $this->actingAs(User::factory()->create());
        $row = WikiPendingWing::factory()->create([
            'wing_slug' => 'project:atlas',
            'wing_name' => 'Atlas',
            'drawer_payload' => [
                'content' => 'Atlas planning',
                'room_slug' => 'planning',
                'source' => 'claude-code:session_digest',
                'metadata' => ['captured_via' => 'session_digest'],
            ],
            'status' => 'pending',
        ]);

        Livewire::test(ListWikiPendingWings::class)
            ->callTableAction('approve', $row);

        $this->assertEquals('approved', $row->fresh()->status);
        $this->assertNotNull(Wing::where('slug', 'project:atlas')->first());
        $this->assertNotNull(Room::where('slug', 'planning')->first());
        $this->assertEquals(1, Drawer::count());
    }

    public function test_reject_action_drops_payload(): void
    {
        $this->actingAs(User::factory()->create());
        $row = WikiPendingWing::factory()->create(['status' => 'pending']);

        Livewire::test(ListWikiPendingWings::class)
            ->callTableAction('reject', $row);

        $this->assertEquals('rejected', $row->fresh()->status);
        $this->assertEquals(0, Wing::count());
        $this->assertEquals(0, Drawer::count());
    }

    public function test_double_approve_is_idempotent(): void
    {
        // Two separate pending proposals for the same wing_slug (the real dedup scenario).
        // firstOrCreate means the wing is only created once; two Drawers are expected
        // because each proposal carries distinct content.
        $this->actingAs(User::factory()->create());

        $rowA = WikiPendingWing::factory()->create([
            'wing_slug' => 'foo',
            'wing_name' => 'Foo',
            'drawer_payload' => [
                'content' => 'first note', 'room_slug' => 'notes',
                'source' => 'claude-code:session_digest', 'metadata' => [],
            ],
            'status' => 'pending',
        ]);

        $rowB = WikiPendingWing::factory()->create([
            'wing_slug' => 'foo',
            'wing_name' => 'Foo',
            'drawer_payload' => [
                'content' => 'second note', 'room_slug' => 'notes',
                'source' => 'claude-code:session_digest', 'metadata' => [],
            ],
            'status' => 'pending',
        ]);

        Livewire::test(ListWikiPendingWings::class)->callTableAction('approve', $rowA);
        Livewire::test(ListWikiPendingWings::class)->callTableAction('approve', $rowB);

        // Only one Wing and one Room created (firstOrCreate), but two Drawers.
        $this->assertEquals(1, Wing::where('slug', 'foo')->count());
        $this->assertEquals(1, Room::where('slug', 'notes')->count());
        $this->assertEquals(2, Drawer::count());
    }
}
