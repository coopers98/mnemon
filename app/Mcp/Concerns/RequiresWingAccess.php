<?php

namespace App\Mcp\Concerns;

use App\Mcp\Support\BrainSessionLogger;
use App\Models\McpClientRestriction;
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
     * Whether an item that exists in this wing must be answered as missing.
     *
     * requireWingAccess() suits arguments the caller chose (a wing slug, a
     * page name): its refusal depends only on what was asked. When the wing
     * is learned from a lookup by id, a distinct refusal would confirm the
     * item exists and name its wing, so the caller answers "not found"
     * instead. The denial is still recorded for the audit trail, which only
     * the admin reads.
     */
    protected function hidesWing(Request $request, string $wingSlug): bool
    {
        $restriction = $this->resolveRestriction($request);

        if ($restriction === null || $restriction->matches($wingSlug)) {
            return false;
        }

        BrainSessionLogger::logDenial(
            $request,
            $this->auditToolName(),
            ['wing' => $wingSlug],
            "Token does not have access to wing: {$wingSlug}"
        );

        return true;
    }

    /**
     * @return array<string>|null null = unrestricted (all wings allowed)
     */
    protected function wingPatternsFor(Request $request): ?array
    {
        return $this->resolveRestriction($request)?->wing_patterns;
    }

    private ?string $resolvedTokenId = null;

    private McpClientRestriction|McpTokenRestriction|null $resolvedRestriction = null;

    /**
     * The restriction governing this request.
     *
     * Client rows are preferred: a device's permitted wings belong to the
     * device, and a refreshed token inherits them by construction. Token rows
     * remain as a fallback for instances that consented before restrictions
     * moved off the token. Absence still means unrestricted, which is how
     * personal access tokens reach every wing.
     */
    private function resolveRestriction(Request $request): McpClientRestriction|McpTokenRestriction|null
    {
        $token = $request->user()?->currentAccessToken();
        $tokenId = $token?->id;

        if ($tokenId === null) {
            return null;
        }

        if ($this->resolvedTokenId !== $tokenId) {
            $this->resolvedTokenId = $tokenId;

            $clientId = $token->client_id ?? null;

            $this->resolvedRestriction = ($clientId !== null
                ? McpClientRestriction::find((string) $clientId)
                : null) ?? McpTokenRestriction::find($tokenId);
        }

        return $this->resolvedRestriction;
    }
}
