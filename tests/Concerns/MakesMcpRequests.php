<?php

namespace Tests\Concerns;

use App\Models\McpTokenRestriction;
use App\Models\User;
use Illuminate\Testing\TestResponse;

trait MakesMcpRequests
{
    protected function mcpCall(string $tool, array $arguments, array $scopes = ['*'], ?array $wingPatterns = null): TestResponse
    {
        $user = User::factory()->create();
        $tokenResult = $user->createToken('Test Client', $scopes);
        $token = $tokenResult->token;

        if ($wingPatterns !== null) {
            McpTokenRestriction::create([
                'access_token_id' => $token->id,
                'wing_patterns'   => $wingPatterns,
            ]);
        }

        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => $arguments,
            ],
        ], [
            'Authorization' => 'Bearer '.$tokenResult->accessToken,
        ]);
    }
}
