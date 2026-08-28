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

    /**
     * D11 round 2 — the test whose absence let the defect ship.
     *
     * Every `recall` test used to lower `confidence_floor` (to 0.1 or 0.0), so
     * none of them exercised the value production actually runs at: 0.45, with
     * no `MNEMON_RECALL_FLOOR` in `.env.example` to override it. At that floor a
     * conversational prompt scored 0.167-0.333 against the whole word count and
     * was filtered out entirely, so `recall` returned `found => true` with an
     * empty `wiki` array — D11's exact symptom, on the production driver, with
     * a green suite.
     *
     * This test deliberately does NOT touch the config.
     */
    public function test_conversational_prompt_surfaces_wiki_at_the_shipped_confidence_floor(): void
    {
        $floor = (float) config('mnemon.recall.confidence_floor');

        $this->assertSame(
            0.45,
            $floor,
            'the shipped floor changed — this test only means something at the value production runs at'
        );

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
        WikiPage::factory()->create([
            'name' => 'project:atlas',
            'title' => 'Atlas',
            'content' => 'The Atlas project is a data pipeline rebuild.',
        ]);

        $service = app(RecallService::class);

        // Two searchable terms ('know', 'dorothy'); the page has one, so 0.5.
        $result = $service->run('what do we know about dorothy', 1500, null, null);
        $this->assertTrue($result['found']);
        $this->assertNotEmpty($result['wiki'], 'a conversational prompt must surface wiki context at the shipped floor');
        $this->assertSame('person:dorothy-vaughan', $result['wiki'][0]['slug']);
        $this->assertGreaterThanOrEqual($floor, $result['wiki'][0]['confidence']);

        // Four searchable terms ('remind', 'atlas', 'project', 'timeline').
        $result = $service->run('can you remind me about the atlas project timeline', 1500, null, null);
        $this->assertNotEmpty($result['wiki']);

        // Two searchable terms, both present: still exactly 1.0, as before.
        $result = $service->run('what is the atlas project about', 1500, null, null);
        $this->assertNotEmpty($result['wiki']);
        $this->assertSame(1.0, $result['wiki'][0]['confidence']);
    }

    /**
     * The floor still has to mean something: a prompt whose searchable terms
     * appear nowhere must not be dragged over the line by the filler around it.
     */
    public function test_unrelated_prompt_still_returns_nothing_at_the_shipped_floor(): void
    {
        WikiPage::factory()->create([
            'name' => 'person:dorothy-vaughan',
            'title' => 'Dorothy Vaughan',
            'content' => 'Dorothy is the lead engineer on the Atlas project.',
        ]);

        $service = app(RecallService::class);
        $result = $service->run('what do we know about quokka bandwidth', 1500, null, null);

        $this->assertFalse($result['found']);
        $this->assertEmpty($result['wiki']);
    }

    public function test_excerpts_long_wiki_pages(): void
    {
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
