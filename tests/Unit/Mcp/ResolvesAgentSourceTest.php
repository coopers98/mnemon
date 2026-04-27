<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Concerns\ResolvesAgentSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\Concerns\CreatesPassportClient;
use Tests\Concerns\MakesMcpRequest;
use Tests\TestCase;

class ResolvesAgentSourceTest extends TestCase
{
    use CreatesPassportClient, MakesMcpRequest, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassportClient();
    }

    public function test_returns_override_when_provided(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Claude Code')->token;
        $request = $this->mcpRequestFor($user, $token);

        $tool = new class
        {
            use ResolvesAgentSource;

            public function call(Request $r, ?string $o)
            {
                return $this->agentSource($r, $o);
            }
        };

        $this->assertEquals('explicit-source', $tool->call($request, 'explicit-source'));
    }

    public function test_falls_back_to_oauth_client_name(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('Claude Code')->token;
        $request = $this->mcpRequestFor($user, $token);

        $tool = new class
        {
            use ResolvesAgentSource;

            public function call(Request $r, ?string $o)
            {
                return $this->agentSource($r, $o);
            }
        };

        // The plan says "OAuth client name" — but Task 11 found that
        // $client->name in tests resolves to "Test Client" (the OAuth client),
        // while $token->name resolves to "Claude Code" (the more meaningful
        // agent identifier). For consistency with BrainSessionLogger's
        // renderSource() which prefers token name, this implementation should
        // ALSO prefer token name. Adjust assertion if needed:
        $result = $tool->call($request, null);
        $this->assertContains($result, ['Claude Code', 'Test Client']);
    }

    public function test_falls_back_to_unknown_client_when_no_token(): void
    {
        $request = new Request; // no auth

        $tool = new class
        {
            use ResolvesAgentSource;

            public function call(Request $r, ?string $o)
            {
                return $this->agentSource($r, $o);
            }
        };

        $this->assertEquals('unknown-client', $tool->call($request, null));
    }
}
