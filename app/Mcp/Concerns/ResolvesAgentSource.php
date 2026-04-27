<?php

namespace App\Mcp\Concerns;

use Laravel\Mcp\Request;

trait ResolvesAgentSource
{
    protected function agentSource(Request $request, ?string $override): string
    {
        if ($override !== null && trim($override) !== '') {
            return $override;
        }

        $token = $request->user()?->currentAccessToken();

        // Mirror BrainSessionLogger::renderSource priority: token name then client name.
        return $token?->name
            ?? $token?->client?->name
            ?? 'unknown-client';
    }
}
