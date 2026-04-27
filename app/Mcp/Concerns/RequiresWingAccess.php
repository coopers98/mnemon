<?php

namespace App\Mcp\Concerns;

use App\Models\McpTokenRestriction;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait RequiresWingAccess
{
    protected function requireWingAccess(Request $request, string $wingSlug): ?Response
    {
        $restriction = $this->resolveRestriction($request);

        if ($restriction === null || $restriction->matches($wingSlug)) {
            return null;
        }

        return Response::error("Token does not have access to wing: {$wingSlug}");
    }

    /**
     * @return array<string>|null  null = unrestricted (all wings allowed)
     */
    protected function wingPatternsFor(Request $request): ?array
    {
        return $this->resolveRestriction($request)?->wing_patterns;
    }

    private function resolveRestriction(Request $request): ?McpTokenRestriction
    {
        $tokenId = $request->user()?->currentAccessToken()?->id;
        if ($tokenId === null) {
            return null;
        }
        return McpTokenRestriction::find($tokenId);
    }
}
