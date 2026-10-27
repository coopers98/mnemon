<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiFrontendTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    // ---- Auth Tests ----

    public function test_landing_page_is_public(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('The memory your AI deserves');
    }

    public function test_wiki_index_requires_auth(): void
    {
        $response = $this->get('/wiki');

        $response->assertRedirect('/login');
    }

    public function test_palace_index_requires_auth(): void
    {
        $response = $this->get('/palace');

        $response->assertRedirect('/login');
    }

    public function test_login_page_loads(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('Sign in');
    }

    public function test_login_with_valid_credentials(): void
    {
        $response = $this->post('/login', [
            'email' => $this->user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('wiki.index'));
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_login_with_invalid_credentials(): void
    {
        $response = $this->post('/login', [
            'email' => $this->user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout(): void
    {
        $response = $this->actingAs($this->user)->post('/logout');

        $response->assertRedirect('/');
        $this->assertGuest();
    }

    // ---- Wiki Controller Tests ----

    public function test_wiki_index_shows_pages(): void
    {
        WikiPage::create([
            'name' => 'project:test-project',
            'type' => 'project',
            'title' => 'Test Project',
            'content' => 'Some content here.',
            'confidence' => 'high',
        ]);

        $response = $this->actingAs($this->user)->get('/wiki');

        $response->assertStatus(200);
        $response->assertSee('Test Project');
    }

    public function test_wiki_show_returns_correct_page(): void
    {
        WikiPage::create([
            'name' => 'project:atlas-abs',
            'type' => 'project',
            'title' => 'Atlas Abs',
            'content' => '# Atlas ABS Project',
            'confidence' => 'high',
        ]);

        $response = $this->actingAs($this->user)->get('/wiki/project:atlas-abs');

        $response->assertStatus(200);
        $response->assertSee('Atlas Abs');
        $response->assertSee('Atlas ABS Project');
    }

    public function test_wiki_show_returns_404_for_nonexistent_page(): void
    {
        $response = $this->actingAs($this->user)->get('/wiki/nonexistent-page');

        $response->assertStatus(404);
    }

    public function test_wiki_history_shows_revisions(): void
    {
        WikiPage::create([
            'name' => 'person:cooper',
            'type' => 'person',
            'title' => 'Cooper',
            'content' => 'Cooper is the creator.',
            'confidence' => 'high',
        ]);

        $response = $this->actingAs($this->user)->get('/wiki/person:cooper/history');

        $response->assertStatus(200);
        $response->assertSee('Revision history');
    }

    public function test_wiki_search_returns_results(): void
    {
        WikiPage::create([
            'name' => 'project:searchable',
            'type' => 'project',
            'title' => 'Searchable Project',
            'content' => 'This is searchable content with unique keywords.',
            'confidence' => 'medium',
        ]);

        $response = $this->actingAs($this->user)->get('/wiki/search?q=searchable');

        $response->assertStatus(200);
        $response->assertSee('Searchable Project');
    }

    public function test_wiki_search_empty_query(): void
    {
        $response = $this->actingAs($this->user)->get('/wiki/search?q=');

        $response->assertStatus(200);
    }

    // ---- Palace Controller Tests ----

    public function test_palace_index_shows_wings(): void
    {
        Wing::create(['name' => 'Research', 'slug' => 'research']);

        $response = $this->actingAs($this->user)->get('/palace');

        $response->assertStatus(200);
        $response->assertSee('Research');
    }

    public function test_palace_wing_shows_rooms(): void
    {
        $wing = Wing::create(['name' => 'Work', 'slug' => 'work']);
        Room::create(['name' => 'Meeting Notes', 'slug' => 'meeting-notes', 'wing_id' => $wing->id]);

        $response = $this->actingAs($this->user)->get('/palace/work');

        $response->assertStatus(200);
        $response->assertSee('Meeting Notes');
    }

    public function test_palace_room_shows_drawers(): void
    {
        $wing = Wing::create(['name' => 'Personal', 'slug' => 'personal']);
        $room = Room::create(['name' => 'Ideas', 'slug' => 'ideas', 'wing_id' => $wing->id]);
        Drawer::create(['content' => 'A brilliant idea for testing.', 'room_id' => $room->id, 'source' => 'test']);

        $response = $this->actingAs($this->user)->get('/palace/personal/ideas');

        $response->assertStatus(200);
        $response->assertSee('A brilliant idea for testing.');
    }

    public function test_palace_drawer_shows_full_content(): void
    {
        $wing = Wing::create(['name' => 'Notes', 'slug' => 'notes']);
        $room = Room::create(['name' => 'Daily', 'slug' => 'daily', 'wing_id' => $wing->id]);
        $drawer = Drawer::create(['content' => 'Full drawer content here.', 'room_id' => $room->id, 'source' => 'manual']);

        $response = $this->actingAs($this->user)->get('/palace/drawer/'.$drawer->id);

        $response->assertStatus(200);
        $response->assertSee('Full drawer content here.');
    }

    public function test_palace_shows_when_a_drawer_happened_when_it_differs_from_storage(): void
    {
        // A backfilled drawer is stored today but describes an older session.
        // The browser must show both, and say nothing extra for live drawers.
        $wing = Wing::create(['name' => 'Notes', 'slug' => 'notes']);
        $room = Room::create(['name' => 'Daily', 'slug' => 'daily', 'wing_id' => $wing->id]);
        $backfilled = Drawer::create([
            'content' => 'Backfilled drawer.',
            'room_id' => $room->id,
            'source' => 'claude-code',
            'occurred_at' => '2026-07-14 10:30:00',
        ]);
        $live = Drawer::create(['content' => 'Live drawer.', 'room_id' => $room->id, 'source' => 'manual']);

        $this->actingAs($this->user)->get('/palace/drawer/'.$backfilled->id)
            ->assertStatus(200)
            ->assertSee('Happened')
            ->assertSee('2026-07-14 10:30');

        $this->actingAs($this->user)->get('/palace/drawer/'.$live->id)
            ->assertStatus(200)
            ->assertDontSee('Happened');

        $this->actingAs($this->user)->get('/palace/notes/daily')
            ->assertStatus(200)
            ->assertSee('happened 2026-07-14');
    }

    public function test_palace_wing_returns_404_for_nonexistent(): void
    {
        $response = $this->actingAs($this->user)->get('/palace/nonexistent');

        $response->assertStatus(404);
    }

    // ---- Contact Form Tests ----

    public function test_contact_form_submission(): void
    {
        $response = $this->post('/contact', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'message' => 'Hello, this is a test message.',
        ]);

        $response->assertRedirect('/');
        $response->assertSessionHas('contact_success');

        $this->assertDatabaseHas('contact_submissions', [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }

    public function test_contact_form_validates_required_fields(): void
    {
        $response = $this->post('/contact', []);

        $response->assertSessionHasErrors(['name', 'email', 'message']);
    }

    /**
     * The auto-maintained index pages were named `wiki/index` and `wiki/log`.
     * The show route constrains the name to `[a-z0-9:_-]+`, which has no `/`,
     * so those two pages could never be reached from the web UI -- a permanent
     * 404 on pages the system generates for itself.
     *
     * Every other page uses the `type:slug` convention (`project:helios`),
     * which the route already serves. Renaming these to match fixes the 404
     * without widening the route, which would otherwise let `wiki.show`
     * greedily swallow the `/history` suffix of the route registered after it.
     */
    public function test_the_auto_maintained_index_pages_are_reachable(): void
    {
        // The names the application actually maintains must be routable. Asserting
        // a hand-made colon page is reachable would only test the route; the
        // defect is that the app writes a name the route cannot serve.
        foreach (['wiki:index' => 'Wiki Index', 'wiki:log' => 'Wiki Log'] as $name => $title) {
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9:_-]+$/',
                $name,
                'the auto-maintained page names must satisfy the wiki.show route constraint'
            );
        }

        foreach (['wiki:index' => 'Wiki Index', 'wiki:log' => 'Wiki Log'] as $name => $title) {
            WikiPage::create([
                'name' => $name,
                'type' => 'synthesis',
                'title' => $title,
                'content' => "# {$title}",
            ]);

            $this->actingAs($this->user)
                ->get('/wiki/'.$name)
                ->assertStatus(200)
                ->assertSee($title);
        }
    }

    // ---- Backlinks ----

    public function test_backlinks_count_pages_that_list_this_one_as_related(): void
    {
        WikiPage::factory()->create(['name' => 'project:alpha', 'related' => []]);
        WikiPage::factory()->create(['name' => 'person:ada', 'related' => ['project:alpha', 'concept:x']]);
        WikiPage::factory()->create(['name' => 'concept:x', 'related' => ['project:alpha']]);
        WikiPage::factory()->create(['name' => 'concept:y', 'related' => ['person:ada']]);

        $this->actingAs($this->user)->get(route('wiki.show', 'project:alpha'))
            ->assertOk()
            ->assertSee('<b>Backlinks</b> 2', false);
    }

    public function test_backlinks_ignore_self_reference_and_null_related(): void
    {
        WikiPage::factory()->create(['name' => 'concept:self', 'related' => ['concept:self']]);
        WikiPage::factory()->create(['name' => 'concept:z', 'related' => null]);

        $this->actingAs($this->user)->get(route('wiki.show', 'concept:self'))
            ->assertOk()
            ->assertSee('<b>Backlinks</b> 0', false);
    }

    public function test_backlinks_match_whole_names_not_prefixes(): void
    {
        WikiPage::factory()->create(['name' => 'project:alpha', 'related' => []]);
        WikiPage::factory()->create(['name' => 'project:alpha-two', 'related' => []]);
        WikiPage::factory()->create(['name' => 'person:ada', 'related' => ['project:alpha-two']]);

        $this->actingAs($this->user)->get(route('wiki.show', 'project:alpha'))
            ->assertOk()
            ->assertSee('<b>Backlinks</b> 0', false);
    }

    public function test_backlinks_tolerate_a_scalar_related_value(): void
    {
        WikiPage::factory()->create(['name' => 'project:alpha', 'related' => []]);
        // The array cast round-trips a bare string as a string, which
        // in_array() rejects with a TypeError.
        WikiPage::factory()->create(['name' => 'person:ada', 'related' => 'project:alpha']);
        WikiPage::factory()->create(['name' => 'concept:x', 'related' => ['project:alpha']]);

        $this->actingAs($this->user)->get(route('wiki.show', 'project:alpha'))
            ->assertOk()
            ->assertSee('<b>Backlinks</b> 1', false);
    }
}
