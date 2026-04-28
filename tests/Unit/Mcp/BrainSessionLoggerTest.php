<?php

namespace Tests\Unit\Mcp;

use App\Mcp\Support\BrainSessionLogger;
use App\Models\BrainSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Passport\AccessToken;
use Tests\Concerns\CreatesPassportClient;
use Tests\TestCase;

/**
 * Test approach: Laravel\Mcp\Request (v0.7.0) is NOT an HTTP request subclass —
 * it has no static createFrom() and no setUserResolver(). Its user() method calls
 * Container::getInstance()->make('auth')->userResolver()($guard).
 *
 * We construct the Request directly with new Request() and use actingAs($user, 'api')
 * to set the api guard as the default auth guard, so Request::user() returns the
 * Passport-authenticated user.
 *
 * Passport's withAccessToken() expects a ScopeAuthorizable. The Eloquent Token model
 * does NOT implement ScopeAuthorizable — only AccessToken and TransientToken do.
 * We create an AccessToken wrapper with the real token's ID so that ->id, ->client_id,
 * and ->client forwarding all work against the real persisted Token row.
 */
class BrainSessionLoggerTest extends TestCase
{
    use CreatesPassportClient, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPassportClient();
    }

    public function test_log_writes_brain_session_with_oauth_metadata(): void
    {
        $user = User::factory()->create();
        $tokenResult = $user->createToken('Claude Code', ['mcp:use']);
        $eloquentToken = $tokenResult->token;

        // Wrap the Eloquent Token in an AccessToken (implements ScopeAuthorizable).
        // AccessToken forwards ->id, ->client_id, ->client to the underlying Token row
        // via its __get/__call magic using oauth_access_token_id as the lookup key.
        $accessToken = new AccessToken([
            'oauth_access_token_id' => $eloquentToken->id,
            'oauth_client_id' => $eloquentToken->client_id,
            'oauth_scopes' => ['mcp:use'],
        ]);

        // actingAs with 'api' guard makes Passport the default guard, so
        // Request::user() (null guard) returns this user with the token set.
        $this->actingAs($user->withAccessToken($accessToken), 'api');

        $request = new Request;

        BrainSessionLogger::log($request, 'drawer_search', ['query' => 'foo'], 5);

        $session = BrainSession::latest('id')->first();
        $this->assertEquals('drawer_search', $session->tool_name);
        $this->assertEquals($user->id, $session->user_id);
        $this->assertEquals($eloquentToken->id, $session->access_token_id);
        $this->assertEquals($eloquentToken->client_id, $session->oauth_client_id);
        $this->assertStringContainsString('Claude Code', $session->source);
        $this->assertEquals(['query' => 'foo'], $session->input);
        $this->assertEquals(5, $session->result_count);
    }
}
