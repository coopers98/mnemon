<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Search;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class SearchPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Avoid embedding-driver hits during drawer creation in tests.
        config(['mnemon.embedding.driver' => 'none']);

        $this->actingAs(User::factory()->create());
    }

    public function test_page_renders_for_authenticated_user(): void
    {
        Livewire::test(Search::class)
            ->assertSuccessful()
            ->assertSee('Search')
            ->assertSee('Type a query above to search.');
    }

    public function test_empty_query_returns_empty_results(): void
    {
        $this->seedDrawer('Some content here');
        $this->seedWiki('project:atlas', 'Some wiki content here');

        Livewire::test(Search::class)
            ->set('data.q', '')
            ->assertSuccessful()
            ->assertSee('Results (0)')
            ->assertSee('Type a query above to search.');
    }

    public function test_query_against_drawers_returns_matches(): void
    {
        $wing = Wing::create(['name' => 'Work']);
        $this->seedDrawer('Laravel is a great PHP framework for web apps', $wing);
        $this->seedDrawer('Python is great for data science', $wing);

        $component = Livewire::test(Search::class)
            ->set('data.q', 'Laravel framework')
            ->set('data.scope', 'palace');

        $results = $component->instance()->getResults();

        $this->assertCount(1, $results);
        $this->assertSame('drawer', $results->first()['kind']);
        $this->assertStringContainsString('Laravel', $results->first()['snippet']);
    }

    public function test_query_against_wiki_returns_matches(): void
    {
        $this->seedWiki('project:atlas', 'Atlas is an asset-backed securities platform built with Laravel');
        $this->seedWiki('project:cora', 'Cora is a recital optimization system for dance studios');

        $component = Livewire::test(Search::class)
            ->set('data.q', 'asset securities')
            ->set('data.scope', 'wiki');

        $results = $component->instance()->getResults();

        $this->assertCount(1, $results);
        $this->assertSame('wiki_page', $results->first()['kind']);
        $this->assertSame('project:atlas', $results->first()['title']);
    }

    public function test_scope_palace_excludes_wiki_results(): void
    {
        $this->seedDrawer('Palace document about widgets and gizmos');
        $this->seedWiki('concept:widgets', 'Widgets are small reusable UI pieces');

        $component = Livewire::test(Search::class)
            ->set('data.q', 'widgets')
            ->set('data.scope', 'palace');

        $results = $component->instance()->getResults();

        $this->assertGreaterThan(0, $results->count());
        foreach ($results as $result) {
            $this->assertSame('drawer', $result['kind']);
        }
    }

    public function test_scope_wiki_excludes_drawer_results(): void
    {
        $this->seedDrawer('Palace document about widgets and gizmos');
        $this->seedWiki('concept:widgets', 'Widgets are small reusable UI pieces');

        $component = Livewire::test(Search::class)
            ->set('data.q', 'widgets')
            ->set('data.scope', 'wiki');

        $results = $component->instance()->getResults();

        $this->assertGreaterThan(0, $results->count());
        foreach ($results as $result) {
            $this->assertSame('wiki_page', $result['kind']);
        }
    }

    public function test_scope_all_returns_both_drawers_and_wiki(): void
    {
        $this->seedDrawer('Palace document about widgets and gizmos');
        $this->seedWiki('concept:widgets', 'Widgets are small reusable UI pieces');

        $component = Livewire::test(Search::class)
            ->set('data.q', 'widgets')
            ->set('data.scope', 'all');

        $results = $component->instance()->getResults();

        $kinds = $results->pluck('kind')->unique()->all();

        $this->assertContains('drawer', $kinds);
        $this->assertContains('wiki_page', $kinds);
    }

    public function test_wing_filter_narrows_palace_results(): void
    {
        $alpha = Wing::create(['name' => 'Alpha']);
        $beta = Wing::create(['name' => 'Beta']);

        $this->seedDrawer('Shared concept across projects', $alpha);
        $this->seedDrawer('Shared concept in different wing', $beta);

        $component = Livewire::test(Search::class)
            ->set('data.q', 'shared concept')
            ->set('data.scope', 'palace')
            ->set('data.wing', 'alpha');

        $results = $component->instance()->getResults();

        $this->assertCount(1, $results);
        $this->assertSame('drawer', $results->first()['kind']);
        $this->assertStringStartsWith('Alpha /', $results->first()['title']);
        $this->assertStringStartsWith('alpha/', $results->first()['location']);
    }

    public function test_results_are_sorted_by_score_descending(): void
    {
        $wing = Wing::create(['name' => 'Sort']);
        // Drawer matching both query words should outrank one matching just one word.
        $this->seedDrawer('alpha bravo charlie content', $wing);
        $this->seedDrawer('alpha only here', $wing);
        $this->seedDrawer('bravo only here', $wing);

        $component = Livewire::test(Search::class)
            ->set('data.q', 'alpha bravo')
            ->set('data.scope', 'palace');

        $results = $component->instance()->getResults();

        $this->assertGreaterThanOrEqual(2, $results->count());

        $scores = $results->pluck('score')->all();
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
    }

    public function test_normalized_drawer_result_has_url_to_drawer_view(): void
    {
        $wing = Wing::create(['name' => 'Routing']);
        $drawer = $this->seedDrawer('a unique routable phrase here', $wing);

        $component = Livewire::test(Search::class)
            ->set('data.q', 'unique routable phrase')
            ->set('data.scope', 'palace');

        $results = $component->instance()->getResults();

        $this->assertCount(1, $results);
        $expected = route('filament.admin.resources.drawers.view', ['record' => $drawer->id]);
        $this->assertSame($expected, $results->first()['url']);
    }

    public function test_normalized_wiki_result_has_url_to_wiki_view(): void
    {
        $page = $this->seedWiki('decision:routing', 'A unique routable wiki phrase about decisions');

        $component = Livewire::test(Search::class)
            ->set('data.q', 'unique routable')
            ->set('data.scope', 'wiki');

        $results = $component->instance()->getResults();

        $this->assertCount(1, $results);
        $expected = route('filament.admin.resources.wiki-pages.view', ['record' => $page->id]);
        $this->assertSame($expected, $results->first()['url']);
    }

    private function seedDrawer(string $content, ?Wing $wing = null, ?string $roomName = null): Drawer
    {
        $wing ??= Wing::firstOrCreate(['name' => 'Default Wing'], ['slug' => 'default-wing']);
        $room = Room::firstOrCreate(
            ['wing_id' => $wing->id, 'slug' => Str::slug($roomName ?? 'Default Room')],
            ['name' => $roomName ?? 'Default Room'],
        );

        return Drawer::create([
            'room_id' => $room->id,
            'content' => $content,
            'source' => 'test',
        ]);
    }

    private function seedWiki(string $name, string $content, string $type = 'concept'): WikiPage
    {
        return WikiPage::create([
            'name' => $name,
            'type' => $type,
            'title' => $name,
            'content' => $content,
        ]);
    }
}
