<?php

namespace Tests\Unit\Services;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use App\Services\RecallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecallServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_found_false_when_nothing_matches(): void
    {
        $service = app(RecallService::class);
        $result = $service->run('xyzzy nothing matches', 1500, null, null);

        $this->assertFalse($result['found']);
        $this->assertEmpty($result['wiki']);
        $this->assertEmpty($result['drawers']);
    }

    public function test_returns_mixed_payload_within_budget(): void
    {
        // SQLite test env has no embeddings; use a low confidence floor so
        // keyword-only matches (score ≈ word-hit fraction) still surface.
        config(['mnemon.recall.confidence_floor' => 0.1]);

        $wing = Wing::factory()->create(['slug' => 'work']);
        $room = Room::factory()->create(['wing_id' => $wing->id]);
        Drawer::factory()->create([
            'room_id' => $room->id,
            'content' => 'meeting notes about dorothy vaughan on the atlas project',
        ]);
        WikiPage::factory()->create([
            'name' => 'person:dorothy-vaughan',
            'title' => 'Dorothy Vaughan',
            'content' => 'Dorothy is the lead engineer on the Atlas project.',
        ]);

        $service = app(RecallService::class);
        $result = $service->run('what do we know about dorothy', 1500, null, null);

        $this->assertTrue($result['found']);
        $this->assertNotEmpty($result['wiki']);
        $this->assertEquals('person:dorothy-vaughan', $result['wiki'][0]['slug']);
        $this->assertGreaterThan(0, $result['tokens_used']);
        $this->assertLessThanOrEqual(1500, $result['tokens_used']);
    }

    public function test_excerpts_long_wiki_pages(): void
    {
        // Lower the floor to surface the page reliably in the SQLite test env
        // where fulltext-only scoring may not clear the production 0.45 default.
        config(['mnemon.recall.confidence_floor' => 0.0]);

        WikiPage::factory()->create([
            'name' => 'concept:big',
            'title' => 'Big',
            'content' => str_repeat('lorem ipsum dolor sit amet ', 500),
        ]);

        $service = app(RecallService::class);
        // Query against words that appear in the content body (SQLite LIKE search
        // only checks the content column, not name/title).
        $result = $service->run('lorem ipsum', 1500, null, null);

        $this->assertNotEmpty($result['wiki'], 'expected the long wiki page to surface');
        $this->assertStringContainsString('[truncated', $result['wiki'][0]['content']);
    }
}
