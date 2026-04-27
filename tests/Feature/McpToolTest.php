<?php

namespace Tests\Feature;

use App\Mcp\Tools\PalaceWakeUpTool;
use App\Models\ApiKey;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\WikiPage;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpToolTest extends TestCase
{
    use RefreshDatabase;

    private ApiKey $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = ApiKey::create([
            'name' => 'Test Key',
            'key_hash' => hash('sha256', 'test-key-value'),
            'scopes' => ['*'],
        ]);
    }

    // ─── palace_wake_up ──────────────────────────────────────────────────────

    public function test_palace_wake_up_returns_recent_drawers(): void
    {
        $wing = Wing::create(['name' => 'Work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);

        for ($i = 1; $i <= 12; $i++) {
            Drawer::create(['content' => "Drawer {$i}", 'room_id' => $room->id]);
        }

        $tool = app(PalaceWakeUpTool::class);
        $result = $tool->execute([], $this->apiKey);

        $this->assertArrayHasKey('recent_drawers', $result);
        $this->assertCount(10, $result['recent_drawers']);
        $this->assertArrayHasKey('content_preview', $result['recent_drawers'][0]);
        $this->assertArrayHasKey('wing', $result['recent_drawers'][0]);
        $this->assertArrayHasKey('room', $result['recent_drawers'][0]);
    }

    public function test_palace_wake_up_returns_active_wings(): void
    {
        $wing = Wing::create(['name' => 'Work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        Drawer::create(['content' => 'A note', 'room_id' => $room->id]);

        $tool = app(PalaceWakeUpTool::class);
        $result = $tool->execute([], $this->apiKey);

        $this->assertArrayHasKey('active_wings', $result);
        $this->assertCount(1, $result['active_wings']);
        $this->assertEquals('work', $result['active_wings'][0]['slug']);
    }

    public function test_palace_wake_up_returns_stale_wiki_pages(): void
    {
        WikiPage::create([
            'name' => 'stale-page',
            'type' => 'synthesis',
            'content' => 'Old',
            'last_compiled_at' => now()->subDays(35),
        ]);

        $tool = app(PalaceWakeUpTool::class);
        $result = $tool->execute([], $this->apiKey);

        $staleNames = array_column($result['stale_wiki_pages'], 'name');
        $this->assertContains('stale-page', $staleNames);
    }
}
