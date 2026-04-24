<?php

namespace Tests\Feature;

use App\Mcp\McpException;
use App\Mcp\Tools\DrawerAddTool;
use App\Mcp\Tools\DrawerGetTool;
use App\Models\ApiKey;
use App\Models\Drawer;
use App\Models\Room;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpAuthTest extends TestCase
{
    use RefreshDatabase;

    // ─── HTTP middleware tests via /api/mcp/call ──────────────────────────────

    public function test_valid_api_key_is_accepted(): void
    {
        ['key' => $plaintext] = ApiKey::generate('Test Key', ['*']);

        $response = $this->postJson('/api/mcp/call', [
            'tool' => 'brain_status',
            'params' => [],
        ], ['X-API-Key' => $plaintext]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['result' => ['drawer_count']]);
    }

    public function test_missing_api_key_is_rejected(): void
    {
        $response = $this->postJson('/api/mcp/call', [
            'tool' => 'brain_status',
            'params' => [],
        ]);

        $response->assertStatus(401);
        $response->assertJsonFragment(['error' => 'Missing API key. Provide X-API-Key header.']);
    }

    public function test_invalid_api_key_is_rejected(): void
    {
        $response = $this->postJson('/api/mcp/call', [
            'tool' => 'brain_status',
            'params' => [],
        ], ['X-API-Key' => 'wrong-key-value']);

        $response->assertStatus(401);
        $response->assertJsonFragment(['error' => 'Invalid API key.']);
    }

    public function test_revoked_api_key_is_rejected(): void
    {
        ['key' => $plaintext, 'model' => $model] = ApiKey::generate('Revoked Key', ['*']);
        $model->update(['revoked_at' => now()]);

        $response = $this->postJson('/api/mcp/call', [
            'tool' => 'brain_status',
            'params' => [],
        ], ['X-API-Key' => $plaintext]);

        $response->assertStatus(401);
        $response->assertJsonFragment(['error' => 'API key has been revoked.']);
    }

    public function test_key_without_required_scope_is_rejected(): void
    {
        ['key' => $plaintext] = ApiKey::generate('Read-Only Key', ['wiki:read']);

        // brain_status requires palace:read
        $response = $this->postJson('/api/mcp/call', [
            'tool' => 'brain_status',
            'params' => [],
        ], ['X-API-Key' => $plaintext]);

        $response->assertStatus(403);
        $response->assertJsonPath('error', fn ($v) => str_contains($v, 'palace:read'));
    }

    public function test_valid_key_updates_last_used_at(): void
    {
        ['key' => $plaintext, 'model' => $model] = ApiKey::generate('Active Key', ['*']);

        $this->assertNull($model->last_used_at);

        $this->postJson('/api/mcp/call', [
            'tool' => 'brain_status',
            'params' => [],
        ], ['X-API-Key' => $plaintext]);

        $model->refresh();
        $this->assertNotNull($model->last_used_at);
    }

    // ─── Wing restriction tests via tool handlers ─────────────────────────────

    public function test_wing_restricted_key_cannot_access_other_wings(): void
    {
        $restrictedKey = ApiKey::create([
            'name' => 'Restricted',
            'key_hash' => hash('sha256', 'restricted'),
            'scopes' => ['*'],
            'wing_restrictions' => ['work'],
        ]);

        $wing = Wing::create(['name' => 'Personal', 'slug' => 'personal']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        $drawer = Drawer::create(['content' => 'Private note', 'room_id' => $room->id]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('personal');

        $tool = app(DrawerGetTool::class);
        $tool->execute(['id' => $drawer->id], $restrictedKey);
    }

    public function test_wing_restricted_key_can_access_allowed_wing(): void
    {
        $restrictedKey = ApiKey::create([
            'name' => 'Work Only',
            'key_hash' => hash('sha256', 'work-only'),
            'scopes' => ['*'],
            'wing_restrictions' => ['work'],
        ]);

        $wing = Wing::create(['name' => 'Work', 'slug' => 'work']);
        $room = Room::create(['wing_id' => $wing->id, 'name' => 'Notes', 'slug' => 'notes']);
        $drawer = Drawer::create(['content' => 'Work note', 'room_id' => $room->id]);

        $tool = app(DrawerGetTool::class);
        $result = $tool->execute(['id' => $drawer->id], $restrictedKey);

        $this->assertEquals($drawer->id, $result['id']);
    }

    public function test_wing_restricted_key_cannot_add_to_other_wings(): void
    {
        $restrictedKey = ApiKey::create([
            'name' => 'Work Only Add',
            'key_hash' => hash('sha256', 'work-only-add'),
            'scopes' => ['*'],
            'wing_restrictions' => ['work'],
        ]);

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('personal');

        $tool = app(DrawerAddTool::class);
        $tool->execute(['content' => 'test', 'wing' => 'Personal'], $restrictedKey);
    }

    // ─── Unknown tool ─────────────────────────────────────────────────────────

    public function test_unknown_tool_returns_404(): void
    {
        ['key' => $plaintext] = ApiKey::generate('Full Key', ['*']);

        $response = $this->postJson('/api/mcp/call', [
            'tool' => 'nonexistent_tool',
            'params' => [],
        ], ['X-API-Key' => $plaintext]);

        $response->assertStatus(404);
    }

    // ─── tools listing endpoint ───────────────────────────────────────────────

    public function test_tools_endpoint_lists_all_tools(): void
    {
        $response = $this->getJson('/api/mcp/tools');

        $response->assertStatus(200);
        $response->assertJsonFragment(['brain_status']);
        $response->assertJsonFragment(['palace_wake_up']);
        $response->assertJsonFragment(['drawer_add']);
        $response->assertJsonFragment(['drawer_search']);
        $response->assertJsonFragment(['drawer_get']);
        $response->assertJsonFragment(['context_get']);
        $response->assertJsonFragment(['context_set']);
        $response->assertJsonFragment(['context_list']);
    }
}
