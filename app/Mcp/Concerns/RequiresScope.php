<?php

namespace App\Mcp\Concerns;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

trait RequiresScope
{
    /**
     * Returns null if scope check passes; returns an error Response otherwise.
     * Tools should: `if ($err = $this->requireScope($request)) return $err;`
     */
    protected function requireScope(Request $request): ?Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token === null || ! $token->can('mcp:use')) {
            return Response::error('Missing required scope: mcp:use');
        }

        return null;
    }
}
