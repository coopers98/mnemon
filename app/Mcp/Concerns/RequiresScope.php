<?php

namespace App\Mcp\Concerns;

use App\Mcp\Support\BrainSessionLogger;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait RequiresScope
{
    use ResolvesToolName;

    /**
     * Returns null if scope check passes; returns an error Response otherwise.
     * Tools should: `if ($err = $this->requireScope($request)) return $err;`
     */
    protected function requireScope(Request $request): ?Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null || ! $token->can('mcp:use')) {
            $reason = 'Missing required scope: mcp:use';
            BrainSessionLogger::logDenial($request, $this->auditToolName(), [], $reason);

            return Response::error($reason);
        }

        return null;
    }
}
