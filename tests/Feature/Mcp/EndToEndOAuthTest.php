<?php

namespace Tests\Feature\Mcp;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\User;
use App\Models\Wing;
use App\Support\TokenRevoker;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use Tests\TestCase;

class EndToEndOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exempt the OAuth consent and token endpoints from CSRF so POSTs work in tests.
        VerifyCsrfToken::except([
            'oauth/authorize',
            'oauth/token',
        ]);
    }

    public function test_full_flow_from_consent_to_mcp_tool_call(): void
    {
        // Setup: wings, rooms, drawers
        $work = Wing::factory()->create(['slug' => 'work']);
        $personal = Wing::factory()->create(['slug' => 'personal']);
        $workRoom = Room::factory()->create(['wing_id' => $work->id]);
        $personalRoom = Room::factory()->create(['wing_id' => $personal->id]);
        Drawer::factory()->create(['room_id' => $workRoom->id, 'content' => 'work-secret']);
        Drawer::factory()->create(['room_id' => $personalRoom->id, 'content' => 'personal-secret']);

        $user = User::factory()->create();
        $client = Client::factory()->create([
            'redirect_uris' => ['http://localhost/cb'],
        ]);

        $this->actingAs($user);

        // Step 1: Initiate consent — get authToken
        $authResp = $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id,
            'redirect_uri' => 'http://localhost/cb',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'st',
        ]));
        $authResp->assertStatus(200);

        $authToken = $authResp->viewData('authToken')
            ?? app('session.store')->get('authToken')
            ?? null;
        $this->assertNotNull($authToken, 'authToken not found in session or view data');

        // Step 2: Approve consent with wing restriction to 'work' only
        $approve = $this->post('/oauth/authorize', [
            'auth_token' => $authToken,
            'client_id' => $client->id,
            'scopes' => ['mcp:use'],
            'wings' => ['work'],
        ]);
        $approve->assertRedirect();

        // Step 3: Extract auth code from redirect
        $location = $approve->headers->get('Location');
        $this->assertNotNull($location, 'Expected redirect after consent approval');
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query, "Expected code in redirect query, got: $location");
        $code = $query['code'];

        // Step 4: Exchange code for access token
        $tokenResp = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'redirect_uri' => 'http://localhost/cb',
            'code' => $code,
        ]);
        $tokenResp->assertStatus(200);
        $accessToken = $tokenResp->json('access_token');
        $this->assertNotNull($accessToken, 'Expected access_token in token response');

        // Step 5: Call drawer_search — should only see work drawer (wing restriction enforced)
        $r = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'drawer_search',
                'arguments' => ['query' => 'secret'],
            ],
        ], ['Authorization' => "Bearer {$accessToken}"]);

        $r->assertStatus(200);
        $results = $r->json('result.structuredContent.results');
        $this->assertNotNull($results, 'Expected results in MCP response');
        $contents = collect($results)->pluck('content')->all();

        $this->assertContains('work-secret', $contents,
            'Expected work-secret in results, got: '.json_encode($contents));
        $this->assertNotContains('personal-secret', $contents,
            'personal-secret should be filtered out by wing restriction');
    }

    public function test_wing_restriction_survives_a_token_refresh(): void
    {
        $work = Wing::factory()->create(['slug' => 'work']);
        $personal = Wing::factory()->create(['slug' => 'personal']);
        $workRoom = Room::factory()->create(['wing_id' => $work->id]);
        $personalRoom = Room::factory()->create(['wing_id' => $personal->id]);
        Drawer::factory()->create(['room_id' => $workRoom->id, 'content' => 'work-secret']);
        Drawer::factory()->create(['room_id' => $personalRoom->id, 'content' => 'personal-secret']);

        $user = User::factory()->create();
        $client = Client::factory()->create(['redirect_uris' => ['http://localhost/cb']]);
        $this->actingAs($user);

        $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'redirect_uri' => 'http://localhost/cb',
            'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 'st',
        ]))->assertStatus(200);
        $authToken = app('session.store')->get('authToken');

        $approve = $this->post('/oauth/authorize', [
            'auth_token' => $authToken, 'client_id' => $client->id,
            'scopes' => ['mcp:use'], 'wings' => ['work'],
        ]);
        parse_str(parse_url($approve->headers->get('Location'), PHP_URL_QUERY), $q);

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'redirect_uri' => 'http://localhost/cb', 'code' => $q['code'],
        ])->json();

        // The defect: a refreshed token has a new id, no restriction row exists
        // for it, and a missing row means unrestricted. One hour after consent,
        // a work-only device silently becomes an all-wings device.
        $refreshed = $this->post('/oauth/token', [
            'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
            'client_id' => $client->id, 'client_secret' => $client->plainSecret,
            'scope' => 'mcp:use',
        ]);
        $refreshed->assertStatus(200);

        $r = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'drawer_search', 'arguments' => ['query' => 'secret']],
        ], ['Authorization' => 'Bearer '.$refreshed->json('access_token')]);

        $contents = collect($r->json('result.structuredContent.results'))->pluck('content')->all();
        $this->assertContains('work-secret', $contents);
        $this->assertNotContains('personal-secret', $contents,
            'a refreshed token must keep the wing restriction consented to — the restriction belongs to the device, not to the hour-long token');
    }

    public function test_a_used_refresh_token_can_be_used_again(): void
    {
        // The device holds one refresh token and no browser. If an exchange
        // succeeds on the server but the response is lost on the way back, the
        // device retries with the only token it has. Under rotation that token
        // is already consumed, so the retry is a guaranteed invalid_grant and
        // the device is stranded until someone opens a browser for it.
        [$client, $refreshToken] = $this->issueTokensViaAuthCodeFlow();

        $first = $this->exchangeRefreshToken($client, $refreshToken);
        $first->assertStatus(200);
        $this->assertNotEmpty($first->json('refresh_token'),
            'each exchange must still issue a fresh refresh token, or the 90-day window stops sliding');

        $second = $this->exchangeRefreshToken($client, $refreshToken);
        $second->assertStatus(200,
            'a response lost on the wire must not brick the device: the refresh token it still holds has to keep working');
    }

    public function test_revoking_the_client_stops_the_refresh_exchange(): void
    {
        // With rotation off, superseded refresh tokens stay valid until their own
        // 90-day expiry, so revoking a single token only stops a device if it
        // happens to hold the newest one. Client revocation is the kill switch
        // that is actually reliable — and it is only a kill switch if the OAuth
        // server honours the rows TokenRevoker writes, which no test covered.
        [$client, $refreshToken] = $this->issueTokensViaAuthCodeFlow();

        $this->exchangeRefreshToken($client, $refreshToken)->assertStatus(200);

        TokenRevoker::client($client);
        $this->asANewRequest();

        $after = $this->exchangeRefreshToken($client, $refreshToken);
        $after->assertStatus(401);
        $this->assertSame('invalid_client', $after->json('error'));
        $this->assertNull($after->json('access_token'),
            'a revoked client must not be able to exchange a refresh token for a working access token');
    }

    public function test_revocation_holds_when_purge_has_orphaned_the_refresh_token(): void
    {
        // passport:purge deletes access tokens expired for more than 168h. Access
        // tokens live an hour and refresh tokens live 90 days, so a refresh token
        // routinely outlives the access-token row it was issued with. TokenRevoker
        // reaches refresh tokens through access_token_id, so it cannot revoke an
        // orphan — nothing left ties that row to a client. What still stops the
        // device is the client's own revoked flag, and this pins that: it is the
        // only mechanism left in the worst case.
        [$client, $refreshToken] = $this->issueTokensViaAuthCodeFlow();

        Token::where('client_id', $client->id)->delete();

        TokenRevoker::client($client);
        $this->assertFalse(RefreshToken::first()->revoked,
            'documents the gap this test exists to cover: the orphan is beyond TokenRevoker reach');

        $this->asANewRequest();

        $after = $this->exchangeRefreshToken($client, $refreshToken);
        $after->assertStatus(401);
        $this->assertNull($after->json('access_token'),
            'a purged-then-revoked client must not keep minting access tokens for the rest of the 90 days');
    }

    /**
     * Drop per-process memoisation so the next call sees committed state.
     *
     * Passport's ClientRepository::find wraps its query in once(), and the
     * repository is a container singleton, so one test process keeps serving the
     * Client model it loaded first — including its pre-revocation revoked flag.
     * A deployment gets a fresh container per request and never sees that; a test
     * that skips this measures the cache and reports a kill switch that works for
     * the wrong reason.
     */
    private function asANewRequest(): void
    {
        Once::flush();
    }

    /**
     * Consent, approve, and exchange the code — the nine calls a real client makes.
     *
     * @return array{0: Client, 1: string} the client and its refresh token
     */
    private function issueTokensViaAuthCodeFlow(): array
    {
        $user = User::factory()->create();
        $client = Client::factory()->create(['redirect_uris' => ['http://localhost/cb']]);
        $this->actingAs($user);

        $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'redirect_uri' => 'http://localhost/cb',
            'response_type' => 'code', 'scope' => 'mcp:use', 'state' => 'st',
        ]))->assertStatus(200);

        $approve = $this->post('/oauth/authorize', [
            'auth_token' => app('session.store')->get('authToken'),
            'client_id' => $client->id, 'scopes' => ['mcp:use'], 'all_wings' => '1',
        ]);
        parse_str(parse_url($approve->headers->get('Location'), PHP_URL_QUERY), $q);

        $tokens = $this->post('/oauth/token', [
            'grant_type' => 'authorization_code', 'client_id' => $client->id,
            'client_secret' => $client->plainSecret,
            'redirect_uri' => 'http://localhost/cb', 'code' => $q['code'],
        ])->json();

        $this->assertNotEmpty($tokens['refresh_token'] ?? null, 'no refresh token to test with');

        return [$client, $tokens['refresh_token']];
    }

    private function exchangeRefreshToken(Client $client, string $refreshToken)
    {
        return $this->post('/oauth/token', [
            'grant_type' => 'refresh_token', 'refresh_token' => $refreshToken,
            'client_id' => $client->id, 'client_secret' => $client->plainSecret,
            'scope' => 'mcp:use',
        ]);
    }
}
