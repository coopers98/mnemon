<?php

namespace Tests\Feature\Mcp;

use App\Models\Drawer;
use App\Models\Room;
use App\Models\User;
use App\Models\Wing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Client;
use Tests\TestCase;

class EndToEndOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exempt the OAuth consent and token endpoints from CSRF so POSTs work in tests.
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::except([
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
        $authResp = $this->get('/oauth/authorize?' . http_build_query([
            'client_id'     => $client->id,
            'redirect_uri'  => 'http://localhost/cb',
            'response_type' => 'code',
            'scope'         => 'palace.read',
            'state'         => 'st',
        ]));
        $authResp->assertStatus(200);

        $authToken = $authResp->viewData('authToken')
            ?? app('session.store')->get('authToken')
            ?? null;
        $this->assertNotNull($authToken, 'authToken not found in session or view data');

        // Step 2: Approve consent with wing restriction to 'work' only
        $approve = $this->post('/oauth/authorize', [
            'auth_token' => $authToken,
            'client_id'  => $client->id,
            'scopes'     => ['palace.read'],
            'wings'      => ['work'],
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
            'grant_type'    => 'authorization_code',
            'client_id'     => $client->id,
            'client_secret' => $client->plainSecret,
            'redirect_uri'  => 'http://localhost/cb',
            'code'          => $code,
        ]);
        $tokenResp->assertStatus(200);
        $accessToken = $tokenResp->json('access_token');
        $this->assertNotNull($accessToken, 'Expected access_token in token response');

        // Step 5: Call drawer_search — should only see work drawer (wing restriction enforced)
        $r = $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id'      => 1,
            'method'  => 'tools/call',
            'params'  => [
                'name'      => 'drawer_search',
                'arguments' => ['query' => 'secret'],
            ],
        ], ['Authorization' => "Bearer {$accessToken}"]);

        $r->assertStatus(200);
        $results = $r->json('result.structuredContent.results');
        $this->assertNotNull($results, 'Expected results in MCP response');
        $contents = collect($results)->pluck('content')->all();

        $this->assertContains('work-secret', $contents,
            'Expected work-secret in results, got: ' . json_encode($contents));
        $this->assertNotContains('personal-secret', $contents,
            'personal-secret should be filtered out by wing restriction');
    }
}
