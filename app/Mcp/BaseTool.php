<?php

namespace App\Mcp;

use App\Models\ApiKey;
use App\Models\BrainSession;

abstract class BaseTool
{
    /**
     * The scope required to use this tool.
     */
    abstract public function requiredScope(): string;

    /**
     * Execute the tool logic and return the result array.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    abstract public function execute(array $params, ApiKey $apiKey): array;

    /**
     * Assert the API key has the required scope.
     *
     * @throws McpException
     */
    protected function requireScope(ApiKey $apiKey, string $scope): void
    {
        if (! $apiKey->hasScope($scope)) {
            throw McpException::forbidden("API key missing required scope: {$scope}");
        }
    }

    /**
     * Assert the API key can access the given wing slug.
     *
     * @throws McpException
     */
    protected function requireWingAccess(ApiKey $apiKey, string $wingSlug): void
    {
        if (! $apiKey->canAccessWing($wingSlug)) {
            throw McpException::forbidden("API key does not have access to wing: {$wingSlug}");
        }
    }

    /**
     * Log a tool invocation to brain_sessions.
     *
     * @param  array<string, mixed>  $input
     */
    protected function logSession(string $toolName, ApiKey $apiKey, array $input, int $resultCount): void
    {
        BrainSession::create([
            'tool_name' => $toolName,
            'source' => $apiKey->name,
            'input' => $input,
            'result_count' => $resultCount,
        ]);
    }
}
