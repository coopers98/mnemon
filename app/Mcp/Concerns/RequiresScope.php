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
        $scope = $this->scope ?? null;

        if ($scope === null) {
            return null;
        }

        $token = $request->user()?->currentAccessToken();

        if ($token === null || ! $token->can($scope)) {
            return Response::error("Missing required scope: {$scope}");
        }

        return null;
    }
}
