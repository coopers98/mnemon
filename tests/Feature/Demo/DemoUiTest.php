<?php

namespace Tests\Feature\Demo;

use App\Models\Room;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoUiTest extends TestCase
{
    use RefreshDatabase;

    private function demo(): void
    {
        config(['mnemon.demo.enabled' => true, 'mnemon.demo.host' => null, 'app.debug' => false]);
    }

    public function test_landing_in_demo_mode(): void
    {
        $this->demo();
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('read-only sample brain', $html);
        $this->assertStringContainsString('href="'.route('wiki.index').'"', $html);
        $this->assertStringContainsString('href="'.route('palace.index').'"', $html);
        $this->assertStringNotContainsString('href="'.route('login').'"', $html);
        $this->assertStringNotContainsString('action="'.route('contact.store').'"', $html);
        $this->assertStringContainsString('github.com/coopers98/mnemon/issues', $html);
    }

    public function test_palace_index_hides_admin_in_demo_mode(): void
    {
        $this->demo();
        Wing::factory()->create();
        $this->get('/palace')->assertOk()->assertDontSee('Open admin');
    }

    public function test_empty_palace_does_not_link_admin_in_demo_mode(): void
    {
        $this->demo();
        $this->get('/palace')->assertOk()->assertDontSee('href="/admin"', false);
    }

    public function test_how_it_works_says_demo(): void
    {
        $this->demo();
        $this->get('/how-it-works')->assertOk()->assertSee('This demo holds')->assertDontSee('This instance currently holds');
    }

    public function test_wing_links_to_its_wiki_page(): void
    {
        $this->demo();
        $wing = Wing::factory()->create(['slug' => 'project-alpha', 'name' => 'project:alpha']);
        Room::factory()->create(['wing_id' => $wing->id]);
        WikiPage::factory()->create(['name' => 'project:alpha']);
        WikiPage::factory()->create(['name' => 'project:alpha-two']);

        $this->get('/palace/project-alpha')->assertOk()
            ->assertSee('href="'.route('wiki.show', 'project:alpha').'"', false);
    }

    public function test_real_install_is_unchanged_and_links_the_demo_when_configured(): void
    {
        config(['mnemon.demo.enabled' => false, 'mnemon.demo.url' => 'https://trymnemon.com']);
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('read-only sample brain', $html);
        $this->assertStringContainsString('href="'.route('login').'"', $html);
        $this->assertStringContainsString('action="'.route('contact.store').'"', $html);
        $this->assertStringContainsString('href="https://trymnemon.com"', $html);
        $this->get('/how-it-works')->assertSee('This instance currently holds');
    }

    public function test_palace_index_shows_driver_not_host(): void
    {
        $this->actingAs(User::factory()->create());
        Wing::factory()->create();
        $this->get('/palace')->assertOk()->assertDontSee('localhost:5432')->assertSee('sqlite');
    }

    public function test_wiki_index_pluralises_types(): void
    {
        $this->actingAs(User::factory()->create());
        WikiPage::factory()->create(['type' => 'synthesis', 'name' => 'synthesis:one']);
        $this->get('/wiki')->assertOk()->assertSee('Syntheses')->assertDontSee('Synthesiss');
    }

    public function test_wiki_page_drops_leading_h1_matching_title(): void
    {
        $this->actingAs(User::factory()->create());
        WikiPage::factory()->create(['name' => 'project:a', 'title' => 'Tidepool', 'content' => "# tidepool \n\nBody text here."]);
        $html = $this->get(route('wiki.show', 'project:a'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<h1>tidepool', $html);
        $this->assertStringNotContainsString('<h1>Tidepool', $html);
        $this->assertStringContainsString('Body text here.', $html);
        $this->assertSame("# tidepool \n\nBody text here.", WikiPage::where('name', 'project:a')->value('content'));
    }

    public function test_wiki_page_keeps_leading_h1_that_differs_from_title(): void
    {
        $this->actingAs(User::factory()->create());
        WikiPage::factory()->create(['name' => 'project:b', 'title' => 'Tidepool', 'content' => "# Something else\n\nBody."]);
        $html = $this->get(route('wiki.show', 'project:b'))->assertOk()->getContent();

        $this->assertStringContainsString('<h1>Something else', $html);
    }
}
