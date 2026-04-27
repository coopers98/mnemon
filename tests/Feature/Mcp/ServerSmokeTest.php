<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPassportClient;
use Tests\TestCase;

class ServerSmokeTest extends TestCase
{
    use RefreshDatabase, CreatesPassportClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassportClient();
    }

    public function test_unauthenticated_request_to_mcp_returns_401(): void
    {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]);

        $response->assertStatus(401);
    }

    public function test_authenticated_tools_list_returns_registered_tools(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->accessToken;

        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ], [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertStatus(200);
        $tools = $response->json('result.tools');
        $this->assertIsArray($tools);
        $names = array_column($tools, 'name');
        $this->assertContains('drawer_search', $names);
    }
}
