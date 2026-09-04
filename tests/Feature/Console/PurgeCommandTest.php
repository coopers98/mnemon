<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Support\TokenRevoker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Tests\TestCase;

class PurgeCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An access token long past its hour, with a refresh token that is still
     * good for months — the ordinary state of an enrolled device.
     */
    private function agedToken(Client $client, bool $withLiveRefresh): Token
    {
        $token = Token::forceCreate([
            'id' => bin2hex(random_bytes(20)),
            'user_id' => User::factory()->create()->id,
            'client_id' => $client->id,
            'scopes' => ['mcp:use'],
            'revoked' => false,
            'expires_at' => now()->subDays(30),
        ]);

        if ($withLiveRefresh) {
            RefreshToken::forceCreate([
                'id' => bin2hex(random_bytes(20)),
                'access_token_id' => $token->id,
                'revoked' => false,
                'expires_at' => now()->addDays(60),
            ]);
        }

        return $token;
    }

    public function test_it_keeps_an_expired_access_token_that_still_has_a_live_refresh_token(): void
    {
        // oauth_refresh_tokens has no client_id: a refresh token is tied to a
        // client only through its access token. Delete that row and the refresh
        // token is orphaned, and TokenRevoker::client can no longer find it —
        // leaving the client's revoked flag as the only thing stopping the
        // device. Keeping the row keeps the second mechanism alive.
        $token = $this->agedToken(Client::factory()->create(), withLiveRefresh: true);

        Artisan::call('passport:purge');

        $this->assertNotNull(Token::find($token->id),
            'purging this row orphans a refresh token that is still valid for two months');
    }

    public function test_it_still_deletes_an_expired_access_token_with_nothing_referencing_it(): void
    {
        $token = $this->agedToken(Client::factory()->create(), withLiveRefresh: false);

        Artisan::call('passport:purge');

        $this->assertNull(Token::find($token->id), 'purge must still do its job');
    }

    public function test_a_dead_refresh_token_does_not_protect_its_access_token(): void
    {
        // Revoked or expired refresh tokens are deleted first, so the access
        // token they referenced becomes collectable in the same run rather than
        // lingering until the next one.
        $client = Client::factory()->create();
        $token = $this->agedToken($client, withLiveRefresh: true);
        RefreshToken::where('access_token_id', $token->id)->update(['revoked' => true]);

        Artisan::call('passport:purge');

        $this->assertNull(Token::find($token->id));
        $this->assertSame(0, RefreshToken::where('access_token_id', $token->id)->count());
    }

    public function test_revocation_still_reaches_every_refresh_token_after_a_purge(): void
    {
        // The property all of this exists to protect.
        $client = Client::factory()->create();
        $token = $this->agedToken($client, withLiveRefresh: true);

        Artisan::call('passport:purge');
        TokenRevoker::client($client);

        $this->assertTrue(
            (bool) RefreshToken::where('access_token_id', $token->id)->first()?->revoked,
            'after a purge, revoking the client must still revoke its refresh tokens'
        );
    }

    public function test_the_expired_only_switch_leaves_revoked_rows_alone(): void
    {
        $client = Client::factory()->create();
        $revoked = Token::forceCreate([
            'id' => bin2hex(random_bytes(20)),
            'user_id' => User::factory()->create()->id,
            'client_id' => $client->id,
            'scopes' => ['mcp:use'],
            'revoked' => true,
            'expires_at' => now()->addHour(),
        ]);

        Artisan::call('passport:purge', ['--expired' => true]);

        $this->assertNotNull(Token::find($revoked->id),
            '--expired must not collect revoked rows; the options have to keep working');
    }

    public function test_the_revoked_only_switch_leaves_merely_expired_rows_alone(): void
    {
        $token = $this->agedToken(Client::factory()->create(), withLiveRefresh: false);

        Artisan::call('passport:purge', ['--revoked' => true]);

        $this->assertNotNull(Token::find($token->id));
    }
}
