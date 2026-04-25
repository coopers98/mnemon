<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\MnemonStatsOverviewWidget;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Wing;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class MnemonStatsOverviewWidgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Skip embedding generation in tests so observers don't try to call
        // out to OpenAI / Ollama.
        config(['mnemon.embedding.driver' => 'none']);

        $this->actingAs(User::factory()->create());
    }

    public function test_widget_renders_for_authenticated_user(): void
    {
        Livewire::test(MnemonStatsOverviewWidget::class)
            ->assertSuccessful();
    }

    public function test_all_four_stat_labels_are_visible(): void
    {
        Livewire::test(MnemonStatsOverviewWidget::class)
            ->assertSee('Drawers')
            ->assertSee('Wiki pages')
            ->assertSee('Drawers added (7d)')
            ->assertSee('Last write');
    }

    public function test_drawer_count_reflects_non_trashed_drawers(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);

        Drawer::create(['content' => 'one', 'room_id' => $room->id]);
        Drawer::create(['content' => 'two', 'room_id' => $room->id]);
        $deleted = Drawer::create(['content' => 'three', 'room_id' => $room->id]);
        $deleted->delete();

        // 3 created, 1 soft-deleted → count() should be 2.
        $widget = new MnemonStatsOverviewWidget;
        $stats = $this->invokeStats($widget);

        $this->assertSame('2', (string) $stats[0]->getValue());
    }

    public function test_wiki_page_count_is_correct(): void
    {
        WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'content' => 'body',
            'last_compiled_at' => now(),
        ]);
        WikiPage::create([
            'name' => 'concept:graph',
            'type' => 'concept',
            'content' => 'body',
            'last_compiled_at' => now(),
        ]);

        $widget = new MnemonStatsOverviewWidget;
        $stats = $this->invokeStats($widget);

        $this->assertSame('2', (string) $stats[1]->getValue());
    }

    public function test_stale_subtitle_reflects_old_or_uncompiled_pages(): void
    {
        // Fresh page (compiled today)
        WikiPage::create([
            'name' => 'project:fresh',
            'type' => 'project',
            'content' => 'body',
            'last_compiled_at' => now(),
        ]);

        // Stale: compiled long ago
        WikiPage::create([
            'name' => 'concept:old',
            'type' => 'concept',
            'content' => 'body',
            'last_compiled_at' => now()->subDays(60),
        ]);

        // Stale: never compiled
        WikiPage::create([
            'name' => 'person:never',
            'type' => 'person',
            'content' => 'body',
            'last_compiled_at' => null,
        ]);

        $widget = new MnemonStatsOverviewWidget;
        $stats = $this->invokeStats($widget);

        $this->assertSame('2 stale', $stats[1]->getDescription());
    }

    public function test_seven_day_count_excludes_older_drawers(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);

        // Within window
        $a = Drawer::create(['content' => 'a', 'room_id' => $room->id]);
        $a->forceFill(['created_at' => now()->subDays(1)])->save();
        $b = Drawer::create(['content' => 'b', 'room_id' => $room->id]);
        $b->forceFill(['created_at' => now()->subDays(3)])->save();

        // Outside window
        $old = Drawer::create(['content' => 'old', 'room_id' => $room->id]);
        $old->forceFill(['created_at' => now()->subDays(30)])->save();

        $widget = new MnemonStatsOverviewWidget;
        $stats = $this->invokeStats($widget);

        $this->assertSame('2', (string) $stats[2]->getValue());
        $this->assertSame([0, 0, 0, 1, 0, 1, 0], $stats[2]->getChart());
    }

    public function test_last_write_shows_never_when_no_content_exists(): void
    {
        $widget = new MnemonStatsOverviewWidget;
        $stats = $this->invokeStats($widget);

        $this->assertSame('Never', $stats[3]->getValue());
        $this->assertNull($stats[3]->getDescription());
    }

    public function test_last_write_shows_relative_time_for_latest_drawer(): void
    {
        Carbon::setTestNow('2026-04-25 12:00:00');

        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Meeting Notes', 'wing_id' => $wing->id]);

        $drawer = Drawer::create(['content' => 'fresh', 'room_id' => $room->id]);
        $drawer->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $widget = new MnemonStatsOverviewWidget;
        $stats = $this->invokeStats($widget);

        $this->assertStringContainsString('5 minutes ago', (string) $stats[3]->getValue());
        $this->assertSame("to 'Work / Meeting Notes'", $stats[3]->getDescription());

        Carbon::setTestNow();
    }

    public function test_last_write_picks_wiki_page_when_newer_than_drawer(): void
    {
        Carbon::setTestNow('2026-04-25 12:00:00');

        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        $drawer = Drawer::create(['content' => 'older', 'room_id' => $room->id]);
        $drawer->forceFill(['created_at' => now()->subHour()])->save();

        $page = WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'content' => 'body',
            'last_compiled_at' => now(),
        ]);
        $page->forceFill(['updated_at' => now()->subMinutes(2)])->save();

        $widget = new MnemonStatsOverviewWidget;
        $stats = $this->invokeStats($widget);

        $this->assertSame("to 'project:atlas'", $stats[3]->getDescription());

        Carbon::setTestNow();
    }

    public function test_widget_renders_with_seeded_content_via_livewire(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $room = Room::create(['name' => 'Notes', 'wing_id' => $wing->id]);
        Drawer::create(['content' => 'one', 'room_id' => $room->id]);
        WikiPage::create([
            'name' => 'project:atlas',
            'type' => 'project',
            'content' => 'body',
            'last_compiled_at' => now(),
        ]);

        Livewire::test(MnemonStatsOverviewWidget::class)
            ->assertSuccessful()
            ->assertSee('Drawers')
            ->assertSee('Wiki pages');
    }

    /**
     * Helper to call the protected getStats() method via reflection.
     *
     * @return array<int, Stat>
     */
    protected function invokeStats(MnemonStatsOverviewWidget $widget): array
    {
        $method = new \ReflectionMethod($widget, 'getStats');
        $method->setAccessible(true);

        return $method->invoke($widget);
    }
}
