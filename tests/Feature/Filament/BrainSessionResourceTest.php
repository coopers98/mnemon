<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\BrainSessions\BrainSessionResource;
use App\Filament\Resources\BrainSessions\Pages\ListBrainSessions;
use App\Filament\Resources\BrainSessions\Pages\ViewBrainSession;
use App\Models\BrainSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BrainSessionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_list_page_renders_for_authenticated_user(): void
    {
        Livewire::test(ListBrainSessions::class)
            ->assertSuccessful();
    }

    public function test_list_page_shows_brain_sessions(): void
    {
        $first = BrainSession::create([
            'tool_name' => 'drawer_search',
            'source' => 'admin',
            'input' => ['query' => 'foo'],
            'result_count' => 3,
        ]);
        $second = BrainSession::create([
            'tool_name' => 'store_drawer',
            'source' => 'mcp',
            'input' => ['content' => 'hello'],
            'result_count' => 1,
        ]);

        Livewire::test(ListBrainSessions::class)
            ->assertCanSeeTableRecords([$first, $second]);
    }

    public function test_tool_name_filter_narrows_results(): void
    {
        $search = BrainSession::create([
            'tool_name' => 'drawer_search',
            'source' => 'mcp',
            'input' => ['query' => 'alpha'],
            'result_count' => 2,
        ]);
        $store = BrainSession::create([
            'tool_name' => 'store_drawer',
            'source' => 'mcp',
            'input' => ['content' => 'beta'],
            'result_count' => 1,
        ]);

        Livewire::test(ListBrainSessions::class)
            ->filterTable('tool_name', 'drawer_search')
            ->assertCanSeeTableRecords([$search])
            ->assertCanNotSeeTableRecords([$store]);
    }

    public function test_source_filter_narrows_results(): void
    {
        $admin = BrainSession::create([
            'tool_name' => 'drawer_search',
            'source' => 'admin',
            'input' => ['query' => 'one'],
            'result_count' => 1,
        ]);
        $mcp = BrainSession::create([
            'tool_name' => 'drawer_search',
            'source' => 'mcp',
            'input' => ['query' => 'two'],
            'result_count' => 4,
        ]);

        Livewire::test(ListBrainSessions::class)
            ->filterTable('source', 'admin')
            ->assertCanSeeTableRecords([$admin])
            ->assertCanNotSeeTableRecords([$mcp]);
    }

    public function test_date_range_filter_narrows_results(): void
    {
        $old = BrainSession::create([
            'tool_name' => 'drawer_search',
            'source' => 'mcp',
            'input' => ['query' => 'old'],
            'result_count' => 0,
        ]);
        $old->forceFill(['created_at' => now()->subDays(30)])->save();

        $recent = BrainSession::create([
            'tool_name' => 'drawer_search',
            'source' => 'mcp',
            'input' => ['query' => 'recent'],
            'result_count' => 0,
        ]);
        $recent->forceFill(['created_at' => now()->subDays(2)])->save();

        Livewire::test(ListBrainSessions::class)
            ->filterTable('created_at', [
                'from' => now()->subDays(7)->toDateString(),
                'until' => now()->toDateString(),
            ])
            ->assertCanSeeTableRecords([$recent])
            ->assertCanNotSeeTableRecords([$old]);
    }

    public function test_view_page_renders_input_json(): void
    {
        $session = BrainSession::create([
            'tool_name' => 'drawer_search',
            'source' => 'admin',
            'input' => ['query' => 'unique-search-token-xyz', 'limit' => 10],
            'result_count' => 7,
        ]);

        Livewire::test(ViewBrainSession::class, ['record' => $session->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('drawer_search')
            ->assertSee('unique-search-token-xyz')
            ->assertSee('admin');
    }

    public function test_resource_disallows_create_edit_and_delete(): void
    {
        $session = BrainSession::create([
            'tool_name' => 'drawer_search',
            'source' => 'mcp',
            'input' => ['query' => 'nope'],
        ]);

        $this->assertFalse(BrainSessionResource::canCreate());
        $this->assertFalse(BrainSessionResource::canEdit($session));
        $this->assertFalse(BrainSessionResource::canDelete($session));
        $this->assertFalse(BrainSessionResource::canDeleteAny());
    }

    public function test_list_page_does_not_show_create_action(): void
    {
        Livewire::test(ListBrainSessions::class)
            ->assertActionDoesNotExist('create');
    }

    public function test_resource_only_registers_index_and_view_pages(): void
    {
        $pages = BrainSessionResource::getPages();

        $this->assertSame(['index', 'view'], array_keys($pages));
    }

    public function test_table_shows_dash_for_null_result_count(): void
    {
        BrainSession::create([
            'tool_name' => 'palace_wake_up',
            'source' => 'mcp',
            'input' => [],
            'result_count' => null,
        ]);

        Livewire::test(ListBrainSessions::class)
            ->assertSee('—');
    }

    public function test_edit_route_does_not_exist(): void
    {
        $session = BrainSession::create([
            'tool_name' => 'drawer_search',
            'source' => 'admin',
            'input' => [],
            'result_count' => 0,
        ]);

        $this->get("/admin/brain-sessions/{$session->id}/edit")
            ->assertNotFound();
    }

    public function test_default_sort_is_created_at_descending(): void
    {
        $a = BrainSession::create([
            'tool_name' => 'drawer_search',
            'source' => 'mcp',
            'input' => ['query' => 'a'],
            'result_count' => 0,
        ]);
        $a->forceFill(['created_at' => now()->subDays(2)])->save();

        $b = BrainSession::create([
            'tool_name' => 'drawer_search',
            'source' => 'mcp',
            'input' => ['query' => 'b'],
            'result_count' => 0,
        ]);
        $b->forceFill(['created_at' => now()->subDay()])->save();

        $c = BrainSession::create([
            'tool_name' => 'drawer_search',
            'source' => 'mcp',
            'input' => ['query' => 'c'],
            'result_count' => 0,
        ]);
        $c->forceFill(['created_at' => now()->subDays(5)])->save();

        Livewire::test(ListBrainSessions::class)
            ->assertCanSeeTableRecords([$b, $a, $c], inOrder: true);
    }
}
