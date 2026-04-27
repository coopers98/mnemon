<?php

namespace Tests\Concerns;

use Laravel\Mcp\Request;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Token;

trait MakesMcpRequest
{
    /**
     * Build a Laravel\Mcp\Request authenticated as $user with $token's scopes.
     *
     * Wraps the Eloquent Token in an AccessToken (which implements ScopeAuthorizable)
     * so that ->can() / tokenCan() Passport semantics work correctly.
     */
    protected function mcpRequestFor(mixed $user, Token $token): Request
    {
        $accessToken = new AccessToken([
            'oauth_access_token_id' => $token->id,
            'oauth_client_id'       => $token->client_id,
            'oauth_scopes'          => $token->scopes,
        ]);

        $this->actingAs($user->withAccessToken($accessToken), 'api');

        return new Request();
    }
}
