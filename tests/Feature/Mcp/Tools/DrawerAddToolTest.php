<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Drawer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MakesMcpRequests;
use Tests\TestCase;

class DrawerAddToolTest extends TestCase
{
    use RefreshDatabase, MakesMcpRequests;

    public function test_creates_drawer_with_oauth_client_as_default_source(): void
    {
        $r = $this->mcpCall('drawer_add', [
            'wing'    => 'work',
            'room'    => 'notes',
            'content' => 'meeting notes',
        ], ['palace.write']);

        $r->assertStatus(200);
        $drawer = Drawer::first();
        $this->assertEquals('meeting notes', $drawer->content);
        $this->assertEquals('Test Client', $drawer->source);
    }

    public function test_explicit_source_overrides_oauth_client_name(): void
    {
        $r = $this->mcpCall('drawer_add', [
            'wing'    => 'work',
            'room'    => 'notes',
            'content' => 'foo',
            'source'  => 'manual-entry',
        ], ['palace.write']);

        $this->assertEquals('manual-entry', Drawer::first()->source);
    }

    public function test_rejects_write_outside_wing_restrictions(): void
    {
        $r = $this->mcpCall('drawer_add', [
            'wing'    => 'personal',
            'room'    => 'notes',
            'content' => 'foo',
        ], ['palace.write'], wingPatterns: ['work:*']);

        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
        $this->assertEquals(0, Drawer::count());
    }

    public function test_rejects_missing_scope(): void
    {
        $r = $this->mcpCall('drawer_add', ['wing' => 'work', 'room' => 'notes', 'content' => 'foo'], ['palace.read']);
        $body = $r->json();
        $this->assertTrue($body['result']['isError'] ?? false);
    }
}
