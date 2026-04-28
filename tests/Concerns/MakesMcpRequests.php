<?php

namespace Tests\Concerns;

use App\Models\McpTokenRestriction;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;

trait MakesMcpRequests
{
    private bool $passportClientCreated = false;

    private function ensurePassportClient(): void
    {
        if ($this->passportClientCreated) {
            return;
        }

        /** @var ClientRepository $clients */
        $clients = app(ClientRepository::class);

        if (! Client::where('personal_access_client', true)->exists()) {
            $clients->createPersonalAccessGrantClient('Test Client', 'users');
        }

        $this->passportClientCreated = true;
    }

    private function mcpRequest(string $method, array $params, array $scopes = ['mcp:use'], ?array $wingPatterns = null): TestResponse
    {
        $this->ensurePassportClient();

        $user = User::factory()->create();
        $tokenResult = $user->createToken('Test Client', $scopes);
        $token = $tokenResult->token;

        if ($wingPatterns !== null) {
            McpTokenRestriction::create([
                'access_token_id' => $token->id,
                'wing_patterns' => $wingPatterns,
            ]);
        }

        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params,
        ], [
            'Authorization' => 'Bearer '.$tokenResult->accessToken,
        ]);
    }

    protected function mcpCall(string $tool, array $arguments, array $scopes = ['mcp:use'], ?array $wingPatterns = null): TestResponse
    {
        return $this->mcpRequest('tools/call', [
            'name' => $tool,
            'arguments' => $arguments,
        ], $scopes, $wingPatterns);
    }

    protected function mcpResourceList(array $scopes = ['mcp:use'], ?array $wingPatterns = null): TestResponse
    {
        return $this->mcpRequest('resources/templates/list', [], $scopes, $wingPatterns);
    }

    protected function mcpResourceRead(string $uri, array $scopes = ['mcp:use'], ?array $wingPatterns = null): TestResponse
    {
        return $this->mcpRequest('resources/read', ['uri' => $uri], $scopes, $wingPatterns);
    }

    protected function mcpPromptList(array $scopes = ['mcp:use'], ?array $wingPatterns = null): TestResponse
    {
        return $this->mcpRequest('prompts/list', [], $scopes, $wingPatterns);
    }

    protected function mcpPromptGet(string $name, array $args = [], array $scopes = ['mcp:use'], ?array $wingPatterns = null): TestResponse
    {
        return $this->mcpRequest('prompts/get', [
            'name' => $name,
            'arguments' => $args,
        ], $scopes, $wingPatterns);
    }
}
