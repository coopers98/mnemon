<?php

namespace Tests\Feature\Mcp;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

/**
 * Wing restrictions used to stop at the palace. `wiki_pages` had no wing
 * dimension, so any token carrying `mcp:use` could read any wiki page whatever
 * its restrictions — and wiki pages are *compiled palace content*, so a token
 * restricted to `work` could read a synthesis of `personal` drawers.
 *
 * The association is derived from the page name with `Wing::slugify`, which is
 * the mapping `wiki_compile` already enforces ("project:atlas" → "project-atlas").
 * A page whose name maps to no wing is treated as unreachable by a restricted
 * token rather than visible to everyone: fail closed, because the alternative
 * is the hole this test exists to close.
 */
class WikiWingIsolationTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mnemon.embedding.driver' => 'none']);

        $work = Wing::create(['name' => 'Work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $work->id, 'name' => 'Notes', 'slug' => 'notes']);
        Drawer::create(['content' => 'a work note', 'room_id' => $room->id]);

        Wing::create(['name' => 'Personal', 'slug' => 'personal']);

        WikiPage::create([
            'name' => 'work',
            'type' => 'synthesis',
            'title' => 'Work',
            'content' => 'Synthesis of work drawers.',
        ]);

        WikiPage::create([
            'name' => 'personal',
            'type' => 'synthesis',
            'title' => 'Personal',
            'content' => 'SECRET personal synthesis that a work token must never read.',
        ]);
    }

    public function test_context_get_denies_a_page_outside_the_tokens_wings(): void
    {
        $res = $this->mcpCall('context_get', ['name' => 'personal'], ['mcp:use'], ['work']);

        $res->assertOk();
        $this->assertStringNotContainsString(
            'SECRET personal synthesis',
            $res->getContent(),
            'a token restricted to work must not read the personal wiki page'
        );
    }

    public function test_context_get_still_serves_a_page_the_token_may_see(): void
    {
        $res = $this->mcpCall('context_get', ['name' => 'work'], ['mcp:use'], ['work']);

        $res->assertOk();
        $this->assertStringContainsString('Synthesis of work drawers', $res->getContent());
    }

    public function test_context_list_does_not_enumerate_forbidden_pages(): void
    {
        $res = $this->mcpCall('context_list', [], ['mcp:use'], ['work']);

        $res->assertOk();
        $body = $res->getContent();

        $this->assertStringContainsString('work', $body);
        $this->assertStringNotContainsString(
            'personal',
            $body,
            'context_list must not enumerate pages the token cannot read'
        );
    }

    public function test_an_unrestricted_token_still_sees_everything(): void
    {
        $res = $this->mcpCall('context_list', [], ['mcp:use'], null);

        $res->assertOk();
        $this->assertStringContainsString('personal', $res->getContent());
    }

    public function test_palace_wake_up_does_not_leak_forbidden_page_names(): void
    {
        $res = $this->mcpCall('palace_wake_up', [], ['mcp:use'], ['work']);

        $res->assertOk();
        $this->assertStringNotContainsString(
            'personal',
            $res->getContent(),
            'wake-up runs on every session start and must not name forbidden pages'
        );
    }

    public function test_brain_status_does_not_leak_forbidden_page_names(): void
    {
        $res = $this->mcpCall('brain_status', [], ['mcp:use'], ['work']);

        $res->assertOk();
        $this->assertStringNotContainsString(
            'personal',
            $res->getContent(),
            'brain_status must not name pages the token cannot read'
        );
    }

    public function test_recall_does_not_return_forbidden_wiki_content(): void
    {
        $res = $this->mcpCall(
            'recall',
            ['prompt' => 'what do we know about personal synthesis matters'],
            ['mcp:use'],
            ['work']
        );

        $res->assertOk();
        $this->assertStringNotContainsString(
            'SECRET personal synthesis',
            $res->getContent(),
            'recall runs automatically on every prompt — its wiki leg must respect restrictions'
        );
    }
}
