<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TokenRevoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Tests\TestCase;

class TokenRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function tokenWithRefresh(Client $client): Token
    {
        $token = Token::forceCreate([
            'id' => bin2hex(random_bytes(20)),
            'user_id' => User::factory()->create()->id,
            'client_id' => $client->id,
            'scopes' => ['mcp:use'],
            'revoked' => false,
            'expires_at' => now()->addHour(),
        ]);

        RefreshToken::forceCreate([
            'id' => bin2hex(random_bytes(20)),
            'access_token_id' => $token->id,
            'revoked' => false,
            'expires_at' => now()->addDays(90),
        ]);

        return $token;
    }

    /**
     * Passport's isRefreshTokenRevoked consults only oauth_refresh_tokens.revoked
     * — it never looks at the linked access token. So revoking an access token
     * alone lets the device refresh straight back to a working one within the
     * hour, while the admin UI reports it lost access immediately.
     */
    public function test_revoking_an_access_token_also_revokes_its_refresh_token(): void
    {
        $token = $this->tokenWithRefresh(Client::factory()->create());

        TokenRevoker::token($token);

        $this->assertTrue($token->fresh()->revoked);
        $this->assertTrue(RefreshToken::where('access_token_id', $token->id)->first()->revoked,
            'a refresh token outliving its access token makes revocation a no-op');
    }

    public function test_revoking_a_client_marks_the_client_revoked(): void
    {
        $client = Client::factory()->create();
        $this->tokenWithRefresh($client);

        TokenRevoker::client($client);

        $this->assertTrue($client->fresh()->revoked,
            'a client left active can keep exchanging refresh tokens');
    }

    public function test_revoking_a_client_revokes_refresh_tokens_too(): void
    {
        $client = Client::factory()->create();
        $token = $this->tokenWithRefresh($client);

        TokenRevoker::client($client);

        $this->assertTrue($token->fresh()->revoked);
        $this->assertTrue(RefreshToken::where('access_token_id', $token->id)->first()->revoked);
    }

    public function test_revoking_one_client_leaves_another_alone(): void
    {
        $keep = Client::factory()->create();
        $keepToken = $this->tokenWithRefresh($keep);
        $drop = Client::factory()->create();
        $this->tokenWithRefresh($drop);

        TokenRevoker::client($drop);

        $this->assertFalse($keep->fresh()->revoked);
        $this->assertFalse($keepToken->fresh()->revoked);
    }
}
