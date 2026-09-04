<?php

namespace Tests\Feature;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use App\Services\DrawerWriteService;
use App\Services\EmbeddingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DrawerDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private function write(): DrawerWriteService
    {
        return app(DrawerWriteService::class);
    }

    public function test_identical_content_in_the_same_room_is_not_stored_twice(): void
    {
        // Cron-launched Claude Code sessions produce byte-identical transcripts
        // every day. Measured on the live palace: 31 duplicate groups, 56
        // redundant copies, 17% of everything stored.
        $first = $this->write()->createDrawer('work', 'notes', 'the daily sync completed', 'cron');
        $second = $this->write()->createDrawer('work', 'notes', 'the daily sync completed', 'cron');

        $this->assertTrue($second->is($first), 'the existing drawer should be handed back');
        $this->assertFalse($second->wasRecentlyCreated, 'callers need to be able to tell it was a duplicate');
        $this->assertSame(1, Drawer::count());
    }

    public function test_a_duplicate_costs_no_embedding_call(): void
    {
        // The reason this is worth doing rather than cleaning up afterwards:
        // every stored duplicate pays for an embedding it did not need.
        config(['mnemon.embedding.driver' => 'openai']);
        $this->write()->createDrawer('work', 'notes', 'repeated content', 'cron');

        $spy = Mockery::spy(EmbeddingManager::class);
        $this->app->instance(EmbeddingManager::class, $spy);

        $this->write()->createDrawer('work', 'notes', 'repeated content', 'cron');

        $spy->shouldNotHaveReceived('embed');
    }

    public function test_a_duplicate_does_not_inflate_the_compile_backlog(): void
    {
        $page = WikiPage::factory()->create([
            'name' => 'project:work',
            'pending_drawers_since_compile' => 0,
        ]);
        Wing::factory()->create(['slug' => 'project-work', 'name' => 'project:work']);

        $this->write()->createDrawer('project-work', 'notes', 'same thing', 'cron');
        $after_first = $page->fresh()->pending_drawers_since_compile;
        $this->write()->createDrawer('project-work', 'notes', 'same thing', 'cron');

        $this->assertSame($after_first, $page->fresh()->pending_drawers_since_compile,
            'a duplicate must not make a page look staler than it is');
    }

    public function test_the_same_content_in_a_different_room_is_kept(): void
    {
        // Two projects can legitimately record the same decision. Rooms are
        // separate contexts and deduplicating across them would lose meaning.
        $first = $this->write()->createDrawer('work', 'notes', 'we chose exponential backoff', 'agent');
        $second = $this->write()->createDrawer('work', 'decisions', 'we chose exponential backoff', 'agent');

        $this->assertFalse($second->is($first));
        $this->assertSame(2, Drawer::count());
    }

    public function test_a_soft_deleted_duplicate_does_not_block_a_new_write(): void
    {
        // Deleting something and recording it again is a deliberate act, not a
        // duplicate.
        $first = $this->write()->createDrawer('work', 'notes', 'transient note', 'agent');
        $first->delete();

        $second = $this->write()->createDrawer('work', 'notes', 'transient note', 'agent');

        $this->assertTrue($second->wasRecentlyCreated);
        $this->assertFalse($second->is($first));
    }

    public function test_deduplication_happens_after_redaction(): void
    {
        // Two captures of the same session differing only in a secret that gets
        // redacted are the same drawer once stored, and must be treated as one.
        $a = $this->write()->createDrawer('work', 'notes', 'token is ghp_'.str_repeat('a', 36), 'agent');
        $b = $this->write()->createDrawer('work', 'notes', 'token is ghp_'.str_repeat('b', 36), 'agent');

        $this->assertTrue($b->is($a), 'identical once redacted, so identical in the palace');
        $this->assertSame(1, Drawer::count());
    }

    public function test_existing_rows_are_backfilled_so_old_duplicates_are_caught(): void
    {
        // A drawer written before this feature has no hash; without a backfill
        // the first repeat after deploy would slip through.
        $wing = Wing::factory()->create(['slug' => 'work']);
        $room = Room::factory()->create(['wing_id' => $wing->id, 'slug' => 'notes']);
        $legacy = Drawer::create([
            'room_id' => $room->id,
            'content' => 'written before the hash existed',
            'source' => 'agent',
            'tier' => 'raw',
        ]);
        $this->assertNotNull($legacy->fresh()->content_hash, 'the model must hash on write');

        $again = $this->write()->createDrawer('work', 'notes', 'written before the hash existed', 'agent');

        $this->assertTrue($again->is($legacy));
    }
}
