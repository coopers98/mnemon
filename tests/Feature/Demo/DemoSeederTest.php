<?php

namespace Tests\Feature\Demo;

use App\Models\Drawer;
use App\Models\EntityRelationship;
use App\Models\WikiPage;
use App\Models\Wing;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_refuses_outside_demo_mode(): void
    {
        config(['mnemon.demo.enabled' => false]);
        $this->expectException(\RuntimeException::class);
        $this->seed(DemoSeeder::class);
    }

    public function test_seeded_brain_matches_what_the_real_write_path_produces(): void
    {
        config(['mnemon.demo.enabled' => true]);
        $this->seed(DemoSeeder::class);

        $this->assertTrue(Schema::hasTable(DemoSeeder::MARKER_TABLE));
        $this->assertGreaterThanOrEqual(100, Drawer::count());
        $this->assertGreaterThanOrEqual(10, WikiPage::count());
        $this->assertEqualsCanonicalizing(['person', 'project', 'concept', 'decision', 'synthesis'],
            WikiPage::distinct()->pluck('type')->all());

        foreach (WikiPage::all() as $page) {
            $revisions = $page->revisions()->reorder('revision')->get();
            $this->assertSame($revisions->count(), $page->revision_count, $page->name);
            $this->assertSame($page->content, $revisions->last()->content, $page->name);
            $this->assertSame(count($page->sources ?? []), $page->source_count, $page->name);
            $this->assertNotEmpty($page->sources, "{$page->name} cites no drawers");
            $this->assertNotSame($page->name, $page->title, "{$page->name} has no title");
            $this->assertTrue($page->last_compiled_at->gt(now()->subDays(config('mnemon.wiki.stale_days'))), "{$page->name} is stale");
            foreach ($page->sources as $id) {
                $this->assertSame('consolidated', Drawer::find($id)?->tier, "{$page->name} source {$id}");
            }
            foreach ($page->related ?? [] as $name) {
                $this->assertTrue(WikiPage::where('name', $name)->exists(), "{$page->name} relates to missing {$name}");
            }
        }

        $this->assertGreaterThan(0, EntityRelationship::count());
        $this->assertTrue(WikiPage::where('revision_count', '>', 1)->exists(), 'no page has history');

        $dates = Drawer::pluck('created_at');
        $this->assertGreaterThan(30, $dates->min()->diffInDays($dates->max()));

        foreach (Wing::all() as $wing) {
            if (Str::startsWith($wing->slug, ['project-', 'person-'])) {
                $this->assertTrue(WikiPage::where('name', Str::replaceFirst('-', ':', $wing->slug))->exists(), "{$wing->slug} has no wiki page");
            }
        }
    }
}
