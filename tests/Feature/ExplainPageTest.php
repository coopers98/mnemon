<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\EntityRelationship;
use App\Models\Room;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The explainer is the page a prospective self-hoster reads before deciding,
 * so it has to be reachable without an account — and its figures have to come
 * from the database rather than the copy, or an architecture page quietly goes
 * stale while claiming to describe the system.
 */
class ExplainPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mnemon.embedding.driver' => 'none']);
    }

    public function test_the_explainer_is_public(): void
    {
        $this->get('/how-it-works')
            ->assertOk()
            ->assertSee('How it works', escape: false);
    }

    public function test_it_covers_the_questions_a_prospective_user_asks(): void
    {
        $response = $this->get('/how-it-works')->assertOk();

        // Each anchor is a question the page exists to answer; losing one
        // silently is the failure this guards against.
        foreach (['what', 'layers', 'retrieval', 'ontology', 'evidence', 'privacy', 'limits', 'cost', 'try'] as $anchor) {
            $response->assertSee('id="'.$anchor.'"', escape: false);
        }
    }

    public function test_the_counts_come_from_the_database(): void
    {
        $wing = Wing::create(['name' => 'Work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);

        foreach (range(1, 3) as $i) {
            Drawer::create(['content' => "note {$i}", 'room_id' => $room->id]);
        }

        WikiPage::create(['name' => 'work', 'type' => 'synthesis', 'title' => 'Work', 'content' => 'x']);
        WikiPage::create(['name' => 'concept:x', 'type' => 'concept', 'title' => 'X', 'content' => 'y']);

        EntityRelationship::create(['from_page' => 'work', 'to_page' => 'concept:x', 'edge_type' => 'references']);

        $response = $this->get('/how-it-works')->assertOk();

        $response->assertSee('3 drawers', escape: false);
        $response->assertSee('2 wiki pages', escape: false);
    }

    public function test_it_states_the_wiki_scoping_caveat_rather_than_omitting_it(): void
    {
        // The honest limitation is the reason this page is trustworthy; a
        // marketing rewrite that drops it should fail here.
        $this->get('/how-it-works')
            ->assertOk()
            ->assertSee('Single-tenant', escape: false)
            ->assertSee('What this does not prove', escape: false);
    }

    /**
     * The page originally said wiki pages are "rewritten whenever enough new
     * material accumulates", which implies a trigger that does not exist:
     * `mnemon:auto-compile-stale` reports stale pages and does not compile
     * them, and nothing is scheduled. Compilation happens when an agent is
     * asked. A reader deciding whether to run this needs to know the compiled
     * layer will not maintain itself.
     */
    public function test_it_does_not_imply_compilation_is_automatic(): void
    {
        $body = $this->get('/how-it-works')->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'rewritten whenever enough new',
            $body,
            'the page must not imply an automatic recompile trigger'
        );

        $this->assertStringContainsString(
            'does not recompile on its own',
            $body,
            'the page must state that compilation is agent-triggered'
        );
    }

    public function test_the_nav_links_to_it_for_signed_out_visitors(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('/how-it-works', escape: false);
    }

    public function test_it_is_also_reachable_while_signed_in(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/how-it-works')
            ->assertOk();
    }
}
