<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

/**
 * D18 — `metadata` came back as a different type depending on which tool asked.
 *
 * `Drawer` casts `metadata` to `array`, but `PalaceSearchService` builds its
 * results with `DB::table()`, which bypasses the model and its casts. So
 * `drawer_search` returned a JSON-encoded string where `drawer_get` returned an
 * object. Agents are the primary consumer of this API and cannot reason around
 * a field whose type depends on the call site — it reads as malformed data
 * rather than as a contract to parse.
 */
class SearchMetadataShapeTest extends TestCase
{
    use MakesMcpRequests, RefreshDatabase;

    private function seedDrawer(array $metadata): int
    {
        $wing = Wing::create(['name' => 'Shape', 'slug' => 'shape']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);

        return Drawer::create([
            'room_id' => $room->id,
            'content' => 'kestrel migration notes',
            'source' => 'shape-test',
            'metadata' => $metadata,
        ])->id;
    }

    public function test_search_returns_metadata_as_an_object(): void
    {
        $this->seedDrawer(['session_id' => 'abc123', 'index' => 7]);

        $response = $this->mcpCall('drawer_search', ['query' => 'kestrel migration'], ['mcp:use']);

        $results = $response->json('result.structuredContent.results');
        $this->assertNotEmpty($results, 'expected the seeded drawer back');

        $metadata = $results[0]['metadata'];
        $this->assertIsArray(
            $metadata,
            'drawer_search must return metadata as an object; a JSON string forces every '
            .'client to special-case this endpoint'
        );
        $this->assertSame('abc123', $metadata['session_id']);
        $this->assertSame(7, $metadata['index']);
    }

    public function test_search_and_get_agree_on_the_shape_of_metadata(): void
    {
        $id = $this->seedDrawer(['session_id' => 'abc123', 'index' => 7]);

        $fromSearch = $this->mcpCall('drawer_search', ['query' => 'kestrel migration'], ['mcp:use'])
            ->json('result.structuredContent.results.0.metadata');
        $fromGet = $this->mcpCall('drawer_get', ['id' => $id], ['mcp:use'])
            ->json('result.structuredContent.metadata');

        $this->assertSame(
            $fromGet,
            $fromSearch,
            'the same drawer must present the same metadata whichever tool asked for it'
        );
    }

    public function test_a_drawer_without_metadata_is_not_turned_into_something_odd(): void
    {
        $wing = Wing::create(['name' => 'Shape', 'slug' => 'shape']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        Drawer::create(['room_id' => $room->id, 'content' => 'kestrel migration notes', 'source' => 'none']);

        $metadata = $this->mcpCall('drawer_search', ['query' => 'kestrel migration'], ['mcp:use'])
            ->json('result.structuredContent.results.0.metadata');

        $this->assertTrue(
            $metadata === null || $metadata === [],
            'absent metadata must stay absent, not become the string "null"'
        );
    }
}
