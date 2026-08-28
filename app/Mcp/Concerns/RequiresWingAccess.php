<?php

namespace App\Mcp\Concerns;

use App\Mcp\Support\BrainSessionLogger;
use App\Models\McpTokenRestriction;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait RequiresWingAccess
{
    use ResolvesToolName;

    protected function requireWingAccess(Request $request, string $wingSlug): ?Response
    {
        $restriction = $this->resolveRestriction($request);

        if ($restriction === null || $restriction->matches($wingSlug)) {
            return null;
        }

        $reason = "Token does not have access to wing: {$wingSlug}";
        BrainSessionLogger::logDenial($request, $this->auditToolName(), ['wing' => $wingSlug], $reason);

        return Response::error($reason);
    }

    /**
     * @return array<string>|null null = unrestricted (all wings allowed)
     */
    protected function wingPatternsFor(Request $request): ?array
    {
        return $this->resolveRestriction($request)?->wing_patterns;
    }

    private ?string $resolvedTokenId = null;

    private ?McpTokenRestriction $resolvedRestriction = null;

    private function resolveRestriction(Request $request): ?McpTokenRestriction
    {
        $tokenId = $request->user()?->currentAccessToken()?->id;
        if ($tokenId === null) {
            return null;
        }

        if ($this->resolvedTokenId !== $tokenId) {
            $this->resolvedTokenId = $tokenId;
            $this->resolvedRestriction = McpTokenRestriction::find($tokenId);
        }

        return $this->resolvedRestriction;
    }
}
