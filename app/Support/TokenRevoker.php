<?php

namespace App\Support;

use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

/**
 * Revocation that actually revokes.
 *
 * Passport's `isRefreshTokenRevoked` consults only `oauth_refresh_tokens.revoked`
 * and never looks at the linked access token, so revoking an access token on its
 * own leaves the refresh token usable: the device exchanges it at the next 401
 * and is fully working again within the hour, while the admin panel reports it
 * lost access immediately.
 *
 * That does not bite while devices carry personal access tokens, which cannot
 * refresh. It becomes a silent no-op the moment any refresh-capable grant is in
 * use, which is why this is fixed before that lands rather than after.
 */
class TokenRevoker
{
    /**
     * Revoke an access token and the refresh token issued alongside it.
     */
    public static function token(Token $token): void
    {
        RefreshToken::where('access_token_id', $token->id)->update(['revoked' => true]);

        $token->revoke();
    }

    /**
     * Revoke a client: its access tokens, their refresh tokens, and the client
     * itself, so no further grant can be exchanged in its name.
     */
    public static function client(Client $client): void
    {
        $tokenIds = Token::where('client_id', $client->id)->pluck('id');

        RefreshToken::whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
        Token::whereIn('id', $tokenIds)->update(['revoked' => true]);

        $client->forceFill(['revoked' => true])->save();
    }
}
